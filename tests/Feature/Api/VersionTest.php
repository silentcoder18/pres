<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Version du poste publiee pour la mise a jour sans deplacement, et heure du serveur. */
class VersionTest extends TestCase
{
    use RefreshDatabase;

    private string $fichier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fichier = storage_path('app/poste-essai/GePressing-Poste.jar');
        config(['pressing.poste.fichier' => 'poste-essai/GePressing-Poste.jar']);
    }

    protected function tearDown(): void
    {
        @unlink($this->fichier);
        @rmdir(dirname($this->fichier));
        parent::tearDown();
    }

    public function test_sans_jar_depose_le_serveur_ne_propose_aucune_mise_a_jour(): void
    {
        config(['pressing.poste.version' => '1.1.0']);

        $reponse = $this->getJson('/api/version')->assertOk();

        $this->assertNull($reponse->json('version'));
        $this->assertNull($reponse->json('jar_url'));
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $reponse->json('date_serveur'));
        $this->assertIsInt($reponse->json('serveur_timestamp'));
        $this->get('/api/poste/telecharger')->assertNotFound();
    }

    public function test_un_jar_depose_avec_sa_version_est_propose_avec_son_empreinte(): void
    {
        @mkdir(dirname($this->fichier), 0777, true);
        file_put_contents($this->fichier, 'contenu du jar');
        config(['pressing.poste.version' => '1.1.0']);

        $reponse = $this->getJson('/api/version')->assertOk();

        $this->assertSame('1.1.0', $reponse->json('version'));
        $this->assertSame(hash('sha256', 'contenu du jar'), $reponse->json('sha256'));
        $this->assertSame(strlen('contenu du jar'), $reponse->json('taille'));
        $this->assertStringEndsWith('/api/poste/telecharger', $reponse->json('jar_url'));

        $telechargement = $this->get('/api/poste/telecharger')->assertOk();
        $this->assertSame('contenu du jar', $telechargement->streamedContent());
    }

    public function test_un_jar_sans_version_n_est_pas_propose(): void
    {
        @mkdir(dirname($this->fichier), 0777, true);
        file_put_contents($this->fichier, 'contenu du jar');
        config(['pressing.poste.version' => null]);

        $this->assertNull($this->getJson('/api/version')->assertOk()->json('jar_url'));
    }
}
