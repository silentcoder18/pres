<?php

namespace Tests\Feature\Finance;

use App\Models\Commande;
use App\Models\Consommable;
use App\Models\Paiement;
use App\Models\Tache;
use App\Models\Travailleur;
use App\Models\Utilisateur;
use App\Services\Finance\RapportHebdomadaire;

/** Situation financiere d'une semaine, calculee par le serveur a partir des faits des postes (§7). */
class RapportHebdomadaireTest extends FinanceTestCase
{
    /** Les chiffres d'un site dans un rapport, sans le site lui-meme. */
    private function ligne(array $rapport, string $siteId): array
    {
        $ligne = collect($rapport['sites'])->firstWhere('site.id', $siteId);
        $this->assertNotNull($ligne, 'le site a sa ligne dans le rapport');
        unset($ligne['site']);

        return $ligne;
    }

    public function test_les_chiffres_de_la_semaine_par_site_suivent_les_definitions(): void
    {
        $this->jeuDeTroisSemaines();

        $rapport = $this->rapport('2026-W41');

        $this->assertEquals([
            'commandes_nombre' => 2,
            'montant_facture' => '12000.00',
            'commissions_prevues' => '1600.00',
            'avances' => '7000.00',
            'soldes' => '8000.00',
            // l'avance de la commande annulee, recue le jeudi et rendue le vendredi
            'restitutions' => '1000.00',
            'net_encaisse' => '14000.00',
            'recette' => '10000.00',
            'recouvrements_tardifs' => '4000.00',
            'reste_semaine' => '2000.00',
            'reste_total' => '5000.00',
            'solde_previsionnel' => '8400.00',
            'caisses_nombre' => 5,
            'caisses_cloturees' => 5,
            'caisses_ouvertes' => 0,
            'manques' => '500.00',
            'excedents' => '200.00',
            'commissions_dues' => '0.00',
            'consommables' => '0.00',
            'achats' => '0.00',
            'charges' => '0.00',
            'avances_commission' => '0.00',
        ], $this->ligne($rapport, $this->centre->id));

        $this->assertEquals([
            'commandes_nombre' => 1,
            'montant_facture' => '3000.00',
            'commissions_prevues' => '400.00',
            'avances' => '1500.00',
            'soldes' => '2000.00',
            'restitutions' => '0.00',
            'net_encaisse' => '3500.00',
            'recette' => '1500.00',
            'recouvrements_tardifs' => '2000.00',
            'reste_semaine' => '1500.00',
            'reste_total' => '4000.00',
            'solde_previsionnel' => '1100.00',
            'caisses_nombre' => 2,
            'caisses_cloturees' => 1,
            'caisses_ouvertes' => 1,
            'manques' => '0.00',
            'excedents' => '0.00',
            'commissions_dues' => '0.00',
            'consommables' => '0.00',
            'achats' => '0.00',
            'charges' => '0.00',
            'avances_commission' => '0.00',
        ], $this->ligne($rapport, $this->marche->id));

        // L'atelier n'a rien encaisse : sa ligne existe, a zero
        $this->assertSame(0, $this->ligne($rapport, $this->atelier->id)['commandes_nombre']);
        $this->assertSame('0.00', $this->ligne($rapport, $this->atelier->id)['net_encaisse']);
        $this->assertSame(['Centre-Ville', 'Marché Central', 'Atelier Central'], collect($rapport['sites'])->pluck('site.nom')->all());
        $this->assertNull($rapport['perimetre']);
        $this->assertSame('2026-W41', $rapport['semaine']->iso());
    }

