<?php

namespace App\Services\Finance;

use App\Models\AvanceCommission;
use App\Models\Commande;
use App\Models\Consommable;
use App\Models\LigneCommande;
use App\Models\Lot;
use App\Models\Paiement;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\Tache;
use App\Models\VersementJournalier;
use App\Support\Affichage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Situation financiere d'une semaine, calculee par le serveur a partir des faits que les postes
 * ont synchronises : commandes deposees, encaissements, sessions de caisse, taches terminees et
 * sorties de consommables (§7).
 *
 * Ce qui est connu est exact : les charges de l'atelier (commissions dues, consommables) sont
 * celles de la semaine. Ce qui releve de la finance (commissions payees, emprunts, cloture :
 * etape 5) n'est pas encore tenu ici : le solde reste donne a titre previsionnel.
 *
 * Sert a la page « Finances » et au rapport PDF : aucun calcul n'est fait dans les vues. Les
 * sommes sont faites sur des chaines, a deux decimales, comme Commande::recalculer().
 */
class RapportHebdomadaire
{
    /** Grandeurs en argent d'une ligne (un site, ou le reseau) : chaines a deux decimales. */
    public const MONTANTS = [
        'montant_facture', 'commissions_prevues',
        'avances', 'soldes', 'restitutions', 'net_encaisse',
        'recette', 'recouvrements_tardifs',
        'reste_semaine', 'reste_total', 'solde_previsionnel',
        'manques', 'excedents',
        'commissions_dues', 'consommables', 'achats', 'charges',
        'avances_commission',
    ];

    /** Grandeurs comptees d'une ligne : entiers. */
    public const COMPTEURS = ['commandes_nombre', 'caisses_nombre', 'caisses_cloturees', 'caisses_ouvertes'];

    /** Grandeurs en argent d'une journee : chaines a deux decimales. */
    public const MONTANTS_DU_JOUR = ['montant_facture', 'avances', 'soldes', 'restitutions', 'net_encaisse'];

