<?php

namespace Tests\Feature\Promoteur;

use App\Models\JournalAudit;
use App\Models\Poste;
use App\Models\Site;
use App\Models\Utilisateur;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Le code de mise en service d'un poste est genere par le Promoteur sur l'interface web, et
 * nulle part ailleurs ; le poste se connecte ensuite avec un compte cree sur cette interface.
 */
class MiseEnServiceParLeWebTest extends PromoteurTestCase
{
    public function test_le_code_se_genere_d_un_clic_depuis_la_liste_des_sites(): void
    {
        $site = $this->creerSite('CV', Site::BOUTIQUE, 'Centre-Ville');
        $this->actingAs($this->promoteur);

        $this->get('/sites')->assertOk()->assertSee('Générer le code de mise en service');

        // Sans rien saisir : le poste prend le nom courant
        $this->post(route('postes.store', $site))->assertRedirect(route('sites.show', $site));

        $this->assertSame('Poste principal', Poste::sole()->nom);
        $code = session('code_mise_en_service')['code'];
        $this->assertSame(Audit::POSTE_DECLARE, JournalAudit::sole()->action);

        // La page montre le code, l'adresse du serveur a saisir sur le poste, et la suite : les comptes
        $this->get(route('sites.show', $site))->assertSee($code)->assertSee(url('/'))->assertSee('Comptes');
        // Un site qui a son poste ne propose plus d'en generer un second
        $this->get('/sites')->assertDontSee('Générer le code de mise en service');
    }

    public function test_aucune_commande_ne_genere_de_code_ni_ne_cree_de_compte_de_site(): void
    {
        $this->creerSite('CV');

        $this->assertArrayNotHasKey('pressing:poste-creer', Artisan::all());
        $this->assertArrayNotHasKey('pressing:poste-revoquer', Artisan::all());

        $this->artisan('pressing:utilisateur-creer', [
            'identifiant' => 'aicha', 'nom' => 'Aicha Bello', 'role' => 'RECEPTIONNISTE', '--site' => 'CV', '--mot-de-passe' => 'motdepasse1',
        ])->assertFailed();

        $this->assertFalse(Utilisateur::where('identifiant', 'aicha')->exists());
        $this->assertSame(0, Poste::count());
    }

    public function test_la_commande_cree_le_premier_compte_promoteur(): void
    {
        $this->artisan('pressing:utilisateur-creer', [
            'identifiant' => 'patronne@visuelplus.cm', 'nom' => 'La Patronne', '--mot-de-passe' => 'motdepasse1',
        ])->assertSuccessful();

        $compte = Utilisateur::where('identifiant', 'patronne@visuelplus.cm')->sole();
        $this->assertSame(Utilisateur::PROMOTEUR, $compte->role);
        $this->assertNull($compte->site_id);
    }

