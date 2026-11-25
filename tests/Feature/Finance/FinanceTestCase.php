<?php

namespace Tests\Feature\Finance;

use App\Models\Client;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Paiement;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\Tarif;
use App\Models\Utilisateur;
use App\Services\Finance\AffectationPaiements;
use App\Services\Finance\RapportHebdomadaire;
use App\Services\Finance\SemaineComptable;
use Carbon\CarbonImmutable;
use Tests\Feature\Promoteur\PromoteurTestCase;

/**
 * Base des essais de la finance hebdomadaire : des depots, des encaissements et des caisses a des
 * dates choisies, donnees a l'heure du reseau (Africa/Douala, UTC+1) et gardees en UTC comme le
 * fait le serveur.
 *
 * « Maintenant » est le lundi 12 octobre 2026 a 09:00 : la semaine 41 (du lundi 5 au dimanche 11)
 * vient de finir, la semaine 42 commence.
 */
abstract class FinanceTestCase extends PromoteurTestCase
{
    protected Site $centre;

    protected Site $marche;

    protected Site $atelier;

    protected Utilisateur $aicha;

    protected Utilisateur $bello;

    protected Client $alice;

    protected Tarif $chemise;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pressing.fuseau' => 'Africa/Douala']);
        $this->travelTo(self::instant('2026-10-12 09:00'));

