<?php

namespace Tests\Feature\Promoteur;

use App\Models\JournalAudit;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\Utilisateur;
use App\Support\Audit;
use Illuminate\Support\Facades\Hash;

/** RG-15, RG-16 : seul le Promoteur cree les comptes, et un compte n'a qu'un role. */
class ComptesTest extends PromoteurTestCase
{
    private Site $boutique;

    private Site $atelier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boutique = $this->creerSite('CV');
        $this->atelier = $this->creerSite('ATL', Site::ATELIER);
        $this->actingAs($this->promoteur);
    }

    private function saisie(array $remplacer = []): array
    {
        return $remplacer + [
            'nom' => 'Aicha Bello',
            'identifiant' => 'aicha.bello',
            'role' => Utilisateur::RECEPTIONNISTE,
            'site_id' => $this->boutique->id,
            'telephone' => '677445566',
            'mot_de_passe' => 'motdepasse1',
            'mot_de_passe_confirmation' => 'motdepasse1',
        ];
    }

    public function test_un_compte_cree_est_transmis_a_tous_les_postes_sans_son_mot_de_passe(): void
    {
        $depuis = $this->dernierChangement();

        $this->post('/comptes', $this->saisie())->assertRedirect(route('comptes.index'));

        $compte = Utilisateur::where('identifiant', 'aicha.bello')->sole();
        $this->assertSame($this->boutique->id, $compte->site_id);
        $this->assertTrue(Hash::check('motdepasse1', $compte->mot_de_passe));
        $this->assertNotSame('motdepasse1', $compte->mot_de_passe);

        $changement = $this->changements('utilisateurs', $depuis)->sole();
        $this->assertNull($changement->site_id, 'tous les postes le recoivent : il peut se connecter sur chacun');
        $this->assertTrue($changement->urgent);
        $this->assertArrayNotHasKey('mot_de_passe', $changement->donnees);

        $ligne = JournalAudit::where('action', Audit::COMPTE_CREE)->sole();
        $this->assertStringNotContainsString('motdepasse1', $ligne->toJson());

        $this->get('/comptes')->assertOk()->assertSee('aicha.bello');
    }

    public function test_le_site_doit_convenir_au_role(): void
    {
        $this->post('/comptes', $this->saisie(['site_id' => $this->atelier->id]))->assertSessionHasErrors('site_id');
        $this->post('/comptes', $this->saisie(['site_id' => null]))->assertSessionHasErrors('site_id');
        $this->post('/comptes', $this->saisie(['role' => Utilisateur::CHEF_ATELIER]))->assertSessionHasErrors('site_id');
        $this->assertSame(1, Utilisateur::count());

        $this->post('/comptes', $this->saisie(['role' => Utilisateur::CHEF_ATELIER, 'site_id' => $this->atelier->id, 'identifiant' => 'chef']))
            ->assertSessionHasNoErrors();
        // Un Promoteur n'est rattache a aucun site, meme si un site est envoye
        $this->post('/comptes', $this->saisie(['role' => Utilisateur::PROMOTEUR, 'identifiant' => 'associe']))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->atelier->id, Utilisateur::where('identifiant', 'chef')->value('site_id'));
        $this->assertNull(Utilisateur::where('identifiant', 'associe')->value('site_id'));
    }

    public function test_un_mot_de_passe_faible_ou_mal_confirme_et_un_identifiant_pris_sont_refuses(): void
    {
        $this->post('/comptes', $this->saisie(['mot_de_passe' => 'court1', 'mot_de_passe_confirmation' => 'court1']))
            ->assertSessionHasErrors('mot_de_passe');
        $this->post('/comptes', $this->saisie(['mot_de_passe' => 'sanschiffres', 'mot_de_passe_confirmation' => 'sanschiffres']))
            ->assertSessionHasErrors('mot_de_passe');
        $this->post('/comptes', $this->saisie(['mot_de_passe_confirmation' => 'autrechose2']))
            ->assertSessionHasErrors('mot_de_passe');
        $this->post('/comptes', $this->saisie(['identifiant' => 'patron']))->assertSessionHasErrors('identifiant');
        $this->post('/comptes', $this->saisie(['identifiant' => 'avec espace']))->assertSessionHasErrors('identifiant');

        $this->assertSame(1, Utilisateur::count());
    }

    public function test_reinitialiser_le_mot_de_passe_oblige_le_poste_a_une_connexion_en_ligne(): void
    {
        $compte = $this->creerCompte('aicha', Utilisateur::RECEPTIONNISTE, $this->boutique);
        $depuis = $this->dernierChangement();

        $this->put(route('comptes.mot-de-passe', $compte), ['mot_de_passe' => 'nouveau2026', 'mot_de_passe_confirmation' => 'nouveau2026'])
            ->assertRedirect(route('comptes.edit', $compte));

        $compte->refresh();
        $this->assertTrue(Hash::check('nouveau2026', $compte->mot_de_passe));
        $this->assertSame(2, $compte->version_acces);

        // §4.6 : le poste recoit la nouvelle version d'acces et oublie le mot de passe garde localement
        $changement = $this->changements('utilisateurs', $depuis)->sole();
        $this->assertSame(2, $changement->donnees['version_acces']);
        $this->assertTrue($changement->urgent);
        $this->assertStringNotContainsString('nouveau2026', JournalAudit::all()->toJson());
    }

    public function test_desactiver_un_compte_ferme_son_acces_sur_le_poste(): void
    {
        $compte = $this->creerCompte('aicha', Utilisateur::RECEPTIONNISTE, $this->boutique);
        $depuis = $this->dernierChangement();

        $this->put(route('comptes.update', $compte), [
            'nom' => 'Aicha Bello', 'site_id' => $this->boutique->id, 'telephone' => '', 'actif' => '0',
            // le role ne se change pas : un envoi force est ignore
            'role' => Utilisateur::PROMOTEUR,
        ])->assertRedirect(route('comptes.index'));

        $compte->refresh();
        $this->assertFalse($compte->actif);
        $this->assertSame(Utilisateur::RECEPTIONNISTE, $compte->role);

        $changement = $this->changements('utilisateurs', $depuis)->sole();
        $this->assertFalse($changement->donnees['actif']);
        $this->assertTrue($changement->urgent);

        $this->assertStringContainsString('Actif : oui → non', JournalAudit::where('action', Audit::COMPTE_MODIFIE)->sole()->detail);
    }

    public function test_changer_le_site_de_rattachement_ne_retire_le_compte_d_aucun_poste(): void
    {
        $autre = $this->creerSite('MC');
        $compte = $this->creerCompte('aicha', Utilisateur::RECEPTIONNISTE, $this->boutique);
        $depuis = $this->dernierChangement();

        $this->put(route('comptes.update', $compte), ['nom' => $compte->nom, 'site_id' => $autre->id, 'actif' => '1'])
            ->assertSessionHasNoErrors();

        // Un seul changement, pour tous les postes : le compte reste valable partout
        $changement = $this->changements('utilisateurs', $depuis)->sole();
        $this->assertSame(SyncChangement::UPSERT, $changement->operation);
        $this->assertNull($changement->site_id);
        $this->assertSame($autre->id, $changement->donnees['site_id']);
        $this->assertSame(1, $compte->refresh()->version_acces, 'le mot de passe garde sur les postes reste valable');
        $this->assertStringContainsString('Site : Site CV → Site MC', JournalAudit::where('action', Audit::COMPTE_MODIFIE)->sole()->detail);
    }

    public function test_le_promoteur_ne_desactive_pas_son_propre_compte(): void
    {
        $this->put(route('comptes.update', $this->promoteur), ['nom' => 'Patron', 'actif' => '0'])
            ->assertSessionHasErrors('actif');

        $this->assertTrue($this->promoteur->refresh()->actif);
    }
}
