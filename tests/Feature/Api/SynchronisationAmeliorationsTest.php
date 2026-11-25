<?php

namespace Tests\Feature\Api;

use App\Models\ArticleConsommable;
use App\Models\AvanceCommission;
use App\Models\Commande;
use App\Models\Consommable;
use App\Models\LigneCommande;
use App\Models\Message;
use App\Models\MouvementConsommable;
use App\Models\Site;
use App\Models\Tarif;
use App\Models\Utilisateur;
use App\Models\VersementJournalier;

/**
 * Ameliorations du 23 octobre 2026, telles que les postes les envoient : remarque d'une ligne de
 * commande, anomalie signalee par l'atelier, versements journaliers et stock de consommables.
 */
class SynchronisationAmeliorationsTest extends ApiPosteTestCase
{
    private Site $boutique;

    private Site $voisine;

    private Site $atelier;

    private Utilisateur $aicha;

    private Utilisateur $chef;

    private Tarif $chemise;

    private string $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pressing.sync.marge_secondes' => 0]);

        $this->boutique = $this->creerSite('CV');
        $this->voisine = $this->creerSite('MC');
        $this->atelier = $this->creerSite('ATL', Site::ATELIER);
        $this->creerPoste($this->boutique, 'jeton-cv');
        $this->creerPoste($this->voisine, 'jeton-mc');
        $this->creerPoste($this->atelier, 'jeton-atl');
        // un second ordinateur a l'atelier : une autre base du meme chef, sous une autre session
        $this->creerPoste($this->atelier, 'jeton-atl-2');
        $this->aicha = $this->creerUtilisateur($this->boutique, 'aicha');
        $this->creerUtilisateur($this->voisine, 'bello');
        $this->chef = $this->creerUtilisateur($this->atelier, 'chef', Utilisateur::CHEF_ATELIER);
        $this->chemise = $this->creerTarif(1500);