    /**
     * Rapport d'une semaine, pour tout le reseau ou pour un seul site.
     *
     * Pour un site X et une semaine S (lundi 00:00 au lundi suivant 00:00, fuseau du reseau) :
     *  - commandes_nombre, montant_facture : commandes de X deposees dans S, hors annulees ;
     *  - commissions_prevues : quantite x commission de leurs lignes (RG-03) ; c'est une prevision
     *    faite au depot, les commissions dues (RG-12) etant donnees a part, voir plus bas ;
     *  - avances, soldes : paiements encaisses dans S sur une caisse de X, par nature ;
     *  - restitutions : parmi eux, ceux dont la commande, annulee, a depuis ete remboursee au client.
     *    Une restitution apres annulation rend toujours tout ce qui a ete verse : chaque versement est
     *    ainsi repris sur sa propre semaine, quelle que soit la date ou l'argent est sorti de la caisse
     *    (RG-24). S'y ajoutent les trop-percus rendus dans S sur une caisse de X (client qui avait
     *    verse plus que la facture corrigee) : eux sortent de la semaine ou ils sont rendus ;
     *    net_encaisse = avances + soldes - restitutions ;
     *  - recette : avances et soldes, hors restitutions, dont la commande a ete deposee la meme
     *    semaine, moins les trop-percus rendus dans S ; recouvrements_tardifs : ceux dont la commande
     *    est d'une semaine anterieure (RG-14) ; toujours recette + recouvrements_tardifs = net_encaisse ;
     *  - reste_semaine : reste a payer des commandes deposees dans S ; reste_total : reste de
     *    toutes les commandes de X ni livrees, ni donnees, ni annulees, a la date du calcul. Un reste
     *    negatif (trop-percu a rendre) n'y est pas compte : il n'est pas a recouvrer ;
     *  - solde_previsionnel = recette - commissions_prevues (RG-11, sans les consommables) ;
     *  - caisses_* : sessions de X ouvertes dans S ; manques et excedents : ecarts negatifs (en
     *    valeur absolue) et positifs des sessions cloturees (RG-18) ;
     *  - commissions_dues : pour chaque tache terminee dans S par un travailleur de X, sa quantite
     *    x la commission copiee de la ligne a l'attribution (RG-03, RG-12) ; consommables : achats
     *    de consommables de X dans S ; achats : ses autres achats (materiel, entretien, energie,
     *    transport, autre ; decision du 23 octobre 2026) ; charges = commissions_dues + consommables
     *    + achats (RG-11). Seul l'atelier en porte ;
     *  - avances_commission : avances remises dans S aux travailleurs de X. Elles se deduisent de ce
     *    qui leur reste a payer ; ce ne sont pas des charges de plus.
     *
     * Les jours et les caisses, eux, sont des comptes de journee (RG-18) : une avance restituee y est
     * deduite le jour ou elle sort de la caisse. La somme des jours (total_jours) peut donc differer
     * des chiffres de la semaine, pour les restitutions et le net encaisse seulement.
     *
     * @return array{
     *     semaine: SemaineComptable,
     *     perimetre: ?Site,
     *     calcule_le: CarbonImmutable,
     *     sites: list<array<string, mixed>>,
     *     total: array<string, int|string>,
     *     jours: list<array<string, mixed>>,
     *     total_jours: array<string, int|string>,
     *     caisses: list<array{session: SessionCaisse, encaisse: string, attendu: string}>,
     *     recouvrements: list<array<string, mixed>>,
     * }
     */
    public function calculer(SemaineComptable $semaine, ?Site $site = null): array
    {
        $sites = $site !== null ? new Collection([$site]) : Site::orderBy('type', 'desc')->orderBy('nom')->get();

        $lignes = [];
        foreach ($sites as $unSite) {
            $lignes[$unSite->id] = self::chiffresVides();
        }
        $jours = [];
        foreach ($semaine->jours() as $jour) {
            $jours[$jour->format('Y-m-d')] = ['date' => $jour] + self::jourVide();
        }

        $this->compterCommandes($semaine, $site, $lignes, $jours);
        $tardifs = $this->compterEncaissements($semaine, $site, $lignes, $jours);
        $this->compterResteTotal($site, $lignes);
        $caisses = $this->compterCaisses($semaine, $site, $lignes);
        $this->compterChargesDeLAtelier($semaine, $site, $lignes);

        $total = self::chiffresVides();
        foreach ($lignes as $siteId => $ligne) {
            $ligne['net_encaisse'] = bcsub(bcadd($ligne['avances'], $ligne['soldes'], 2), $ligne['restitutions'], 2);
            $ligne['charges'] = bcadd(bcadd($ligne['commissions_dues'], $ligne['consommables'], 2), $ligne['achats'], 2);
            // RG-11 : la recette de la semaine doit couvrir ses charges ; seules les commissions
            // prevues sont connues ici
            $ligne['solde_previsionnel'] = bcsub($ligne['recette'], $ligne['commissions_prevues'], 2);
            $lignes[$siteId] = $ligne;

            // Reseau entier = somme des sites
            foreach (self::MONTANTS as $cle) {
                $total[$cle] = bcadd($total[$cle], $ligne[$cle], 2);
            }
            foreach (self::COMPTEURS as $cle) {
                $total[$cle] += $ligne[$cle];
            }
        }
        $totalJours = self::jourVide();
        foreach ($jours as $cle => $jour) {
            $jour['net_encaisse'] = bcsub(bcadd($jour['avances'], $jour['soldes'], 2), $jour['restitutions'], 2);
            $jours[$cle] = $jour;

            $totalJours['commandes_nombre'] += $jour['commandes_nombre'];
            foreach (self::MONTANTS_DU_JOUR as $montant) {
                $totalJours[$montant] = bcadd($totalJours[$montant], $jour[$montant], 2);
            }
        }

        $parSite = [];
        foreach ($sites as $unSite) {
            // Un site desactive ne garde sa ligne que s'il lui reste quelque chose a montrer
            if ($site !== null || $unSite->actif || self::aDesChiffres($lignes[$unSite->id])) {
                $parSite[] = ['site' => $unSite] + $lignes[$unSite->id];
            }
        }

        return [
            'semaine' => $semaine,
            'perimetre' => $site,
            'calcule_le' => CarbonImmutable::now(Affichage::fuseau()),
            'sites' => $parSite,
            'total' => $total,
            'jours' => array_values($jours),
            'total_jours' => $totalJours,
            'caisses' => $caisses,
            'recouvrements' => $this->detaillerRecouvrements($tardifs, $sites),
            'sections_consolidees' => $this->calculerSectionsConsolidees($semaine, $site, $total, $lignes),
        ];
    }

