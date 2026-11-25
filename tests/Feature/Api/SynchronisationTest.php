<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\AuthentifierPoste;
use App\Models\Client;
use App\Models\Parametre;
use App\Models\SessionPoste;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\SyncOperation;
use App\Models\Utilisateur;
use Database\Seeders\ParametresSeeder;

class SynchronisationTest extends ApiPosteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Sans marge, le curseur suit immediatement ; la marge a son propre test
        config(['pressing.sync.marge_secondes' => 0]);
    }

    public function test_l_instantane_contient_ce_qui_concerne_le_poste_et_rien_d_autre(): void
    {
        $boutique = $this->creerSite('CV');
        $atelier = $this->creerSite('ATL', Site::ATELIER);
        $this->creerPoste($boutique, 'jeton-cv');
        $this->seed(ParametresSeeder::class);
        $tarif = $this->creerTarif();
        $this->creerUtilisateur($boutique, 'aicha');
        $this->creerUtilisateur($atelier, 'chef', Utilisateur::CHEF_ATELIER);
        $this->creerUtilisateur(null, 'promoteur', Utilisateur::PROMOTEUR);

        $reponse = $this->withToken('jeton-cv')->getJson('/api/sync/instantane')->assertOk();

        $lignes = collect($reponse->json('changements'));
        $parTable = $lignes->groupBy('table');

        $this->assertCount(2, $parTable['sites']);
        $this->assertCount(7, $parTable['parametres']);
        $this->assertSame('1500.00', $parTable['tarifs'][0]['donnees']['prix_vente']);
        $this->assertSame($tarif->id, $parTable['tarifs'][0]['ligne_id']);
        // Tous les comptes du reseau, sans mot de passe : un poste connecte n'importe lequel
        $this->assertEqualsCanonicalizing(['aicha', 'chef', 'promoteur'], $parTable['utilisateurs']->pluck('donnees.identifiant')->all());
        $this->assertArrayNotHasKey('mot_de_passe', $parTable['utilisateurs'][0]['donnees']);
        $this->assertSame((int) SyncChangement::max('seq'), $reponse->json('curseur'));

        // Un tarif ne peut pas arriver avant son article et son service
        $ordre = $lignes->pluck('table')->unique()->values()->all();
        $this->assertLessThan(array_search('tarifs', $ordre), array_search('articles', $ordre));
        $this->assertLessThan(array_search('tarifs', $ordre), array_search('services', $ordre));
    }

    public function test_un_client_pousse_est_enregistre_une_seule_fois_meme_renvoye(): void
    {
        $site = $this->creerSite();
        $this->creerPoste($site, 'jeton-cv');
        $id = (string) str()->uuid();
        $operation = $this->operationClient($id, '699112233');

        $this->withToken('jeton-cv')->postJson('/api/sync/pousser', ['operations' => [$operation]])
            ->assertOk()
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        $this->withToken('jeton-cv')->postJson('/api/sync/pousser', ['operations' => [$operation]])
            ->assertOk()
            ->assertJsonPath('resultats.0.resultat', 'deja_appliquee');

        $this->assertSame(1, Client::count());
        $client = Client::first();
        $this->assertSame($id, $client->id);
        $this->assertSame($site->id, $client->site_origine_id);
    }

    public function test_une_operation_rejetee_le_reste_quand_elle_est_renvoyee(): void
    {
        $this->creerPoste($this->creerSite('CV'), 'jeton-cv');
        $this->creerPoste($this->creerSite('MC'), 'jeton-mc');
        $id = (string) str()->uuid();
        $this->withToken('jeton-cv')
            ->postJson('/api/sync/pousser', ['operations' => [$this->operationClient($id, '699112233')]])
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        // Une fiche appartient au site qui l'a creee
        $intrusion = $this->operationClient($id, '699112233', 'Nom Change');
        $this->withToken('jeton-mc')
            ->postJson('/api/sync/pousser', ['operations' => [$intrusion]])
            ->assertOk()
            ->assertJsonPath('resultats.0.resultat', 'rejetee')
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');

        $this->withToken('jeton-mc')
            ->postJson('/api/sync/pousser', ['operations' => [$intrusion]])
            ->assertJsonPath('resultats.0.resultat', 'rejetee')
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');

        $this->assertSame('Alice Mbia', Client::first()->nom);
    }

    public function test_une_table_inconnue_est_rejetee(): void
    {
        $this->creerPoste($this->creerSite(), 'jeton-cv');
        $operation = $this->operationClient((string) str()->uuid(), '699112233');
        $operation['table'] = 'emprunts';

        $this->withToken('jeton-cv')
            ->postJson('/api/sync/pousser', ['operations' => [$operation]])
            ->assertJsonPath('resultats.0.resultat', 'rejetee')
            ->assertJsonPath('resultats.0.motif', 'table_inconnue');
    }

    public function test_un_changement_du_promoteur_arrive_au_poste_en_urgent(): void
    {
        $this->creerPoste($this->creerSite(), 'jeton-cv');
        $tarif = $this->creerTarif(1500);
        $curseur = (int) SyncChangement::max('seq');

        $tarif->update(['prix_vente' => 1700]);

        $reponse = $this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur='.$curseur)->assertOk();

        $reponse->assertJsonCount(1, 'changements')
            ->assertJsonPath('changements.0.table', 'tarifs')
            ->assertJsonPath('changements.0.donnees.prix_vente', '1700.00')
            ->assertJsonPath('changements.0.urgent', true)
            ->assertJsonPath('reste', false);
        $this->assertSame((int) SyncChangement::max('seq'), $reponse->json('curseur'));

        // Rien de nouveau : le curseur ne bouge plus
        $this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur='.$reponse->json('curseur'))
            ->assertJsonCount(0, 'changements');
    }

    public function test_un_element_cree_est_publie_actif(): void
    {
        $this->creerPoste($this->creerSite(), 'jeton-cv');
        $this->creerTarif();

        $changements = collect($this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur=0')->json('changements'));

        foreach (['sites', 'articles', 'services', 'tarifs'] as $table) {
            $this->assertTrue($changements->firstWhere('table', $table)['donnees']['actif'], $table);
        }
    }

    public function test_un_client_pousse_par_un_poste_arrive_aux_autres_mais_ne_lui_revient_pas(): void
    {
        $this->creerPoste($this->creerSite('CV'), 'jeton-cv');
        $this->creerPoste($this->creerSite('MC'), 'jeton-mc');
        $id = (string) str()->uuid();

        $this->withToken('jeton-cv')
            ->postJson('/api/sync/pousser', ['operations' => [$this->operationClient($id, '699112233')]])
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        $pourAutre = collect($this->withToken('jeton-mc')->getJson('/api/sync/tirer?curseur=0')->json('changements'))
            ->where('table', 'clients')->values();
        $this->assertCount(1, $pourAutre);
        $this->assertSame($id, $pourAutre[0]['ligne_id']);
        $this->assertFalse($pourAutre[0]['urgent']);

        $pourOrigine = collect($this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur=0')->json('changements'));
        $this->assertFalse($pourOrigine->contains('table', 'clients'));
    }

    public function test_ce_qu_une_personne_envoie_arrive_aux_autres_personnes_du_meme_poste(): void
    {
        // Decision du 16 octobre 2026 : chaque personne a sa base sur le poste ; ce que l'une envoie
        // ne lui revient pas, mais arrive a l'autre, meme sur le meme ordinateur
        $site = $this->creerSite('CV');
        $poste = $this->creerPoste($site, 'jeton-cv');
        [, $jetonAicha] = SessionPoste::ouvrir($poste, $this->creerUtilisateur($site, 'aicha'));
        [, $jetonMariam] = SessionPoste::ouvrir($poste, $this->creerUtilisateur($site, 'mariam'));
        $sous = fn (string $jeton) => $this->sansSession('jeton-cv')->withHeader(AuthentifierPoste::ENTETE_SESSION, $jeton);
        $id = (string) str()->uuid();

        $sous($jetonAicha)->postJson('/api/sync/pousser', ['operations' => [$this->operationClient($id, '699112233')]])
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame($poste->id, SyncOperation::findOrFail(SyncOperation::value('id'))->poste_id);
        $this->assertNotNull(SyncOperation::value('session_poste_id'));

        $clients = fn (string $jeton) => collect($sous($jeton)->getJson('/api/sync/tirer?curseur=0')->json('changements'))
            ->where('table', 'clients')->pluck('ligne_id')->all();
        $this->assertSame([$id], $clients($jetonMariam));
        $this->assertSame([], $clients($jetonAicha));
    }

    public function test_un_compte_est_transmis_a_tous_les_postes(): void
    {
        $boutique = $this->creerSite('CV');
        $atelier = $this->creerSite('ATL', Site::ATELIER);
        $this->creerPoste($boutique, 'jeton-cv');
        $this->creerPoste($atelier, 'jeton-atl');
        $compte = $this->creerUtilisateur($boutique, 'aicha');
        $this->creerUtilisateur(null, 'promoteur', Utilisateur::PROMOTEUR);

        $pourBoutique = collect($this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur=0')->json('changements'));
        $pourAtelier = collect($this->withToken('jeton-atl')->getJson('/api/sync/tirer?curseur=0')->json('changements'));

        // Tous les comptes, meme celui du Promoteur, qui ne se connecte pas sur un poste : la liste sert a connecter les personnes
        $this->assertContains($compte->id, $pourBoutique->where('table', 'utilisateurs')->pluck('ligne_id')->all());
        $this->assertCount(2, $pourBoutique->where('table', 'utilisateurs'));
        $this->assertCount(2, $pourAtelier->where('table', 'utilisateurs'));
    }

    public function test_changer_le_mot_de_passe_ou_l_acces_augmente_la_version_et_previent_le_poste(): void
    {
        $site = $this->creerSite();
        $this->creerPoste($site, 'jeton-cv');
        $compte = $this->creerUtilisateur($site, 'aicha');
        $curseur = (int) SyncChangement::max('seq');

        $compte->update(['mot_de_passe' => 'nouveaumotdepasse2']);
        $this->assertSame(2, $compte->refresh()->version_acces);

        $compte->update(['actif' => false]);
        $this->assertSame(3, $compte->refresh()->version_acces);

        $changements = $this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur='.$curseur)->json('changements');
        $dernier = end($changements);
        $this->assertSame('utilisateurs', $dernier['table']);
        $this->assertFalse($dernier['donnees']['actif']);
        $this->assertSame(3, $dernier['donnees']['version_acces']);
        $this->assertTrue($dernier['urgent']);
    }

    public function test_un_changement_trop_recent_est_livre_sans_faire_avancer_le_curseur(): void
    {
        config(['pressing.sync.marge_secondes' => 10]);
        $this->creerPoste($this->creerSite(), 'jeton-cv');

        $this->travelTo(now()->subMinute());
        Parametre::create(['cle' => 'ancien', 'valeur' => '1', 'libelle' => 'Ancien']);
        $this->travelBack();
        $seqAncien = (int) SyncChangement::max('seq');

        Parametre::create(['cle' => 'recent', 'valeur' => '2', 'libelle' => 'Recent']);

        $reponse = $this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur=0')->assertOk();
        $cles = collect($reponse->json('changements'))->where('table', 'parametres')->pluck('ligne_id')->all();

        $this->assertContains('ancien', $cles);
        $this->assertContains('recent', $cles);
        $this->assertSame($seqAncien, $reponse->json('curseur'));

        // Une fois la marge passee, le curseur rattrape
        $this->travel(11)->seconds();
        $this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur='.$seqAncien)
            ->assertJsonPath('curseur', (int) SyncChangement::max('seq'));
    }
}