    public function test_le_total_du_reseau_est_la_somme_des_sites(): void
    {
        $this->jeuDeTroisSemaines();

        $rapport = $this->rapport('2026-W41');

        $this->assertEquals([
            'commandes_nombre' => 3,
            'montant_facture' => '15000.00',
            'commissions_prevues' => '2000.00',
            'avances' => '8500.00',
            'soldes' => '10000.00',
            'restitutions' => '1000.00',
            'net_encaisse' => '17500.00',
            'recette' => '11500.00',
            'recouvrements_tardifs' => '6000.00',
            'reste_semaine' => '3500.00',
            'reste_total' => '9000.00',
            'solde_previsionnel' => '9500.00',
            'caisses_nombre' => 7,
            'caisses_cloturees' => 6,
            'caisses_ouvertes' => 1,
            'manques' => '500.00',
            'excedents' => '200.00',
            'commissions_dues' => '0.00',
            'consommables' => '0.00',
            'achats' => '0.00',
            'charges' => '0.00',
            'avances_commission' => '0.00',
        ], $rapport['total']);

        foreach (RapportHebdomadaire::MONTANTS as $cle) {
            $somme = '0.00';
            foreach ($rapport['sites'] as $ligne) {
                $somme = bcadd($somme, $ligne[$cle], 2);
            }
            $this->assertSame($somme, $rapport['total'][$cle], $cle);
        }
        foreach (RapportHebdomadaire::COMPTEURS as $cle) {
            $this->assertSame(collect($rapport['sites'])->sum($cle), $rapport['total'][$cle], $cle);
        }
    }

    public function test_recette_plus_recouvrements_tardifs_egale_le_net_encaisse(): void
    {
        $this->jeuDeTroisSemaines();

        foreach (['2026-W39', '2026-W40', '2026-W41', '2026-W42', '2026-W43'] as $iso) {
            $rapport = $this->rapport($iso);
            foreach ([...$rapport['sites'], $rapport['total']] as $ligne) {
                // RG-24 : un versement rendu depuis au client n'est ni une recette ni un recouvrement tardif
                $this->assertSame($ligne['net_encaisse'], bcadd($ligne['recette'], $ligne['recouvrements_tardifs'], 2), $iso);
                $this->assertSame(bcsub(bcadd($ligne['avances'], $ligne['soldes'], 2), $ligne['restitutions'], 2), $ligne['net_encaisse'], $iso);
                $this->assertSame(bcsub($ligne['recette'], $ligne['commissions_prevues'], 2), $ligne['solde_previsionnel'], $iso);
            }
        }

        // Semaine 40 : chaque avance porte sur une commande de la semaine, tout est de la recette
        $s40 = $this->rapport('2026-W40')['total'];
        $this->assertSame('3000.00', $s40['recette']);
        $this->assertSame('0.00', $s40['recouvrements_tardifs']);
        $this->assertSame(2, $s40['commandes_nombre']);
        // Une semaine sans rien : tout est a zero, sauf le reste total, qui est celui d'aujourd'hui
        $s43 = $this->rapport('2026-W43')['total'];
        $this->assertSame('0.00', $s43['net_encaisse']);
        $this->assertSame('9000.00', $s43['reste_total']);
    }