    /**
     * Les chiffres d'un seul site pour une semaine : MONTANTS en chaines a deux decimales,
     * COMPTEURS en entiers. C'est ce qui se compare avec les comptes du poste.
     *
     * @return array<string, int|string>
     */
    public function chiffresDuSite(SemaineComptable $semaine, Site $site): array
    {
        return $this->calculer($semaine, $site)['total'];
    }

    /** Commandes deposees dans la semaine, hors annulees : nombre, facture, reste et commissions prevues. */
    private function compterCommandes(SemaineComptable $semaine, ?Site $site, array &$lignes, array &$jours): void
    {
        $deposees = fn (Builder $requete) => $requete
            ->where('commandes.deposee_le', '>=', $semaine->debutUtc())
            ->where('commandes.deposee_le', '<', $semaine->finUtc())
            ->where('commandes.statut', '!=', Commande::ANNULEE)
            ->when($site !== null, fn (Builder $q) => $q->where('commandes.site_id', $site->id));

        $commandes = Commande::query()->tap($deposees)
            ->get(['commandes.id', 'commandes.site_id', 'commandes.deposee_le', 'commandes.montant', 'commandes.reste']);
        foreach ($commandes as $commande) {
            if (! isset($lignes[$commande->site_id])) {
                continue;
            }
            $ligne = &$lignes[$commande->site_id];
            $ligne['commandes_nombre']++;
            $ligne['montant_facture'] = bcadd($ligne['montant_facture'], (string) $commande->montant, 2);
            $ligne['reste_semaine'] = bcadd($ligne['reste_semaine'], self::aRecouvrer((string) $commande->reste), 2);
            unset($ligne);

            $jour = SemaineComptable::jourDe($commande->deposee_le);
            if (isset($jours[$jour])) {
                $jours[$jour]['commandes_nombre']++;
                $jours[$jour]['montant_facture'] = bcadd($jours[$jour]['montant_facture'], (string) $commande->montant, 2);
            }
        }

        // RG-03 : la commission est celle figee sur la ligne au depot. Elle n'est due qu'une fois
        // la tache terminee (RG-12) : ce chiffre-ci reste une prevision, comptee des le depot.
        $lignesCommande = LigneCommande::query()
            ->join('commandes', 'commandes.id', '=', 'lignes_commande.commande_id')
            ->tap($deposees)
            ->get(['lignes_commande.id', 'lignes_commande.quantite', 'lignes_commande.commission_appliquee', 'commandes.site_id']);
        foreach ($lignesCommande as $ligneCommande) {
            if (isset($lignes[$ligneCommande->site_id])) {
                $lignes[$ligneCommande->site_id]['commissions_prevues'] = bcadd(
                    $lignes[$ligneCommande->site_id]['commissions_prevues'],
                    bcmul((string) $ligneCommande->commission_appliquee, (string) $ligneCommande->quantite, 2),
                    2,
                );
            }
        }
    }

