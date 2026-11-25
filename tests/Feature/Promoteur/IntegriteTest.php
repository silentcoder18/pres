<?php

namespace Tests\Feature\Promoteur;

use App\Models\JournalAudit;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\Tarif;
use App\Models\Utilisateur;
use RuntimeException;

/** §11, Integrite : une action du Promoteur est enregistree entierement ou pas du tout. */
class IntegriteTest extends PromoteurTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->promoteur);
    }

    public function test_si_la_publication_vers_les_postes_echoue_rien_n_est_enregistre(): void
    {
        $boutique = $this->creerSite('CV');
        $tarif = $this->creerTarif(1500);
        $avant = SyncChangement::count();

        // Panne au moment de publier le changement : la modification ne doit pas rester a moitie faite
        SyncChangement::creating(fn () => throw new RuntimeException('panne simulée'));

        $this->post('/comptes', [
            'nom' => 'Aicha Bello', 'identifiant' => 'aicha', 'role' => Utilisateur::RECEPTIONNISTE, 'site_id' => $boutique->id,
            'mot_de_passe' => 'motdepasse1', 'mot_de_passe_confirmation' => 'motdepasse1',
        ])->assertStatus(500);
        $this->put(route('catalogue.tarifs.update', $tarif), ['prix_vente' => '1750', 'commission_fixe' => '200', 'actif' => '1'])
            ->assertStatus(500);
        $this->post('/sites', ['code' => 'MC', 'nom' => 'Marché', 'type' => Site::BOUTIQUE])->assertStatus(500);

        $this->assertFalse(Utilisateur::where('identifiant', 'aicha')->exists());
        $this->assertSame('1500.00', Tarif::sole()->prix_vente);
        $this->assertSame(1, Site::count());
        $this->assertSame($avant, SyncChangement::count());
        $this->assertSame(0, JournalAudit::count());
    }

    public function test_une_saisie_illisible_est_refusee_avant_toute_ecriture(): void
    {
        $boutique = $this->creerSite('CV');

        // "ï" envoye dans un autre codage que celui de la page : il ne pourrait pas partir vers les postes
        $this->from('/comptes/nouveau')->post('/comptes', [
            'nom' => "A\xEFcha Bello", 'identifiant' => 'aicha', 'role' => Utilisateur::RECEPTIONNISTE, 'site_id' => $boutique->id,
            'mot_de_passe' => 'motdepasse1', 'mot_de_passe_confirmation' => 'motdepasse1',
        ])->assertRedirect('/comptes/nouveau')->assertSessionHasErrors('saisie');

        $this->assertFalse(Utilisateur::where('identifiant', 'aicha')->exists());

        // Le meme nom, correctement code, est accepte
        $this->post('/comptes', [
            'nom' => 'Aïcha Bello', 'identifiant' => 'aicha', 'role' => Utilisateur::RECEPTIONNISTE, 'site_id' => $boutique->id,
            'mot_de_passe' => 'motdepasse1', 'mot_de_passe_confirmation' => 'motdepasse1',
        ])->assertSessionHasNoErrors();
        $this->assertSame('Aïcha Bello', Utilisateur::where('identifiant', 'aicha')->value('nom'));
    }

    public function test_une_action_refusee_a_la_validation_ne_laisse_rien(): void
    {
        $this->creerSite('CV');
        $avant = SyncChangement::count();

        $this->post('/sites', ['code' => 'CV', 'nom' => 'Doublon', 'type' => Site::BOUTIQUE])->assertSessionHasErrors('code');

        $this->assertSame(1, Site::count());
        $this->assertSame($avant, SyncChangement::count());
    }
}
