<?php

namespace Tests\Feature\Promoteur;

use App\Models\AvanceCommission;
use App\Models\Commande;
use App\Models\Consommable;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use App\Models\Lot;
use App\Models\Paiement;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\Tache;
use App\Models\Travailleur;
use App\Models\Utilisateur;
use App\Models\VersementJournalier;
use App\Services\Finance\RapportPdf;
use App\Support\Audit;
use Tests\Feature\Finance\FinanceTestCase;

/** Page « Finances » et rapport financier hebdomadaire en PDF (§7, §9.3). */
class FinancesTest extends FinanceTestCase
{
    /** Un montant tel qu'il est affiche : les milliers separes par une espace insecable. */
    private static function montant(int $valeur): string
    {
        return number_format($valeur, 0, ',', "\u{00A0}");
    }

    public function test_la_page_affiche_les_chiffres_de_la_semaine_demandee(): void
    {
        $this->jeuDeTroisSemaines();

        $page = $this->actingAs($this->promoteur)->get('/finances?semaine=2026-W41')->assertOk();

        $total = $page->viewData('rapport')['total'];
        $this->assertSame('11500.00', $total['recette']);
        $this->assertSame('6000.00', $total['recouvrements_tardifs']);
        $this->assertSame('17500.00', $total['net_encaisse']);
        $this->assertSame('3500.00', $total['reste_semaine']);
        $this->assertSame('2000.00', $total['commissions_prevues']);
        $this->assertSame('9500.00', $total['solde_previsionnel']);

        $page->assertSee('Finances — Semaine 41 de 2026')
            ->assertSee('Du lundi 5 octobre 2026 au dimanche 11 octobre 2026')
            ->assertSeeInOrder([
                'Recette de la semaine', self::montant(11500),
                'Recouvrements tardifs', self::montant(6000),
                'Net encaissé', self::montant(17500),
                'Reste à recouvrer', self::montant(3500),
                'Commissions prévues', 'Prévision', self::montant(2000),
                'Solde prévisionnel', 'Provisoire', self::montant(9500), 'Excédent',
            ])
            // Par site, puis le total du reseau
            ->assertSeeInOrder(['Centre-Ville', self::montant(12000), self::montant(7000), self::montant(8000), self::montant(14000)])
            ->assertSeeInOrder(['Réseau entier (FCFA)', self::montant(15000), self::montant(8500), self::montant(10000), self::montant(17500)])
            // Par jour
            ->assertSeeInOrder(['Lundi 05/10', 'Mardi 06/10', 'Mercredi 07/10', 'Jeudi 08/10', 'Vendredi 09/10', 'Samedi 10/10', 'Dimanche 11/10'])
            // Caisses : l'ecart est dit en toutes lettres
            ->assertSeeInOrder(['7 sessions ouvertes dans la semaine', '6 clôturées', '1 encore ouverte'])
            ->assertSee('Manque 500'."\u{00A0}".'FCFA')
            ->assertSee('Excédent 200'."\u{00A0}".'FCFA')
            ->assertSee('Aucun écart')
            // Recouvrements tardifs
            ->assertSeeInOrder(['CV-S40-09-2026-AAAA', 'Alice Mbia', 'Solde', '2026-S40', 'Réserve', self::montant(4000)])
            ->assertSee('MC-S40-10-2026-EEEE')
            ->assertDontSee('MC-S42-10-2026-GGGG')
            // Ce qui reste provisoire est dit
            // Charges de l'atelier : commissions dues et consommables de la semaine
            ->assertSeeInOrder(["Charges de l'atelier", 'Commissions dues', 'Consommables', 'Total des charges', 'Atelier Central'], false)
            ->assertSee('Ce qui est provisoire ou pas encore compté')
            ->assertSee('Commissions prévues et commissions dues.')
            ->assertSee('Télécharger le rapport (PDF)');
    }

