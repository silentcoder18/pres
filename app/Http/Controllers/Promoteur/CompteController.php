<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Models\Utilisateur;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Comptes utilisateurs (RG-15, RG-16) : seul le Promoteur en cree, en desactive, ou reinitialise
 * un mot de passe. Un compte a un seul role, fixe a sa creation. Un changement de mot de passe ou
 * d'acces oblige le poste a redemander une connexion en ligne (§4.6).
 */
class CompteController extends Controller
{
    public function index(Request $request): View
    {
        $filtres = $request->validate([
            'site' => ['nullable', 'uuid'],
            'role' => ['nullable', Rule::in(Utilisateur::ROLES)],
        ]);

        return view('promoteur.comptes.index', [
            'comptes' => Utilisateur::with('site')
                ->when($filtres['site'] ?? null, fn ($q, $site) => $q->where('site_id', $site))
                ->when($filtres['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
                ->orderBy('nom')->get(),
            'sites' => Site::orderBy('nom')->get(),
            'filtres' => $filtres,
        ]);
    }

    public function create(): View
    {
        return view('promoteur.comptes.create', ['sites' => Site::where('actif', true)->orderBy('nom')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            // une adresse de courriel fait un identifiant acceptable : c'est ce dont on se souvient le mieux
            'identifiant' => ['required', 'string', 'min:3', 'max:100', 'regex:/^[A-Za-z0-9._@+-]+$/', 'unique:utilisateurs,identifiant'],
            'role' => ['required', Rule::in(Utilisateur::ROLES)],
            'site_id' => ['nullable', 'uuid', 'exists:sites,id'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'mot_de_passe' => $this->regleMotDePasse(),
        ], $this->messages());

        $donnees['site_id'] = $this->siteAdmis($donnees['role'], $donnees['site_id'] ?? null);

        $compte = Utilisateur::create($donnees);
        Audit::noter(Audit::COMPTE_CREE, $compte, $compte->identifiant.' — '.$compte->nom, 'Rôle : '.$compte->role);

        $suite = $compte->role === Utilisateur::PROMOTEUR
            ? ''
            : ' Sa première connexion sur le poste doit se faire avec Internet.';

        return redirect()->route('comptes.index')->with('succes', "Le compte « {$compte->identifiant} » est créé.".$suite);
    }

    public function edit(Utilisateur $compte): View
    {
        return view('promoteur.comptes.edit', [
            'compte' => $compte,
            'sites' => Site::where('actif', true)->orWhere('id', $compte->site_id)->orderBy('nom')->get(),
        ]);
    }

    public function update(Request $request, Utilisateur $compte): RedirectResponse
    {
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'site_id' => ['nullable', 'uuid', 'exists:sites,id'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'actif' => ['required', 'boolean'],
        ]);

        if ($compte->is($request->user()) && ! $request->boolean('actif')) {
            throw ValidationException::withMessages(['actif' => 'Vous ne pouvez pas désactiver votre propre compte.']);
        }
        $donnees['site_id'] = $this->siteAdmis($compte->role, $donnees['site_id'] ?? null);

        $ancienSite = $compte->site?->nom;
        $compte->update($donnees);

        if ($compte->wasChanged()) {
            $detail = Audit::changements($compte, ['nom' => 'Nom', 'telephone' => 'Téléphone', 'actif' => 'Actif']);
            if ($compte->wasChanged('site_id')) {
                $detail = trim($detail.' ; Site : '.$ancienSite.' → '.$compte->fresh()->site?->nom, ' ;');
            }
            Audit::noter(Audit::COMPTE_MODIFIE, $compte, $compte->identifiant.' — '.$compte->nom, $detail);
        }

        return redirect()->route('comptes.index')->with('succes', "Le compte « {$compte->identifiant} » est mis à jour.");
    }

    public function motDePasse(Request $request, Utilisateur $compte): RedirectResponse
    {
        $donnees = $request->validate(['mot_de_passe' => $this->regleMotDePasse()], $this->messages());

        $compte->update(['mot_de_passe' => $donnees['mot_de_passe']]);
        Audit::noter(Audit::COMPTE_MOT_DE_PASSE, $compte, $compte->identifiant.' — '.$compte->nom);

        $suite = $compte->role === Utilisateur::PROMOTEUR
            ? ''
            : ' Sa prochaine connexion sur le poste doit se faire avec Internet.';

        return redirect()->route('comptes.edit', $compte)->with('succes', 'Le mot de passe est réinitialisé.'.$suite);
    }

    private function regleMotDePasse(): array
    {
        return ['required', 'string', 'min:8', 'max:200', 'regex:/\pL/u', 'regex:/\d/', 'confirmed'];
    }

    private function messages(): array
    {
        return [
            'identifiant.regex' => "L'identifiant ne comporte que des lettres, des chiffres et les signes . - _ @ + (pas d'espace ni d'accent).",
            'mot_de_passe.regex' => 'Le mot de passe doit comporter au moins une lettre et un chiffre.',
        ];
    }

    /**
     * Le Promoteur n'est rattache a aucun site ; une receptionniste l'est a une boutique et un
     * chef d'atelier a l'atelier.
     */
    private function siteAdmis(string $role, ?string $siteId): ?string
    {
        if ($role === Utilisateur::PROMOTEUR) {
            return null;
        }

        $attendu = $role === Utilisateur::CHEF_ATELIER ? Site::ATELIER : Site::BOUTIQUE;
        $site = $siteId === null ? null : Site::find($siteId);

        if ($site === null) {
            throw ValidationException::withMessages(['site_id' => 'Choisissez le site de rattachement du compte.']);
        }
        if ($site->type !== $attendu) {
            throw ValidationException::withMessages(['site_id' => $role === Utilisateur::CHEF_ATELIER
                ? "Un chef d'atelier est rattaché à l'atelier."
                : 'Une réceptionniste est rattachée à une boutique.']);
        }

        return $site->id;
    }
}
