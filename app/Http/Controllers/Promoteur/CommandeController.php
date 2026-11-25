<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\Site;
use App\Support\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Consultation des commandes du reseau. Le Promoteur les lit ; il ne les modifie pas ici. */
class CommandeController extends Controller
{
    public function index(Request $request): View
    {
        $filtres = $request->validate([
            'recherche' => ['nullable', 'string', 'max:100'],
            'site' => ['nullable', 'uuid'],
            'statut' => ['nullable', Rule::in(array_keys(Affichage::statuts()))],
            'du' => ['nullable', 'date_format:Y-m-d'],
            'au' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:du'],
            // commandes dont le client a verse plus que la facture : un trop-percu a lui rendre
            'trop_percu' => ['nullable', 'boolean'],
        ]);

        $requete = Commande::with(['site', 'client'])
            ->when($filtres['site'] ?? null, fn (Builder $q, $site) => $q->where('site_id', $site))
            ->when($filtres['statut'] ?? null, fn (Builder $q, $statut) => $q->where('statut', $statut))
            ->when($filtres['du'] ?? null, fn (Builder $q, $du) => $q->where('deposee_le', '>=', $this->debut($du)))
            ->when($filtres['au'] ?? null, fn (Builder $q, $au) => $q->where('deposee_le', '<', $this->debut($au)->addDay()))
            ->when($filtres['trop_percu'] ?? false, fn (Builder $q) => $q->where('reste', '<', 0))
            ->when($filtres['recherche'] ?? null, function (Builder $q, $recherche) {
                $motif = '%'.addcslashes(trim($recherche), '%_\\').'%';
                $q->where(fn (Builder $q) => $q
                    ->where('code', 'like', $motif)
                    ->orWhereHas('client', fn (Builder $c) => $c->where('nom', 'like', $motif)->orWhere('telephone', 'like', $motif)));
            });

        return view('promoteur.commandes.index', [
            'totaux' => [
                'nombre' => (clone $requete)->count(),
                'montant' => (clone $requete)->sum('montant'),
                'paye' => (clone $requete)->sum('total_paye'),
                'reste' => (clone $requete)->sum('reste'),
            ],
            'commandes' => $requete->orderByDesc('deposee_le')->orderByDesc('id')->paginate(25)->withQueryString(),
            'sites' => Site::orderBy('nom')->get(),
            'filtres' => $filtres,
        ]);
    }

    public function show(Commande $commande): View
    {
        $commande->load([
            'site', 'client', 'utilisateur',
            'lignes.tarif.article', 'lignes.tarif.service',
            'paiements' => fn ($q) => $q->orderBy('encaisse_le'),
            'paiements.utilisateur',
            'lots' => fn ($q) => $q->orderBy('cloture_le'),
            'lots.siteOrigine', 'lots.siteDestination',
            'messages' => fn ($q) => $q->orderBy('cree_le'),
            'controles.utilisateur', 'controles.decidePar', 'controles.tarif.article', 'controles.tarif.service',
            'taches.travailleur', 'taches.ligne.tarif.article', 'taches.ligne.tarif.service',
            'demandesAnnulation.demandeePar', 'demandesAnnulation.decideePar',
            'livreePar',
        ]);

        return view('promoteur.commandes.show', ['commande' => $commande]);
    }

    /** Minuit du jour choisi dans le fuseau du reseau, exprime en UTC comme les dates enregistrees. */
    private function debut(string $jour): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $jour, Affichage::fuseau())->startOfDay()->utc();
    }
}