    public function test_le_detail_par_jour_suit_les_jours_du_reseau(): void
    {
        $this->jeuDeTroisSemaines();

        $rapport = $this->rapport('2026-W41');
        $jours = collect($rapport['jours'])->keyBy(fn (array $jour) => $jour['date']->format('Y-m-d'));

        $this->assertSame(
            ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'],
            $jours->keys()->all(),
        );
        $attendu = [
            //             commandes, facture, avances, soldes, restitutions, net
            '2026-10-05' => [1, '3000.00', '1000.00', '0.00', '0.00', '1000.00'],
            '2026-10-06' => [0, '0.00', '0.00', '4000.00', '0.00', '4000.00'],
            '2026-10-07' => [1, '9000.00', '5000.00', '0.00', '0.00', '5000.00'],
            // la commande annulee n'est pas comptee, son avance l'est
            '2026-10-08' => [0, '0.00', '1000.00', '6000.00', '0.00', '7000.00'],
            '2026-10-09' => [0, '0.00', '0.00', '0.00', '1000.00', '-1000.00'],
            '2026-10-10' => [0, '0.00', '0.00', '0.00', '0.00', '0.00'],
            // deposee le dimanche a 23:30 heure du reseau : encore le dimanche, encore la semaine 41
            '2026-10-11' => [1, '3000.00', '1500.00', '0.00', '0.00', '1500.00'],
        ];
        foreach ($attendu as $date => [$nombre, $facture, $avances, $soldes, $restitutions, $net]) {
            $this->assertSame($nombre, $jours[$date]['commandes_nombre'], $date);
            $this->assertSame($facture, $jours[$date]['montant_facture'], $date);
            $this->assertSame($avances, $jours[$date]['avances'], $date);
            $this->assertSame($soldes, $jours[$date]['soldes'], $date);
            $this->assertSame($restitutions, $jours[$date]['restitutions'], $date);
            $this->assertSame($net, $jours[$date]['net_encaisse'], $date);
        }

        // Le total des journees est la somme des jours
        $this->assertSame($jours->sum('commandes_nombre'), $rapport['total_jours']['commandes_nombre']);
        foreach (RapportHebdomadaire::MONTANTS_DU_JOUR as $cle) {
            $somme = $jours->reduce(fn (string $somme, array $jour) => bcadd($somme, $jour[$cle], 2), '0.00');
            $this->assertSame($somme, $rapport['total_jours'][$cle], $cle);
        }
        // Depots et versements : la somme des jours est le chiffre de la semaine
        foreach (['commandes_nombre', 'montant_facture', 'avances', 'soldes'] as $cle) {
            $this->assertEquals($rapport['total'][$cle], $rapport['total_jours'][$cle], $cle);
        }
        // Ici l'avance rendue avait ete recue la meme semaine : journees et semaine disent la meme chose
        $this->assertSame($rapport['total']['restitutions'], $rapport['total_jours']['restitutions']);
        $this->assertSame($rapport['total']['net_encaisse'], $rapport['total_jours']['net_encaisse']);
    }

