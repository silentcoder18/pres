<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Paiement;
use App\Models\SessionCaisse;
use App\Models\Site;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Sessions de caisse des sites (RG-18) : ce que chaque receptionniste a ouvert, encaisse et compte. */
class CaisseController extends Controller
{
    public const ETATS = [
        'ouverte' => 'Ouvertes',
        'cloturee' => 'Clôturées',
        'ecart' => 'Clôturées avec un écart',
    ];

    public function index(Request $request): View
    {
        $filtres = $request->validate([
            'etat' => ['nullable', Rule::in(array_keys(self::ETATS))],
            'site' => ['nullable', 'uuid'],
        ]);

        $sessions = SessionCaisse::with(['site', 'ouvertePar', 'clotureePar', 'paiements'])
            ->when($filtres['site'] ?? null, fn (Builder $q, $site) => $q->where('site_id', $site))
            ->when($filtres['etat'] ?? null, fn (Builder $q, $etat) => match ($etat) {
                'ouverte' => $q->whereNull('cloturee_le'),
                'cloturee' => $q->whereNotNull('cloturee_le'),
                'ecart' => $q->whereNotNull('cloturee_le')->where('ecart', '!=', 0),
            })
            ->orderByDesc('ouverte_le')
            ->paginate(25)->withQueryString();

        return view('promoteur.caisses.index', [
            'sessions' => $sessions,
            'comptes' => $sessions->getCollection()->mapWithKeys(fn (SessionCaisse $s) => [$s->id => $this->comptes($s)]),
            'sites' => Site::orderBy('nom')->get(),
            'filtres' => $filtres,
            'etats' => self::ETATS,
        ]);
    }

    public function show(SessionCaisse $session): View
    {
        $session->load([
            'site', 'ouvertePar', 'clotureePar',
            'paiements' => fn ($q) => $q->orderBy('encaisse_le'),
            'paiements.commande.client', 'paiements.utilisateur',
        ]);

        return view('promoteur.caisses.show', ['session' => $session, 'comptes' => $this->comptes($session)]);
    }

    /**
     * Comptes de la session d'apres les encaissements recus (RG-18). Pour une session ouverte,
     * c'est la situation a la derniere synchronisation du poste.
     *
     * @return array{avances: float, soldes: float, restitutions: float, attendu: float, nombre: int}
     */
    private function comptes(SessionCaisse $session): array
    {
        $somme = fn (string $type) => (float) $session->paiements->where('type', $type)->sum('montant');
        $avances = $somme(Paiement::ACOMPTE);
        $soldes = $somme(Paiement::SOLDE);
        $restitutions = $somme(Paiement::REMBOURSEMENT);

        return [
            'avances' => $avances,
            'soldes' => $soldes,
            'restitutions' => $restitutions,
            'attendu' => (float) $session->fond_initial + $avances + $soldes - $restitutions,
            'nombre' => $session->paiements->count(),
        ];
    }
}