        $this->centre = $this->creerSite('CV', Site::BOUTIQUE, 'Centre-Ville');
        $this->marche = $this->creerSite('MC', Site::BOUTIQUE, 'Marché Central');
        $this->atelier = $this->creerSite('ATL', Site::ATELIER, 'Atelier Central');
        $this->aicha = $this->creerCompte('aicha', Utilisateur::RECEPTIONNISTE, $this->centre);
        $this->bello = $this->creerCompte('bello', Utilisateur::RECEPTIONNISTE, $this->marche);
        $this->alice = $this->creerClient($this->centre);
        // Une chemise : 1 500 de prix, 200 de commission
        $this->chemise = $this->creerTarif(1500);
    }

    /** Un instant donne a l'heure du reseau, tel qu'il est garde en base : en UTC. */
    protected static function instant(string $heureDuReseau): CarbonImmutable
    {
        return CarbonImmutable::parse($heureDuReseau, 'Africa/Douala')->utc();
    }

    protected function semaine(string $iso = '2026-W41'): SemaineComptable
    {
        return SemaineComptable::depuisIso($iso);
    }

    protected function rapport(string $iso = '2026-W41', ?Site $site = null): array
    {
        return app(RapportHebdomadaire::class)->calculer($this->semaine($iso), $site);
    }

    /** Commande de chemises a 1 500 (commission 200) deposee sur un site a l'heure donnee. */
    protected function deposer(Site $site, string $code, string $le, int $quantite = 2, string $statut = Commande::ENREGISTREE, string $prix = '1500', string $commission = '200'): Commande
    {
        $commande = Commande::create([
            'code' => $code,
            'site_id' => $site->id,
            'client_id' => $this->alice->id,
            'utilisateur_id' => $this->auteur($site)->id,
            'statut' => $statut,
            'deposee_le' => self::instant($le),
        ]);
        LigneCommande::create([
            'commande_id' => $commande->id,
            'tarif_id' => $this->chemise->id,
            'quantite' => $quantite,
            'prix_applique' => $prix,
            'commission_appliquee' => $commission,
        ]);
        $commande->recalculer();

        return $commande->refresh();
    }

    /** Session de caisse d'un site ; cloturee si une heure de cloture et un montant compte sont donnes. */
    protected function caisse(Site $site, string $ouverteLe, ?string $clotureeLe = null, ?int $compte = null, int $fond = 5000): SessionCaisse
    {
        $session = SessionCaisse::create([
            'site_id' => $site->id,
            'ouverte_par' => $this->auteur($site)->id,
            'ouverte_le' => self::instant($ouverteLe),
            'fond_initial' => $fond,
        ]);
        if ($clotureeLe !== null) {
            $session->update([
                'cloturee_par' => $this->auteur($site)->id,
                'cloturee_le' => self::instant($clotureeLe),
                'total_compte' => $compte,
            ]);
            $session->recalculer();
        }

        return $session;
    }

    /** Encaissement a l'heure donnee, avec l'affectation que le serveur lui aurait donnee a la reception. */
    protected function encaisserLe(Commande $commande, SessionCaisse $session, string|int $montant, string $le, string $type = Paiement::ACOMPTE): Paiement
    {
        $paiement = Paiement::create([
            'commande_id' => $commande->id,
            'session_caisse_id' => $session->id,
            'utilisateur_id' => $session->ouverte_par,
            'type' => $type,
            'montant' => $montant,
            'encaisse_le' => self::instant($le),
            'affectation' => AffectationPaiements::decider($type, self::instant($le), $commande->deposee_le),
        ]);
        $commande->recalculer();
        // RG-18 : attendu et ecart d'une session cloturee suivent les encaissements recus
        $session->recalculer();

        return $paiement;
    }

    /**
     * Deux boutiques sur les semaines 40 a 42. Semaine 41 :
     *
     *  Centre-Ville : 2 commandes deposees (3 000 et 9 000), 1 annulee ; avances 7 000, soldes
     *  8 000 dont 4 000 sur une commande de la semaine 40, 1 000 restitue ; 5 caisses cloturees,
     *  un manque de 500 et un excedent de 200.
     *  Marche Central : 1 commande deposee le dimanche a 23:30 (3 000), avance 1 500 ; solde de
     *  2 000 sur une commande de la semaine 40 ; 2 caisses dont une encore ouverte.
     */
    protected function jeuDeTroisSemaines(): void
    {
        // --- Centre-Ville
        // Jamais payee, toujours a retirer : elle ne pese que dans le reste a recouvrer total
        $this->deposer($this->centre, 'CV-S39-09-2026-ZZZZ', '2026-09-22 10:00', 2, 'RETOURNEE');
        $a = $this->deposer($this->centre, 'CV-S40-09-2026-AAAA', '2026-09-30 10:00', 4);
        $b = $this->deposer($this->centre, 'CV-S41-10-2026-BBBB', '2026-10-05 09:00', 2);
        $c = $this->deposer($this->centre, 'CV-S41-10-2026-CCCC', '2026-10-07 11:00', 6);
        $d = $this->deposer($this->centre, 'CV-S41-10-2026-DDDD', '2026-10-08 09:00', 2);

        $s40 = $this->caisse($this->centre, '2026-09-30 08:00', '2026-09-30 18:00', 7000);
        $this->encaisserLe($a, $s40, 2000, '2026-09-30 10:05');

        $lundi = $this->caisse($this->centre, '2026-10-05 08:00', '2026-10-05 18:00', 5500);
        $this->encaisserLe($b, $lundi, 1000, '2026-10-05 09:05');

        $mardi = $this->caisse($this->centre, '2026-10-06 08:00', '2026-10-06 18:00', 9200);
        $this->encaisserLe($a, $mardi, 4000, '2026-10-06 16:00', Paiement::SOLDE);

        $mercredi = $this->caisse($this->centre, '2026-10-07 08:00', '2026-10-07 18:00', 10000);
        $this->encaisserLe($c, $mercredi, 5000, '2026-10-07 11:05');

        $jeudi = $this->caisse($this->centre, '2026-10-08 08:00', '2026-10-08 18:00', 10000);
        $this->encaisserLe($c, $jeudi, 4000, '2026-10-08 15:00', Paiement::SOLDE);
        $this->encaisserLe($d, $jeudi, 1000, '2026-10-08 09:05');

        // RG-24 : commande annulee, son avance est rendue le lendemain sur la caisse du jour
        $vendredi = $this->caisse($this->centre, '2026-10-09 08:00', '2026-10-09 18:00', 4000);
        $this->encaisserLe($d, $vendredi, 1000, '2026-10-09 09:00', Paiement::REMBOURSEMENT);

        $a->update(['statut' => 'LIVREE']);
        $c->update(['statut' => 'LIVREE']);
        $d->update(['statut' => Commande::ANNULEE]);

        // --- Marche Central
        $e = $this->deposer($this->marche, 'MC-S40-10-2026-EEEE', '2026-10-02 10:00', 2);
        $f = $this->deposer($this->marche, 'MC-S41-10-2026-FFFF', '2026-10-11 23:30', 2);
        $g = $this->deposer($this->marche, 'MC-S42-10-2026-GGGG', '2026-10-12 00:30', 2);

        $m40 = $this->caisse($this->marche, '2026-10-02 08:00', '2026-10-02 18:00', 6000);
        $this->encaisserLe($e, $m40, 1000, '2026-10-02 10:05');

        $mJeudi = $this->caisse($this->marche, '2026-10-08 08:00', '2026-10-08 18:00', 7000);
        $this->encaisserLe($e, $mJeudi, 2000, '2026-10-08 12:00', Paiement::SOLDE);

        // Ouverte le dimanche soir, pas encore cloturee : elle encaisse aussi apres minuit
        $dimanche = $this->caisse($this->marche, '2026-10-11 22:00');
        $this->encaisserLe($f, $dimanche, 1500, '2026-10-11 23:40');
        $this->encaisserLe($g, $dimanche, 500, '2026-10-12 00:35');

        $e->update(['statut' => 'LIVREE']);
    }

    private function auteur(Site $site): Utilisateur
    {
        return $site->is($this->marche) ? $this->bello : $this->aicha;
    }
}
