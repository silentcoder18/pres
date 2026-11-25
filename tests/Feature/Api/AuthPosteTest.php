<?php

namespace Tests\Feature\Api;

use App\Http\Middleware\AuthentifierPoste;
use App\Models\SessionPoste;
use App\Models\Site;
use App\Models\Travailleur;
use App\Models\Utilisateur;

class AuthPosteTest extends ApiPosteTestCase
{
    // la verification d'un compte vient de la base de l'ordinateur, avant toute session
    protected bool $sessionsAutomatiques = false;

    public function test_sans_jeton_le_poste_est_refuse(): void
    {
        $this->postJson('/api/auth/verifier', ['identifiant' => 'x', 'mot_de_passe' => 'y'])
            ->assertStatus(401)
            ->assertJsonPath('erreur', 'jeton_invalide');
    }

    public function test_un_poste_revoque_est_refuse(): void
    {
        $poste = $this->creerPoste($this->creerSite(), 'jeton-cv');
        $poste->forceFill(['revoque_le' => now()])->save();

        $this->withToken('jeton-cv')->getJson('/api/sync/tirer?curseur=0')
            ->assertStatus(403)
            ->assertJsonPath('erreur', 'poste_revoque');
    }

    public function test_des_identifiants_justes_renvoient_le_compte_sans_mot_de_passe(): void
    {
        $site = $this->creerSite();
        $this->creerPoste($site, 'jeton-cv');
        $compte = $this->creerUtilisateur($site, 'aicha');

        $reponse = $this->withToken('jeton-cv')
            ->postJson('/api/auth/verifier', ['identifiant' => 'aicha', 'mot_de_passe' => 'motdepasse1']);

        $reponse->assertOk()
            ->assertJsonPath('utilisateur.id', $compte->id)
            ->assertJsonPath('utilisateur.role', Utilisateur::RECEPTIONNISTE)
            ->assertJsonPath('utilisateur.version_acces', 1)
            ->assertJsonMissingPath('utilisateur.mot_de_passe');
    }

    public function test_un_mauvais_mot_de_passe_est_refuse(): void
    {
        $site = $this->creerSite();
        $this->creerPoste($site, 'jeton-cv');
        $this->creerUtilisateur($site, 'aicha');

        $this->withToken('jeton-cv')
            ->postJson('/api/auth/verifier', ['identifiant' => 'aicha', 'mot_de_passe' => 'faux-mot-de-passe'])
            ->assertStatus(401)
            ->assertJsonPath('erreur', 'identifiants_invalides');

        $this->withToken('jeton-cv')
            ->postJson('/api/auth/verifier', ['identifiant' => 'inconnu', 'mot_de_passe' => 'motdepasse1'])
            ->assertStatus(401)
            ->assertJsonPath('erreur', 'identifiants_invalides');
    }

    public function test_un_poste_connecte_n_importe_quel_compte_d_un_site_mais_pas_le_promoteur(): void
    {
        $boutique = $this->creerSite('CV');
        $atelier = $this->creerSite('ATL', Site::ATELIER);
        $this->creerPoste($boutique, 'jeton-cv');
        $this->creerUtilisateur($boutique, 'aicha');
        $chef = $this->creerUtilisateur($atelier, 'chef', Utilisateur::CHEF_ATELIER);
        $this->creerUtilisateur(null, 'promoteur', Utilisateur::PROMOTEUR);

        // Decisions des 9 et 16 octobre 2026 : le site du poste ne ferme le poste a personne
        foreach (['aicha', 'chef'] as $identifiant) {
            $this->withToken('jeton-cv')
                ->postJson('/api/auth/verifier', ['identifiant' => $identifiant, 'mot_de_passe' => 'motdepasse1'])
                ->assertOk()
                ->assertJsonPath('utilisateur.identifiant', $identifiant);
        }

        // La session porte le site de rattachement du compte, pas celui du poste
        $this->withToken('jeton-cv')
            ->postJson('/api/auth/verifier', ['identifiant' => 'chef', 'mot_de_passe' => 'motdepasse1'])
            ->assertJsonPath('utilisateur.id', $chef->id)
            ->assertJsonPath('utilisateur.site_id', $atelier->id)
            ->assertJsonPath('session.site_id', $atelier->id);

        // Les fonctions du Promoteur sont sur le web
        $this->withToken('jeton-cv')
            ->postJson('/api/auth/verifier', ['identifiant' => 'promoteur', 'mot_de_passe' => 'motdepasse1'])
            ->assertStatus(403)
            ->assertJsonPath('erreur', 'acces_refuse')
            ->assertJsonPath('message', 'Les fonctions du Promoteur sont sur l\'interface web : ce compte ne se connecte pas sur un poste.');
        $this->assertSame(0, SessionPoste::whereHas('utilisateur', fn ($q) => $q->where('role', Utilisateur::PROMOTEUR))->count());
    }

