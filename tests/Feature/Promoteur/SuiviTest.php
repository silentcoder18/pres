<?php

namespace Tests\Feature\Promoteur;

use App\Models\Client;
use App\Models\ClientReuni;
use App\Models\Commande;
use App\Models\JournalAudit;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Paiement;
use App\Models\Poste;
use App\Models\Site;
use App\Models\SyncOperation;
use App\Models\Utilisateur;
use App\Support\Audit;

/** Tableau de bord et pages de consultation : ce que les postes ont synchronise, lu par le Promoteur. */
class SuiviTest extends PromoteurTestCase
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

    private function creerLot(array $commandes, array $remplacer = []): Lot
    {
        $lot = Lot::create($remplacer + [
            'sens' => Lot::ALLER,
            'site_origine_id' => $this->boutique->id,
            'site_destination_id' => $this->atelier->id,
            'cloture_par' => $this->aicha->id,
            'cloture_le' => now()->subHours(3),
            'nombre_annonce' => count($commandes),
        ]);
        $lot->commandes()->attach(collect($commandes)->pluck('id'));

        return $lot;
    }

    public function test_le_tableau_de_bord_compte_les_commandes_et_les_encaissements_du_jour(): void
    {
        $enBoutique = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-AAAA');
        $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-BBBB', Commande::ENVOYEE);
        $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-CCCC', 'LIVREE');
        $caisse = $this->ouvrirCaisse($this->boutique, $this->aicha);
        $this->encaisser($enBoutique, $caisse, 1000);
        // Un encaissement d'hier ne compte pas dans la recette du jour
        Paiement::create([
            'commande_id' => $enBoutique->id, 'session_caisse_id' => $caisse->id, 'utilisateur_id' => $this->aicha->id,
            'type' => Paiement::ACOMPTE, 'montant' => 700, 'encaisse_le' => now()->subDays(2),
        ]);

        $page = $this->get('/tableau-de-bord')->assertOk();

        $chiffres = $page->viewData('chiffres');
        $this->assertSame(2, $chiffres['commandes_en_cours']);
        $this->assertEquals(1000, $chiffres['encaisse_jour']);
        $this->assertSame(1, $chiffres['caisses_ouvertes']);

        $ligne = collect($page->viewData('sites'))->firstWhere('site.id', $this->boutique->id);
        $this->assertSame(['a_envoyer' => 1, 'a_l_atelier' => 1, 'a_retirer' => 0], $ligne['etapes']);
        $this->assertEquals(1000, $ligne['encaisse_jour']);
        $page->assertSee('Centre-Ville')->assertSee('Aucun poste');
    }

    public function test_sans_rien_a_signaler_le_tableau_de_bord_le_dit(): void
    {
        $this->get('/tableau-de-bord')->assertOk()->assertSee('Rien à signaler');
    }

    public function test_un_lot_sans_accuse_de_reception_au_dela_du_delai_est_signale(): void
    {
        $commande = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-AAAA', Commande::ENVOYEE);
        $recent = $this->creerLot([$commande]);
        $enRetard = $this->creerLot([$commande], ['cloture_le' => now()->subHours(30)]);

        $this->get('/tableau-de-bord')->assertSee('1 lot sans accusé de réception depuis plus de 24 heures');

        $this->get('/lots?etat=en_retard')->assertOk()
            ->assertSee(strtoupper(substr($enRetard->id, -8)))
            ->assertDontSee(strtoupper(substr($recent->id, -8)));
        $this->get('/lots?etat=en_route')->assertSee(strtoupper(substr($recent->id, -8)));
        $this->get(route('lots.show', $enRetard))->assertOk()
            ->assertSee("n'a pas d'accusé de réception", false)
            ->assertSee('CV-S41-10-2026-AAAA');
    }

    public function test_un_trop_percu_a_rendre_au_client_est_signale(): void
    {
        $payee = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-AAAA', Commande::CONTROLEE);
        $autre = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-BBBB');
        // La facture de 3 000 a ete corrigee apres un versement de 4 000
        $this->encaisser($payee, $this->ouvrirCaisse($this->boutique, $this->aicha), 4000);
        $this->assertSame('-1000.00', $payee->refresh()->reste);

        $this->get('/tableau-de-bord')->assertSee('1 commande avec un trop-perçu à rendre au client');
        $page = $this->get('/commandes?trop_percu=1')->assertOk()->assertSee('Seules les commandes dont le client a versé plus');
        $this->assertSame([$payee->id], collect($page->viewData('commandes')->items())->pluck('id')->all());
        $this->assertNotSame($autre->id, $payee->id);
    }

    public function test_un_ecart_de_comptage_a_la_reception_est_signale(): void
    {
        $premiere = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-AAAA', 'RECEPTIONNEE');
        $seconde = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-BBBB', 'RECEPTIONNEE');
        $lot = $this->creerLot([$premiere, $seconde], ['recu_le' => now()->subHour(), 'nombre_recu' => 1]);
        $this->creerLot([$premiere], ['recu_le' => now()->subHour(), 'nombre_recu' => 1]);

        $this->get('/tableau-de-bord')->assertSee('1 lot reçu avec un écart de comptage');
        $page = $this->get('/lots?etat=ecart')->assertOk()->assertSee('Écart de 1');
        $this->assertCount(1, $page->viewData('lots'));
        $this->get(route('lots.show', $lot))->assertSee('Écart de comptage');
    }

    public function test_les_commandes_se_filtrent_et_la_facture_se_consulte(): void
    {
        $autre = $this->creerSite('MC', Site::BOUTIQUE, 'Marché Central');
        $bello = $this->creerCompte('bello', Utilisateur::RECEPTIONNISTE, $autre);
        $bouba = $this->creerClient($autre, 'Bouba Hamadou', '677000111');
        $commande = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-AAAA');
        $this->creerCommande($autre, $bello, $bouba, 'MC-S41-10-2026-ZZZZ', Commande::ENVOYEE);
        $this->encaisser($commande, $this->ouvrirCaisse($this->boutique, $this->aicha), 1000);

        $this->get('/commandes')->assertOk()->assertSee('CV-S41-10-2026-AAAA')->assertSee('MC-S41-10-2026-ZZZZ');
        $this->get('/commandes?site='.$autre->id)->assertSee('MC-S41-10-2026-ZZZZ')->assertDontSee('CV-S41-10-2026-AAAA');
        $this->get('/commandes?statut=ENVOYEE')->assertSee('MC-S41-10-2026-ZZZZ')->assertDontSee('CV-S41-10-2026-AAAA');
        $this->get('/commandes?recherche=bouba')->assertSee('MC-S41-10-2026-ZZZZ')->assertDontSee('CV-S41-10-2026-AAAA');
        $this->get('/commandes?recherche=699112')->assertSee('CV-S41-10-2026-AAAA')->assertDontSee('MC-S41-10-2026-ZZZZ');
        $this->get('/commandes?du='.now()->addDay()->format('Y-m-d'))->assertSee('Aucune commande ne correspond');
        $this->get('/commandes?statut=INCONNU')->assertSessionHasErrors('statut');

        $totaux = $this->get('/commandes?site='.$this->boutique->id)->viewData('totaux');
        $this->assertEquals(3000, $totaux['montant']);
        $this->assertEquals(1000, $totaux['paye']);
        $this->assertEquals(2000, $totaux['reste']);

        $this->get(route('commandes.show', $commande))->assertOk()
            ->assertSee('Alice Mbia')->assertSee('Chemise')->assertSee('Avance')
            ->assertSee("3\u{00A0}000\u{00A0}FCFA")->assertSee("2\u{00A0}000\u{00A0}FCFA");
    }

    public function test_les_caisses_montrent_ce_qui_a_ete_compte_et_l_ecart(): void
    {
        $commande = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-AAAA');
        $caisse = $this->ouvrirCaisse($this->boutique, $this->aicha, 5000);
        $this->encaisser($commande, $caisse, 1000);

        $this->get('/caisses?etat=ouverte')->assertOk()->assertSee('Centre-Ville')->assertSee('Ouverte');
        $this->get(route('caisses.show', $caisse))->assertSee('Session en cours')->assertSee('CV-S41-10-2026-AAAA');

        $caisse->update(['cloturee_par' => $this->aicha->id, 'cloturee_le' => now(), 'total_compte' => 5500]);
        $caisse->recalculer();

        $this->assertSame('6000.00', $caisse->refresh()->total_attendu);
        $this->get('/caisses?etat=ecart')->assertSee("Manque 500\u{00A0}FCFA");
        $this->get('/caisses?etat=ouverte')->assertSee('Aucune session de caisse ne correspond');
        $this->get('/tableau-de-bord')->assertSee('1 caisse clôturée avec un écart ces 7 derniers jours');
    }

    public function test_la_file_des_messages_et_les_ecritures_refusees_se_consultent(): void
    {
        $commande = $this->creerCommande($this->boutique, $this->aicha, $this->alice, 'CV-S41-10-2026-AAAA');
        Message::create([
            'site_id' => $this->boutique->id, 'client_id' => $this->alice->id, 'commande_id' => $commande->id,
            'declencheur' => 'COMMANDE_ENREGISTREE', 'contenu' => 'Votre commande est enregistrée.', 'cree_le' => now(),
        ]);
        $poste = Poste::create(['site_id' => $this->boutique->id, 'nom' => 'Poste principal', 'jeton_hash' => Poste::empreinte('jeton')]);
        SyncOperation::create([
            'id' => (string) str()->uuid(), 'poste_id' => $poste->id, 'table_cible' => 'paiements',
            'ligne_id' => (string) str()->uuid(), 'resultat' => SyncOperation::REJETEE, 'motif' => 'depasse_le_reste', 'recu_le' => now(),
        ]);

        $this->get('/messages')->assertOk()
            ->assertSee('Commande enregistrée')->assertSee('En attente')->assertSee('Votre commande est enregistrée.');
        $this->get('/messages?etat=ECHOUE')->assertSee('Aucun message ne correspond');

        $this->get('/ecritures-refusees')->assertOk()
            ->assertSee('Encaissement supérieur au reste à payer')->assertSee('Centre-Ville');
        $this->get('/tableau-de-bord')->assertSee('1 écriture refusée par le serveur ces 7 derniers jours');
    }

    public function test_une_fiche_en_double_tranchee_prend_le_nom_retenu_dans_tout_le_reseau(): void
    {
        $autre = $this->creerSite('MC', Site::BOUTIQUE, 'Marché Central');
        $reunion = new ClientReuni([
            'id' => (string) str()->uuid(), 'client_id' => $this->alice->id, 'site_id' => $autre->id,
            'nom_propose' => 'Alice Mbia Ngono', 'a_trancher' => true,
        ]);
        $reunion->saveQuietly();
        $depuis = $this->dernierChangement();

        $this->get('/tableau-de-bord')->assertSee('1 fiche client en double à trancher');
        $this->get('/clients/doublons')->assertOk()->assertSee('Alice Mbia Ngono')->assertSee('Marché Central');

        $this->post(route('clients.trancher', $reunion), ['nom' => 'propose'])->assertRedirect(route('clients.doublons'));

        $this->assertSame('Alice Mbia Ngono', $this->alice->refresh()->nom);
        $this->assertFalse($reunion->refresh()->a_trancher);
        $this->assertNotNull($reunion->tranche_le);

        // §4.6 : la decision du Promoteur part en priorite vers tous les postes
        $changement = $this->changements('clients', $depuis)->sole();
        $this->assertSame('Alice Mbia Ngono', $changement->donnees['nom']);
        $this->assertNull($changement->site_id);
        $this->assertTrue($changement->urgent);

        $this->assertSame(Audit::DOUBLON_TRANCHE, JournalAudit::sole()->action);
        $this->get('/clients/doublons')->assertSee("Aucune fiche en double n'attend votre décision", false);
        $this->get('/journal')->assertOk()->assertSee('Fiche en double tranchée');
    }

    public function test_garder_le_nom_existant_ne_change_pas_la_fiche(): void
    {
        $reunion = new ClientReuni([
            'id' => (string) str()->uuid(), 'client_id' => $this->alice->id, 'site_id' => $this->boutique->id,
            'nom_propose' => 'Autre Nom', 'a_trancher' => true,
        ]);
        $reunion->saveQuietly();
        $depuis = $this->dernierChangement();

        $this->post(route('clients.trancher', $reunion), ['nom' => 'conserve'])->assertRedirect(route('clients.doublons'));

        $this->assertSame('Alice Mbia', $this->alice->refresh()->nom);
        $this->assertFalse($reunion->refresh()->a_trancher);
        $this->assertCount(0, $this->changements('clients', $depuis));
    }

    public function test_les_clients_se_recherchent_par_nom_ou_numero(): void
    {
        $this->creerClient($this->boutique, 'Bouba Hamadou', '677000111');

        $this->get('/clients')->assertOk()->assertSee('Alice Mbia')->assertSee('Bouba Hamadou');
        $this->get('/clients?recherche=677')->assertSee('Bouba Hamadou')->assertDontSee('Alice Mbia');
    }
}
