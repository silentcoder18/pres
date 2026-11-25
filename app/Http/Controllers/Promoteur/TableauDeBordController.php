<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\ClientReuni;
use App\Models\Commande;
use App\Models\Controle;
use App\Models\DemandeAnnulation;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Paiement;
use App\Models\Parametre;
use App\Models\Poste;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\SyncOperation;
use App\Support\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\View\View;

/**
 * Tableau de bord du reseau : ou en sont les commandes, ce qui a ete encaisse, et ce qui
 * demande l'attention du Promoteur (§9.3). Tout est lu sur ce que les postes ont synchronise.
 */
class TableauDeBordController extends Controller
{
    /** Regroupement des statuts de RG-05 par lieu ou se trouve le linge. */
    private const ETAPES = [
        'a_envoyer' => ['ENREGISTREE'],
        'a_l_atelier' => ['ENVOYEE', 'RECEPTIONNEE', 'EN_ATTENTE_ACCORD', 'CONTROLEE', 'EN_TRAITEMENT', 'TRAITEE'],
        'a_retirer' => ['RETOURNEE', 'NON_RECLAMEE'],
    ];

    public function __invoke(): View
    {
        $maintenant = CarbonImmutable::now(Affichage::fuseau());
        $debutJour = $maintenant->startOfDay()->utc();
        $debutSemaine = $maintenant->startOfWeek(CarbonImmutable::MONDAY)->utc();

        $delaiLot = $this->parametre(Parametre::DELAI_ALERTE_LOT_HEURES, 24);
        $dureeHorsLigne = $this->parametre(Parametre::DUREE_MAX_HORS_LIGNE_HEURES, 48);

        $enCours = Commande::whereNotIn('statut', Commande::TERMINAUX);

        $chiffres = [
            'commandes_en_cours' => (clone $enCours)->count(),
            'reste_a_recouvrer' => (clone $enCours)->sum('reste'),
            'encaisse_jour' => $this->encaisse($debutJour),
            'encaisse_semaine' => $this->encaisse($debutSemaine),
            'lots_en_route' => Lot::whereNull('recu_le')->count(),
            'caisses_ouvertes' => SessionCaisse::whereNull('cloturee_le')->count(),
        ];

        return view('promoteur.tableau-de-bord', [
            'maintenant' => $maintenant,
            'debutSemaine' => $debutSemaine,
            'chiffres' => $chiffres,
            'alertes' => $this->alertes($delaiLot, $dureeHorsLigne),
            'sites' => $this->parSite($debutJour),
        ]);
    }

    /** Encaissements nets depuis une date : avances et soldes, moins les avances restituees. */
    private function encaisse(CarbonImmutable $depuis, ?string $siteId = null): float
    {
        $requete = fn () => Paiement::where('encaisse_le', '>=', $depuis)
            ->when($siteId !== null, fn ($q) => $q->whereHas('sessionCaisse', fn ($s) => $s->where('site_id', $siteId)));

        return (float) $requete()->where('type', '!=', Paiement::REMBOURSEMENT)->sum('montant')
            - (float) $requete()->where('type', Paiement::REMBOURSEMENT)->sum('montant');
    }

