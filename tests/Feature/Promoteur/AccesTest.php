<?php

namespace Tests\Feature\Promoteur;

use App\Models\JournalAudit;
use App\Models\Site;
use App\Models\Utilisateur;
use App\Support\Audit;

/** L'interface web est reservee au Promoteur. */
class AccesTest extends PromoteurTestCase
{
    public function test_sans_session_on_arrive_sur_la_page_de_connexion(): void
    {
        $this->get('/')->assertRedirect('/tableau-de-bord');
        $this->get('/tableau-de-bord')->assertRedirect(route('connexion'));
        $this->get('/comptes')->assertRedirect(route('connexion'));
        $this->post('/sites', ['code' => 'CV', 'nom' => 'Centre', 'type' => Site::BOUTIQUE])->assertRedirect(route('connexion'));

        $this->get('/connexion')->assertOk()->assertSee('Espace du Promoteur');
        $this->assertSame(0, Site::count());
    }

    public function test_le_promoteur_se_connecte_et_sa_connexion_est_notee_au_journal(): void
    {
        $this->post('/connexion', ['identifiant' => 'patron', 'mot_de_passe' => 'motdepasse1'])
            ->assertRedirect(route('tableau-de-bord'));

        $this->assertAuthenticatedAs($this->promoteur);
        $this->get('/tableau-de-bord')->assertOk()->assertSee('Tableau de bord du réseau');

        $ligne = JournalAudit::sole();
        $this->assertSame(Audit::CONNEXION, $ligne->action);
        $this->assertSame($this->promoteur->nom, $ligne->auteur);
    }

    public function test_un_mot_de_passe_faux_un_compte_de_site_ou_un_compte_desactive_sont_refuses_de_la_meme_facon(): void
    {
        $boutique = $this->creerSite();
        $this->creerCompte('aicha', Utilisateur::RECEPTIONNISTE, $boutique);
        $this->creerCompte('ancien', Utilisateur::PROMOTEUR, actif: false);

        foreach ([
            ['patron', 'mauvais-mot-de-passe'],
            ['aicha', 'motdepasse1'],
            ['ancien', 'motdepasse1'],
            ['inconnu', 'motdepasse1'],
        ] as [$identifiant, $motDePasse]) {
            $this->from('/connexion')
                ->post('/connexion', ['identifiant' => $identifiant, 'mot_de_passe' => $motDePasse])
                ->assertRedirect('/connexion')
                ->assertSessionHasErrors(['identifiant' => 'Identifiant ou mot de passe incorrect.']);
            $this->assertGuest();
        }

        $this->assertSame(0, JournalAudit::count());
    }

    public function test_un_compte_desactive_en_cours_de_session_perd_l_acces_a_la_page_suivante(): void
    {
        $this->actingAs($this->promoteur)->get('/tableau-de-bord')->assertOk();

        $this->promoteur->update(['actif' => false]);

        $this->get('/tableau-de-bord')->assertRedirect(route('connexion'));
        $this->assertGuest();
    }

    public function test_un_compte_de_site_connecte_par_un_autre_moyen_n_entre_pas(): void
    {
        $chef = $this->creerCompte('chef', Utilisateur::CHEF_ATELIER, $this->creerSite('ATL', Site::ATELIER));

        $this->actingAs($chef)->get('/comptes')->assertRedirect(route('connexion'));
        $this->assertGuest();
    }

    public function test_la_deconnexion_ferme_la_session(): void
    {
        $this->actingAs($this->promoteur)->post('/deconnexion')->assertRedirect(route('connexion'));

        $this->assertGuest();
        $this->get('/tableau-de-bord')->assertRedirect(route('connexion'));
    }

    public function test_chaque_page_s_affiche_sur_un_reseau_vide(): void
    {
        $this->actingAs($this->promoteur);

        foreach ([
            '/tableau-de-bord', '/commandes', '/accords', '/annulations', '/lots', '/caisses', '/finances', '/messages', '/sites',
            '/comptes', '/comptes/nouveau',
            '/clients', '/clients/doublons', '/ecritures-refusees', '/catalogue', '/catalogue?onglet=articles',
            '/catalogue?onglet=prestations', '/parametres', '/journal',
        ] as $page) {
            $this->get($page)->assertOk();
        }
    }
}
