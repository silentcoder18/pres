<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\AvanceCommission;
use App\Models\Tache;
use App\Models\Travailleur;
use App\Services\Finance\SemaineComptable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Travailleurs enroles a l'atelier (§9.3). Leurs donnees d'identite sont saisies sur le poste de
 * l'atelier et ne se consultent qu'ici (§11). Le paiement des commissions viendra avec la finance
 * hebdomadaire ; cette page montre ce que chacun a gagne dans la semaine en cours (RG-12).
 */
class TravailleurController extends Controller
{
    public function index(Request $request): View
    {
        $filtres = $request->validate(['recherche' => ['nullable', 'string', 'max:100']]);
        $semaine = SemaineComptable::courante();

        $travailleurs = Travailleur::with(['site', 'creePar'])
            ->when($filtres['recherche'] ?? null, function (Builder $q, $recherche) {
                $motif = '%'.addcslashes(trim($recherche), '%_\\').'%';
                $q->where(fn (Builder $q) => $q
                    ->where('nom', 'like', $motif)
                    ->orWhere('matricule', 'like', $motif)
                    ->orWhere('telephone', 'like', $motif));
            })
            ->orderBy('nom')
            ->paginate(25)->withQueryString();

        // RG-12 : une commission appartient a la semaine ou la tache a ete terminee
        $terminees = Tache::whereIn('travailleur_id', $travailleurs->pluck('id'))
            ->where('terminee_le', '>=', $semaine->debutUtc())
            ->where('terminee_le', '<', $semaine->finUtc())
            ->get()
            ->groupBy('travailleur_id');

        // avances sur commission remises dans la semaine (decision du 23 octobre 2026)
        $avances = AvanceCommission::whereIn('travailleur_id', $travailleurs->pluck('id'))
            ->where('versee_le', '>=', $semaine->debutUtc())
            ->where('versee_le', '<', $semaine->finUtc())
            ->get()
            ->groupBy('travailleur_id')
            ->map(fn ($lignes) => $lignes->reduce(fn (string $total, AvanceCommission $a) => bcadd($total, (string) $a->montant, 2), '0'));

        return view('promoteur.travailleurs.index', [
            'travailleurs' => $travailleurs,
            'filtres' => $filtres,
            'semaine' => $semaine,
            'articles' => $terminees->map(fn ($taches) => (int) $taches->sum('quantite')),
            'commissions' => $terminees->map(fn ($taches) => $taches->reduce(
                fn (string $total, Tache $tache) => bcadd($total, bcmul((string) $tache->commission_unitaire, (string) $tache->quantite, 2), 2),
                '0',
            )),
            'avances' => $avances,
        ]);
    }
}