    public function test_une_avance_rendue_une_autre_semaine_est_reprise_sur_la_sienne_et_la_page_le_dit(): void
    {
        // Avance de 2 000 recue en semaine 40 ; la commande est annulee, l'avance rendue le mercredi de la semaine 41
        $annulee = $this->deposer($this->centre, 'CV-S40-09-2026-AAAA', '2026-09-30 10:00', 4, Commande::ANNULEE);
        $this->encaisserLe($annulee, $this->caisse($this->centre, '2026-09-30 08:00', '2026-09-30 18:00', 7000), 2000, '2026-09-30 10:05');
        $this->encaisserLe($annulee, $this->caisse($this->centre, '2026-10-07 08:00', '2026-10-07 18:00', 3000), 2000, '2026-10-07 09:00', Paiement::REMBOURSEMENT);
        $this->actingAs($this->promoteur);
        $deux = '>'.self::montant(2000).'<';

        // Semaine de l'avance : elle y est reprise, alors que rien n'est sorti de ses caisses
        $page = $this->get('/finances?semaine=2026-W40')->assertOk();
        $this->assertSame('2000.00', $page->viewData('rapport')['total']['restitutions']);
        $this->assertSame('0.00', $page->viewData('rapport')['total']['recette']);
        $page->assertSeeInOrder(['Centre-Ville', '>0<', '>0<', $deux, '>0<', $deux, '>0<'], false)
            ->assertSeeInOrder(['Total des journées (FCFA)', '>0<', '>0<', $deux, '>0<', '>0<', $deux], false);

        // Semaine de la restitution : rien pour la semaine, mais la sortie de caisse du mercredi dans les journees
        $page = $this->get('/finances?semaine=2026-W41')->assertOk();
        $this->assertSame('0.00', $page->viewData('rapport')['total']['restitutions']);
        $this->assertSame('0.00', $page->viewData('rapport')['total']['net_encaisse']);
        $page->assertSeeInOrder([
            'Mercredi 07/10', $deux, '>-'.self::montant(2000).'<',
            'Total des journées (FCFA)', $deux, '>-'.self::montant(2000).'<',
        ], false)
            ->assertSee('le total des journées et la semaine peuvent donc différer')
            ->assertSee('Recette de la semaine + recouvrements tardifs = net encaissé')
            ->assertSee('est retirée de la semaine où elle avait été reçue');

        // Le rapport PDF le dit de la meme facon
        $html = app(RapportPdf::class)->html($this->rapport('2026-W41'), 'Nom patron');
        foreach ([
            'Total des journées',
            'le total des journées et la semaine peuvent donc différer',
            'Recette de la semaine + recouvrements tardifs = net encaissé',
            'est retirée de la semaine où elle avait été reçue',
        ] as $texte) {
            $this->assertStringContainsString($texte, $html);
        }
    }

    public function test_sans_semaine_demandee_la_page_montre_la_semaine_en_cours(): void
    {
        $this->jeuDeTroisSemaines();

        $page = $this->actingAs($this->promoteur)->get('/finances')->assertOk();

        $this->assertSame('2026-W42', $page->viewData('semaine')->iso());
        $this->assertSame('500.00', $page->viewData('rapport')['total']['recette']);
        $page->assertSee('Finances — Semaine 42 de 2026')->assertSee('Semaine en cours');
    }

