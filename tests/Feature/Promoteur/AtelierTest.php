<?php

namespace Tests\Feature\Promoteur;

use App\Models\Commande;
use App\Models\Controle;
use App\Models\Site;
use App\Models\Tache;
use App\Models\Travailleur;
use App\Models\Utilisateur;

/** Ce que l'atelier a synchronise, lu par le Promoteur : travailleurs, controle et taches d'une commande. */
class AtelierTest extends PromoteurTestCase
{
    private Site $boutique;

    private Site $atelier;

    private Utilisateur $chef;

    private Commande $commande;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boutique = $this->creerSite('CV', Site::BOUTIQUE, 'Centre-Ville');
        $this->atelier = $this->creerSite('ATL', Site::ATELIER, 'Atelier Central');
        $this->chef = $this->creerCompte('chef', Utilisateur::CHEF_ATELIER, $this->atelier);
        $aicha = $this->creerCompte('aicha', Utilisateur::RECEPTIONNISTE, $this->boutique);
        $this->commande = $this->creerCommande($this->boutique, $aicha, $this->creerClient($this->boutique),
            'CV-S41-10-2026-AAAA', Commande::EN_TRAITEMENT);
    }

    private function travailleur(string $nom, string $matricule, array $remplacer = []): Travailleur
    {
        return Travailleur::create($remplacer + [
            'site_id' => $this->atelier->id,
            'matricule' => $matricule,
            'nom' => $nom,
            'cni' => '112233445',
            'telephone' => '677001122',
            'quartier' => 'Pitoaré',
            'cree_par' => $this->chef->id,
            'enrole_le' => now()->subDays(10),
        ]);
    }

    private function tache(Travailleur $travailleur, int $quantite, mixed $termineeLe): Tache
    {
        return Tache::create([
            'commande_id' => $this->commande->id,
            'ligne_commande_id' => $this->commande->lignes->first()->id,
            'travailleur_id' => $travailleur->id,
            'quantite' => $quantite,
            'commission_unitaire' => 200,
            'attribuee_par' => $this->chef->id,
            'attribuee_le' => now()->subDays(9),
            'terminee_le' => $termineeLe,
        ]);
    }

    public function test_la_page_des_travailleurs_est_reservee_au_promoteur(): void
    {
        $this->get('/travailleurs')->assertRedirect('/connexion');
        $this->actingAs($this->chef)->get('/travailleurs')->assertRedirect(route('connexion'));
    }

    public function test_les_travailleurs_se_consultent_avec_leur_identite_et_leur_commission_de_la_semaine(): void
    {
        // Mercredi 7 octobre 2026 : la semaine 41 va du lundi 5 au dimanche 11
        $this->travelTo('2026-10-07 10:00:00');
        $moussa = $this->travailleur('Moussa Hamadou', 'VP-A2B3C-T');
        $this->travailleur('Fadimatou Bello', 'VP-Z9Y8X-T', ['cni' => null, 'telephone' => null, 'quartier' => null]);
        $this->tache($moussa, 1, now()->subDay());
        // RG-12 : la commission appartient a la semaine ou la tache a ete terminee
        $this->tache($moussa, 1, now()->subWeeks(2));
        $this->tache($moussa, 3, null);

        $page = $this->actingAs($this->promoteur)->get('/travailleurs')->assertOk();

        $page->assertSeeInOrder(['Fadimatou Bello', 'Moussa Hamadou']);
        $page->assertSee(['VP-A2B3C-T', '112233445', '677001122', 'Pitoaré', 'Atelier Central', 'Nom chef']);
        $page->assertSee('semaine 41 de 2026');
        $this->assertSame([$moussa->id => 1], $page->viewData('articles')->all());
        $this->assertSame([$moussa->id => '200.00'], $page->viewData('commissions')->all());

        $this->get('/travailleurs?recherche=VP-Z9')->assertOk()
            ->assertSee('Fadimatou Bello')->assertDontSee('Moussa Hamadou');
    }

    public function test_la_fiche_d_une_commande_montre_son_controle_et_ses_taches(): void
    {
        $moussa = $this->travailleur('Moussa Hamadou', 'VP-A2B3C-T');
        $this->tache($moussa, 1, now()->subDay());
        $this->tache($moussa, 1, null);
        $ligne = $this->commande->lignes->first();
        Controle::create([
            'commande_id' => $this->commande->id,
            'utilisateur_id' => $this->chef->id,
            'resultat' => Controle::ECART,
            'remarque' => 'Une chemise en plus dans le sac',
            'controle_le' => now()->subDays(9),
            'ligne_commande_id' => $ligne->id,
            'tarif_id' => $ligne->tarif_id,
            'quantite_avant' => 1,
            'quantite_apres' => 2,
            'etat' => Controle::APPLIQUEE_APRES_DELAI,
            'decide_le' => now()->subDays(6),
            'montant_avant' => 1500,
            'montant_apres' => 3000,
        ]);

        $this->actingAs($this->promoteur)->get('/commandes/'.$this->commande->id)->assertOk()
            ->assertSee('Contrôle à l\'atelier', false)
            ->assertSee('Écart de quantité')
            ->assertSee('1 enregistré(s), 2 constaté(s)')
            ->assertSee("Appliquée d'office après le délai")
            ->assertSee('Une chemise en plus dans le sac')
            ->assertSeeInOrder(['Tâches', 'Moussa Hamadou', 'VP-A2B3C-T', 'Terminée', 'En cours']);
    }

    public function test_une_commande_qui_n_est_pas_passee_par_l_atelier_ne_montre_ni_controle_ni_taches(): void
    {
        $this->actingAs($this->promoteur)->get('/commandes/'.$this->commande->id)->assertOk()
            ->assertDontSee('Contrôle à l\'atelier', false)
            ->assertDontSee('Tâches');
    }
}