    /**
     * Encaissements de la semaine, rattaches au site de leur session de caisse (RG-17). Chaque
     * avance ou solde est de la recette, un recouvrement tardif (RG-14), ou un versement rendu
     * depuis au client (RG-24). Les jours, eux, suivent la caisse : voir calculer().
     *
     * @return list<Paiement> les recouvrements tardifs, dans l'ordre des encaissements
     */
    private function compterEncaissements(SemaineComptable $semaine, ?Site $site, array &$lignes, array &$jours): array
    {
        // Avances restituees sur la commande du paiement, a quelque date que ce soit
        $rendus = Paiement::from('paiements as rendus')
            ->selectRaw('count(*)')
            ->whereColumn('rendus.commande_id', 'paiements.commande_id')
            ->where('rendus.type', Paiement::REMBOURSEMENT);

        $paiements = Paiement::query()
            ->join('sessions_caisse', 'sessions_caisse.id', '=', 'paiements.session_caisse_id')
            ->join('commandes', 'commandes.id', '=', 'paiements.commande_id')
            ->where('paiements.encaisse_le', '>=', $semaine->debutUtc())
            ->where('paiements.encaisse_le', '<', $semaine->finUtc())
            ->when($site !== null, fn (Builder $q) => $q->where('sessions_caisse.site_id', $site->id))
            ->orderBy('paiements.encaisse_le')->orderBy('paiements.id')
            ->withCasts(['commande_deposee_le' => 'datetime'])
            ->select([
                'paiements.*', 'sessions_caisse.site_id as site_caisse_id',
                'commandes.deposee_le as commande_deposee_le', 'commandes.statut as commande_statut',
            ])
            ->selectSub($rendus, 'restitutions_de_la_commande')
            ->get();

        $tardifs = [];
        foreach ($paiements as $paiement) {
            $nature = match ($paiement->type) {
                Paiement::ACOMPTE => 'avances',
                Paiement::SOLDE => 'soldes',
                Paiement::REMBOURSEMENT => 'restitutions',
                default => null,
            };
            if ($nature === null || ! isset($lignes[$paiement->site_caisse_id])) {
                continue;
            }
            $montant = (string) $paiement->montant;

            // Compte de journee : chaque mouvement le jour ou il passe par la caisse (RG-18)
            $jour = SemaineComptable::jourDe($paiement->encaisse_le);
            if (isset($jours[$jour])) {
                $jours[$jour][$nature] = bcadd($jours[$jour][$nature], $montant, 2);
            }
            $annulee = $paiement->commande_statut === Commande::ANNULEE;
            if ($nature === 'restitutions') {
                if (! $annulee) {
                    // Trop-percu rendu au client : il sort de la recette de la semaine ou il est rendu
                    $ligne = &$lignes[$paiement->site_caisse_id];
                    $ligne['restitutions'] = bcadd($ligne['restitutions'], $montant, 2);
                    $ligne['recette'] = bcsub($ligne['recette'], $montant, 2);
                    unset($ligne);
                }

                // Apres une annulation, la sortie de caisse ne compte pas dans la semaine : ce sont
                // les versements rendus qui sont repris, chacun sur sa propre semaine
                continue;
            }

            $ligne = &$lignes[$paiement->site_caisse_id];
            $ligne[$nature] = bcadd($ligne[$nature], $montant, 2);
            if ($annulee && (int) $paiement->restitutions_de_la_commande > 0) {
                // RG-24 : rendu depuis au client, il n'est ni une recette ni un recouvrement tardif
                $ligne['restitutions'] = bcadd($ligne['restitutions'], $montant, 2);
            } elseif (AffectationPaiements::estTardif($paiement->encaisse_le, $paiement->commande_deposee_le)) {
                // Meme regle que l'affectation posee a la reception du paiement
                $ligne['recouvrements_tardifs'] = bcadd($ligne['recouvrements_tardifs'], $montant, 2);
                $tardifs[] = $paiement;
            } else {
                $ligne['recette'] = bcadd($ligne['recette'], $montant, 2);
            }
            unset($ligne);
        }

        return $tardifs;
    }

    /** Reste a recouvrer sur toutes les commandes encore en cours, a la date du calcul. */
    private function compterResteTotal(?Site $site, array &$lignes): void
    {
        $enCours = Commande::query()
            ->whereNotIn('statut', Commande::TERMINAUX)
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->toBase()
            ->select(['site_id', 'reste'])
            ->cursor();

        // Lues une a une : leur nombre ne depend pas de la semaine, il grandit avec le reseau
        foreach ($enCours as $commande) {
            if (isset($lignes[$commande->site_id])) {
                $lignes[$commande->site_id]['reste_total'] = bcadd(
                    $lignes[$commande->site_id]['reste_total'], self::aRecouvrer(self::decimal($commande->reste)), 2,
                );
            }
        }
    }