    public function test_dimanche_23h30_et_lundi_0h30_a_douala_tombent_dans_deux_semaines(): void
    {
        $commande = $this->deposer($this->centre, 'CV-S41-10-2026-AAAA', '2026-10-09 10:00', 4);
        $caisse = $this->caisse($this->centre, '2026-10-11 20:00');
        $dimanche = $this->encaisserLe($commande, $caisse, 1000, '2026-10-11 23:30');
        $lundi = $this->encaisserLe($commande, $caisse, 700, '2026-10-12 00:30', Paiement::SOLDE);

        // En UTC, les deux encaissements sont le meme dimanche 11 octobre
        $this->assertSame('2026-10-11 22:30:00', $dimanche->refresh()->encaisse_le->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11 23:30:00', $lundi->refresh()->encaisse_le->format('Y-m-d H:i:s'));

        $s41 = $this->rapport('2026-W41');
        $this->assertSame('1000.00', $s41['total']['avances']);
        $this->assertSame('0.00', $s41['total']['soldes']);
        $this->assertSame('1000.00', $s41['total']['recette']);
        $this->assertSame('1000.00', $s41['jours'][6]['avances'], 'dimanche');
        $this->assertSame([], $s41['recouvrements']);

        // Le lundi 00:30 appartient a la semaine 42 : pour une commande de la semaine 41, c'est un recouvrement tardif
        $s42 = $this->rapport('2026-W42');
        $this->assertSame('0.00', $s42['total']['avances']);
        $this->assertSame('700.00', $s42['total']['soldes']);
        $this->assertSame('0.00', $s42['total']['recette']);
        $this->assertSame('700.00', $s42['total']['recouvrements_tardifs']);
        $this->assertSame('700.00', $s42['jours'][0]['soldes'], 'lundi');
        $this->assertSame(Paiement::RECETTE, $dimanche->affectation);
        $this->assertSame(Paiement::RESERVE, $lundi->affectation);

        // La caisse, ouverte le dimanche, est une caisse de la semaine 41 ; elle montre tout ce qu'elle a encaisse
        $this->assertSame(1, $s41['total']['caisses_nombre']);
        $this->assertSame(0, $s42['total']['caisses_nombre']);
        $this->assertSame('1700.00', $s41['caisses'][0]['encaisse']);
        $this->assertSame('6700.00', $s41['caisses'][0]['attendu']);
    }

    public function test_une_commande_deposee_a_minuit_pile_le_lundi_est_de_la_nouvelle_semaine(): void
    {
        $this->deposer($this->centre, 'CV-S41-10-2026-AAAA', '2026-10-11 23:59:59', 2);
        $this->deposer($this->centre, 'CV-S42-10-2026-BBBB', '2026-10-12 00:00:00', 4);

        $this->assertSame('3000.00', $this->rapport('2026-W41')['total']['montant_facture']);
        $this->assertSame('6000.00', $this->rapport('2026-W42')['total']['montant_facture']);
    }

    public function test_une_commande_annulee_n_est_pas_comptee_et_son_avance_rendue_n_est_plus_une_recette(): void
    {
        $gardee = $this->deposer($this->centre, 'CV-S41-10-2026-AAAA', '2026-10-06 10:00', 2);
        $annulee = $this->deposer($this->centre, 'CV-S41-10-2026-BBBB', '2026-10-06 11:00', 10);
        $caisse = $this->caisse($this->centre, '2026-10-06 08:00');
        $this->encaisserLe($gardee, $caisse, 1000, '2026-10-06 10:05');
        $this->encaisserLe($annulee, $caisse, 4000, '2026-10-06 11:05');
        $annulee->update(['statut' => Commande::ANNULEE]);

        // Annulee, mais l'avance n'est pas encore rendue : elle est toujours dans la caisse et dans la recette
        $avant = $this->rapport('2026-W41')['total'];
        $this->assertSame(1, $avant['commandes_nombre']);
        $this->assertSame('5000.00', $avant['avances']);
        $this->assertSame('0.00', $avant['restitutions']);
        $this->assertSame('5000.00', $avant['recette']);

        $restitution = $this->encaisserLe($annulee, $caisse, 4000, '2026-10-07 09:00', Paiement::REMBOURSEMENT);

        $total = $this->rapport('2026-W41')['total'];

        $this->assertSame(1, $total['commandes_nombre']);
        $this->assertSame('3000.00', $total['montant_facture']);
        $this->assertSame('400.00', $total['commissions_prevues']);
        $this->assertSame('2000.00', $total['reste_semaine']);
        $this->assertSame('2000.00', $total['reste_total']);
        // RG-24 : l'avance rendue est deduite du net encaisse, et n'est plus une recette de la semaine
        $this->assertSame('5000.00', $total['avances']);
        $this->assertSame('4000.00', $total['restitutions']);
        $this->assertSame('1000.00', $total['net_encaisse']);
        $this->assertSame('1000.00', $total['recette']);
        $this->assertSame('600.00', $total['solde_previsionnel']);
        $this->assertNull($restitution->affectation);
    }

    public function test_une_avance_restituee_est_reprise_sur_la_semaine_ou_elle_avait_ete_recue(): void
    {
        // Semaine 40 : deux commandes et leurs avances. Semaine 41 : un solde sur la premiere, puis son
        // annulation ; tout ce qu'elle avait verse (3 000) est rendu le mercredi sur la caisse du jour
        $annulee = $this->deposer($this->centre, 'CV-S40-09-2026-AAAA', '2026-09-30 10:00', 4);
        $gardee = $this->deposer($this->centre, 'CV-S40-09-2026-BBBB', '2026-09-30 11:00', 2);
        $recente = $this->deposer($this->centre, 'CV-S41-10-2026-CCCC', '2026-10-06 09:00', 2);
        $s40 = $this->caisse($this->centre, '2026-09-30 08:00', '2026-09-30 18:00', 8500);
        $this->encaisserLe($annulee, $s40, 2000, '2026-09-30 10:05');
        $this->encaisserLe($gardee, $s40, 1500, '2026-09-30 11:05');
        $lundi = $this->caisse($this->centre, '2026-10-05 08:00', '2026-10-05 18:00', 6000);
        $this->encaisserLe($annulee, $lundi, 1000, '2026-10-05 10:00', Paiement::SOLDE);
        $mardi = $this->caisse($this->centre, '2026-10-06 08:00', '2026-10-06 18:00', 6000);
        $this->encaisserLe($recente, $mardi, 1000, '2026-10-06 09:05');
        $annulee->update(['statut' => Commande::ANNULEE]);

        // Avant la restitution : l'avance est une recette de la semaine 40, le solde un recouvrement tardif de la 41
        $this->assertSame('3500.00', $this->rapport('2026-W40')['total']['recette']);
        $this->assertSame('1000.00', $this->rapport('2026-W41')['total']['recouvrements_tardifs']);

        $mercredi = $this->caisse($this->centre, '2026-10-07 08:00', '2026-10-07 18:00', 2000);
        $this->encaisserLe($annulee, $mercredi, 3000, '2026-10-07 09:00', Paiement::REMBOURSEMENT);
        $this->assertSame('0.00', $annulee->refresh()->total_paye);

        // Semaine 40 : l'avance de 2 000 y est reprise, bien que l'argent soit sorti de la caisse en semaine 41
        $semaine40 = $this->rapport('2026-W40');
        $this->assertSame('3500.00', $semaine40['total']['avances']);
        $this->assertSame('2000.00', $semaine40['total']['restitutions']);
        $this->assertSame('1500.00', $semaine40['total']['net_encaisse']);
        $this->assertSame('1500.00', $semaine40['total']['recette']);
        $this->assertSame('0.00', $semaine40['total']['recouvrements_tardifs']);
        // Ses journees, elles, ne changent pas : rien n'est sorti de la caisse cette semaine-la
        $this->assertSame('0.00', $semaine40['total_jours']['restitutions']);
        $this->assertSame('3500.00', $semaine40['total_jours']['net_encaisse']);

        // Semaine 41 : seul le solde de 1 000 recu le lundi y est repris ; il n'est plus un recouvrement tardif
        $semaine41 = $this->rapport('2026-W41');
        $this->assertSame('1000.00', $semaine41['total']['avances']);
        $this->assertSame('1000.00', $semaine41['total']['soldes']);
        $this->assertSame('1000.00', $semaine41['total']['restitutions']);
        $this->assertSame('1000.00', $semaine41['total']['net_encaisse']);
        $this->assertSame('1000.00', $semaine41['total']['recette']);
        $this->assertSame('0.00', $semaine41['total']['recouvrements_tardifs']);
        $this->assertSame([], $semaine41['recouvrements']);
        // RG-18 : les 3 000 sortent de la caisse du mercredi, et sont deduits des comptes de cette journee
        $jours = collect($semaine41['jours'])->keyBy(fn (array $jour) => $jour['date']->format('Y-m-d'));
        $this->assertSame(['0.00', '1000.00', '0.00', '1000.00'], array_values(
            collect($jours['2026-10-05'])->only(['avances', 'soldes', 'restitutions', 'net_encaisse'])->all()));
        $this->assertSame(['0.00', '0.00', '3000.00', '-3000.00'], array_values(
            collect($jours['2026-10-07'])->only(['avances', 'soldes', 'restitutions', 'net_encaisse'])->all()));
        $this->assertSame('3000.00', $semaine41['total_jours']['restitutions']);
        $this->assertSame('-1000.00', $semaine41['total_jours']['net_encaisse']);
        $caisse = collect($semaine41['caisses'])->first(fn (array $caisse) => $caisse['session']->is($mercredi));
        $this->assertSame('-3000.00', $caisse['encaisse']);
        $this->assertSame('2000.00', $caisse['attendu']);
        $this->assertSame('0.00', $caisse['session']->refresh()->ecart);

        // Rien ne se perd : ce qui est repris sur les semaines est exactement ce qui est sorti des caisses
        $this->assertSame('3000.00', bcadd($semaine40['total']['restitutions'], $semaine41['total']['restitutions'], 2));
        foreach ([$semaine40, $semaine41] as $rapport) {
            $this->assertSame(
                $rapport['total']['net_encaisse'],
                bcadd($rapport['total']['recette'], $rapport['total']['recouvrements_tardifs'], 2),
            );
        }
    }

    public function test_le_reste_a_recouvrer_total_ignore_les_commandes_livrees_donnees_ou_annulees(): void
    {
        foreach (['ENREGISTREE', 'ENVOYEE', 'TRAITEE', 'RETOURNEE', 'NON_RECLAMEE', 'LIVREE', 'DON', 'ANNULEE'] as $rang => $statut) {
            $this->deposer($this->centre, 'CV-S30-07-2026-000'.$rang, '2026-07-20 10:00', 1, $statut);
        }

        $total = $this->rapport('2026-W41')['total'];

        // Cinq commandes encore en cours, a 1 500 chacune ; aucune n'est de la semaine
        $this->assertSame('7500.00', $total['reste_total']);
        $this->assertSame('0.00', $total['reste_semaine']);
        $this->assertSame(0, $total['commandes_nombre']);
    }

    public function test_le_perimetre_d_un_site_ne_compte_que_ce_site(): void
    {
        $this->jeuDeTroisSemaines();

        $reseau = $this->rapport('2026-W41');
        $site = $this->rapport('2026-W41', $this->marche);

        $this->assertTrue($site['perimetre']->is($this->marche));
        $this->assertCount(1, $site['sites']);
        $this->assertEquals($this->ligne($reseau, $this->marche->id), $site['total']);
        $this->assertEquals($site['total'], $this->ligne($site, $this->marche->id));
        $this->assertSame($site['total'], app(RapportHebdomadaire::class)->chiffresDuSite($this->semaine(), $this->marche));

        // Les listes aussi : seules les caisses et les recouvrements du site
        $this->assertSame(['Marché Central', 'Marché Central'], collect($site['caisses'])->pluck('session.site.nom')->all());
        $this->assertSame(['MC-S40-10-2026-EEEE'], collect($site['recouvrements'])->pluck('commande.code')->all());
        $this->assertSame('1500.00', $site['jours'][6]['avances']);
        $this->assertSame('0.00', $site['jours'][0]['avances']);
    }

    public function test_les_caisses_de_la_semaine_sont_listees_avec_leur_ecart(): void
    {
        $this->jeuDeTroisSemaines();

        $caisses = collect($this->rapport('2026-W41')['caisses']);

        $this->assertCount(7, $caisses);
        $this->assertSame(
            ['-500.00', '200.00', '0.00', '0.00', '0.00', '0.00', null],
            $caisses->map(fn (array $caisse) => $caisse['session']->ecart)->all(),
        );
        // Lundi a Centre-Ville : fond 5 000 + avance 1 000, compte 5 500
        $lundi = $caisses->first();
        $this->assertSame('Centre-Ville', $lundi['session']->site->nom);
        $this->assertSame('1000.00', $lundi['encaisse']);
        $this->assertSame('6000.00', $lundi['attendu']);
        // Vendredi : une avance restituee sort de la caisse
        $vendredi = $caisses->first(fn (array $caisse) => $caisse['session']->ouverte_le->equalTo(self::instant('2026-10-09 08:00')));
        $this->assertSame('-1000.00', $vendredi['encaisse']);
        $this->assertSame('4000.00', $vendredi['attendu']);
        // La caisse du dimanche soir n'est pas cloturee : attendu a la derniere synchronisation
        $ouverte = $caisses->last();
        $this->assertTrue($ouverte['session']->estOuverte());
        $this->assertSame('2000.00', $ouverte['encaisse']);
        $this->assertSame('7000.00', $ouverte['attendu']);
    }

    public function test_les_recouvrements_tardifs_sont_listes_avec_la_semaine_d_origine_de_la_commande(): void
    {
        $this->jeuDeTroisSemaines();

        $recouvrements = $this->rapport('2026-W41')['recouvrements'];

        $this->assertCount(2, $recouvrements);
        [$premier, $second] = $recouvrements;
        $this->assertSame('CV-S40-09-2026-AAAA', $premier['commande']->code);
        $this->assertSame('Centre-Ville', $premier['site']->nom);
        $this->assertSame('Alice Mbia', $premier['client']);
        $this->assertSame(Paiement::SOLDE, $premier['type']);
        $this->assertSame('4000.00', $premier['montant']);
        $this->assertSame('2026-W40', $premier['semaine_origine']->iso());
        $this->assertSame(Paiement::RESERVE, $premier['affectation']);
        $this->assertTrue($premier['encaisse_le']->equalTo(self::instant('2026-10-06 16:00')));
        $this->assertSame('MC-S40-10-2026-EEEE', $second['commande']->code);
        $this->assertSame('2000.00', $second['montant']);
    }

    public function test_un_site_desactive_ne_garde_sa_ligne_que_s_il_a_des_chiffres(): void
    {
        $ferme = $this->creerSite('DL', nom: 'Doualare');
        $ferme->update(['actif' => false]);
        $this->assertNotContains('Doualare', collect($this->rapport('2026-W41')['sites'])->pluck('site.nom')->all());

        $this->deposer($ferme, 'DL-S41-10-2026-AAAA', '2026-10-06 10:00', 2);

        $rapport = $this->rapport('2026-W41');
        $this->assertContains('Doualare', collect($rapport['sites'])->pluck('site.nom')->all());
        $this->assertSame('3000.00', $rapport['total']['montant_facture']);
    }

    public function test_les_sommes_sont_exactes_au_centime(): void
    {
        // Trois fois 0,10 ne font pas 0,30 en nombres flottants ; ici si
        $commande = $this->deposer($this->centre, 'CV-S41-10-2026-AAAA', '2026-10-06 10:00', 3, prix: '1000.10', commission: '0.10');
        $caisse = $this->caisse($this->centre, '2026-10-06 08:00');
        foreach (['10:05', '10:06', '10:07'] as $heure) {
            $this->encaisserLe($commande, $caisse, '0.10', '2026-10-06 '.$heure);
        }

        $total = $this->rapport('2026-W41')['total'];

        $this->assertSame('3000.30', $total['montant_facture']);
        $this->assertSame('0.30', $total['commissions_prevues']);
        $this->assertSame('0.30', $total['avances']);
        $this->assertSame('0.30', $total['recette']);
        $this->assertSame('3000.00', $total['reste_semaine']);
        $this->assertSame('3000.00', $total['reste_total']);
        $this->assertSame('0.00', $total['solde_previsionnel']);
    }

    public function test_un_trop_percu_n_est_pas_a_recouvrer_et_sort_de_la_recette_de_la_semaine_ou_il_est_rendu(): void
    {
        // Facture de 3 000 payee 4 000 le mardi de la semaine 41 (facture corrigee apres le versement)
        $commande = $this->deposer($this->centre, 'CV-S41-10-2026-AAAA', '2026-10-06 09:00');
        $mardi = $this->caisse($this->centre, '2026-10-06 08:00');
        $this->encaisserLe($commande, $mardi, 4000, '2026-10-06 09:05');
        $this->assertSame('-1000.00', $commande->refresh()->reste);

        // Un reste negatif n'est pas a recouvrer : il ne diminue pas ce que les autres clients doivent
        $autre = $this->deposer($this->centre, 'CV-S41-10-2026-BBBB', '2026-10-06 10:00');
        $semaine41 = $this->ligne($this->rapport('2026-W41'), $this->centre->id);
        $this->assertSame('3000.00', $semaine41['reste_semaine']);
        $this->assertSame('3000.00', $semaine41['reste_total']);
        $this->assertSame('4000.00', $semaine41['recette']);

        // Rendu au client la semaine suivante : il sort de la recette de cette semaine-la
        $lundi = $this->caisse($this->centre, '2026-10-12 08:00');
        $this->encaisserLe($commande, $lundi, 1000, '2026-10-12 09:00', Paiement::REMBOURSEMENT);
        $this->assertSame('0.00', $commande->refresh()->reste);
        $semaine42 = $this->ligne($this->rapport('2026-W42'), $this->centre->id);
        $this->assertSame('1000.00', $semaine42['restitutions']);
        $this->assertSame('-1000.00', $semaine42['recette']);
        $this->assertSame('-1000.00', $semaine42['net_encaisse']);
        $this->assertSame(bcadd($semaine42['recette'], $semaine42['recouvrements_tardifs'], 2), $semaine42['net_encaisse']);
        // La semaine du versement n'est pas reprise : la commande n'est pas annulee
        $this->assertSame('4000.00', $this->ligne($this->rapport('2026-W41'), $this->centre->id)['recette']);
        $this->assertSame($autre->site_id, $this->centre->id);
    }

    public function test_les_charges_de_l_atelier_sont_celles_des_taches_terminees_et_des_sorties_de_la_semaine(): void
    {
        $chef = $this->creerCompte('chef', Utilisateur::CHEF_ATELIER, $this->atelier);
        $moussa = Travailleur::create([
            'site_id' => $this->atelier->id, 'matricule' => 'VP-A2B3C-T', 'nom' => 'Moussa Hamadou',
            'cree_par' => $chef->id, 'enrole_le' => self::instant('2026-09-01 08:00'),
        ]);
        // Commande deposee la semaine 40, traitee a cheval sur les semaines 40 et 41
        $commande = $this->deposer($this->centre, 'CV-S40-10-2026-AAAA', '2026-10-01 10:00', quantite: 5);
        $tache = fn (int $quantite, ?string $termineeLe) => Tache::create([
            'commande_id' => $commande->id, 'ligne_commande_id' => $commande->lignes->first()->id,
            'travailleur_id' => $moussa->id, 'quantite' => $quantite, 'commission_unitaire' => '200',
            'attribuee_par' => $chef->id, 'attribuee_le' => self::instant('2026-10-02 09:00'),
            'terminee_le' => $termineeLe === null ? null : self::instant($termineeLe),
        ]);
        $tache(2, '2026-10-04 23:30');   // dimanche soir : encore la semaine 40
        $tache(2, '2026-10-05 00:30');   // lundi 00:30 a l'heure du reseau : semaine 41
        $tache(1, null);                 // attribuee mais pas terminee : rien n'est du
        $sortie = fn (string $montant, string $le) => Consommable::create([
            'site_id' => $this->atelier->id, 'utilisateur_id' => $chef->id, 'description' => 'Lessive',
            'montant' => $montant, 'sortie_le' => self::instant($le),
        ]);
        $sortie('4500', '2026-10-06 10:00');
        $sortie('1200.50', '2026-10-11 23:59');
        $sortie('9000', '2026-10-12 08:00');   // lundi suivant : semaine 42

        $rapport = $this->rapport('2026-W41');

        // RG-12 : la commission appartient a la semaine ou la tache a ete terminee, pas a celle du depot
        $atelier = $this->ligne($rapport, $this->atelier->id);
        $this->assertSame('400.00', $atelier['commissions_dues']);
        $this->assertSame('5700.50', $atelier['consommables']);
        $this->assertSame('6100.50', $atelier['charges']);
        // Seul l'atelier porte ces charges ; le reseau en est la somme
        $this->assertSame('0.00', $this->ligne($rapport, $this->centre->id)['charges']);
        $this->assertSame('6100.50', $rapport['total']['charges']);
        // La prevision faite au depot ne change pas de semaine : elle reste a la semaine 40
        $this->assertSame('0.00', $rapport['total']['commissions_prevues']);
        $this->assertSame('1000.00', $this->rapport('2026-W40')['total']['commissions_prevues']);
        $this->assertSame('400.00', $this->rapport('2026-W40')['total']['commissions_dues']);
        $this->assertSame('9000.00', $this->rapport('2026-W42')['total']['consommables']);
        // Vu d'un seul site : l'atelier garde ses charges, une boutique n'en a pas
        $this->assertSame('6100.50', $this->rapport('2026-W41', $this->atelier)['total']['charges']);
        $this->assertSame('0.00', $this->rapport('2026-W41', $this->centre)['total']['charges']);
    }
}
