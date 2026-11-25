<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Controle;
use App\Models\DemandeAnnulation;
use App\Models\Message;
use App\Models\Paiement;
use App\Models\Parametre;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\Tarif;
use App\Models\Travailleur;
use App\Models\Utilisateur;
use App\Services\Atelier\Corrections;

/**
 * Etape 3 : ce que la receptionniste envoie sur les commandes parties a l'atelier. Accord du client
 * sur une correction (RG-09), demande d'annulation et restitution de l'avance (RG-24).
 */
class SynchronisationReceptionTest extends ApiPosteTestCase
{
    private Site $boutique;

    private Site $atelier;

    private Utilisateur $aicha;

    private Utilisateur $chef;

    private Tarif $chemise;

    private Tarif $costume;

    private string $clientId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['pressing.sync.marge_secondes' => 0]);

        $this->boutique = $this->creerSite('CV');
        $this->atelier = $this->creerSite('ATL', Site::ATELIER);
        $this->creerPoste($this->boutique, 'jeton-cv');
        $this->creerPoste($this->atelier, 'jeton-atl');
        $this->aicha = $this->creerUtilisateur($this->boutique, 'aicha');
        $this->chef = $this->creerUtilisateur($this->atelier, 'chef', Utilisateur::CHEF_ATELIER);
        $this->chemise = $this->creerTarif(1500);
        $this->costume = $this->creerTarif(5000, 'Costume 2 pièces', 'Nettoyage à sec');

        $this->clientId = (string) str()->uuid();
        $this->pousser('jeton-cv', $this->operationClient($this->clientId, '699112233'));
    }

    // ------------------------------------------------------------------ ecritures

    private function operationAccord(string $controleId, array $remplacer = []): array
    {
        return $this->operation('accords', $remplacer + [
            'id' => $controleId,
            'accepte_par' => $this->aicha->id,
            'accepte_le' => '2026-10-07T09:42:00Z',
        ]);
    }

    private function operationDemande(string $commandeId, array $remplacer = []): array
    {
        return $this->operation('demandes_annulation', $remplacer + [
            'id' => (string) str()->uuid(),
            'commande_id' => $commandeId,
            'demandee_par' => $this->aicha->id,
            'motif' => 'Le client a changé d\'avis',
            'demandee_le' => '2026-10-06T10:30:00Z',
        ]);
    }

    private function operationPaiement(string $commandeId, string $sessionId, int $montant, array $remplacer = []): array
    {
        return $this->operation('paiements', $remplacer + [
            'id' => (string) str()->uuid(),
            'commande_id' => $commandeId,
            'session_caisse_id' => $sessionId,
            'utilisateur_id' => $this->aicha->id,
            'type' => 'ACOMPTE',
            'montant' => (string) $montant,
            'encaisse_le' => '2026-10-06T09:16:00Z',
        ]);
    }

    private function operationSession(array $remplacer = []): array
    {
        return $this->operation('sessions_caisse', $remplacer + [
            'id' => (string) str()->uuid(),
            'ouverte_par' => $this->aicha->id,
            'ouverte_le' => '2026-10-06T08:00:00Z',
            'fond_initial' => '5000',
        ]);
    }

    // ------------------------------------------------------------------ situations

    /** Commande de deux chemises et un costume (8 000), enregistree par la boutique. */
    private function commandeDeLaBoutique(string $jeton = 'jeton-cv', ?Utilisateur $auteur = null, string $codeSite = 'CV'): Commande
    {
        $id = (string) str()->uuid();
        $this->pousser($jeton, $this->operation('commandes', [
            'id' => $id,
            'code' => $codeSite.'-S41-10-2026-'.strtoupper(substr($id, 0, 4)),
            'client_id' => $this->clientId,
            'utilisateur_id' => ($auteur ?? $this->aicha)->id,
            'statut' => 'ENREGISTREE',
            'deposee_le' => '2026-10-06T09:15:00Z',
            'lignes' => [
                ['id' => (string) str()->uuid(), 'tarif_id' => $this->chemise->id, 'quantite' => 2,
                    'prix_applique' => '1500', 'commission_appliquee' => '200'],
                ['id' => (string) str()->uuid(), 'tarif_id' => $this->costume->id, 'quantite' => 1,
                    'prix_applique' => '5000', 'commission_appliquee' => '800'],
            ],
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        return Commande::findOrFail($id);
    }

    /** Commande partie a l'atelier par un lot aller, que l'atelier a recu. */
    private function commandeRecue(): Commande
    {
        $commande = $this->commandeDeLaBoutique();
        $lot = $this->operation('lots', [
            'id' => (string) str()->uuid(),
            'sens' => 'ALLER',
            'site_destination_id' => $this->atelier->id,
            'cloture_par' => $this->aicha->id,
            'cloture_le' => '2026-10-06T12:00:00Z',
            'commande_ids' => [$commande->id],
        ]);
        $this->pousser('jeton-cv', $lot)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->pousser('jeton-atl', $this->operation('receptions_lot', [
            'id' => $lot['ligne_id'],
            'recu_par' => $this->chef->id,
            'recu_le' => '2026-10-06T15:00:00Z',
            'nombre_recu' => 1,
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $commande->refresh();
    }

    /**
     * Commande recue dont le controle a releve un ecart sur une ligne : elle attend l'accord du client.
     *
     * @return array{0: Commande, 1: Controle}
     */
    private function commandeEnAttenteDAccord(?Tarif $tarif = null, int $constate = 1): array
    {
        $commande = $this->commandeRecue();
        $controle = $this->operation('controles', [
            'id' => (string) str()->uuid(),
            'commande_id' => $commande->id,
            'utilisateur_id' => $this->chef->id,
            'resultat' => 'ECART',
            'remarque' => 'Une seule chemise dans le sac',
            'controle_le' => '2026-10-06T15:30:00Z',
            'ligne_commande_id' => $commande->lignes->firstWhere('tarif_id', ($tarif ?? $this->chemise)->id)->id,
            'quantite_apres' => $constate,
        ]);
        $this->pousser('jeton-atl', $controle)->assertJsonPath('resultats.0.resultat', 'appliquee');

        return [$commande->refresh(), Controle::findOrFail($controle['ligne_id'])];
    }

    /** Ce que la confirmation du Promoteur fait a la commande (voir AnnulationController). */
    private function annuler(Commande $commande): Commande
    {
        $commande->statut = Commande::ANNULEE;
        $commande->recalculer();

        return $commande->refresh();
    }

    private function sessionOuverte(array $remplacer = []): string
    {
        $operation = $this->operationSession($remplacer);
        $this->pousser('jeton-cv', $operation)->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $operation['ligne_id'];
    }

    private function changements(string $jeton, string $table, int $depuis = 0)
    {
        return $this->changementsPour($jeton, $depuis)->where('table', $table)->values();
    }

    private function instantane(string $jeton)
    {
        return collect($this->withToken($jeton)->getJson('/api/sync/instantane')->assertOk()->json('changements'))->groupBy('table');
    }

    // ------------------------------------------------------------------ accord du client (RG-09)

    public function test_l_accord_du_client_applique_la_correction_et_debloque_la_commande_sur_les_deux_postes(): void
    {
        [$commande, $controle] = $this->commandeEnAttenteDAccord();
        $chemises = $commande->lignes->firstWhere('tarif_id', $this->chemise->id);
        $this->assertSame(Commande::EN_ATTENTE_ACCORD, $commande->statut);
        $depuisBoutique = $this->changementsPour('jeton-cv')->max('seq');
        $depuisAtelier = $this->changementsPour('jeton-atl')->max('seq');

        $this->pousser('jeton-cv', $this->operationAccord($controle->id))->assertJsonPath('resultats.0.resultat', 'appliquee');

        // RG-09 : la ligne prend la quantite constatee, la facture est recalculee, la commande est debloquee
        $commande->refresh();
        $this->assertSame(Commande::CONTROLEE, $commande->statut);
        $this->assertSame(1, $chemises->refresh()->quantite);
        $this->assertSame('6500.00', $commande->montant);
        $this->assertSame('6500.00', $commande->reste);
        $controle->refresh();
        $this->assertSame(Controle::ACCEPTEE, $controle->etat);
        $this->assertSame('2026-10-07T09:42:00Z', $controle->decide_le->toIso8601ZuluString());
        $this->assertSame($this->aicha->id, $controle->decide_par);
        $this->assertSame('8000.00', $controle->montant_avant);
        $this->assertSame('6500.00', $controle->montant_apres);

        // L'atelier, qui attendait pour traiter, et la boutique, y compris le poste qui a envoye
        // l'accord : il recoit la facture calculee par le serveur
        foreach (['jeton-cv' => $depuisBoutique, 'jeton-atl' => $depuisAtelier] as $jeton => $depuis) {
            $recue = $this->changements($jeton, 'commandes', $depuis)->sole()['donnees'];
            $this->assertSame('CONTROLEE', $recue['statut'], $jeton);
            $this->assertSame('6500.00', $recue['montant'], $jeton);
            $this->assertSame('6500.00', $recue['reste'], $jeton);
            $this->assertSame([1, 1], collect($recue['lignes'])->pluck('quantite')->sort()->values()->all(), $jeton);
            $accepte = $this->changements($jeton, 'controles', $depuis)->sole()['donnees'];
            $this->assertSame('ACCEPTEE', $accepte['etat'], $jeton);
            $this->assertSame('2026-10-07T09:42:00Z', $accepte['decide_le'], $jeton);
            // Le controle arrive avant la commande
            $this->assertSame(['controles', 'commandes'], $this->changementsPour($jeton, $depuis)->pluck('table')->all(), $jeton);
        }

        // §8 : le message « Correction acceptee » vient du poste ; le serveur n'en cree pas
        $this->assertSame(0, Message::count());
        $message = $this->operation('messages', [
            'id' => (string) str()->uuid(), 'client_id' => $this->clientId, 'commande_id' => $commande->id,
            'declencheur' => 'CORRECTION_ACCEPTEE', 'contenu' => 'Votre facture passe de 8 000 à 6 500 FCFA.',
            'cree_le' => '2026-10-07T09:42:00Z',
        ]);
        $this->pousser('jeton-cv', $message)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame('CORRECTION_ACCEPTEE', Message::sole()->declencheur);

        // Debloquee, la commande recoit ses taches
        $travailleur = $this->operation('travailleurs', [
            'id' => (string) str()->uuid(), 'matricule' => 'VP-A2B3C-T', 'nom' => 'Moussa Hamadou',
            'cree_par' => $this->chef->id, 'enrole_le' => '2026-10-05T08:00:00Z',
        ]);
        $tache = $this->operation('taches', [
            'id' => (string) str()->uuid(), 'ligne_commande_id' => $chemises->id, 'travailleur_id' => $travailleur['ligne_id'],
            'quantite' => 1, 'attribuee_par' => $this->chef->id, 'attribuee_le' => '2026-10-07T10:00:00Z', 'terminee_le' => null,
        ]);
        $this->pousser('jeton-atl', $travailleur, $tache)->assertJsonPath('resultats.1.resultat', 'appliquee');
        $this->assertSame(1, Travailleur::count());
        $this->assertSame(Commande::EN_TRAITEMENT, $commande->refresh()->statut);
    }

    public function test_un_accord_sur_une_ligne_ramenee_a_zero_la_retire_de_la_facture(): void
    {
        [$commande, $controle] = $this->commandeEnAttenteDAccord($this->costume, 0);

        $this->pousser('jeton-cv', $this->operationAccord($controle->id))->assertJsonPath('resultats.0.resultat', 'appliquee');

        $commande->refresh();
        $this->assertSame(Commande::CONTROLEE, $commande->statut);
        $this->assertSame([$this->chemise->id], $commande->lignes->pluck('tarif_id')->all());
        $this->assertSame('3000.00', $commande->montant);
        $this->assertSame('3000.00', $controle->refresh()->montant_apres);
    }

    public function test_un_accord_renvoye_ne_s_applique_qu_une_fois(): void
    {
        [$commande, $controle] = $this->commandeEnAttenteDAccord();
        $accord = $this->operationAccord($controle->id);
        $this->pousser('jeton-cv', $accord)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $depuis = $this->changementsPour('jeton-atl')->max('seq');

        // La meme operation renvoyee apres une coupure, puis le meme accord sous un autre numero
        $this->pousser('jeton-cv', $accord)->assertJsonPath('resultats.0.resultat', 'deja_appliquee');
        $this->pousser('jeton-cv', $this->operationAccord($controle->id, ['accepte_le' => '2026-10-08T08:00:00Z']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        // Rien n'est refait : ni la facture, ni la date de l'accord, ni une publication
        $this->assertSame('6500.00', $commande->refresh()->montant);
        $this->assertSame(1, $commande->lignes->firstWhere('tarif_id', $this->chemise->id)->quantite);
        $this->assertSame('2026-10-07T09:42:00Z', $controle->refresh()->decide_le->toIso8601ZuluString());
        $this->assertCount(0, $this->changementsPour('jeton-atl', $depuis));
    }

    public function test_un_accord_arrive_apres_l_application_d_office_ne_la_defait_pas(): void
    {
        $this->travelTo('2026-10-06 16:00:00');
        Parametre::updateOrCreate(['cle' => Parametre::DELAI_ACCORD_ANOMALIE_JOURS], ['valeur' => '1', 'libelle' => 'Délai']);
        [$commande, $controle] = $this->commandeEnAttenteDAccord();

        // Le delai passe pendant que la boutique est hors ligne : le serveur applique la correction d'office
        $this->travelTo('2026-10-08 16:00:00');
        $this->assertSame(1, app(Corrections::class)->appliquerEchues());
        $this->assertSame(Controle::APPLIQUEE_APRES_DELAI, $controle->refresh()->etat);
        $decideLe = $controle->decide_le->toIso8601ZuluString();
        $depuis = $this->changementsPour('jeton-atl')->max('seq');

        // L'accord enregistre entre-temps sur le poste arrive ensuite : accepte, sans rien refaire
        $this->pousser('jeton-cv', $this->operationAccord($controle->id))->assertJsonPath('resultats.0.resultat', 'appliquee');

        $controle->refresh();
        $this->assertSame(Controle::APPLIQUEE_APRES_DELAI, $controle->etat);
        $this->assertSame($decideLe, $controle->decide_le->toIso8601ZuluString());
        $this->assertNull($controle->decide_par);
        $this->assertSame('6500.00', $commande->refresh()->montant);
        $this->assertSame(Commande::CONTROLEE, $commande->statut);
        $this->assertCount(0, $this->changementsPour('jeton-atl', $depuis));
        $this->assertSame(1, Message::where('declencheur', Corrections::DECLENCHEUR_APRES_DELAI)->count());
    }

    public function test_une_correction_acceptee_n_est_pas_appliquee_une_seconde_fois_apres_le_delai(): void
    {
        $this->travelTo('2026-10-06 16:00:00');
        Parametre::updateOrCreate(['cle' => Parametre::DELAI_ACCORD_ANOMALIE_JOURS], ['valeur' => '1', 'libelle' => 'Délai']);
        [$commande, $controle] = $this->commandeEnAttenteDAccord();
        $this->pousser('jeton-cv', $this->operationAccord($controle->id))->assertJsonPath('resultats.0.resultat', 'appliquee');

        $this->travelTo('2026-10-08 16:00:00');
        $this->assertSame(0, app(Corrections::class)->appliquerEchues());

        $this->assertSame(Controle::ACCEPTEE, $controle->refresh()->etat);
        $this->assertSame('6500.00', $commande->refresh()->montant);
        // Le client qui a donne son accord ne recoit pas l'avertissement d'une application d'office
        $this->assertSame(0, Message::count());
    }

    public function test_un_accord_mal_adresse_est_refuse(): void
    {
        [$commande, $controle] = $this->commandeEnAttenteDAccord();
        $conforme = $this->commandeRecue();
        $sansCorrection = $this->operation('controles', [
            'id' => (string) str()->uuid(), 'commande_id' => $conforme->id, 'utilisateur_id' => $this->chef->id,
            'resultat' => 'CONFORME', 'remarque' => null, 'controle_le' => '2026-10-06T15:30:00Z',
        ]);
        $this->pousser('jeton-atl', $sansCorrection)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $voisine = $this->creerSite('MC');
        $this->creerPoste($voisine, 'jeton-mc');

        $this->pousser(
            'jeton-cv',
            $this->operationAccord((string) str()->uuid()),
            $this->operationAccord($controle->id, ['accepte_par' => (string) str()->uuid()]),
            $this->operationAccord($controle->id, ['accepte_le' => null]),
            // un controle conforme n'a jamais propose de correction
            $this->operationAccord($sansCorrection['ligne_id']),
        )
            ->assertJsonPath('resultats.0.motif', 'controle_inconnu')
            ->assertJsonPath('resultats.1.motif', 'utilisateur_inconnu')
            ->assertJsonPath('resultats.2.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.3.motif', 'donnees_invalides');
        // C'est la boutique qui a enregistre la commande qui recueille l'accord : ni l'atelier, ni une autre boutique
        $this->pousser('jeton-atl', $this->operationAccord($controle->id, ['accepte_par' => $this->chef->id]))
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');
        $this->pousser('jeton-mc', $this->operationAccord($controle->id))
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');

        $this->assertSame(Controle::PROPOSEE, $controle->refresh()->etat);
        $this->assertSame(Commande::EN_ATTENTE_ACCORD, $commande->refresh()->statut);
        $this->assertSame('8000.00', $commande->montant);
    }

    public function test_un_accord_sur_une_commande_annulee_entre_temps_reste_sans_effet(): void
    {
        [$commande, $controle] = $this->commandeEnAttenteDAccord();
        $this->annuler($commande);

        $this->pousser('jeton-cv', $this->operationAccord($controle->id))->assertJsonPath('resultats.0.resultat', 'appliquee');

        // RG-24 : la correction n'a plus lieu d'etre appliquee, la facture ne bouge pas
        $this->assertSame(Commande::ANNULEE, $commande->refresh()->statut);
        $this->assertSame('8000.00', $commande->montant);
        $this->assertSame(Controle::PROPOSEE, $controle->refresh()->etat);
    }

    // ------------------------------------------------------------------ demande d'annulation (RG-24)

    public function test_la_receptionniste_demande_l_annulation_d_une_commande_de_son_site(): void
    {
        $commande = $this->commandeRecue();
        $depuisBoutique = $this->changementsPour('jeton-cv')->max('seq');
        $depuisAtelier = $this->changementsPour('jeton-atl')->max('seq');

        $operation = $this->operationDemande($commande->id);
        $this->pousser('jeton-cv', $operation)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $demande = DemandeAnnulation::findOrFail($operation['ligne_id']);
        $this->assertSame($commande->id, $demande->commande_id);
        $this->assertSame($this->aicha->id, $demande->demandee_par);
        $this->assertSame('Le client a changé d\'avis', $demande->motif);
        $this->assertSame('2026-10-06T10:30:00Z', $demande->demandee_le->toIso8601ZuluString());
        $this->assertSame(DemandeAnnulation::DEMANDEE, $demande->etat);
        $this->assertNull($demande->decidee_le);
        // L'annulation n'est effective qu'apres la confirmation du Promoteur : la commande ne change pas
        $this->assertSame(Commande::RECEPTIONNEE, $commande->refresh()->statut);
        // Le poste ne recoit pas en retour ce qu'il vient d'envoyer, et l'atelier n'en sait rien
        $this->assertCount(0, $this->changementsPour('jeton-cv', $depuisBoutique));
        $this->assertCount(0, $this->changementsPour('jeton-atl', $depuisAtelier));

        // Renvoyee sous un autre numero d'operation apres une coupure : rien a refaire
        $this->pousser('jeton-cv', $this->operation('demandes_annulation', $operation['donnees']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        // Une seule demande en attente par commande
        $this->pousser('jeton-cv', $this->operationDemande($commande->id, ['motif' => 'Autre motif']))
            ->assertJsonPath('resultats.0.motif', 'demande_deja_en_cours');
        $this->assertSame(1, DemandeAnnulation::count());

        // Refusee par le Promoteur, elle n'empeche pas une nouvelle demande
        $demande->update(['etat' => DemandeAnnulation::REFUSEE, 'decidee_le' => now()]);
        $this->pousser('jeton-cv', $this->operationDemande($commande->id, ['motif' => 'Le client insiste']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        // La premiere, renvoyee une fois tranchee, ne redevient pas une demande en attente
        $this->pousser('jeton-cv', $this->operation('demandes_annulation', $operation['donnees']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame(DemandeAnnulation::REFUSEE, $demande->refresh()->etat);
        $this->assertSame(2, DemandeAnnulation::count());
    }

    public function test_une_commande_ne_s_annule_plus_une_fois_entree_en_traitement(): void
    {
        $commande = $this->commandeDeLaBoutique();

        foreach (['ENREGISTREE', 'ENVOYEE', 'RECEPTIONNEE', 'EN_ATTENTE_ACCORD', 'CONTROLEE'] as $statut) {
            Commande::whereKey($commande->id)->update(['statut' => $statut]);
            DemandeAnnulation::query()->update(['etat' => DemandeAnnulation::REFUSEE]);
            $this->pousser('jeton-cv', $this->operationDemande($commande->id))
                ->assertJsonPath('resultats.0.resultat', 'appliquee');
        }
        $avant = DemandeAnnulation::count();
        DemandeAnnulation::query()->update(['etat' => DemandeAnnulation::REFUSEE]);

        foreach (['EN_TRAITEMENT', 'TRAITEE', 'RETOURNEE', 'NON_RECLAMEE', 'LIVREE', 'DON', 'ANNULEE'] as $statut) {
            Commande::whereKey($commande->id)->update(['statut' => $statut]);
            $this->pousser('jeton-cv', $this->operationDemande($commande->id))
                ->assertJsonPath('resultats.0.motif', 'commande_non_annulable');
        }
        $this->assertSame($avant, DemandeAnnulation::count());
    }

    public function test_une_demande_d_annulation_mal_adressee_est_refusee(): void
    {
        $commande = $this->commandeDeLaBoutique();
        $voisine = $this->creerSite('MC');
        $this->creerPoste($voisine, 'jeton-mc');
        $autre = $this->commandeDeLaBoutique();
        $dejaRecue = $this->operationDemande($autre->id);
        $this->pousser('jeton-cv', $dejaRecue)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $this->pousser(
            'jeton-cv',
            $this->operationDemande((string) str()->uuid()),
            $this->operationDemande($commande->id, ['demandee_par' => (string) str()->uuid()]),
            // RG-24 : une demande porte son motif
            $this->operationDemande($commande->id, ['motif' => '']),
            $this->operationDemande($commande->id, ['motif' => str_repeat('a', 501)]),
            $this->operationDemande($commande->id, ['demandee_le' => 'hier matin']),
            // l'identifiant d'une demande deja recue ne sert pas pour une autre commande
            $this->operationDemande($commande->id, ['id' => $dejaRecue['ligne_id']]),
        )
            ->assertJsonPath('resultats.0.motif', 'commande_inconnue')
            ->assertJsonPath('resultats.1.motif', 'utilisateur_inconnu')
            ->assertJsonPath('resultats.2.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.3.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.4.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.5.motif', 'donnees_invalides');
        // Un site ne demande pas l'annulation des commandes d'un autre
        $this->pousser('jeton-mc', $this->operationDemande($commande->id))
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');
        $this->pousser('jeton-atl', $this->operationDemande($commande->id, ['demandee_par' => $this->chef->id]))
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');

        $this->assertSame(1, DemandeAnnulation::count());
        $this->assertSame($autre->id, DemandeAnnulation::sole()->commande_id);
    }

    // ------------------------------------------------------------------ restitution de l'avance (RG-24)

    public function test_l_avance_d_une_commande_annulee_est_restituee_en_entier_sur_une_caisse_du_site(): void
    {
        $commande = $this->commandeDeLaBoutique();
        // L'avance : 3 000 le jour du depot, sur une caisse cloturee le soir
        $premierJour = $this->operationSession();
        $this->pousser('jeton-cv', $premierJour, $this->operationPaiement($commande->id, $premierJour['ligne_id'], 3000));
        $this->pousser('jeton-cv', $this->operationSession([
            'id' => $premierJour['ligne_id'], 'cloturee_par' => $this->aicha->id,
            'cloturee_le' => '2026-10-06T18:00:00Z', 'total_compte' => '8000',
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');
        // Le lendemain, caisse ouverte avec 5 000 de fond puis cloturee, avant que le poste ne synchronise
        $lendemain = $this->operationSession(['ouverte_le' => '2026-10-07T08:00:00Z']);
        $cloture = $this->operationSession([
            'id' => $lendemain['ligne_id'], 'ouverte_le' => '2026-10-07T08:00:00Z', 'cloturee_par' => $this->aicha->id,
            'cloturee_le' => '2026-10-07T18:00:00Z', 'total_compte' => '2000',
        ]);
        $this->pousser('jeton-cv', $lendemain, $cloture)->assertJsonPath('resultats.1.resultat', 'appliquee');
        $session = SessionCaisse::findOrFail($lendemain['ligne_id']);
        $this->assertSame('-3000.00', $session->ecart);
        $restitution = fn (int $montant, array $remplacer = []) => $this->operationPaiement($commande->id, $session->id, $montant, $remplacer + [
            'type' => 'REMBOURSEMENT', 'encaisse_le' => '2026-10-07T09:30:00Z',
        ]);

        // Tant que le Promoteur n'a pas confirme l'annulation, rien ne se restitue
        $this->pousser('jeton-cv', $restitution(3000))->assertJsonPath('resultats.0.motif', 'pas_de_trop_percu');

        $this->annuler($commande);
        $this->assertSame('3000.00', $commande->total_paye);
        $this->assertSame('0.00', $commande->reste);
        $depuis = $this->changementsPour('jeton-cv')->max('seq');

        // La restitution rend tout ce qui a ete verse, ni plus ni moins
        $rendue = $restitution(3000);
        $this->pousser('jeton-cv', $restitution(2999), $restitution(3001), $rendue)
            ->assertJsonPath('resultats.0.motif', 'montant_different_du_paye')
            ->assertJsonPath('resultats.1.motif', 'montant_different_du_paye')
            ->assertJsonPath('resultats.2.resultat', 'appliquee');

        $paiement = Paiement::findOrFail($rendue['ligne_id']);
        $this->assertSame(Paiement::REMBOURSEMENT, $paiement->type);
        $this->assertSame('3000.00', $paiement->montant);
        $this->assertSame($session->id, $paiement->session_caisse_id);
        // Une avance restituee n'est ni une recette ni un recouvrement tardif
        $this->assertNull($paiement->affectation);
        $commande->refresh();
        $this->assertSame('0.00', $commande->total_paye);
        $this->assertSame('0.00', $commande->reste);
        $this->assertSame(Commande::ANNULEE, $commande->statut);
        // RG-18 : sortie de la caisse du jour, deduite de ses comptes ; la session deja cloturee est recalculee
        $session->refresh();
        $this->assertSame('2000.00', $session->total_attendu);
        $this->assertSame('0.00', $session->ecart);
        // Le poste a fait le meme calcul : rien ne lui revient
        $this->assertCount(0, $this->changements('jeton-cv', 'commandes', $depuis));

        // Il ne reste rien a rendre : ni une seconde restitution, ni la meme sous un autre numero
        $this->pousser('jeton-cv', $restitution(3000), $this->operation('paiements', $rendue['donnees']))
            ->assertJsonPath('resultats.0.motif', 'montant_different_du_paye')
            ->assertJsonPath('resultats.1.resultat', 'appliquee');
        $this->assertSame(2, Paiement::count());
    }

    public function test_un_versement_encaisse_avant_d_apprendre_l_annulation_se_restitue_avec_l_avance(): void
    {
        $commande = $this->commandeDeLaBoutique();
        $session = $this->sessionOuverte();
        $this->pousser('jeton-cv', $this->operationPaiement($commande->id, $session, 3000))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->annuler($commande);

        // La boutique, hors ligne, ne savait pas encore la commande annulee : l'argent est en caisse,
        // le serveur l'enregistre plutot que de le perdre
        $this->pousser('jeton-cv', $this->operationPaiement($commande->id, $session, 1000))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $commande->refresh();
        $this->assertSame(Commande::ANNULEE, $commande->statut);
        $this->assertSame('4000.00', $commande->total_paye);
        $this->assertSame('0.00', $commande->reste);

        // Tout ce qui a ete verse se rend au client, en une fois
        $this->pousser('jeton-cv', $this->operationPaiement($commande->id, $session, 3000, ['type' => 'REMBOURSEMENT']))
            ->assertJsonPath('resultats.0.motif', 'montant_different_du_paye');
        $this->pousser('jeton-cv', $this->operationPaiement($commande->id, $session, 4000, ['type' => 'REMBOURSEMENT']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame('0.00', $commande->refresh()->total_paye);
    }

    public function test_une_restitution_suit_les_regles_de_tout_paiement(): void
    {
        $commande = $this->commandeDeLaBoutique();
        $session = $this->sessionOuverte();
        $this->pousser('jeton-cv', $this->operationPaiement($commande->id, $session, 3000));
        $this->annuler($commande);
        $restitution = fn (array $remplacer = []) => $this->operationPaiement($commande->id, $session, 3000, $remplacer + [
            'type' => 'REMBOURSEMENT', 'encaisse_le' => '2026-10-06T11:00:00Z',
        ]);
        $voisine = $this->creerSite('MC');
        $this->creerPoste($voisine, 'jeton-mc');

        // RG-17 : pas de sortie de caisse sans session de caisse du site
        $this->pousser('jeton-cv', $restitution(['session_caisse_id' => (string) str()->uuid()]))
            ->assertJsonPath('resultats.0.motif', 'session_inconnue');
        // RG-10 : le chef d'atelier ne restitue que l'avance de ses propres commandes
        $this->pousser('jeton-cv', $restitution(['utilisateur_id' => $this->chef->id]))
            ->assertJsonPath('resultats.0.motif', 'hors_perimetre');
        $this->pousser('jeton-mc', $restitution())->assertJsonPath('resultats.0.motif', 'non_proprietaire');
        $this->assertSame('3000.00', $commande->refresh()->total_paye);

        $this->pousser('jeton-cv', $restitution())->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame('0.00', $commande->refresh()->total_paye);
    }

    // ------------------------------------------------------------------ etat initial

    public function test_l_etat_initial_d_une_boutique_garde_ses_demandes_et_les_avances_a_restituer(): void
    {
        $session = $this->sessionOuverte();
        $enAttente = $this->commandeRecue();
        $refusee = $this->commandeDeLaBoutique();
        $aRestituer = $this->commandeRecue();
        $sansAvance = $this->commandeDeLaBoutique();
        $livree = $this->commandeDeLaBoutique();
        $avance = $this->operationPaiement($aRestituer->id, $session, 3000);
        $demandes = [];
        foreach (['enAttente' => $enAttente, 'refusee' => $refusee, 'aRestituer' => $aRestituer, 'sansAvance' => $sansAvance, 'livree' => $livree] as $cle => $commande) {
            $demandes[$cle] = $this->operationDemande($commande->id);
        }
        $this->pousser('jeton-cv', $avance, ...array_values($demandes));
        // Les decisions du Promoteur
        DemandeAnnulation::whereKey($demandes['refusee']['ligne_id'])
            ->update(['etat' => DemandeAnnulation::REFUSEE, 'decidee_le' => '2026-10-07 08:00:00', 'motif_refus' => 'Déjà en route']);
        DemandeAnnulation::whereKey([$demandes['aRestituer']['ligne_id'], $demandes['sansAvance']['ligne_id']])
            ->update(['etat' => DemandeAnnulation::CONFIRMEE, 'decidee_le' => '2026-10-07 08:00:00']);
        $this->annuler($aRestituer);
        $this->annuler($sansAvance);
        Commande::whereKey($livree->id)->update(['statut' => Commande::LIVREE]);
        // Bien plus tard : les commandes sorties du suivi depuis longtemps ne sont plus envoyees
        $this->travel(9)->weeks();

        $boutique = $this->instantane('jeton-cv');

        // RG-24 : une commande annulee reste suivie tant que son avance n'est pas rendue
        $this->assertEqualsCanonicalizing([$enAttente->id, $refusee->id, $aRestituer->id], $boutique['commandes']->pluck('ligne_id')->all());
        $annulee = $boutique['commandes']->firstWhere('ligne_id', $aRestituer->id)['donnees'];
        $this->assertSame('ANNULEE', $annulee['statut']);
        $this->assertSame('3000.00', $annulee['total_paye']);
        $this->assertSame('0.00', $annulee['reste']);
        $this->assertSame([$avance['ligne_id']], $boutique['paiements']->pluck('ligne_id')->all());
        // Les demandes des commandes que le poste recoit, en attente ou deja tranchees
        $this->assertEqualsCanonicalizing(
            [$demandes['enAttente']['ligne_id'], $demandes['refusee']['ligne_id'], $demandes['aRestituer']['ligne_id']],
            $boutique['demandes_annulation']->pluck('ligne_id')->all(),
        );
        $this->assertSame([
            'id' => $demandes['refusee']['ligne_id'],
            'commande_id' => $refusee->id,
            'demandee_par' => $this->aicha->id,
            'motif' => 'Le client a changé d\'avis',
            'demandee_le' => '2026-10-06T10:30:00Z',
            'etat' => 'REFUSEE',
            'decidee_le' => '2026-10-07T08:00:00Z',
            'motif_refus' => 'Déjà en route',
        ], $boutique['demandes_annulation']->firstWhere('ligne_id', $demandes['refusee']['ligne_id'])['donnees']);
        $recue = $boutique['demandes_annulation']->firstWhere('ligne_id', $demandes['enAttente']['ligne_id'])['donnees'];
        $this->assertSame('DEMANDEE', $recue['etat']);
        $this->assertNull($recue['decidee_le']);
        // Une demande n'arrive jamais avant sa commande
        $ordre = collect($this->withToken('jeton-cv')->getJson('/api/sync/instantane')->json('changements'))->pluck('table')->unique()->values();
        $this->assertLessThan($ordre->search('demandes_annulation'), $ordre->search('commandes'));

        // L'atelier ne garde que les commandes qu'il a encore a traiter ; les demandes ne le concernent pas
        $atelier = $this->instantane('jeton-atl');
        $this->assertSame([$enAttente->id], $atelier['commandes']->pluck('ligne_id')->all());
        $this->assertArrayNotHasKey('demandes_annulation', $atelier->all());

        // L'avance rendue, la commande annulee sort du suivi avec sa demande
        $this->pousser('jeton-cv', $this->operationPaiement($aRestituer->id, $session, 3000, [
            'type' => 'REMBOURSEMENT', 'encaisse_le' => '2026-10-07T09:30:00Z',
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');
        // (passe le delai pendant lequel un poste remis en service retrouve les commandes sorties du suivi)
        $this->travel(9)->weeks();
        $apres = $this->instantane('jeton-cv');
        $this->assertEqualsCanonicalizing([$enAttente->id, $refusee->id], $apres['commandes']->pluck('ligne_id')->all());
        $this->assertEqualsCanonicalizing(
            [$demandes['enAttente']['ligne_id'], $demandes['refusee']['ligne_id']],
            $apres['demandes_annulation']->pluck('ligne_id')->all(),
        );
        $this->assertArrayNotHasKey('paiements', $apres->all());
    }
}