    /**
     * Sessions de caisse ouvertes dans la semaine (RG-18), avec ce que chacune a encaisse.
     *
     * @return list<array{session: SessionCaisse, encaisse: string, attendu: string}>
     */
    private function compterCaisses(SemaineComptable $semaine, ?Site $site, array &$lignes): array
    {
        $dansLaSemaine = fn (Builder $requete) => $requete
            ->where('sessions_caisse.ouverte_le', '>=', $semaine->debutUtc())
            ->where('sessions_caisse.ouverte_le', '<', $semaine->finUtc())
            ->when($site !== null, fn (Builder $q) => $q->where('sessions_caisse.site_id', $site->id));

        $sessions = SessionCaisse::with(['site', 'ouvertePar', 'clotureePar'])->tap($dansLaSemaine)
            ->orderBy('sessions_caisse.ouverte_le')->orderBy('sessions_caisse.id')
            ->get();

        // Tout ce qu'une session a encaisse, meme si l'encaissement deborde sur le lundi suivant
        $encaisse = [];
        $paiements = Paiement::query()
            ->join('sessions_caisse', 'sessions_caisse.id', '=', 'paiements.session_caisse_id')
            ->tap($dansLaSemaine)
            ->get(['paiements.id', 'paiements.session_caisse_id', 'paiements.type', 'paiements.montant']);
        foreach ($paiements as $paiement) {
            $deja = $encaisse[$paiement->session_caisse_id] ?? '0.00';
            $encaisse[$paiement->session_caisse_id] = $paiement->type === Paiement::REMBOURSEMENT
                ? bcsub($deja, (string) $paiement->montant, 2)
                : bcadd($deja, (string) $paiement->montant, 2);
        }

        $caisses = [];
        foreach ($sessions as $session) {
            $net = $encaisse[$session->id] ?? '0.00';
            $caisses[] = [
                'session' => $session,
                'encaisse' => $net,
                // Session ouverte : situation a la derniere synchronisation du poste
                'attendu' => $session->estOuverte() || $session->total_attendu === null
                    ? bcadd((string) $session->fond_initial, $net, 2)
                    : (string) $session->total_attendu,
            ];

            if (! isset($lignes[$session->site_id])) {
                continue;
            }
            $ligne = &$lignes[$session->site_id];
            $ligne['caisses_nombre']++;
            $ligne[$session->estOuverte() ? 'caisses_ouvertes' : 'caisses_cloturees']++;
            if (! $session->estOuverte() && $session->ecart !== null) {
                $ecart = (string) $session->ecart;
                if (bccomp($ecart, '0', 2) < 0) {
                    $ligne['manques'] = bcsub($ligne['manques'], $ecart, 2);
                } elseif (bccomp($ecart, '0', 2) > 0) {
                    $ligne['excedents'] = bcadd($ligne['excedents'], $ecart, 2);
                }
            }
            unset($ligne);
        }

        return $caisses;
    }

    /**
     * Charges de la semaine (RG-11), rattachees au site qui les porte : commissions des taches
     * terminees dans la semaine (RG-12) et sorties de consommables (§7).
     */
    private function compterChargesDeLAtelier(SemaineComptable $semaine, ?Site $site, array &$lignes): void
    {
        $terminees = Tache::query()
            ->join('travailleurs', 'travailleurs.id', '=', 'taches.travailleur_id')
            ->where('taches.terminee_le', '>=', $semaine->debutUtc())
            ->where('taches.terminee_le', '<', $semaine->finUtc())
            ->when($site !== null, fn (Builder $q) => $q->where('travailleurs.site_id', $site->id))
            ->get(['taches.id', 'taches.quantite', 'taches.commission_unitaire', 'travailleurs.site_id as site_atelier_id']);
        foreach ($terminees as $tache) {
            if (isset($lignes[$tache->site_atelier_id])) {
                $lignes[$tache->site_atelier_id]['commissions_dues'] = bcadd(
                    $lignes[$tache->site_atelier_id]['commissions_dues'],
                    bcmul((string) $tache->commission_unitaire, (string) $tache->quantite, 2),
                    2,
                );
            }
        }

        $sorties = Consommable::query()
            ->where('sortie_le', '>=', $semaine->debutUtc())
            ->where('sortie_le', '<', $semaine->finUtc())
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->get(['id', 'site_id', 'categorie', 'montant']);
        foreach ($sorties as $sortie) {
            if (isset($lignes[$sortie->site_id])) {
                // les consommables d'un cote, les autres achats de l'autre (23 octobre 2026)
                $cle = ($sortie->categorie ?? Consommable::CONSOMMABLES) === Consommable::CONSOMMABLES ? 'consommables' : 'achats';
                $lignes[$sortie->site_id][$cle] = bcadd($lignes[$sortie->site_id][$cle], (string) $sortie->montant, 2);
            }
        }

        $avances = AvanceCommission::query()
            ->where('versee_le', '>=', $semaine->debutUtc())
            ->where('versee_le', '<', $semaine->finUtc())
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->get(['site_id', 'montant']);
        foreach ($avances as $avance) {
            if (isset($lignes[$avance->site_id])) {
                $lignes[$avance->site_id]['avances_commission'] = bcadd(
                    $lignes[$avance->site_id]['avances_commission'], (string) $avance->montant, 2,
                );
            }
        }
    }