    public function test_la_base_d_une_personne_recoit_le_travail_de_son_site_quel_que_soit_le_poste(): void
    {
        $boutique = $this->creerSite('CV');
        $atelier = $this->creerSite('ATL', Site::ATELIER);
        $this->creerPoste($boutique, 'jeton-cv');
        $chef = $this->creerUtilisateur($atelier, 'chef', Utilisateur::CHEF_ATELIER);
        $travailleur = Travailleur::create([
            'site_id' => $atelier->id, 'matricule' => 'VP-AB123-T', 'nom' => 'Moussa', 'cree_par' => $chef->id, 'enrole_le' => now(),
        ]);

        $jeton = $this->withToken('jeton-cv')
            ->postJson('/api/auth/verifier', ['identifiant' => 'chef', 'mot_de_passe' => 'motdepasse1'])
            ->json('session.jeton');
        $this->assertSame(64, strlen($jeton));

        // Sur le poste de la boutique, la base du chef recoit le travail de l'atelier
        $tables = fn ($reponse) => collect($reponse->assertOk()->json('changements'))->pluck('table')->unique()->values()->all();
        $avecSession = $this->withToken('jeton-cv')->withHeader(AuthentifierPoste::ENTETE_SESSION, $jeton);
        $this->assertContains('travailleurs', $tables($avecSession->getJson('/api/sync/instantane')));
        $this->assertContains($travailleur->id, collect($avecSession->getJson('/api/sync/tirer?curseur=0')->json('changements'))
            ->where('table', 'travailleurs')->pluck('ligne_id')->all());

        // La base de l'ordinateur, elle, n'a que de quoi connecter les personnes
        $this->sansSession('jeton-cv');
        $this->assertSame(['sites', 'utilisateurs'], $tables($this->getJson('/api/sync/instantane')));
        $this->assertSame(['sites', 'utilisateurs'], $tables($this->getJson('/api/sync/tirer?curseur=0')));
    }

    public function test_une_session_tombe_quand_le_compte_change_ou_qu_une_connexion_la_remplace(): void
    {
        $boutique = $this->creerSite('CV');
        $this->creerPoste($boutique, 'jeton-cv');
        $aicha = $this->creerUtilisateur($boutique, 'aicha');
        $verifier = fn () => $this->withToken('jeton-cv')
            ->postJson('/api/auth/verifier', ['identifiant' => 'aicha', 'mot_de_passe' => 'motdepasse1'])
            ->assertOk()->json('session.jeton');
        $tirer = fn (string $jeton) => $this->withToken('jeton-cv')
            ->withHeader(AuthentifierPoste::ENTETE_SESSION, $jeton)->getJson('/api/sync/tirer?curseur=0');

        $premiere = $verifier();
        $tirer($premiere)->assertOk();

        // Une nouvelle verification sur ce poste remplace la session : l'ancienne tombe
        $seconde = $verifier();
        $tirer($premiere)->assertStatus(401)->assertJsonPath('erreur', 'session_invalide');
        $tirer($seconde)->assertOk();

        // Mot de passe change, puis site change, puis compte desactive : la session tombe chaque fois
        $aicha->update(['mot_de_passe' => 'nouveaumotdepasse2']);
        $tirer($seconde)->assertStatus(401)
            ->assertJsonPath('message', 'Le mot de passe ou le site de ce compte a changé. Reconnectez-vous.');
        $aicha->update(['mot_de_passe' => 'motdepasse1']);
        $troisieme = $verifier();
        $aicha->update(['site_id' => $this->creerSite('MC')->id]);
        $tirer($troisieme)->assertStatus(401);
        $quatrieme = $verifier();
        $aicha->update(['actif' => false]);
        $tirer($quatrieme)->assertStatus(401)->assertJsonPath('message', 'Ce compte a été désactivé par le Promoteur.');

        // Une session inconnue, ou celle d'un autre poste, ne vaut rien
        $tirer('inconnue')->assertStatus(401)->assertJsonPath('erreur', 'session_invalide');
    }

    public function test_un_compte_desactive_par_le_promoteur_est_refuse_sur_tous_les_postes(): void
    {
        $boutique = $this->creerSite('CV');
        $this->creerPoste($boutique, 'jeton-cv');
        $this->creerPoste($this->creerSite('ATL', Site::ATELIER), 'jeton-atl');
        $this->creerUtilisateur($boutique, 'ancienne')->update(['actif' => false]);

        foreach (['jeton-cv', 'jeton-atl'] as $jeton) {
            $this->withToken($jeton)
                ->postJson('/api/auth/verifier', ['identifiant' => 'ancienne', 'mot_de_passe' => 'motdepasse1'])
                ->assertStatus(403)
                ->assertJsonPath('erreur', 'acces_refuse')
                ->assertJsonPath('message', 'Ce compte est désactivé. Adressez-vous au Promoteur.');
        }
    }
}