    public function test_la_navigation_passe_d_une_semaine_a_l_autre_sans_aller_dans_l_avenir(): void
    {
        $this->actingAs($this->promoteur);
        $adresse = fn (string $semaine) => e(route('finances.index', ['semaine' => $semaine]));

        // Semaine passee : precedente, suivante, et retour a la semaine en cours
        $this->get('/finances?semaine=2026-W41')->assertOk()
            ->assertSee('href="'.$adresse('2026-W40').'" rel="prev"', false)
            ->assertSee('href="'.$adresse('2026-W42').'" rel="next"', false)
            ->assertSee('href="'.route('finances.index').'">Cette semaine</a>', false)
            ->assertDontSee('Semaine en cours');

        // Semaine en cours : pas de lien vers une semaine qui n'a pas commence
        $this->get('/finances')->assertOk()
            ->assertSee('href="'.$adresse('2026-W41').'" rel="prev"', false)
            ->assertDontSee($adresse('2026-W43'), false)
            ->assertDontSee('rel="next"', false);

        // Au passage d'une annee a l'autre, la numerotation ISO est suivie
        $this->get('/finances?semaine=2026-W01')->assertOk()
            ->assertSee('href="'.$adresse('2025-W52').'" rel="prev"', false)
            ->assertSee('href="'.$adresse('2026-W02').'" rel="next"', false);

        // Une semaine a venir se consulte, et le dit
        $this->get('/finances?semaine=2026-W45')->assertOk()->assertSee("Cette semaine n'a pas encore commencé", false);
    }

    public function test_le_filtre_par_site_est_garde_par_la_navigation_et_par_le_rapport(): void
    {
        $this->jeuDeTroisSemaines();
        $this->actingAs($this->promoteur);

        $page = $this->get('/finances?semaine=2026-W41&site='.$this->marche->id)->assertOk();

        $this->assertSame('1500.00', $page->viewData('rapport')['total']['recette']);
        $this->assertCount(1, $page->viewData('rapport')['sites']);
        $page->assertSee('Marché Central')
            ->assertDontSee('CV-S40-09-2026-AAAA')
            ->assertSee(e(route('finances.index', ['semaine' => '2026-W40', 'site' => $this->marche->id])), false)
            ->assertSee(e(route('finances.rapport', ['semaine' => '2026-W41', 'site' => $this->marche->id])), false);
    }