    /**
     * Liste des recouvrements tardifs : qui a paye quoi, sur quelle commande, de quelle semaine.
     *
     * @param  list<Paiement>  $tardifs
     * @param  Collection<int, Site>  $sites
     * @return list<array<string, mixed>>
     */
    private function detaillerRecouvrements(array $tardifs, Collection $sites): array
    {
        $paiements = (new Collection($tardifs))->load('commande.client');
        $sites = $sites->keyBy('id');

        $liste = [];
        foreach ($paiements as $paiement) {
            $liste[] = [
                'encaisse_le' => $paiement->encaisse_le,
                'site' => $sites[$paiement->site_caisse_id] ?? null,
                'commande' => $paiement->commande,
                'client' => $paiement->commande?->client?->nom,
                'type' => $paiement->type,
                'montant' => (string) $paiement->montant,
                'semaine_origine' => SemaineComptable::contenant($paiement->commande_deposee_le),
                'affectation' => $paiement->affectation,
            ];
        }

        return $liste;
    }

    /** @return array<string, int|string> */
    private static function chiffresVides(): array
    {
        return array_fill_keys(self::COMPTEURS, 0) + array_fill_keys(self::MONTANTS, '0.00');
    }

    /** @return array<string, int|string> */
    private static function jourVide(): array
    {
        return [
            'commandes_nombre' => 0,
            'montant_facture' => '0.00',
            'avances' => '0.00',
            'soldes' => '0.00',
            'restitutions' => '0.00',
            'net_encaisse' => '0.00',
        ];
    }

    private static function aDesChiffres(array $ligne): bool
    {
        foreach (self::COMPTEURS as $cle) {
            if ($ligne[$cle] !== 0) {
                return true;
            }
        }
        foreach (self::MONTANTS as $cle) {
            if (bccomp($ligne[$cle], '0', 2) !== 0) {
                return true;
            }
        }

        return false;
    }

    /** Ce qu'un reste a payer laisse a recouvrer : rien quand il est negatif (trop-percu a rendre). */
    private static function aRecouvrer(string $reste): string
    {
        return bccomp($reste, '0', 2) > 0 ? $reste : '0';
    }

    /** Un montant lu sans passer par un modele : chaine sous MySQL, nombre sous SQLite. */
    private static function decimal(mixed $valeur): string
    {
        return is_string($valeur) ? bcadd($valeur, '0', 2) : number_format((float) $valeur, 2, '.', '');
    }

