<?php

namespace Tests\Feature\Promoteur;

use App\Models\Client;
use App\Models\Commande;
use App\Models\Controle;
use App\Models\DemandeAnnulation;
use App\Models\JournalAudit;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\Utilisateur;
use App\Services\Atelier\Corrections;
use App\Support\Audit;
use RuntimeException;

/**
 * Demandes d'annulation tranchees par le Promoteur (RG-24), commandes en attente de l'accord du
 * client (RG-09), et les reperes qui y menent : menu, tableau de bord, fiche d'une commande.
 */
class AnnulationsTest extends PromoteurTestCase
{
    private Site $boutique;

    private Site $atelier;

    private Utilisateur $aicha;

    private Client $alice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boutique = $this->creerSite('CV', Site::BOUTIQUE, 'Centre-Ville');
        $this->atelier = $this->creerSite('ATL', Site::ATELIER, 'Atelier Central');
        $this->aicha = $this->creerCompte('aicha', Utilisateur::RECEPTIONNISTE, $this->boutique);
        $this->alice = $this->creerClient($this->boutique);
        $this->actingAs($this->promoteur);
    }

    private function commande(string $suffixe, string $statut = Commande::ENREGISTREE): Commande
    {
        return $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-'.$suffixe, $statut);
    }

    /** Demande telle que le poste de la boutique l'a synchronisee. */
    private function demander(Commande $commande, string $motif = 'Le client a changé de pressing', mixed $le = null): DemandeAnnulation
    {
        return DemandeAnnulation::create([
            'commande_id' => $commande->id,
            'demandee_par' => $this->aicha->id,
            'motif' => $motif,
            'demandee_le' => $le ?? now()->subMinutes(20),
        ]);
    }

    /** Lot aller qui emporte la commande vers l'atelier ; en route tant qu'il n'est pas recu. */
    private function envoyer(Commande $commande, mixed $recuLe = null): Lot
    {
        $lot = Lot::create([
            'sens' => Lot::ALLER,
            'site_origine_id' => $this->boutique->id,
            'site_destination_id' => $this->atelier->id,
            'cloture_par' => $this->aicha->id,
            'cloture_le' => now()->subHours(3),
            'nombre_annonce' => 1,
            'recu_le' => $recuLe,
            'nombre_recu' => $recuLe === null ? null : 1,
        ]);
        $lot->commandes()->attach($commande->id);

        return $lot;
    }

    /** Ecart releve au controle : une chemise au lieu de deux, correction proposee. */
    private function correction(Commande $commande, mixed $controleLe, string $etat = Controle::PROPOSEE): Controle
    {
        $ligne = $commande->lignes->first();

        return Controle::create([
            'commande_id' => $commande->id,
            'utilisateur_id' => $this->aicha->id,
            'resultat' => Controle::ECART,
            'remarque' => 'Une seule chemise dans le sac',
            'controle_le' => $controleLe,
            // recu par le serveur a l'heure du controle : c'est de la que court le delai d'accord
            'created_at' => $controleLe,
            'ligne_commande_id' => $ligne->id,
            'tarif_id' => $ligne->tarif_id,
            'quantite_avant' => 2,
            'quantite_apres' => 1,
            'etat' => $etat,
        ]);
    }

    private function delaiDAccord(?string $jours): void
    {
        Parametre::updateOrCreate(['cle' => Parametre::DELAI_ACCORD_ANOMALIE_JOURS], ['valeur' => $jours, 'libelle' => 'Délai d\'accord']);
    }

    // ------------------------------------------------------------------ acces

    public function test_les_demandes_d_annulation_et_les_accords_sont_reserves_au_promoteur(): void
    {
        $commande = $this->commande('AAAA');
        $demande = $this->demander($commande);
        auth()->logout();

        foreach (['/annulations', '/accords'] as $page) {
            $this->get($page)->assertRedirect(route('connexion'));
        }
        $this->post(route('annulations.confirmer', $demande))->assertRedirect(route('connexion'));
        $this->post(route('annulations.refuser', $demande))->assertRedirect(route('connexion'));

        // Un compte de site, meme connecte par un autre moyen, ne decide de rien
        $this->actingAs($this->aicha)->post(route('annulations.confirmer', $demande))->assertRedirect(route('connexion'));
        $this->assertGuest();

        $this->assertSame(DemandeAnnulation::DEMANDEE, $demande->refresh()->etat);
        $this->assertSame(Commande::ENREGISTREE, $commande->refresh()->statut);
        $this->assertSame(0, JournalAudit::count());
    }

    // ------------------------------------------------------------------ confirmation

    public function test_confirmer_annule_la_commande_et_previent_la_boutique_et_l_atelier(): void
    {
        $commande = $this->commande('AAAA', Commande::ENVOYEE);
        // Partie dans un lot que l'atelier n'a pas encore recu
        $this->envoyer($commande);
        $this->encaisser($commande, $this->ouvrirCaisse($this->boutique, $this->aicha), 1000);
        $demande = $this->demander($commande);
        $this->assertSame('2000.00', $commande->refresh()->reste);
        $depuis = $this->dernierChangement();

        $this->post(route('annulations.confirmer', $demande))
            ->assertRedirect(route('annulations.index'))
            ->assertSessionHas('succes', "La commande CV-S41-10-2026-AAAA est annulée. Centre-Ville doit restituer au client son avance de 1\u{00A0}000\u{00A0}FCFA.");

        $demande->refresh();
        $this->assertSame(DemandeAnnulation::CONFIRMEE, $demande->etat);
        $this->assertSame($this->promoteur->id, $demande->decidee_par);
        $this->assertNotNull($demande->decidee_le);
        $this->assertNull($demande->motif_refus);
        // RG-24 : annulee, la commande n'a plus de reste a payer ; son avance reste a restituer
        $commande->refresh();
        $this->assertSame(Commande::ANNULEE, $commande->statut);
        $this->assertSame('3000.00', $commande->montant);
        $this->assertSame('1000.00', $commande->total_paye);
        $this->assertSame('0.00', $commande->reste);

        // La boutique, puis l'atelier a qui elle a ete envoyee : il ne doit pas la traiter
        $publiees = $this->changements('commandes', $depuis);
        $this->assertSame([$this->boutique->id, $this->atelier->id], $publiees->pluck('site_id')->all());
        foreach ($publiees as $publiee) {
            $this->assertSame('ANNULEE', $publiee->donnees['statut']);
            $this->assertSame('0.00', $publiee->donnees['reste']);
            $this->assertSame('1000.00', $publiee->donnees['total_paye']);
            // §4.6 : decision du Promoteur, transmise en priorite a tous les postes concernes
            $this->assertTrue($publiee->urgent);
            $this->assertNull($publiee->origine_poste_id);
        }
        $decision = $this->changements('demandes_annulation', $depuis)->sole();
        $this->assertSame($this->boutique->id, $decision->site_id);
        $this->assertSame('CONFIRMEE', $decision->donnees['etat']);
        $this->assertSame($demande->decidee_le->toIso8601ZuluString(), $decision->donnees['decidee_le']);
        $this->assertTrue($decision->urgent);
        // Le poste ne voit la demande confirmee qu'une fois la commande annulee
        $this->assertGreaterThan($publiees->max('seq'), $decision->seq);

        $ligne = JournalAudit::sole();
        $this->assertSame(Audit::ANNULATION_CONFIRMEE, $ligne->action);
        $this->assertSame($this->promoteur->id, $ligne->utilisateur_id);
        $this->assertSame('commandes', $ligne->objet_type);
        $this->assertSame($commande->id, $ligne->objet_id);
        $this->assertSame('CV-S41-10-2026-AAAA', $ligne->objet_libelle);
        $this->assertStringContainsString('Le client a changé de pressing', $ligne->detail);
        $this->assertStringContainsString("avance à restituer au client : 1\u{00A0}000\u{00A0}FCFA", $ligne->detail);
        $this->get('/journal?action='.Audit::ANNULATION_CONFIRMEE)->assertOk()
            ->assertSee('Annulation confirmée')->assertSee('CV-S41-10-2026-AAAA');
    }

    public function test_une_commande_restee_en_boutique_et_sans_avance_n_est_annoncee_qu_a_sa_boutique(): void
    {
        $commande = $this->commande('AAAA');
        $demande = $this->demander($commande);
        $depuis = $this->dernierChangement();

        $this->post(route('annulations.confirmer', $demande))
            ->assertSessionHas('succes', "La commande CV-S41-10-2026-AAAA est annulée. Aucune avance n'est à restituer.");

        $this->assertSame(Commande::ANNULEE, $commande->refresh()->statut);
        $this->assertSame('0.00', $commande->reste);
        $this->assertSame([$this->boutique->id], $this->changements('commandes', $depuis)->pluck('site_id')->all());
        $this->assertStringContainsString('aucune avance à restituer', JournalAudit::sole()->detail);
    }

    public function test_une_correction_proposee_ne_s_applique_plus_a_une_commande_annulee(): void
    {
        $this->delaiDAccord('1');
        $annulee = $this->commande('AAAA', Commande::EN_ATTENTE_ACCORD);
        $this->envoyer($annulee, now()->subDays(6));
        $sansSuite = $this->correction($annulee, now()->subDays(5));
        // Temoin : meme ecart, meme anciennete, mais la commande n'est pas annulee
        $bloquee = $this->commande('BBBB', Commande::EN_ATTENTE_ACCORD);
        $echue = $this->correction($bloquee, now()->subDays(5));

        $this->post(route('annulations.confirmer', $this->demander($annulee)))->assertSessionHas('succes');
        $this->assertSame(1, app(Corrections::class)->appliquerEchues());

        // RG-24 : la commande annulee garde sa facture ; sa correction reste proposee, sans suite
        $annulee->refresh();
        $this->assertSame(Commande::ANNULEE, $annulee->statut);
        $this->assertSame('3000.00', $annulee->montant);
        $this->assertSame(2, $annulee->lignes->first()->quantite);
        $this->assertSame(Controle::PROPOSEE, $sansSuite->refresh()->etat);
        $this->assertSame(Controle::APPLIQUEE_APRES_DELAI, $echue->refresh()->etat);
        $this->assertSame('1500.00', $bloquee->refresh()->montant);
        // Seul le client de la commande corrigee est averti
        $this->assertSame([$bloquee->id], Message::pluck('commande_id')->all());
        // Elle n'attend plus aucun accord
        $this->assertCount(0, $this->get('/accords')->assertOk()->viewData('controles'));
    }

    public function test_confirmer_est_impossible_si_la_commande_est_entree_en_traitement(): void
    {
        $commande = $this->commande('AAAA', Commande::CONTROLEE);
        $this->envoyer($commande, now()->subHours(2));
        $demande = $this->demander($commande);
        // Depuis la demande, l'atelier a attribue une premiere tache
        $commande->update(['statut' => Commande::EN_TRAITEMENT]);
        $depuis = $this->dernierChangement();

        $this->post(route('annulations.confirmer', $demande))
            ->assertRedirect(route('annulations.index'))
            ->assertSessionHasErrors(['annulation' => 'La commande CV-S41-10-2026-AAAA ne peut plus être annulée : elle est passée au statut « En traitement » depuis la demande. Refusez la demande.']);

        // Rien n'est modifie, rien n'est publie, rien n'est note
        $this->assertSame(DemandeAnnulation::DEMANDEE, $demande->refresh()->etat);
        $this->assertNull($demande->decidee_le);
        $this->assertSame(Commande::EN_TRAITEMENT, $commande->refresh()->statut);
        $this->assertSame('3000.00', $commande->reste);
        $this->assertSame($depuis, $this->dernierChangement());
        $this->assertSame(0, JournalAudit::count());

        // La page le dit, et ne propose plus que le refus
        $this->get('/annulations')->assertOk()
            ->assertSee('Cette commande ne peut plus être annulée')
            ->assertSee('Refuser')
            ->assertDontSee(route('annulations.confirmer', $demande), false);

        // Une commande sortie du suivi ne s'annule pas davantage
        $commande->update(['statut' => Commande::LIVREE]);
        $this->post(route('annulations.confirmer', $demande))->assertSessionHasErrors('annulation');
        $this->assertSame(Commande::LIVREE, $commande->refresh()->statut);

        $this->post(route('annulations.refuser', $demande))->assertSessionHasNoErrors();
        $this->assertSame(DemandeAnnulation::REFUSEE, $demande->refresh()->etat);
    }

    public function test_si_la_publication_vers_les_postes_echoue_la_confirmation_ne_laisse_rien(): void
    {
        $commande = $this->commande('AAAA', Commande::ENVOYEE);
        $this->envoyer($commande);
        $demande = $this->demander($commande);
        $avant = SyncChangement::count();

        // §11, Integrite : la commande annulee, sa publication, la demande et le journal vont ensemble
        SyncChangement::creating(fn () => throw new RuntimeException('panne simulée'));

        $this->post(route('annulations.confirmer', $demande))->assertStatus(500);
        $this->post(route('annulations.refuser', $demande), ['motif_refus' => 'Trop tard'])->assertStatus(500);

        $this->assertSame(DemandeAnnulation::DEMANDEE, $demande->refresh()->etat);
        $this->assertNull($demande->decidee_par);
        $this->assertNull($demande->motif_refus);
        $this->assertSame(Commande::ENVOYEE, $commande->refresh()->statut);
        $this->assertSame('3000.00', $commande->reste);
        $this->assertSame($avant, SyncChangement::count());
        $this->assertSame(0, JournalAudit::count());
    }

    // ------------------------------------------------------------------ refus

    public function test_refuser_laisse_la_commande_suivre_son_cours(): void
    {
        $commande = $this->commande('AAAA', Commande::ENVOYEE);
        $this->envoyer($commande);
        $demande = $this->demander($commande);
        $depuis = $this->dernierChangement();

        // Un motif trop long est refuse a la saisie, sans rien enregistrer
        $this->post(route('annulations.refuser', $demande), ['motif_refus' => str_repeat('a', 256)])->assertSessionHasErrors('motif_refus');
        $this->assertSame(DemandeAnnulation::DEMANDEE, $demande->refresh()->etat);

        $this->post(route('annulations.refuser', $demande), ['motif_refus' => 'Le lot est déjà parti'])
            ->assertRedirect(route('annulations.index'))
            ->assertSessionHas('succes', "La demande d'annulation de la commande CV-S41-10-2026-AAAA est refusée : la commande suit son cours.");

        $demande->refresh();
        $this->assertSame(DemandeAnnulation::REFUSEE, $demande->etat);
        $this->assertSame('Le lot est déjà parti', $demande->motif_refus);
        $this->assertSame($this->promoteur->id, $demande->decidee_par);
        $this->assertNotNull($demande->decidee_le);
        // La commande ne change pas : seule la demande repart vers la boutique
        $this->assertSame(Commande::ENVOYEE, $commande->refresh()->statut);
        $this->assertSame('3000.00', $commande->reste);
        $this->assertCount(0, $this->changements('commandes', $depuis));
        $decision = $this->changements('demandes_annulation', $depuis)->sole();
        $this->assertSame($this->boutique->id, $decision->site_id);
        $this->assertSame('REFUSEE', $decision->donnees['etat']);
        $this->assertSame('Le lot est déjà parti', $decision->donnees['motif_refus']);
        $this->assertTrue($decision->urgent);

        $ligne = JournalAudit::sole();
        $this->assertSame(Audit::ANNULATION_REFUSEE, $ligne->action);
        $this->assertSame($commande->id, $ligne->objet_id);
        $this->assertStringContainsString('motif du refus : Le lot est déjà parti', $ligne->detail);
        $this->get('/journal')->assertOk()->assertSee("Demande d'annulation refusée");
    }

    public function test_un_refus_se_passe_de_motif_et_une_demande_tranchee_ne_se_decide_pas_deux_fois(): void
    {
        $commande = $this->commande('AAAA');
        $demande = $this->demander($commande);

        $this->post(route('annulations.refuser', $demande), ['motif_refus' => ''])->assertSessionHasNoErrors();
        $this->assertSame(DemandeAnnulation::REFUSEE, $demande->refresh()->etat);
        $this->assertNull($demande->motif_refus);
        $this->assertStringContainsString('refusée sans motif', JournalAudit::sole()->detail);
        $decideeLe = $demande->decidee_le;
        $depuis = $this->dernierChangement();

        // Une page restee ouverte, ou un double clic : la decision prise est gardee
        $this->post(route('annulations.confirmer', $demande))
            ->assertRedirect(route('annulations.index'))->assertSessionHas('succes', 'Cette demande a déjà reçu une décision.');
        $this->post(route('annulations.refuser', $demande), ['motif_refus' => 'Autre motif'])
            ->assertSessionHas('succes', 'Cette demande a déjà reçu une décision.');

        $this->assertSame(DemandeAnnulation::REFUSEE, $demande->refresh()->etat);
        $this->assertNull($demande->motif_refus);
        $this->assertTrue($decideeLe->equalTo($demande->decidee_le));
        $this->assertSame(Commande::ENREGISTREE, $commande->refresh()->statut);
        $this->assertSame($depuis, $this->dernierChangement());
        $this->assertSame(1, JournalAudit::count());

        $this->post('/annulations/'.str()->uuid().'/confirmation')->assertNotFound();
    }

    // ------------------------------------------------------------------ pages et reperes

    public function test_la_page_liste_les_demandes_a_trancher_de_la_plus_ancienne_a_la_plus_recente_puis_les_decisions(): void
    {
        $recente = $this->commande('BBBB', Commande::ENVOYEE);
        $ancienne = $this->commande('AAAA');
        $this->encaisser($ancienne, $this->ouvrirCaisse($this->boutique, $this->aicha), 1000);
        $this->demander($recente, 'Erreur de saisie', now()->subHour());
        $this->demander($ancienne->refresh(), 'Le client a changé de pressing', now()->subDays(2));
        $refusee = $this->demander($this->commande('CCCC'), 'Doublon', now()->subDays(3));
        $this->post(route('annulations.refuser', $refusee), ['motif_refus' => 'Ce ne sont pas les mêmes articles']);

        $page = $this->get('/annulations')->assertOk();

        $this->assertSame(['CV-S41-10-2026-AAAA', 'CV-S41-10-2026-BBBB'], $page->viewData('aTrancher')->pluck('commande.code')->all());
        $page->assertSeeInOrder([
            'À trancher',
            'CV-S41-10-2026-AAAA', 'Enregistrée', 'Centre-Ville', 'Alice Mbia', '699112233',
            "3\u{00A0}000\u{00A0}FCFA", "1\u{00A0}000\u{00A0}FCFA", 'Le client a changé de pressing', 'Nom aicha',
            'CV-S41-10-2026-BBBB', 'Envoyée', 'Aucune avance versée', 'Erreur de saisie',
            'Demandes décidées',
            'Nom patron', 'CV-S41-10-2026-CCCC', 'Doublon', 'Demande refusée', 'Ce ne sont pas les mêmes articles',
        ]);
        // La confirmation est une decision sans retour : elle demande confirmation
        $page->assertSee('data-confirmer="Confirmer l\'annulation de la commande CV-S41-10-2026-AAAA ? Cette décision est définitive. Le site devra restituer au client son avance de 1'."\u{00A0}".'000'."\u{00A0}".'FCFA."', false);
        $page->assertSee('href="'.route('commandes.show', $ancienne).'"', false);

        // Tout est tranche : la page le dit
        DemandeAnnulation::where('etat', DemandeAnnulation::DEMANDEE)->get()
            ->each(fn (DemandeAnnulation $demande) => $this->post(route('annulations.refuser', $demande)));
        $this->get('/annulations')->assertOk()->assertSee("Aucune demande d'annulation n'attend votre décision", false);
    }

    public function test_le_menu_et_le_tableau_de_bord_signalent_ce_qui_attend(): void
    {
        $page = $this->get('/tableau-de-bord')->assertOk();
        $page->assertSee('Rien à signaler')->assertDontSee('menu-compteur', false);
        $page->assertSeeInOrder(['Commandes', 'En attente d&#039;accord', 'Demandes d&#039;annulation', 'Lots'], false);

        $this->demander($this->commande('AAAA'));
        $this->demander($this->commande('BBBB'));
        $this->correction($this->commande('CCCC', Commande::EN_ATTENTE_ACCORD), now()->subHours(4));
        // Deja acceptee : elle n'attend plus rien
        $this->correction($this->commande('DDDD', Commande::CONTROLEE), now()->subHours(4), Controle::ACCEPTEE);

        $page = $this->get('/tableau-de-bord')->assertOk();
        $page->assertSee("2 demandes d'annulation à trancher")
            ->assertSee("1 commande en attente de l'accord du client")
            ->assertSee('href="'.route('annulations.index').'">Trancher</a>', false)
            ->assertSee('href="'.route('accords.index').'">Voir les commandes</a>', false)
            ->assertDontSee('Rien à signaler');
        // Les compteurs du menu, sur toutes les pages
        $page->assertSee('title="À trancher">2</span>', false)
            ->assertSee('title="En attente de l&#039;accord du client">1</span>', false);
        $this->get('/annulations')->assertOk()->assertSee('aria-current="page"', false)->assertSee('title="À trancher">2</span>', false);

        // Une demande tranchee quitte le compteur
        $this->post(route('annulations.refuser', DemandeAnnulation::first()));
        $this->get('/tableau-de-bord')->assertSee("1 demande d'annulation à trancher")->assertSee('title="À trancher">1</span>', false);
    }

    public function test_la_page_des_accords_liste_les_corrections_en_attente_avec_leur_echeance(): void
    {
        $this->travelTo('2026-10-08 10:00:00');
        $commande = $this->commande('AAAA', Commande::EN_ATTENTE_ACCORD);
        $controle = $this->correction($commande, '2026-10-06 15:30:00');
        // Ni une correction deja acceptee, ni une commande annulee depuis n'attendent d'accord
        $this->correction($this->commande('BBBB', Commande::CONTROLEE), '2026-10-06 15:30:00', Controle::ACCEPTEE);
        $this->correction($this->commande('CCCC', Commande::ANNULEE), '2026-10-06 15:30:00');

        // RG-23 : tant que le Promoteur n'a pas fixe de delai, rien ne s'applique d'office
        $page = $this->get('/accords')->assertOk();
        $this->assertSame(['CV-S41-10-2026-AAAA'], $page->viewData('controles')->pluck('commande.code')->all());
        $page->assertSeeInOrder([
            "Commandes en attente d'accord", "Aucun délai d'accord n'est fixé", route('parametres.index'),
            'CV-S41-10-2026-AAAA', 'Centre-Ville', 'Alice Mbia', '699112233', 'Chemise', 'Nettoyage simple',
            '2 → 1', 'Une seule chemise dans le sac', '06/10/2026 16:30', 'aucun délai fixé',
        ], false);
        $page->assertSee('href="'.route('commandes.show', $commande).'"', false)
            ->assertDontSee('CV-S41-10-2026-BBBB')->assertDontSee('CV-S41-10-2026-CCCC');

        // Delai de trois jours : application d'office le 9 octobre a 16:30, heure du reseau
        $this->delaiDAccord('3');
        $this->get('/accords')->assertOk()
            ->assertSee("Sans accord 3 jours après l'arrivée de la correction sur le serveur", false)
            ->assertSee('le 09/10/2026 16:30')
            ->assertDontSee('aucun délai fixé');

        // Delai deja passe : le serveur l'appliquera a sa prochaine verification
        $this->delaiDAccord('1');
        $this->get('/accords')->assertOk()->assertSee('Délai écoulé')->assertDontSee('le 07/10/2026 16:30');

        // Arrivee sur le serveur un jour apres le controle (atelier hors ligne) : le delai court depuis son arrivee
        Controle::whereKey($controle->id)->update(['created_at' => '2026-10-07 15:30:00']);
        $this->get('/accords')->assertOk()->assertSee('le 08/10/2026 16:30')->assertDontSee('Délai écoulé');

        $this->get('/accords?page=2')->assertOk()->assertSee("Aucune commande n'attend l'accord d'un client", false);
    }

    public function test_la_fiche_d_une_commande_montre_ses_demandes_d_annulation_et_leur_issue(): void
    {
        $commande = $this->commande('AAAA');
        $this->get(route('commandes.show', $commande))->assertOk()->assertDontSee('Faites par la boutique');

        $this->encaisser($commande, $this->ouvrirCaisse($this->boutique, $this->aicha), 1000);
        $refusee = $this->demander($commande, 'Erreur de saisie', now()->subDays(2));
        $this->get(route('commandes.show', $commande))->assertOk()
            ->assertSeeInOrder(['Erreur de saisie', 'En attente de votre décision', 'Trancher la demande']);

        $this->post(route('annulations.refuser', $refusee), ['motif_refus' => 'La saisie est juste']);
        $this->post(route('annulations.confirmer', $this->demander($commande, 'Le client renonce', now()->subHour())));

        $this->get(route('commandes.show', $commande))->assertOk()
            ->assertSee('Annulée')
            ->assertSeeInOrder([
                'Faites par la boutique',
                'Nom aicha', 'Erreur de saisie', 'Demande refusée', 'Nom patron', 'La saisie est juste',
                'Le client renonce', 'Annulation confirmée', 'Nom patron',
                "le site doit encore restituer au client son avance de 1\u{00A0}000\u{00A0}FCFA",
            ])
            // RG-24 : une commande annulee n'a plus de reste a payer
            ->assertSee('<span>Reste à payer</span><strong>0'."\u{00A0}".'FCFA</strong>', false);
    }
}