    /**
     * Ce qui attend une decision ou une verification. Une alerte sans objet n'est pas listee.
     *
     * @return list<array{niveau: string, texte: string, lien: string, action: string}>
     */
    private function alertes(int $delaiLot, int $dureeHorsLigne): array
    {
        $alertes = [];
        $ajouter = function (int $nombre, string $niveau, string $singulier, string $pluriel, string $lien, string $action) use (&$alertes) {
            if ($nombre > 0) {
                $alertes[] = [
                    'niveau' => $niveau,
                    'texte' => $nombre.' '.($nombre > 1 ? $pluriel : $singulier),
                    'lien' => $lien,
                    'action' => $action,
                ];
            }
        };

        // RG-19 : lot sans accuse de reception au-dela du delai fixe par le Promoteur
        $ajouter(
            Lot::whereNull('recu_le')->where('cloture_le', '<', now()->subHours($delaiLot))->count(),
            'grave',
            "lot sans accusé de réception depuis plus de {$delaiLot} heures",
            "lots sans accusé de réception depuis plus de {$delaiLot} heures",
            route('lots.index', ['etat' => 'en_retard']),
            'Voir les lots',
        );
        $ajouter(
            Lot::whereNotNull('recu_le')->whereColumn('nombre_recu', '!=', 'nombre_annonce')->count(),
            'grave',
            'lot reçu avec un écart de comptage',
            'lots reçus avec un écart de comptage',
            route('lots.index', ['etat' => 'ecart']),
            'Voir les écarts',
        );
        // RG-24 : une annulation n'est effective qu'apres la confirmation du Promoteur
        $ajouter(
            DemandeAnnulation::where('etat', DemandeAnnulation::DEMANDEE)->count(),
            'attention',
            "demande d'annulation à trancher",
            "demandes d'annulation à trancher",
            route('annulations.index'),
            'Trancher',
        );
        // §4.4 : meme numero, noms differents
        $ajouter(
            ClientReuni::where('a_trancher', true)->count(),
            'attention',
            'fiche client en double à trancher',
            'fiches client en double à trancher',
            route('clients.doublons'),
            'Trancher',
        );
        // RG-09 : commande bloquee a l'atelier tant que le client n'a pas accepte la correction
        $ajouter(
            Controle::correctionsEnAttente()->count(),
            'attention',
            "commande en attente de l'accord du client",
            "commandes en attente de l'accord du client",
            route('accords.index'),
            'Voir les commandes',
        );
        // Client qui a verse plus que sa facture, corrigee depuis : la boutique doit le lui rendre
        $ajouter(
            Commande::where('reste', '<', 0)->count(),
            'attention',
            'commande avec un trop-perçu à rendre au client',
            'commandes avec un trop-perçu à rendre au client',
            route('commandes.index', ['trop_percu' => 1]),
            'Voir les commandes',
        );
        // RG-25 : un poste silencieux continue de travailler (decision du 16 octobre 2026), mais ses
        // donnees ne sont plus fraiches et ce qu'il a fait n'est pas encore ici
        $ajouter(
            Poste::whereNotNull('jeton_hash')->whereNull('revoque_le')
                ->where('dernier_contact_le', '<', now()->subHours($dureeHorsLigne))->count(),
            'grave',
            "poste sans contact depuis plus de {$dureeHorsLigne} heures : il travaille sur des données qui ne sont plus à jour",
            "postes sans contact depuis plus de {$dureeHorsLigne} heures : ils travaillent sur des données qui ne sont plus à jour",
            route('sites.index'),
            'Voir les postes',
        );
        $ajouter(
            SyncOperation::where('resultat', SyncOperation::REJETEE)->where('recu_le', '>=', now()->subDays(7))->count(),
            'attention',
            'écriture refusée par le serveur ces 7 derniers jours',
            'écritures refusées par le serveur ces 7 derniers jours',
            route('ecritures.index'),
            'Voir les écritures',
        );
        $ajouter(
            SessionCaisse::whereNotNull('cloturee_le')->where('ecart', '!=', 0)
                ->where('cloturee_le', '>=', now()->subDays(7))->count(),
            'attention',
            'caisse clôturée avec un écart ces 7 derniers jours',
            'caisses clôturées avec un écart ces 7 derniers jours',
            route('caisses.index', ['etat' => 'ecart']),
            'Voir les caisses',
        );
        $ajouter(
            Message::where('etat', Message::ECHOUE)->count(),
            'attention',
            "message WhatsApp en échec d'envoi",
            "messages WhatsApp en échec d'envoi",
            route('messages.index', ['etat' => Message::ECHOUE]),
            'Voir les messages',
        );

        // Ce qui presse d'abord ; a niveau egal, l'ordre ci-dessus est garde
        return collect($alertes)->sortBy(fn (array $alerte) => $alerte['niveau'] === 'grave' ? 0 : 1)->values()->all();
    }

    /** Une ligne par site actif : ses commandes par etape, sa recette du jour, sa caisse, son poste. */
    private function parSite(CarbonImmutable $debutJour): array
    {
        $comptes = Commande::selectRaw('site_id, statut, count(*) as nombre')
            ->whereNotIn('statut', Commande::TERMINAUX)
            ->groupBy('site_id', 'statut')
            ->get()
            ->groupBy('site_id');

        $caisses = SessionCaisse::whereNull('cloturee_le')->get()->keyBy('site_id');
        $postes = Poste::whereNull('revoque_le')->orderByDesc('enrole_le')->get()->groupBy('site_id');

        $lignes = [];
        foreach (Site::where('actif', true)->orderBy('type', 'desc')->orderBy('nom')->get() as $site) {
            $parStatut = ($comptes[$site->id] ?? collect())->pluck('nombre', 'statut');
            $etapes = [];
            foreach (self::ETAPES as $etape => $statuts) {
                $etapes[$etape] = (int) $parStatut->only($statuts)->sum();
            }

            $lignes[] = [
                'site' => $site,
                'etapes' => $etapes,
                'en_cours' => (int) $parStatut->sum(),
                'encaisse_jour' => $site->estAtelier() ? null : $this->encaisse($debutJour, $site->id),
                'caisse' => $caisses[$site->id] ?? null,
                'poste' => ($postes[$site->id] ?? collect())->first(),
            ];
        }

        return $lignes;
    }

    private function parametre(string $cle, int $defaut): int
    {
        $valeur = Parametre::whereKey($cle)->value('valeur');

        return is_numeric($valeur) && (int) $valeur > 0 ? (int) $valeur : $defaut;
    }
}
