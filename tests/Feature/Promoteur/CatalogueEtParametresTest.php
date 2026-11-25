<?php

namespace Tests\Feature\Promoteur;

use App\Models\Article;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Tarif;
use App\Models\Utilisateur;
use App\Support\Audit;
use Database\Seeders\ParametresSeeder;

class CatalogueEtParametresTest extends PromoteurTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->promoteur);
    }

    public function test_article_prestation_et_tarif_crees_partent_vers_les_postes(): void
    {
        $this->post('/catalogue/articles', ['nom' => 'Chemise', 'description' => 'Chemise standard'])
            ->assertRedirect(route('catalogue.index', ['onglet' => 'articles']));
        $this->post('/catalogue/prestations', ['nom' => 'Nettoyage simple'])
            ->assertRedirect(route('catalogue.index', ['onglet' => 'prestations']));
        $article = Article::sole();
        $service = Service::sole();

        $this->post('/catalogue/tarifs', [
            'article_id' => $article->id, 'service_id' => $service->id, 'prix_vente' => '1500', 'commission_fixe' => '200',
        ])->assertRedirect(route('catalogue.index', ['onglet' => 'tarifs']));

        $changement = $this->changements('tarifs')->sole();
        $this->assertSame('1500.00', $changement->donnees['prix_vente']);
        $this->assertTrue($changement->donnees['actif']);
        $this->assertTrue($changement->urgent);
        $this->assertCount(1, $this->changements('articles'));
        $this->assertCount(1, $this->changements('services'));

        $this->get('/catalogue')->assertOk()->assertSee('Chemise')->assertSee('Nettoyage simple');
        $this->assertSame(3, JournalAudit::where('action', Audit::CATALOGUE_CREE)->count());
    }

    public function test_un_tarif_en_double_ou_une_commission_superieure_au_prix_sont_refuses(): void
    {
        $tarif = $this->creerTarif(1500);

        $this->post('/catalogue/tarifs', [
            'article_id' => $tarif->article_id, 'service_id' => $tarif->service_id, 'prix_vente' => '2000', 'commission_fixe' => '100',
        ])->assertSessionHasErrorsIn('tarifs', 'service_id');
        $this->put(route('catalogue.tarifs.update', $tarif), ['prix_vente' => '1000', 'commission_fixe' => '1200', 'actif' => '1'])
            ->assertSessionHasErrorsIn('tarifs', 'commission_fixe');
        $this->put(route('catalogue.tarifs.update', $tarif), ['prix_vente' => '-5', 'commission_fixe' => '0', 'actif' => '1'])
            ->assertSessionHasErrorsIn('tarifs', 'prix_vente');
        $this->post('/catalogue/articles', ['nom' => 'Chemise'])->assertSessionHasErrorsIn('articles', 'nom');

        $this->assertSame(1, Tarif::count());
        $this->assertSame('1500.00', $tarif->refresh()->prix_vente);
    }

    public function test_un_prix_modifie_part_vers_les_postes_sans_toucher_aux_commandes_enregistrees(): void
    {
        $boutique = $this->creerSite();
        $tarif = $this->creerTarif(1500);
        $commande = $this->creerCommande($boutique, $this->creerCompte('aicha', Utilisateur::RECEPTIONNISTE, $boutique),
            $this->creerClient($boutique), 'CV-S41-10-2026-AAAA');
        $depuis = $this->dernierChangement();

        $this->put(route('catalogue.tarifs.update', $tarif), ['prix_vente' => '1750', 'commission_fixe' => '250', 'actif' => '1'])
            ->assertRedirect(route('catalogue.index', ['onglet' => 'tarifs']));

        $changement = $this->changements('tarifs', $depuis)->sole();
        $this->assertSame('1750.00', $changement->donnees['prix_vente']);
        $this->assertTrue($changement->urgent);

        // RG-03 : la ligne garde le prix du jour du depot
        $this->assertSame('1500.00', LigneCommande::sole()->prix_applique);
        $this->assertSame('3000.00', $commande->refresh()->montant);
        $this->assertCount(0, $this->changements('commandes', $depuis));

        $detail = JournalAudit::where('action', Audit::CATALOGUE_MODIFIE)->sole()->detail;
        // une commission donnee d'un seul tenant se partage entre le lavage et le repassage
        $this->assertStringContainsString('Prix : 1 500 → 1 750 ; Commission du lavage : 100 → 125 ; Commission du repassage : 100 → 125',
            str_replace(["\u{00A0}", "\u{202F}"], ' ', $detail));
    }

    public function test_chaque_etape_a_sa_commission_et_une_prestation_dit_ses_etapes(): void
    {
        $tarif = $this->creerTarif(1500);
        $service = $tarif->service;
        $depuis = $this->dernierChangement();

        // Deux commissions, une par etape ; ensemble elles ne depassent pas le prix
        $this->put(route('catalogue.tarifs.update', $tarif),
            ['prix_vente' => '1500', 'commission_lavage' => '150', 'commission_repassage' => '90', 'actif' => '1'])
            ->assertSessionHasNoErrors();
        $donnees = $this->changements('tarifs', $depuis)->last()->donnees;
        $this->assertSame(['150.00', '90.00', '240.00'],
            [$donnees['commission_lavage'], $donnees['commission_repassage'], $donnees['commission_fixe']]);
        $this->put(route('catalogue.tarifs.update', $tarif),
            ['prix_vente' => '200', 'commission_lavage' => '150', 'commission_repassage' => '90', 'actif' => '1'])
            ->assertSessionHasErrorsIn('tarifs', 'commission_lavage');

        // « Repassage seul » : plus de lavage ; son tarif ne rapporte plus rien au lavage
        $this->put(route('catalogue.services.update', $service),
            ['nom' => 'Repassage seul', 'description' => '', 'actif' => '1', 'etapes_envoyees' => '1', 'etapes' => ['REPASSAGE']])
            ->assertSessionHasNoErrors();
        $this->assertFalse($service->refresh()->avec_lavage);
        $this->assertSame(['0.00', '90.00', '90.00'],
            [$tarif->refresh()->commission_lavage, $tarif->commission_repassage, $tarif->commission_fixe]);
        $publie = $this->changements('services', $depuis)->last()->donnees;
        $this->assertSame([false, true], [$publie['avec_lavage'], $publie['avec_repassage']]);

        // Une prestation comprend au moins une etape
        $this->put(route('catalogue.services.update', $service),
            ['nom' => 'Repassage seul', 'description' => '', 'actif' => '1', 'etapes_envoyees' => '1'])
            ->assertSessionHasErrorsIn('prestations', 'etapes');
        $this->post(route('catalogue.services.store'), ['nom' => 'Lavage seul', 'etapes_envoyees' => '1', 'etapes' => ['LAVAGE']])
            ->assertSessionHasNoErrors();
        $this->assertSame([true, false], [
            (bool) Service::where('nom', 'Lavage seul')->value('avec_lavage'),
            (bool) Service::where('nom', 'Lavage seul')->value('avec_repassage'),
        ]);
    }

    public function test_un_article_retire_reste_en_base_et_le_retrait_est_transmis(): void
    {
        $tarif = $this->creerTarif();
        $article = $tarif->article;
        $depuis = $this->dernierChangement();

        $this->put(route('catalogue.articles.update', $article), ['nom' => 'Chemise', 'description' => '', 'actif' => '0'])
            ->assertRedirect(route('catalogue.index', ['onglet' => 'articles']));

        $this->assertFalse($article->refresh()->actif);
        $this->assertFalse($this->changements('articles', $depuis)->sole()->donnees['actif']);
        $this->get('/catalogue')->assertSee('article retiré');
    }

    public function test_les_parametres_modifies_partent_vers_les_postes_et_sont_notes_au_journal(): void
    {
        $this->seed(ParametresSeeder::class);
        $depuis = $this->dernierChangement();
        $valeurs = Parametre::pluck('valeur', 'cle')->all();

        $this->get('/parametres')->assertOk()->assertSee('Délai de retrait avant pénalité');

        $this->put('/parametres', ['valeurs' => [
            Parametre::DELAI_ALERTE_LOT_HEURES => '12',
            Parametre::DELAI_TRAITEMENT_JOURS => '3',
        ] + $valeurs])->assertRedirect(route('parametres.index'))->assertSessionHasNoErrors();

        $this->assertSame('12', Parametre::find(Parametre::DELAI_ALERTE_LOT_HEURES)->valeur);
        $this->assertSame('3', Parametre::find(Parametre::DELAI_TRAITEMENT_JOURS)->valeur);

        // Seuls les deux parametres changes sont publies, en priorite
        $changements = $this->changements('parametres', $depuis);
        $this->assertEqualsCanonicalizing(
            [Parametre::DELAI_ALERTE_LOT_HEURES, Parametre::DELAI_TRAITEMENT_JOURS],
            $changements->pluck('ligne_id')->all(),
        );
        $this->assertTrue($changements->every->urgent);

        $details = JournalAudit::where('action', Audit::PARAMETRE_MODIFIE)->pluck('detail');
        $this->assertContains('Valeur : 24 → 12', $details);
        $this->assertContains('Valeur : (vide) → 3', $details);
    }

    public function test_un_parametre_obligatoire_ne_se_vide_pas_et_un_facultatif_si(): void
    {
        $this->seed(ParametresSeeder::class);
        Parametre::find(Parametre::DELAI_TRAITEMENT_JOURS)->update(['valeur' => '3']);
        $valeurs = Parametre::pluck('valeur', 'cle')->all();

        $this->put('/parametres', ['valeurs' => [Parametre::MONTANT_PENALITE => ''] + $valeurs])
            ->assertSessionHasErrors('valeurs.'.Parametre::MONTANT_PENALITE);
        $this->put('/parametres', ['valeurs' => [Parametre::INTERVALLE_SYNCHRO_HEURES => '0'] + $valeurs])
            ->assertSessionHasErrors('valeurs.'.Parametre::INTERVALLE_SYNCHRO_HEURES);
        $this->assertSame('1000', Parametre::find(Parametre::MONTANT_PENALITE)->valeur);

        $this->put('/parametres', ['valeurs' => [Parametre::DELAI_TRAITEMENT_JOURS => ''] + $valeurs])
            ->assertSessionHasNoErrors();
        $this->assertNull(Parametre::find(Parametre::DELAI_TRAITEMENT_JOURS)->valeur);
    }
}