        $this->clientId = (string) str()->uuid();
        $this->pousser('jeton-cv', $this->operationClient($this->clientId, '699112233'));
    }

    // ------------------------------------------------------------------ remarque et anomalie

    public function test_la_remarque_d_une_ligne_est_gardee_et_suit_la_commande_jusqu_a_l_atelier(): void
    {
        $commande = $this->operationCommande('Tache sur le col');
        $this->pousser('jeton-cv', $commande)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $ligneId = $commande['donnees']['lignes'][0]['id'];
        $this->assertSame('Tache sur le col', LigneCommande::findOrFail($ligneId)->remarque);

        $this->pousser('jeton-cv', $this->operationLot([$commande['ligne_id']]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        $recue = $this->changementsPour('jeton-atl')->where('table', 'commandes')->last();
        $this->assertSame('Tache sur le col', $recue['donnees']['lignes'][0]['remarque']);

        // une remarque vide n'est pas gardee
        $sansRemarque = $this->operationCommande('   ');
        $this->pousser('jeton-cv', $sansRemarque)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertNull(LigneCommande::findOrFail($sansRemarque['donnees']['lignes'][0]['id'])->remarque);
    }

    public function test_l_atelier_annonce_au_client_une_anomalie_sur_une_commande_qu_il_a_recue(): void
    {
        $commande = $this->operationCommande();
        $lot = $this->operationLot([$commande['ligne_id']]);
        $this->pousser('jeton-cv', $commande, $lot);
        $this->pousser('jeton-atl', $this->operation('receptions_lot', [
            'id' => $lot['ligne_id'], 'recu_par' => $this->chef->id, 'recu_le' => '2026-10-06T15:00:00Z', 'nombre_recu' => 1,
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        $message = $this->operationMessage($commande['ligne_id']);
        $this->pousser('jeton-atl', $message)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $enregistre = Message::findOrFail($message['ligne_id']);
        $this->assertSame($this->atelier->id, $enregistre->site_id);
        $this->assertSame('anomalie_constatee', $enregistre->declencheur);

        // une autre boutique ne peut toujours pas ecrire sur cette commande
        $this->pousser('jeton-mc', $this->operationMessage($commande['ligne_id']))
            ->assertJsonPath('resultats.0.resultat', 'rejetee')
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');
    }

    public function test_l_atelier_ne_signale_rien_sur_une_commande_qu_il_n_a_pas_recue(): void
    {
        $commande = $this->operationCommande();
        // le lot est parti mais l'atelier ne l'a pas encore recu
        $this->pousser('jeton-cv', $commande, $this->operationLot([$commande['ligne_id']]));

        $this->pousser('jeton-atl', $this->operationMessage($commande['ligne_id']))
            ->assertJsonPath('resultats.0.resultat', 'rejetee')
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');
    }

    // ------------------------------------------------------------------ versements journaliers

    public function test_un_versement_declare_par_la_boutique_est_recu_a_l_atelier_puis_revient_a_la_boutique(): void
    {
        $sessionId = $this->caisseCloturee('48500');
        $versement = $this->operationVersement($sessionId, '48500');
        $this->pousser('jeton-cv', $versement)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $enregistre = VersementJournalier::findOrFail($versement['ligne_id']);
        $this->assertSame($this->boutique->id, $enregistre->site_id);
        $this->assertSame(VersementJournalier::EN_ATTENTE, $enregistre->statut());

        // l'atelier le recoit, la boutique voisine non
        $this->assertSame('EN_ATTENTE', $this->changementsPour('jeton-atl')->firstWhere('table', 'versements_journaliers')['donnees']['statut']);
        $this->assertNull($this->changementsPour('jeton-mc')->firstWhere('table', 'versements_journaliers'));

        $curseurBoutique = $this->dernierCurseur('jeton-cv');
        $this->pousser('jeton-atl', $this->operationReception($versement['ligne_id'], '48000', 'Il manque un billet de 500'))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        $enregistre->refresh();
        $this->assertSame(VersementJournalier::RECU, $enregistre->statut());
        $this->assertSame('-500.00', $enregistre->ecart());
        $this->assertSame($this->atelier->id, $enregistre->site_reception_id);

        // la boutique apprend la reception ; l'autre base de l'atelier aussi
        $retour = $this->changementsPour('jeton-cv', $curseurBoutique)->where('table', 'versements_journaliers')->last();
        $this->assertSame('RECU', $retour['donnees']['statut']);
        $this->assertSame('48000.00', $retour['donnees']['montant_recu']);
        $this->assertSame('RECU', $this->changementsPour('jeton-atl-2')->where('table', 'versements_journaliers')->last()['donnees']['statut']);

        // une seconde reception, faite hors ligne sur l'autre base, est refusee
        $this->pousser('jeton-atl-2', $this->operationReception($versement['ligne_id'], '48500'))
            ->assertJsonPath('resultats.0.motif', 'versement_deja_recu');
    }

    public function test_seule_une_boutique_declare_et_seul_un_atelier_recoit_un_versement(): void
    {
        $sessionId = $this->caisseCloturee('10000');
        $versement = $this->operationVersement($sessionId, '10000');
        // chaque envoi est une ecriture nouvelle : un identifiant d'ecriture deja recu rendrait la meme reponse
        $memeVersement = fn () => $this->operation('versements_journaliers', $versement['donnees']);

        $this->pousser('jeton-atl', $memeVersement())->assertJsonPath('resultats.0.motif', 'reserve_a_la_boutique');
        $this->pousser('jeton-mc', $memeVersement())->assertJsonPath('resultats.0.motif', 'session_inconnue');

        $this->pousser('jeton-cv', $memeVersement())->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->pousser('jeton-cv', $this->operationReception($versement['ligne_id'], '10000'))
            ->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');

        // une caisse ne fait qu'un versement
        $this->pousser('jeton-cv', $this->operationVersement($sessionId, '10000'))
            ->assertJsonPath('resultats.0.motif', 'versement_deja_declare');
    }

    public function test_l_atelier_mis_en_service_recoit_les_versements_qui_attendent(): void
    {
        $versement = $this->operationVersement($this->caisseCloturee('7000'), '7000');
        $this->pousser('jeton-cv', $versement);

        $atelier = collect($this->withToken('jeton-atl')->getJson('/api/sync/instantane')->json('changements'))->groupBy('table');
        $this->assertSame([$versement['ligne_id']], $atelier['versements_journaliers']->pluck('ligne_id')->all());
        $voisine = collect($this->withToken('jeton-mc')->getJson('/api/sync/instantane')->json('changements'))->groupBy('table');
        $this->assertArrayNotHasKey('versements_journaliers', $voisine->all());
    }

    // ------------------------------------------------------------------ stock de consommables

    public function test_le_stock_de_l_atelier_passe_par_le_serveur_et_arrive_aux_autres_bases_du_site(): void
    {
        $articleId = (string) str()->uuid();
        $article = $this->operation('articles_consommables', [
            'id' => $articleId, 'code' => 'CINTRE', 'designation' => 'Cintres métalliques', 'unite' => 'pièces', 'seuil_alerte' => '100',
        ]);
        $entree = $this->operationMouvement($articleId, 'APPROVISIONNEMENT', '250', '12500');
        $sortie = $this->operationMouvement($articleId, 'SORTIE', '50.5');
        $this->pousser('jeton-atl', $article, $entree, $sortie)
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.resultat', 'appliquee')
            ->assertJsonPath('resultats.2.resultat', 'appliquee');

        $this->assertSame('199.500', ArticleConsommable::findOrFail($articleId)->stock());
        $this->assertNull(MouvementConsommable::findOrFail($sortie['ligne_id'])->cout_total);

        // la meme fiche, creee par une autre base du meme atelier, n'est pas un doublon
        $this->pousser('jeton-atl-2', $this->operation('articles_consommables', $article['donnees']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $recus = $this->changementsPour('jeton-atl-2');
        $this->assertCount(1, $recus->where('table', 'articles_consommables'));
        $this->assertCount(2, $recus->where('table', 'mouvements_consommables'));
        $this->assertNull($this->changementsPour('jeton-cv')->firstWhere('table', 'mouvements_consommables'));

        $instantane = collect($this->withToken('jeton-atl-2')->getJson('/api/sync/instantane')->json('changements'))->groupBy('table');
        $this->assertCount(2, $instantane['mouvements_consommables']);
    }

    public function test_le_stock_est_reserve_a_l_atelier_et_a_ses_articles(): void
    {
        $articleId = (string) str()->uuid();
        $article = $this->operation('articles_consommables', [
            'id' => $articleId, 'code' => 'FILM', 'designation' => 'Film', 'unite' => 'rouleaux', 'seuil_alerte' => '5',
        ]);
        $this->pousser('jeton-cv', $article)->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');
        $this->pousser('jeton-atl', $this->operationMouvement($articleId, 'SORTIE', '1'))
            ->assertJsonPath('resultats.0.motif', 'article_inconnu');

        $this->pousser('jeton-atl', $this->operation('articles_consommables', $article['donnees']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        // un autre article du meme code, sur le meme atelier
        $this->pousser('jeton-atl', $this->operation('articles_consommables', [
            'id' => (string) str()->uuid(), 'code' => 'FILM', 'designation' => 'Film', 'unite' => 'rouleaux', 'seuil_alerte' => '5',
        ]))->assertJsonPath('resultats.0.motif', 'doublon_code');
    }

    // ------------------------------------------------------------------ achats et avances sur commission

    public function test_un_achat_porte_sa_categorie_et_un_poste_d_avant_achete_des_consommables(): void
    {
        $energie = $this->operationAchat('ENERGIE', 'Facture d\'électricité', '25000');
        $ancien = $this->operation('consommables', [
            'id' => (string) str()->uuid(), 'utilisateur_id' => $this->chef->id,
            'description' => 'Lessive', 'montant' => '4000', 'sortie_le' => '2026-10-07T09:00:00Z',
        ]);
        $this->pousser('jeton-atl', $energie, $ancien)
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.resultat', 'appliquee');

        $this->assertSame('ENERGIE', Consommable::findOrFail($energie['ligne_id'])->categorie);
        $this->assertSame(Consommable::CONSOMMABLES, Consommable::findOrFail($ancien['ligne_id'])->categorie);
        $this->assertSame('ENERGIE', $this->changementsPour('jeton-atl-2')->firstWhere('ligne_id', $energie['ligne_id'])['donnees']['categorie']);

        $this->pousser('jeton-atl', $this->operationAchat('LOYER', 'Loyer', '50000'))
            ->assertJsonPath('resultats.0.motif', 'donnees_invalides');
        $this->pousser('jeton-cv', $this->operationAchat('MATERIEL', 'Fer à repasser', '15000'))
            ->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');
    }

    public function test_une_avance_sur_commission_est_enregistree_et_arrive_aux_autres_bases_de_l_atelier(): void
    {
        $travailleurId = $this->enrolerTravailleur();
        $avance = $this->operationAvance($travailleurId, '1500');
        $this->pousser('jeton-atl', $avance)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $enregistree = AvanceCommission::findOrFail($avance['ligne_id']);
        $this->assertSame($this->atelier->id, $enregistree->site_id);
        $this->assertSame('1500.00', (string) $enregistree->montant);
        $this->assertSame('1500.00', $this->changementsPour('jeton-atl-2')->firstWhere('table', 'avances_commission')['donnees']['montant']);
        $this->assertNull($this->changementsPour('jeton-cv')->firstWhere('table', 'avances_commission'));

        // une avance reste reservee a l'atelier et a ses travailleurs
        $this->pousser('jeton-cv', $this->operationAvance($travailleurId, '500'))
            ->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');
        $this->pousser('jeton-atl', $this->operationAvance((string) str()->uuid(), '500'))
            ->assertJsonPath('resultats.0.motif', 'travailleur_inconnu');

        $instantane = collect($this->withToken('jeton-atl-2')->getJson('/api/sync/instantane')->json('changements'))->groupBy('table');
        $this->assertCount(1, $instantane['avances_commission']);
    }

    // ------------------------------------------------------------------ ecritures

    private function operationAchat(string $categorie, string $description, string $montant): array
    {
        return $this->operation('consommables', [
            'id' => (string) str()->uuid(),
            'utilisateur_id' => $this->chef->id,
            'categorie' => $categorie,
            'description' => $description,
            'montant' => $montant,
            'sortie_le' => '2026-10-07T09:00:00Z',
        ]);
    }

    private function enrolerTravailleur(): string
    {
        $id = (string) str()->uuid();
        $this->pousser('jeton-atl', $this->operation('travailleurs', [
            'id' => $id, 'matricule' => 'VP-AB123-T', 'nom' => 'Moussa', 'role' => 'LAVEUR',
            'cree_par' => $this->chef->id, 'enrole_le' => '2026-10-05T08:00:00Z',
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $id;
    }

    private function operationAvance(string $travailleurId, string $montant): array
    {
        return $this->operation('avances_commission', [
            'id' => (string) str()->uuid(),
            'travailleur_id' => $travailleurId,
            'montant' => $montant,
            'versee_par' => $this->chef->id,
            'versee_le' => '2026-10-07T17:00:00Z',
            'motif' => 'Transport',
        ]);
    }

    private function operationCommande(?string $remarque = null): array
    {
        $id = (string) str()->uuid();

        return $this->operation('commandes', [
            'id' => $id,
            'code' => 'CV-S41-10-2026-'.strtoupper(substr($id, 0, 4)),
            'client_id' => $this->clientId,
            'utilisateur_id' => $this->aicha->id,
            'statut' => 'ENREGISTREE',
            'deposee_le' => '2026-10-06T09:15:00Z',
            'lignes' => [
                ['id' => (string) str()->uuid(), 'tarif_id' => $this->chemise->id, 'quantite' => 2,
                    'prix_applique' => '1500', 'commission_appliquee' => '200', 'avec_lavage' => true,
                    'avec_repassage' => true, 'commission_lavage' => '100', 'commission_repassage' => '100',
                    'remarque' => $remarque],
            ],
        ]);
    }

    private function operationLot(array $commandeIds): array
    {
        return $this->operation('lots', [
            'id' => (string) str()->uuid(),
            'sens' => 'ALLER',
            'site_destination_id' => $this->atelier->id,
            'cloture_par' => $this->aicha->id,
            'cloture_le' => '2026-10-06T12:00:00Z',
            'commande_ids' => $commandeIds,
        ]);
    }

    private function operationMessage(string $commandeId): array
    {
        return $this->operation('messages', [
            'id' => (string) str()->uuid(),
            'client_id' => $this->clientId,
            'commande_id' => $commandeId,
            'declencheur' => 'anomalie_constatee',
            'contenu' => 'Une anomalie a ete constatee : bouton manquant.',
            'cree_le' => '2026-10-07T10:00:00Z',
        ]);
    }

    /** Caisse de la boutique ouverte puis cloturee ; rend son identifiant. */
    private function caisseCloturee(string $compte): string
    {
        $id = (string) str()->uuid();
        $session = [
            'id' => $id,
            'ouverte_par' => $this->aicha->id,
            'ouverte_le' => '2026-10-06T08:00:00Z',
            'fond_initial' => '0',
        ];
        $this->pousser('jeton-cv', $this->operation('sessions_caisse', $session))->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->pousser('jeton-cv', $this->operation('sessions_caisse', $session + [
            'cloturee_par' => $this->aicha->id,
            'cloturee_le' => '2026-10-06T18:00:00Z',
            'total_compte' => $compte,
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $id;
    }

    private function operationVersement(string $sessionId, string $montant): array
    {
        return $this->operation('versements_journaliers', [
            'id' => (string) str()->uuid(),
            'session_caisse_id' => $sessionId,
            'date_versement' => '2026-10-06',
            'montant_declare' => $montant,
            'remis_par' => $this->aicha->id,
            'declare_le' => '2026-10-06T18:00:00Z',
        ]);
    }

    private function operationReception(string $versementId, string $montant, ?string $observation = null): array
    {
        return $this->operation('receptions_versement', [
            'id' => $versementId,
            'recu_par' => $this->chef->id,
            'recu_le' => '2026-10-07T08:30:00Z',
            'montant_recu' => $montant,
            'observation' => $observation,
        ]);
    }

    private function operationMouvement(string $articleId, string $type, string $quantite, ?string $cout = null): array
    {
        return $this->operation('mouvements_consommables', [
            'id' => (string) str()->uuid(),
            'article_id' => $articleId,
            'type' => $type,
            'quantite' => $quantite,
            'cout_total' => $cout,
            'utilisateur_id' => $this->chef->id,
            'mouvement_le' => '2026-10-07T09:00:00Z',
            'remarque' => null,
        ]);
    }

    private function dernierCurseur(string $jeton): int
    {
        return (int) $this->withToken($jeton)->getJson('/api/sync/tirer?curseur=0')->json('curseur');
    }
}