    /**
     * Les quatre sections d'exploitation du rapport (amelioration du 23 octobre 2026) :
     * 1. activite : commandes deposees, pieces traitees (une piece compte une fois, a la fin de sa
     *    derniere etape), commandes remises au client, commandes en penderie depuis plus de 14 jours ;
     * 2. encaissements de la semaine par sorte de site, creances a la date du calcul ;
     * 3. commissions dues par role de travailleur, consommables, charges ;
     * 4. encaisse net moins charges, et versements journaliers des boutiques : declares, recus, ecart.
     *
     * La penderie se compte a la fin de la semaine (ou maintenant, pour la semaine en cours), d'apres
     * la date de reception du lot retour en boutique et la date de remise : le chiffre d'une semaine
     * passee ne change plus.
     */
    private function calculerSectionsConsolidees(SemaineComptable $semaine, ?Site $site, array $total, array $lignes): array
    {
        $debut = $semaine->debutUtc();
        $fin = $semaine->finUtc();
        $maintenant = CarbonImmutable::now('UTC');
        $reference = $fin->lessThan($maintenant) ? $fin : $maintenant;

        // une piece est traitee quand sa derniere etape est terminee : le repassage, ou le lavage
        // d'un article qui n'est pas repasse
        $piecesTraitees = (int) Tache::query()
            ->join('travailleurs', 'travailleurs.id', '=', 'taches.travailleur_id')
            ->join('lignes_commande', 'lignes_commande.id', '=', 'taches.ligne_commande_id')
            ->where('taches.terminee_le', '>=', $debut)
            ->where('taches.terminee_le', '<', $fin)
            ->where(fn (Builder $q) => $q
                ->where('taches.etape', Tache::REPASSAGE)
                ->orWhere(fn (Builder $q) => $q
                    ->where('taches.etape', Tache::LAVAGE)
                    ->where('lignes_commande.avec_repassage', false)))
            ->when($site !== null, fn (Builder $q) => $q->where('travailleurs.site_id', $site->id))
            ->sum('taches.quantite');

        $commandesRetirees = Commande::query()
            ->where('statut', Commande::LIVREE)
            ->where('livree_le', '>=', $debut)
            ->where('livree_le', '<', $fin)
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->count();

        $penderiePlus14j = Commande::query()
            ->whereNotIn('statut', [Commande::ANNULEE, Commande::DON])
            ->whereHas('lots', fn (Builder $q) => $q
                ->where('sens', Lot::RETOUR)
                ->whereNotNull('recu_le')
                ->where('recu_le', '<=', $reference->subDays(14)))
            ->where(fn (Builder $q) => $q->whereNull('livree_le')->orWhere('livree_le', '>', $reference))
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->count();

        $encaisseBoutiques = '0.00';
        $encaisseAtelier = '0.00';
        $sitesCollection = Site::all()->keyBy('id');
        foreach ($lignes as $siteId => $ligne) {
            $unSite = $sitesCollection[$siteId] ?? null;
            if ($unSite && $unSite->estAtelier()) {
                $encaisseAtelier = bcadd($encaisseAtelier, $ligne['net_encaisse'], 2);
            } else {
                $encaisseBoutiques = bcadd($encaisseBoutiques, $ligne['net_encaisse'], 2);
            }
        }

        // linge pret qui attend son client : revenu en boutique, ou traite a l'atelier pour un depot
        // direct ; a la date du calcul, comme le reste a recouvrer
        $creancesLingePret = '0.00';
        $pretes = Commande::query()
            ->where('reste', '>', 0)
            ->where(fn (Builder $q) => $q
                ->whereIn('statut', [Commande::RETOURNEE, Commande::NON_RECLAMEE])
                ->orWhere(fn (Builder $q) => $q
                    ->where('statut', Commande::TRAITEE)
                    ->whereHas('site', fn (Builder $s) => $s->where('type', Site::ATELIER))))
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->pluck('reste');
        foreach ($pretes as $reste) {
            $creancesLingePret = bcadd($creancesLingePret, (string) $reste, 2);
        }

        $commissionsParRole = ['laveur' => '0.00', 'repasseur' => '0.00', 'polyvalent' => '0.00'];
        $tachesRole = Tache::query()
            ->join('travailleurs', 'travailleurs.id', '=', 'taches.travailleur_id')
            ->where('taches.terminee_le', '>=', $semaine->debutUtc())
            ->where('taches.terminee_le', '<', $semaine->finUtc())
            ->when($site !== null, fn (Builder $q) => $q->where('travailleurs.site_id', $site->id))
            ->get(['taches.quantite', 'taches.commission_unitaire', 'travailleurs.role']);

        foreach ($tachesRole as $t) {
            $roleCle = strtolower($t->role ?? 'polyvalent');
            if (! isset($commissionsParRole[$roleCle])) {
                $roleCle = 'polyvalent';
            }
            $montantTache = bcmul((string) $t->commission_unitaire, (string) $t->quantite, 2);
            $commissionsParRole[$roleCle] = bcadd($commissionsParRole[$roleCle], $montantTache, 2);
        }

        $margeNette = bcsub((string) $total['net_encaisse'], (string) $total['charges'], 2);

        // versements journaliers des boutiques dont la date tombe dans la semaine : ceux d'une boutique,
        // ceux recus par un atelier, ou ceux de tout le reseau
        $versements = VersementJournalier::query()
            ->where('date_versement', '>=', $semaine->debut()->toDateString())
            ->where('date_versement', '<=', $semaine->dernierJour()->toDateString())
            ->when($site !== null, fn (Builder $q) => $site->estAtelier()
                ? $q->where('site_reception_id', $site->id)
                : $q->where('site_id', $site->id))
            ->get();
        $declares = '0.00';
        $recus = '0.00';
        $declaresDesRecus = '0.00';
        $enAttente = '0.00';
        $nombreEnAttente = 0;
        foreach ($versements as $versement) {
            $declares = bcadd($declares, (string) $versement->montant_declare, 2);
            if ($versement->estRecu()) {
                $recus = bcadd($recus, (string) $versement->montant_recu, 2);
                $declaresDesRecus = bcadd($declaresDesRecus, (string) $versement->montant_declare, 2);
            } else {
                $enAttente = bcadd($enAttente, (string) $versement->montant_declare, 2);
                $nombreEnAttente++;
            }
        }

        // achats de la semaine par categorie, dans l'ordre des categories
        $parCategorie = array_fill_keys(array_keys(Consommable::CATEGORIES), '0.00');
        $achats = Consommable::query()
            ->where('sortie_le', '>=', $debut)
            ->where('sortie_le', '<', $fin)
            ->when($site !== null, fn (Builder $q) => $q->where('site_id', $site->id))
            ->get(['categorie', 'montant']);
        foreach ($achats as $achat) {
            $categorie = isset($parCategorie[$achat->categorie]) ? $achat->categorie : 'AUTRE';
            $parCategorie[$categorie] = bcadd($parCategorie[$categorie], (string) $achat->montant, 2);
        }

        // Fonds de l'atelier (decision du 23 octobre 2026) : achats et avances sur commission sont
        // payes avec les versements des boutiques recus a l'atelier pendant la semaine
        $fondsRecus = '0.00';
        $versementsRecus = VersementJournalier::query()
            ->where('recu_le', '>=', $debut)
            ->where('recu_le', '<', $fin)
            ->when($site !== null, fn (Builder $q) => $q->where('site_reception_id', $site->id))
            ->pluck('montant_recu');
        foreach ($versementsRecus as $montant) {
            $fondsRecus = bcadd($fondsRecus, (string) $montant, 2);
        }
        $fondsDepenses = bcadd(bcadd((string) $total['consommables'], (string) $total['achats'], 2),
            (string) $total['avances_commission'], 2);

        return [
            'reference' => $reference->setTimezone(Affichage::fuseau()),
            'section_1_activite' => [
                'commandes_enregistrees' => $total['commandes_nombre'],
                'pieces_traitees' => $piecesTraitees,
                'commandes_retirees' => $commandesRetirees,
                'penderie_plus_14j' => $penderiePlus14j,
            ],
            'section_2_creances' => [
                'encaisse_boutiques' => $encaisseBoutiques,
                'encaisse_atelier' => $encaisseAtelier,
                'total_net_encaisse' => $total['net_encaisse'],
                'creances_linge_pret' => $creancesLingePret,
                'creances_totales' => $total['reste_total'],
            ],
            'section_3_charges' => [
                'commissions_laveurs' => $commissionsParRole['laveur'],
                'commissions_repasseurs' => $commissionsParRole['repasseur'],
                'commissions_polyvalents' => $commissionsParRole['polyvalent'],
                'total_commissions' => $total['commissions_dues'],
                'consommables' => $total['consommables'],
                'achats' => $total['achats'],
                // libelle de la categorie => montant, toutes categories, consommables compris
                'achats_par_categorie' => collect($parCategorie)
                    ->mapWithKeys(fn (string $montant, string $code) => [Consommable::CATEGORIES[$code] => $montant])
                    ->all(),
                'total_charges' => $total['charges'],
                'avances_commission' => $total['avances_commission'],
                'commissions_restant_a_payer' => bcsub((string) $total['commissions_dues'], (string) $total['avances_commission'], 2),
            ],
            'section_4_marge' => [
                'marge_nette' => $margeNette,
                'versements_nombre' => $versements->count(),
                'versements_declares' => $declares,
                'versements_recus' => $recus,
                // recu moins declare, sur les seuls versements deja recus
                'versements_ecart' => bcsub($recus, $declaresDesRecus, 2),
                'versements_en_attente' => $enAttente,
                'versements_en_attente_nombre' => $nombreEnAttente,
                'fonds_recus' => $fondsRecus,
                'fonds_depenses' => $fondsDepenses,
                'fonds_reste' => bcsub($fondsRecus, $fondsDepenses, 2),
                'manques' => $total['manques'],
                'excedents' => $total['excedents'],
            ],
        ];
    }
}
