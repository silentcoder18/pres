<?php

namespace Tests\Feature\Finance;

use App\Models\Paiement;
use App\Models\SyncChangement;
use App\Services\Finance\AffectationPaiements;

/** RG-14 : recette de la semaine ou recouvrement tardif, decide par le serveur. */
class AffectationPaiementsTest extends FinanceTestCase
{
    public function test_un_paiement_de_la_semaine_du_depot_est_de_la_recette_un_paiement_posterieur_va_a_la_reserve(): void
    {
        $depot = self::instant('2026-10-06 10:00');

        // Meme semaine, jusqu'au dimanche soir
        $this->assertSame(Paiement::RECETTE, AffectationPaiements::decider(Paiement::ACOMPTE, self::instant('2026-10-06 10:05'), $depot));
        $this->assertSame(Paiement::RECETTE, AffectationPaiements::decider(Paiement::SOLDE, self::instant('2026-10-11 23:30'), $depot));
        // Des le lundi 00:00 du reseau, c'est un recouvrement tardif
        $this->assertSame(Paiement::RESERVE, AffectationPaiements::decider(Paiement::SOLDE, self::instant('2026-10-12 00:00'), $depot));
        $this->assertSame(Paiement::RESERVE, AffectationPaiements::decider(Paiement::ACOMPTE, self::instant('2026-11-20 15:00'), $depot));

        $this->assertFalse(AffectationPaiements::estTardif(self::instant('2026-10-11 23:30'), $depot));
        $this->assertTrue(AffectationPaiements::estTardif(self::instant('2026-10-12 00:30'), $depot));
    }

    public function test_une_avance_restituee_n_a_pas_d_affectation(): void
    {
        $depot = self::instant('2026-10-06 10:00');

        $this->assertNull(AffectationPaiements::decider(Paiement::REMBOURSEMENT, self::instant('2026-10-07 10:00'), $depot));
        $this->assertNull(AffectationPaiements::decider(Paiement::REMBOURSEMENT, self::instant('2026-10-20 10:00'), $depot));
    }

    public function test_un_encaissement_date_d_avant_le_depot_reste_de_la_recette(): void
    {
        // Horloge du poste dereglee (§4.4) : rien d'anterieur a rembourser, ce n'est pas un recouvrement tardif
        $this->assertSame(
            Paiement::RECETTE,
            AffectationPaiements::decider(Paiement::ACOMPTE, self::instant('2026-09-29 10:00'), self::instant('2026-10-06 10:00')),
        );
    }

    public function test_la_commande_de_rattrapage_remplit_les_affectations_manquantes_et_se_relance_sans_effet(): void
    {
        $commande = $this->deposer($this->centre, 'CV-S41-10-2026-AAAA', '2026-10-06 10:00', 4);
        $caisse = $this->caisse($this->centre, '2026-10-06 08:00');
        $avance = $this->encaisserLe($commande, $caisse, 1000, '2026-10-06 10:05');
        $tardif = $this->encaisserLe($commande, $caisse, 2000, '2026-10-12 08:30', Paiement::SOLDE);
        $restitution = $this->encaisserLe($commande, $caisse, 500, '2026-10-12 08:45', Paiement::REMBOURSEMENT);
        $dejaAffecte = $this->encaisserLe($commande, $caisse, 300, '2026-10-12 08:50', Paiement::SOLDE);
        // Tels que le serveur les gardait avant de decider l'affectation : sans rien
        Paiement::whereKey([$avance->id, $tardif->id])->update(['affectation' => null]);
        // Une decision deja prise n'est pas refaite, meme si la regle en donnerait une autre
        Paiement::whereKey($dejaAffecte->id)->update(['affectation' => Paiement::RECETTE]);
        $publies = SyncChangement::count();

        $this->artisan('pressing:paiements-affecter')
            ->expectsOutput('2 encaissements affectés : 1 à la recette de leur semaine, 1 à la réserve (recouvrements tardifs).')
            ->assertSuccessful();

        $this->assertSame(Paiement::RECETTE, $avance->refresh()->affectation);
        $this->assertSame(Paiement::RESERVE, $tardif->refresh()->affectation);
        $this->assertNull($restitution->refresh()->affectation);
        $this->assertSame(Paiement::RECETTE, $dejaAffecte->refresh()->affectation);
        // L'affectation reste sur le serveur : rien n'est republie vers les postes
        $this->assertSame($publies, SyncChangement::count());

        $this->artisan('pressing:paiements-affecter')
            ->expectsOutput('Aucun encaissement à affecter.')
            ->assertSuccessful();

        $this->assertSame(Paiement::RECETTE, $avance->refresh()->affectation);
        $this->assertSame(Paiement::RESERVE, $tardif->refresh()->affectation);
        $this->assertSame($publies, SyncChangement::count());
    }

    public function test_l_affectation_ne_part_pas_vers_les_postes(): void
    {
        $commande = $this->deposer($this->centre, 'CV-S41-10-2026-AAAA', '2026-10-06 10:00', 4);
        $paiement = $this->encaisserLe($commande, $this->caisse($this->centre, '2026-10-06 08:00'), 1000, '2026-10-06 10:05');

        $this->assertSame(Paiement::RECETTE, $paiement->affectation);
        $this->assertSame(
            ['id', 'commande_id', 'session_caisse_id', 'utilisateur_id', 'type', 'montant', 'encaisse_le'],
            array_keys($paiement->donneesPoste()),
        );
        $this->assertArrayNotHasKey('affectation', $this->changements('paiements')->last()->donnees);
    }
}
