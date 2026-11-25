<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Lot;
use App\Models\Parametre;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Suivi des lots (RG-19) : lots en route, lots sans accuse de reception au-dela du delai
 * d'alerte, ecarts entre le nombre annonce et le nombre recu.
 */
class LotController extends Controller
{
    public const ETATS = [
        'en_route' => 'En route',
        'en_retard' => 'Sans accusé de réception au-delà du délai',
        'recu' => 'Reçus',
        'ecart' => 'Reçus avec un écart',
    ];

    public function index(Request $request): View
    {
        $filtres = $request->validate([
            'etat' => ['nullable', Rule::in(array_keys(self::ETATS))],
            'site' => ['nullable', 'uuid'],
        ]);
        $delai = $this->delaiAlerteHeures();

        $lots = Lot::with(['siteOrigine', 'siteDestination', 'cloturePar', 'recuPar'])
            ->when($filtres['site'] ?? null, fn (Builder $q, $site) => $q
                ->where(fn (Builder $q) => $q->where('site_origine_id', $site)->orWhere('site_destination_id', $site)))
            ->when($filtres['etat'] ?? null, fn (Builder $q, $etat) => match ($etat) {
                'en_route' => $q->whereNull('recu_le'),
                'en_retard' => $q->whereNull('recu_le')->where('cloture_le', '<', now()->subHours($delai)),
                'recu' => $q->whereNotNull('recu_le'),
                'ecart' => $q->whereNotNull('recu_le')->whereColumn('nombre_recu', '!=', 'nombre_annonce'),
            })
            ->orderByDesc('cloture_le')
            ->paginate(25)->withQueryString();

        return view('promoteur.lots.index', [
            'lots' => $lots,
            'sites' => Site::orderBy('nom')->get(),
            'filtres' => $filtres,
            'etats' => self::ETATS,
            'delai' => $delai,
            'limiteAlerte' => now()->subHours($delai),
        ]);
    }

    public function show(Lot $lot): View
    {
        $lot->load(['siteOrigine', 'siteDestination', 'cloturePar', 'recuPar']);

        return view('promoteur.lots.show', [
            'lot' => $lot,
            'commandes' => $lot->commandes()->with('client')->orderBy('commandes.code')->get(),
            'enRetard' => $lot->recu_le === null && $lot->cloture_le->lt(now()->subHours($this->delaiAlerteHeures())),
            'delai' => $this->delaiAlerteHeures(),
        ]);
    }

    private function delaiAlerteHeures(): int
    {
        $valeur = Parametre::whereKey(Parametre::DELAI_ALERTE_LOT_HEURES)->value('valeur');

        return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : 24;
    }
}
