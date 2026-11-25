<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Site;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Boutiques et atelier du reseau. Un site ne se supprime pas : il se desactive. */
class SiteController extends Controller
{
    public function index(): View
    {
        return view('promoteur.sites.index', [
            'sites' => Site::with(['postes' => fn ($q) => $q->orderByDesc('created_at')])
                ->withCount('utilisateurs')
                ->orderBy('type', 'desc')->orderBy('nom')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['code' => strtoupper(trim((string) $request->input('code')))]);

        $donnees = $request->validate([
            // RG-01 : le code du site prefixe chaque code de commande
            'code' => ['required', 'string', 'regex:/^[A-Z0-9]{2,10}$/', 'unique:sites,code'],
            'nom' => ['required', 'string', 'max:150'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'type' => ['required', Rule::in([Site::BOUTIQUE, Site::ATELIER])],
        ], [
            'code.regex' => 'Le code du site comporte de 2 à 10 lettres ou chiffres, sans espace.',
        ]);

        $site = Site::create($donnees);
        Audit::noter(Audit::SITE_CREE, $site, $site->code.' — '.$site->nom);

        return redirect()->route('sites.show', $site)
            ->with('succes', "Le site « {$site->nom} » est créé. Déclarez maintenant son poste.");
    }

    public function show(Site $site): View
    {
        return view('promoteur.sites.show', [
            'site' => $site,
            'postes' => $site->postes()->orderByDesc('created_at')->get(),
            'comptes' => $site->utilisateurs()->orderBy('nom')->get(),
            'commandesEnCours' => Commande::where('site_id', $site->id)->whereNotIn('statut', Commande::TERMINAUX)->count(),
        ]);
    }

    public function update(Request $request, Site $site): RedirectResponse
    {
        // Le code et le type ne changent plus : les codes de commande et les lots en dependent
        $donnees = $request->validate([
            'nom' => ['required', 'string', 'max:150'],
            'adresse' => ['nullable', 'string', 'max:255'],
            'actif' => ['required', 'boolean'],
        ]);

        $site->update($donnees);

        if ($site->wasChanged()) {
            Audit::noter(Audit::SITE_MODIFIE, $site, $site->code.' — '.$site->nom,
                Audit::changements($site, ['nom' => 'Nom', 'adresse' => 'Adresse', 'actif' => 'Actif']));
        }

        return redirect()->route('sites.show', $site)->with('succes', 'Le site est mis à jour.');
    }
}
