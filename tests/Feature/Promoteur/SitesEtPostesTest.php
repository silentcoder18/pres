<?php

namespace Tests\Feature\Promoteur;

use App\Models\JournalAudit;
use App\Models\Poste;
use App\Models\Site;
use App\Support\Audit;

class SitesEtPostesTest extends PromoteurTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->promoteur);
    }

    public function test_un_site_cree_est_transmis_aux_postes_en_priorite(): void
    {
        $this->post('/sites', ['code' => ' cv ', 'nom' => 'Centre-Ville', 'adresse' => 'Avenue Centrale', 'type' => Site::BOUTIQUE])
            ->assertRedirect(route('sites.show', Site::sole()));

        $site = Site::sole();
        $this->assertSame('CV', $site->code);
        $this->assertTrue($site->actif);

        $changement = $this->changements('sites')->sole();
        $this->assertSame('Centre-Ville', $changement->donnees['nom']);
        $this->assertNull($changement->site_id, 'tous les sites le recoivent');
        $this->assertTrue($changement->urgent);

        $this->assertSame(Audit::SITE_CREE, JournalAudit::sole()->action);
        $this->get('/sites')->assertOk()->assertSee('Centre-Ville')->assertSee('Pas encore de poste');
    }

    public function test_un_code_de_site_mal_forme_ou_deja_pris_est_refuse(): void
    {
        $this->creerSite('CV');

        $this->post('/sites', ['code' => 'CV', 'nom' => 'Autre', 'type' => Site::BOUTIQUE])->assertSessionHasErrors('code');
        $this->post('/sites', ['code' => 'C V', 'nom' => 'Autre', 'type' => Site::BOUTIQUE])->assertSessionHasErrors('code');
        $this->post('/sites', ['code' => 'MC', 'nom' => 'Marché', 'type' => 'entrepot'])->assertSessionHasErrors('type');

        $this->assertSame(1, Site::count());
    }

    public function test_le_code_et_le_type_d_un_site_ne_changent_pas(): void
    {
        $site = $this->creerSite('CV');

        $this->put(route('sites.update', $site), [
            'nom' => 'Centre-Ville rénové', 'adresse' => '', 'actif' => '0', 'code' => 'XX', 'type' => Site::ATELIER,
        ])->assertRedirect(route('sites.show', $site));

        $site->refresh();
        $this->assertSame('Centre-Ville rénové', $site->nom);
        $this->assertFalse($site->actif);
        $this->assertSame('CV', $site->code);
        $this->assertSame(Site::BOUTIQUE, $site->type);

        $ligne = JournalAudit::where('action', Audit::SITE_MODIFIE)->sole();
        $this->assertStringContainsString('Actif : oui → non', $ligne->detail);
    }

    public function test_un_poste_declare_recoit_un_code_montre_une_fois_et_se_met_en_service_avec(): void
    {
        $site = $this->creerSite('CV');

        $reponse = $this->post(route('postes.store', $site), ['nom' => 'Poste principal'])
            ->assertRedirect(route('sites.show', $site));
        $code = session('code_mise_en_service')['code'];
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);

        // Montre sur la page qui suit, plus sur la suivante
        $this->get(route('sites.show', $site))->assertSee($code)->assertSee('En attente de mise en service');
        $this->get(route('sites.show', $site))->assertDontSee($code);

        // Le journal garde la trace de l'action, jamais le code
        $this->assertStringNotContainsString($code, JournalAudit::all()->toJson());

        // L'ordinateur du site echange ce code contre son jeton
        $enrolement = $this->postJson('/api/postes/enroler', ['code' => $code, 'nom_machine' => 'PC-BOUTIQUE'])->assertOk();
        $this->assertSame($site->id, $enrolement->json('site.id'));

        $this->get(route('sites.show', $site))->assertSee('En service')->assertSee('PC-BOUTIQUE');
    }

    public function test_un_site_n_a_qu_un_poste(): void
    {
        $site = $this->creerSite('CV');
        $this->post(route('postes.store', $site), ['nom' => 'Poste principal']);

        $this->from(route('sites.show', $site))
            ->post(route('postes.store', $site), ['nom' => 'Second poste'])
            ->assertRedirect(route('sites.show', $site))
            ->assertSessionHasErrors('nom');

        $this->assertSame(1, Poste::count());
    }

    public function test_un_poste_revoque_est_refuse_a_sa_synchronisation_suivante(): void
    {
        $site = $this->creerSite('CV');
        $this->post(route('postes.store', $site), ['nom' => 'Poste principal']);
        $jeton = $this->postJson('/api/postes/enroler', ['code' => session('code_mise_en_service')['code']])->json('jeton');
        $poste = Poste::sole();

        $this->withToken($jeton)->getJson('/api/sync/tirer?curseur=0')->assertOk();

        $this->actingAs($this->promoteur)->post(route('postes.revoquer', $poste))->assertRedirect(route('sites.show', $site));

        $this->withToken($jeton)->getJson('/api/sync/tirer?curseur=0')
            ->assertStatus(403)->assertJsonPath('erreur', 'poste_revoque');
        $this->assertSame(Audit::POSTE_REVOQUE, JournalAudit::latest('id')->first()->action);
    }

    public function test_un_nouveau_code_remplace_l_ordinateur_du_poste(): void
    {
        $site = $this->creerSite('CV');
        $this->post(route('postes.store', $site), ['nom' => 'Poste principal']);
        $ancienJeton = $this->postJson('/api/postes/enroler', ['code' => session('code_mise_en_service')['code']])->json('jeton');
        $poste = Poste::sole();

        $this->actingAs($this->promoteur)->post(route('postes.code', $poste))->assertRedirect(route('sites.show', $site));
        $nouveauCode = session('code_mise_en_service')['code'];

        // L'ancien ordinateur n'est plus reconnu ; le nouveau se met en service avec le nouveau code
        $this->withToken($ancienJeton)->getJson('/api/sync/tirer?curseur=0')
            ->assertStatus(401)->assertJsonPath('erreur', 'jeton_invalide');
        $nouveauJeton = $this->postJson('/api/postes/enroler', ['code' => $nouveauCode])->assertOk()->json('jeton');
        $this->withToken($nouveauJeton)->getJson('/api/sync/tirer?curseur=0')->assertOk();

        $this->assertSame(1, Poste::count(), 'le poste reste le meme : seul son ordinateur change');
    }

    public function test_un_poste_revoque_retrouve_l_acces_avec_un_nouveau_code(): void
    {
        $site = $this->creerSite('CV');
        $this->post(route('postes.store', $site), ['nom' => 'Poste principal']);
        $poste = Poste::sole();
        $this->post(route('postes.revoquer', $poste));

        $this->post(route('postes.code', $poste))->assertRedirect(route('sites.show', $site));

        $this->postJson('/api/postes/enroler', ['code' => session('code_mise_en_service')['code']])->assertOk();
        $this->assertNull($poste->refresh()->revoque_le);
    }

    public function test_pas_de_poste_pour_un_site_desactive(): void
    {
        $site = $this->creerSite('CV');
        $site->update(['actif' => false]);

        $this->post(route('postes.store', $site), ['nom' => 'Poste principal'])->assertSessionHasErrors('nom');

        $this->assertSame(0, Poste::count());
    }
}