    public function test_le_poste_se_connecte_avec_un_compte_cree_sur_le_web(): void
    {
        $boutique = $this->creerSite('CV', Site::BOUTIQUE, 'Centre-Ville');
        $autre = $this->creerSite('MC', Site::BOUTIQUE, 'Marché Central');
        $this->actingAs($this->promoteur);

        // Sur le web : le code, puis les comptes (un identifiant peut etre une adresse de courriel)
        $this->post(route('postes.store', $boutique));
        $code = session('code_mise_en_service')['code'];
        foreach ([['martha.recept@visuelplus.cm', $boutique], ['bello', $autre]] as [$identifiant, $site]) {
            $this->post('/comptes', [
                'nom' => 'Compte '.$identifiant, 'identifiant' => $identifiant, 'role' => Utilisateur::RECEPTIONNISTE, 'site_id' => $site->id,
                'mot_de_passe' => 'motdepasse1', 'mot_de_passe_confirmation' => 'motdepasse1',
            ])->assertSessionHasNoErrors();
        }

        // Sur l'ordinateur du site : mise en service, puis premiere connexion verifiee par le serveur
        $jeton = $this->postJson('/api/postes/enroler', ['code' => $code, 'nom_machine' => 'PC-BOUTIQUE'])->assertOk()->json('jeton');
        $verifier = fn (string $identifiant, string $motDePasse) => $this->withToken($jeton)
            ->postJson('/api/auth/verifier', ['identifiant' => $identifiant, 'mot_de_passe' => $motDePasse]);

        $verifier('martha.recept@visuelplus.cm', 'motdepasse1')->assertOk()
            ->assertJsonPath('utilisateur.identifiant', 'martha.recept@visuelplus.cm')
            ->assertJsonPath('utilisateur.site_id', $boutique->id);
        // Tous les comptes du serveur figurent dans ce que le poste telecharge a sa mise en service
        $comptes = collect($this->withToken($jeton)->getJson('/api/sync/instantane')->json('changements'))
            ->where('table', 'utilisateurs')->pluck('donnees.identifiant');
        $this->assertEqualsCanonicalizing(['martha.recept@visuelplus.cm', 'bello', 'patron'], $comptes->all());

        $verifier('martha.recept@visuelplus.cm', 'mauvais')->assertStatus(401)->assertJsonPath('erreur', 'identifiants_invalides');
        // Decisions des 9 et 16 octobre 2026 : le poste connecte le compte d'un autre site, sur les
        // donnees de son site ; le Promoteur, lui, travaille sur le web
        $verifier('bello', 'motdepasse1')->assertOk()->assertJsonPath('utilisateur.site_id', $autre->id)
            ->assertJsonPath('session.site_id', $autre->id);
        $verifier('patron', 'motdepasse1')->assertStatus(403)->assertJsonPath('erreur', 'acces_refuse');

        // Un compte cree APRES la mise en service, que le poste n'a pas encore recu par
        // synchronisation, est verifie par le serveur des la premiere tentative
        $this->actingAs($this->promoteur)->post('/comptes', [
            'nom' => 'Aicha Bello', 'identifiant' => 'aicha.nouvelle', 'role' => Utilisateur::RECEPTIONNISTE, 'site_id' => $boutique->id,
            'mot_de_passe' => 'motdepasse1', 'mot_de_passe_confirmation' => 'motdepasse1',
        ])->assertSessionHasNoErrors();
        $verifier('aicha.nouvelle', 'motdepasse1')->assertOk()->assertJsonPath('utilisateur.identifiant', 'aicha.nouvelle');

        Utilisateur::where('identifiant', 'martha.recept@visuelplus.cm')->sole()->update(['actif' => false]);
        $verifier('martha.recept@visuelplus.cm', 'motdepasse1')->assertStatus(403)
            ->assertJsonPath('message', 'Ce compte est désactivé. Adressez-vous au Promoteur.');
    }

    public function test_une_base_en_retard_d_une_mise_a_niveau_le_dit_au_lieu_d_echouer(): void
    {
        $site = $this->creerSite('CV');
        $poste = Poste::create(['site_id' => $site->id, 'nom' => 'Poste principal', 'jeton_hash' => Poste::empreinte('jeton-cv')]);

        // Comme si le programme avait ete mis a jour sans que « migrate » soit lance
        DB::table('migrations')->where('migration', 'like', '%creer_journal_audit')->delete();

        $this->get('/connexion')->assertStatus(503)->assertSee('doit être mise à niveau')->assertSee('run-serveur.bat');
        $this->post('/connexion', ['identifiant' => 'patron', 'mot_de_passe' => 'motdepasse1'])->assertStatus(503);
        $this->assertGuest();
        // Un poste recoit « indisponible » : il retentera a sa synchronisation suivante
        $this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur=0')
            ->assertStatus(503)->assertJsonPath('erreur', 'base_a_mettre_a_niveau');
        $this->assertNull($poste->refresh()->dernier_contact_le);
    }
}