    public function test_une_semaine_mal_formee_ou_un_site_inconnu_sont_refuses_proprement(): void
    {
        $this->actingAs($this->promoteur);

        foreach (['2026-41', '2026-W54', '2027-W53', 'hier', '2026-W41-CV'] as $semaine) {
            $this->get('/finances?semaine='.$semaine)
                ->assertRedirect(route('finances.index'))
                ->assertSessionHasErrors(['semaine' => "La semaine demandée n'existe pas. Elle s'écrit avec l'année et le numéro de semaine, par exemple 2026-W41."]);
        }
        $this->get('/finances?semaine[]=2026-W41')->assertRedirect(route('finances.index'))->assertSessionHasErrors('semaine');
        $this->get('/finances?site=pas-un-identifiant')->assertRedirect(route('finances.index'))->assertSessionHasErrors('site');
        $this->get('/finances?site='.str()->uuid())->assertRedirect(route('finances.index'))->assertSessionHasErrors('site');

        // Le refus s'affiche sur la page de la semaine en cours
        $this->followingRedirects()->get('/finances?semaine=2026-41')
            ->assertOk()
            ->assertSee("La semaine demandée n'existe pas.")
            ->assertSee('Finances — Semaine 42 de 2026');

        // Meme refus pour le rapport : aucun fichier, aucune ligne au journal
        $this->get('/finances/rapport.pdf?semaine=2026-41')->assertRedirect(route('finances.index'))->assertSessionHasErrors('semaine');
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_sans_session_ni_la_page_ni_le_rapport_ne_sont_servis(): void
    {
        $this->get('/finances')->assertRedirect(route('connexion'));
        $this->get('/finances?semaine=2026-W41')->assertRedirect(route('connexion'));
        $this->get('/finances/rapport.pdf?semaine=2026-W41')->assertRedirect(route('connexion'));

        // Un compte de site, meme connecte par un autre moyen, n'entre pas non plus
        $this->actingAs($this->aicha)->get('/finances/rapport.pdf')->assertRedirect(route('connexion'));
        $this->assertGuest();

        $this->assertSame(0, JournalAudit::count());
    }

    public function test_le_menu_et_le_tableau_de_bord_menent_aux_finances(): void
    {
        $this->actingAs($this->promoteur);

        $this->get('/tableau-de-bord')->assertOk()
            ->assertSeeInOrder(['Caisses des sites', 'Finances', 'Messages WhatsApp'])
            ->assertSee('Finances de la semaine')
            ->assertSee('href="'.route('finances.index').'"', false);
        $this->get('/finances')->assertOk()->assertSee('aria-current="page"', false);
    }

    public function test_le_rapport_se_telecharge_en_pdf_et_laisse_une_ligne_au_journal(): void
    {
        $this->jeuDeTroisSemaines();

        $reponse = $this->actingAs($this->promoteur)->get('/finances/rapport.pdf?semaine=2026-W41')->assertOk();

        $reponse->assertHeader('Content-Type', 'application/pdf');
        $reponse->assertHeader('Content-Disposition', 'attachment; filename="rapport-financier-2026-S41.pdf"');
        $this->assertStringStartsWith('%PDF-', $reponse->getContent());
        $this->assertStringContainsString('%%EOF', substr($reponse->getContent(), -32));
        // Format A4 : 595,28 x 841,89 points
        $this->assertMatchesRegularExpression('#/MediaBox \[0(\.0+)? 0(\.0+)? 595\.2\d* 841\.8\d*\]#', $reponse->getContent());

        $ligne = JournalAudit::sole();
        $this->assertSame(Audit::RAPPORT_FINANCIER_EXPORTE, $ligne->action);
        $this->assertSame($this->promoteur->nom, $ligne->auteur);
        $this->assertSame($this->promoteur->id, $ligne->utilisateur_id);
        $this->assertSame('Semaine 41 de 2026 — réseau entier', $ligne->objet_libelle);
        $this->assertNull($ligne->objet_id);
        $this->assertStringContainsString('Semaine 2026-W41, du 05/10/2026 au 11/10/2026', $ligne->detail);
        $this->assertStringContainsString('périmètre : réseau entier', $ligne->detail);
        $this->assertStringContainsString('rapport-financier-2026-S41.pdf', $ligne->detail);

        $this->get('/journal?action='.Audit::RAPPORT_FINANCIER_EXPORTE)->assertOk()
            ->assertSee('Rapport financier exporté (PDF)')
            ->assertSee('Semaine 41 de 2026 — réseau entier');
    }

    public function test_le_rapport_d_un_site_porte_son_code_dans_le_nom_du_fichier(): void
    {
        $this->jeuDeTroisSemaines();

        $reponse = $this->actingAs($this->promoteur)
            ->get('/finances/rapport.pdf?semaine=2026-W41&site='.$this->centre->id)
            ->assertOk();

        $reponse->assertHeader('Content-Disposition', 'attachment; filename="rapport-financier-2026-S41-CV.pdf"');
        $this->assertStringStartsWith('%PDF-', $reponse->getContent());

        $ligne = JournalAudit::sole();
        $this->assertSame('sites', $ligne->objet_type);
        $this->assertSame($this->centre->id, $ligne->objet_id);
        $this->assertSame('Semaine 41 de 2026 — Centre-Ville', $ligne->objet_libelle);
        $this->assertStringContainsString('périmètre : site CV — Centre-Ville', $ligne->detail);
    }

    public function test_sans_semaine_le_rapport_est_celui_de_la_semaine_en_cours(): void
    {
        $this->actingAs($this->promoteur)->get('/finances/rapport.pdf')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="rapport-financier-2026-S42.pdf"');
    }

    public function test_la_page_du_rapport_porte_les_chiffres_de_la_semaine(): void
    {
        $this->jeuDeTroisSemaines();

        $html = app(RapportPdf::class)->html($this->rapport('2026-W41'), 'Nom patron');

        foreach ([
            'Rapport financier hebdomadaire',
            'Semaine 41 de 2026',
            'du lundi 5 octobre 2026 au dimanche 11 octobre 2026',
            'Réseau entier — 3 sites',
            // « maintenant » est le lundi 12 octobre a 09:00, heure du reseau
            'Édité le 12/10/2026 à 09:00 par Nom patron',
            'Synthèse',
            'Par site — commandes déposées et encaissements',
            'Par site — recette, recouvrement et prévision',
            'Par jour',
            'Caisses de la semaine',
            '7 sessions ouvertes dans la semaine',
            'Manque 500'."\u{00A0}".'FCFA',
            'Excédent 200'."\u{00A0}".'FCFA',
            'Encore ouverte',
            'Recouvrements tardifs',
            'CV-S40-09-2026-AAAA',
            'MC-S40-10-2026-EEEE',
            '2026-S40',
            'Réserve',
            "Charges de l'atelier",
            'Commissions<br>dues',
            'Ce que ce rapport ne compte pas encore',
            'Commissions prévues et commissions dues.',
            'Solde prévisionnel.',
            'Emprunts et réserve.',
            'DejaVu Sans',
        ] as $texte) {
            $this->assertStringContainsString($texte, $html);
        }

        // Synthese, dans l'ordre des tuiles
        $this->assertMatchesRegularExpression(
            '/Recette de la semaine.*?'.self::montant(11500).'.*?Recouvrements tardifs.*?'.self::montant(6000)
            .'.*?Net encaissé.*?'.self::montant(17500).'.*?Reste à recouvrer.*?'.self::montant(3500)
            .'.*?Commissions prévues.*?'.self::montant(2000).'.*?Solde prévisionnel.*?'.self::montant(9500).'/su',
            $html,
        );
        // Ligne de Centre-Ville puis total du reseau, dans le premier tableau par site
        $this->assertMatchesRegularExpression(
            '/Centre-Ville.*?>2<.*?'.self::montant(12000).'.*?'.self::montant(7000).'.*?'.self::montant(8000).'.*?'.self::montant(1000).'.*?'.self::montant(14000)
            .'.*?Réseau entier<.*?>3<.*?'.self::montant(15000).'.*?'.self::montant(8500).'.*?'.self::montant(10000).'.*?'.self::montant(17500).'/su',
            $html,
        );
        // Une commande d'une autre semaine n'y figure pas
        $this->assertStringNotContainsString('MC-S42-10-2026-GGGG', $html);
        // Le logo vient du disque, pas du reseau ; sans l'extension gd, le rapport sort sans lui
        if (extension_loaded('gd')) {
            $this->assertStringContainsString(public_path('images/logo-visuelplus.png'), $html);
        }
        $this->assertStringNotContainsString('http', $html);
    }

    public function test_la_page_du_rapport_d_un_site_ne_montre_que_ce_site(): void
    {
        $this->jeuDeTroisSemaines();

        $html = app(RapportPdf::class)->html($this->rapport('2026-W41', $this->marche), 'Nom patron');

        $this->assertStringContainsString('Marché Central (Boutique MC)', $html);
        $this->assertStringNotContainsString('Centre-Ville', $html);
        $this->assertStringNotContainsString('Réseau entier', $html);
        $this->assertStringContainsString('MC-S40-10-2026-EEEE', $html);
        $this->assertSame('rapport-financier-2026-S41-MC.pdf', app(RapportPdf::class)->nomFichier($this->semaine(), $this->marche));
        $this->assertSame('rapport-financier-2026-S41.pdf', app(RapportPdf::class)->nomFichier($this->semaine()));
    }

    public function test_un_rapport_sans_aucune_activite_se_fabrique_quand_meme(): void
    {
        $reponse = $this->actingAs($this->promoteur)->get('/finances/rapport.pdf?semaine=2026-W30')->assertOk();

        $this->assertStringStartsWith('%PDF-', $reponse->getContent());
        $html = app(RapportPdf::class)->html($this->rapport('2026-W30'), 'Nom patron');
        $this->assertStringContainsString('Aucune session de caisse n\'a été ouverte cette semaine.', $html);
        $this->assertStringContainsString('Aucun encaissement de la semaine ne porte sur une commande d\'une semaine antérieure.', $html);
    }

    public function test_le_rapport_d_un_grand_reseau_laisse_ses_tableaux_par_site_passer_d_une_page_a_l_autre(): void
    {
        $this->jeuDeTroisSemaines();
        $pdf = app(RapportPdf::class);

        // Trois sites : synthese, les deux tableaux par site, les charges de l'atelier et le tableau
        // par jour ne sont pas coupes
        $this->assertSame(5, substr_count($pdf->html($this->rapport('2026-W41'), 'Nom patron'), 'class="bloc-entier"'));

        for ($rang = 1; $rang <= 11; $rang++) {
            $this->creerSite(sprintf('B%02d', $rang));
        }

        // Quatorze sites : un tableau par site peut ne plus tenir sur une page, il doit pouvoir etre coupe
        $rapport = $this->rapport('2026-W41');
        $this->assertCount(14, $rapport['sites']);
        // les charges de l'atelier, qui ne listent que lui, restent d'un seul tenant
        $this->assertSame(3, substr_count($pdf->html($rapport, 'Nom patron'), 'class="bloc-entier"'));
        $contenu = $pdf->generer($rapport, 'Nom patron');
        $this->assertStringStartsWith('%PDF-', $contenu);
        $this->assertGreaterThan(1, preg_match_all('#/Type /Page\b(?!s)#', $contenu));
    }

    public function test_un_compte_promoteur_desactive_n_exporte_plus(): void
    {
        $this->actingAs($this->promoteur);
        $this->promoteur->update(['actif' => false]);

        $this->get('/finances/rapport.pdf')->assertRedirect(route('connexion'));
        $this->assertSame(0, JournalAudit::count());
        $this->assertSame(Utilisateur::PROMOTEUR, $this->promoteur->role);
    }

    public function test_le_rapport_hebdomadaire_fournit_les_quatre_sections_d_exploitation_consolidees(): void
    {
        $this->jeuDeTroisSemaines();
        $chef = $this->creerCompte('chef', Utilisateur::CHEF_ATELIER, $this->atelier);

        // Penderie : la commande ZZZZ est revenue en boutique le 24 septembre et n'est pas retiree
        $zzzz = Commande::where('code', 'CV-S39-09-2026-ZZZZ')->firstOrFail();
        $retour = Lot::create([
            'sens' => Lot::RETOUR,
            'site_origine_id' => $this->atelier->id,
            'site_destination_id' => $this->centre->id,
            'cloture_par' => $chef->id,
            'cloture_le' => self::instant('2026-09-24 08:00'),
            'nombre_annonce' => 1,
            'recu_par' => $this->aicha->id,
            'recu_le' => self::instant('2026-09-24 10:00'),
            'nombre_recu' => 1,
        ]);
        $retour->commandes()->attach($zzzz->id);

        // Une commande remise au client pendant la semaine
        Commande::where('code', 'CV-S41-10-2026-BBBB')->firstOrFail()->update([
            'statut' => Commande::LIVREE,
            'livree_le' => self::instant('2026-10-09 17:00'),
            'livree_par' => $this->aicha->id,
        ]);

        // Deux chemises lavees puis repassees, et un article lave qui n'est pas repasse : trois pieces
        $c = Commande::where('code', 'CV-S41-10-2026-CCCC')->firstOrFail();
        $laveur = $this->travailleur('VP-AB123-T', Travailleur::LAVEUR, $chef);
        $repasseur = $this->travailleur('VP-CD456-T', Travailleur::REPASSEUR, $chef);
        $ligne = $c->lignes()->firstOrFail();
        $this->tacheTerminee($c, $ligne, $laveur, Tache::LAVAGE, 2, '2026-10-07 14:00', $chef);
        $this->tacheTerminee($c, $ligne, $repasseur, Tache::REPASSAGE, 2, '2026-10-08 10:00', $chef);
        $sansRepassage = LigneCommande::create([
            'commande_id' => $c->id, 'tarif_id' => $this->chemise->id, 'quantite' => 1,
            'prix_applique' => '1500', 'commission_appliquee' => '100', 'avec_repassage' => false,
        ]);
        $this->tacheTerminee($c, $sansRepassage, $laveur, Tache::LAVAGE, 1, '2026-10-09 10:00', $chef);

        // Versements de la semaine : celui du lundi recu avec 500 de moins, celui du mardi en route ;
        // celui de la semaine 40 n'est pas compte
        $sessions = SessionCaisse::where('site_id', $this->centre->id)->orderBy('ouverte_le')->get();
        $this->versement($sessions[0], '2026-09-30', '7000', $this->atelier, $chef, '7000');
        $this->versement($sessions[1], '2026-10-05', '5500', $this->atelier, $chef, '5000');
        $this->versement($sessions[2], '2026-10-06', '9200');

        // Achats de l'atelier par categorie et avance sur commission (decisions du 23 octobre 2026)
        Consommable::create(['site_id' => $this->atelier->id, 'utilisateur_id' => $chef->id, 'categorie' => 'CONSOMMABLES',
            'description' => 'Lessive', 'montant' => '1000', 'sortie_le' => self::instant('2026-10-06 10:00')]);
        Consommable::create(['site_id' => $this->atelier->id, 'utilisateur_id' => $chef->id, 'categorie' => 'ENERGIE',
            'description' => 'Électricité', 'montant' => '2500', 'sortie_le' => self::instant('2026-10-08 10:00')]);
        AvanceCommission::create(['site_id' => $this->atelier->id, 'travailleur_id' => $laveur->id, 'montant' => '150',
            'versee_par' => $chef->id, 'versee_le' => self::instant('2026-10-08 18:00')]);

        $rapport = $this->rapport('2026-W41');
        $sec = $rapport['sections_consolidees'];

        $this->assertSame('1000.00', $rapport['total']['consommables']);
        $this->assertSame('2500.00', $rapport['total']['achats']);
        $this->assertSame('150.00', $rapport['total']['avances_commission']);
        // charges = commissions dues (5 taches a 100) + consommables + autres achats ; l'avance n'en est pas
        $this->assertSame('500.00', $rapport['total']['commissions_dues']);
        $this->assertSame('4000.00', $rapport['total']['charges']);
        $this->assertSame('2500.00', $sec['section_3_charges']['achats_par_categorie']['Énergie (eau, électricité, carburant)']);
        $this->assertSame('1000.00', $sec['section_3_charges']['achats_par_categorie']['Consommables']);
        $this->assertSame('350.00', $sec['section_3_charges']['commissions_restant_a_payer']);
        // fonds de l'atelier : versement du lundi recu dans la semaine, moins achats et avance
        $this->assertSame('5000.00', $sec['section_4_marge']['fonds_recus']);
        $this->assertSame('3650.00', $sec['section_4_marge']['fonds_depenses']);
        $this->assertSame('1350.00', $sec['section_4_marge']['fonds_reste']);

        $this->assertSame(3, $sec['section_1_activite']['commandes_enregistrees']);
        $this->assertSame(3, $sec['section_1_activite']['pieces_traitees']);
        $this->assertSame(1, $sec['section_1_activite']['commandes_retirees']);
        $this->assertSame(1, $sec['section_1_activite']['penderie_plus_14j']);
        $this->assertSame('300.00', $sec['section_3_charges']['commissions_laveurs']);
        $this->assertSame('200.00', $sec['section_3_charges']['commissions_repasseurs']);
        $this->assertSame(2, $sec['section_4_marge']['versements_nombre']);
        $this->assertSame('14700.00', $sec['section_4_marge']['versements_declares']);
        $this->assertSame('5000.00', $sec['section_4_marge']['versements_recus']);
        $this->assertSame('-500.00', $sec['section_4_marge']['versements_ecart']);
        $this->assertSame('9200.00', $sec['section_4_marge']['versements_en_attente']);
        $this->assertSame(1, $sec['section_4_marge']['versements_en_attente_nombre']);

        // Par site : les pieces sont celles des travailleurs du site, les versements ceux de la
        // boutique, ou ceux que l'atelier a recus
        $atelier = $this->rapport('2026-W41', $this->atelier)['sections_consolidees'];
        $this->assertSame(3, $atelier['section_1_activite']['pieces_traitees']);
        $this->assertSame(1, $atelier['section_4_marge']['versements_nombre']);
        $centre = $this->rapport('2026-W41', $this->centre)['sections_consolidees'];
        $this->assertSame(0, $centre['section_1_activite']['pieces_traitees']);
        $this->assertSame(2, $centre['section_4_marge']['versements_nombre']);
        $this->assertSame(1, $centre['section_1_activite']['penderie_plus_14j']);
        $marche = $this->rapport('2026-W41', $this->marche)['sections_consolidees'];
        $this->assertSame(0, $marche['section_1_activite']['penderie_plus_14j']);

        // Une semaine passee ne change plus : ZZZZ remise bien plus tard reste en penderie en semaine 41
        $this->travelTo(self::instant('2026-11-20 09:00'));
        $zzzz->update(['statut' => Commande::LIVREE, 'livree_le' => self::instant('2026-11-19 10:00')]);
        $this->assertSame(1, $this->rapport('2026-W41')['sections_consolidees']['section_1_activite']['penderie_plus_14j']);

        $html = app(RapportPdf::class)->html($rapport, 'Nom patron');
        $this->assertStringContainsString('Indicateurs consolidés d\'exploitation (4 Sections)', $html);
        $this->assertStringContainsString('1. Activité Physique', $html);
        $this->assertStringContainsString('2. Encaissements &amp; Créances', $html);
        $this->assertStringContainsString('3. Charges d\'Exploitation', $html);
        $this->assertStringContainsString('4. Marge &amp; Versements', $html);
        $this->assertStringContainsString('Reçus à l\'atelier', $html);

        // La page montre les memes sections
        $this->actingAs($this->promoteur);
        $this->get('/finances?semaine=2026-W41')
            ->assertOk()
            ->assertSee('Exploitation de la semaine')
            ->assertSee('Écart sur les versements reçus');
    }

    private function travailleur(string $matricule, string $role, Utilisateur $chef): Travailleur
    {
        return Travailleur::create([
            'site_id' => $this->atelier->id,
            'matricule' => $matricule,
            'nom' => 'Travailleur '.$matricule,
            'role' => $role,
            'cree_par' => $chef->id,
            'enrole_le' => self::instant('2026-10-01 08:00'),
        ]);
    }

    private function tacheTerminee(Commande $commande, LigneCommande $ligne, Travailleur $travailleur, string $etape, int $quantite, string $le, Utilisateur $chef): Tache
    {
        return Tache::create([
            'commande_id' => $commande->id,
            'ligne_commande_id' => $ligne->id,
            'travailleur_id' => $travailleur->id,
            'etape' => $etape,
            'quantite' => $quantite,
            'commission_unitaire' => '100',
            'attribuee_par' => $chef->id,
            'attribuee_le' => self::instant($le)->subHours(2),
            'terminee_le' => self::instant($le),
        ]);
    }

    private function versement(SessionCaisse $session, string $date, string $declare, ?Site $atelier = null, ?Utilisateur $chef = null, ?string $recu = null): VersementJournalier
    {
        return VersementJournalier::create([
            'site_id' => $session->site_id,
            'session_caisse_id' => $session->id,
            'date_versement' => $date,
            'montant_declare' => $declare,
            'remis_par' => $session->ouverte_par,
            'declare_le' => self::instant($date.' 18:00'),
            'site_reception_id' => $atelier?->id,
            'recu_par' => $chef?->id,
            'recu_le' => $recu === null ? null : self::instant($date.' 20:00'),
            'montant_recu' => $recu,
        ]);
    }
}
