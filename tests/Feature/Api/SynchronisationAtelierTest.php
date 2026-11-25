<?php

namespace Tests\Feature\Api;

use App\Models\Commande;
use App\Models\Consommable;
use App\Models\Controle;
use App\Models\JournalAudit;
use App\Models\LigneCommande;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Parametre;
use App\Models\Service;
use App\Models\Site;
use App\Models\Tache;
use App\Models\Tarif;
use App\Models\Travailleur;
use App\Models\Utilisateur;
use App\Services\Atelier\Corrections;
use App\Support\Audit;

/** Etape 3 : ce que l'atelier envoie au serveur (reception, controle, taches, retours, travailleurs, consommables). */
class SynchronisationAtelierTest extends ApiPosteTestCase
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

    /** Commande de deux chemises et un costume, telle que le poste du site donne l'envoie. */
    private function operationCommande(Utilisateur $auteur, string $codeSite = 'CV'): array
    {
        $id = (string) str()->uuid();

        return $this->operation('commandes', [
            'id' => $id,
            'code' => $codeSite.'-S41-10-2026-'.strtoupper(substr($id, 0, 4)),
            'client_id' => $this->clientId,
            'utilisateur_id' => $auteur->id,
            'statut' => 'ENREGISTREE',
            'deposee_le' => '2026-10-06T09:15:00Z',
            // chaque article est lave puis repasse ; la commission se partage entre les deux etapes
            'lignes' => [
                ['id' => (string) str()->uuid(), 'tarif_id' => $this->chemise->id, 'quantite' => 2,
                    'prix_applique' => '1500', 'commission_appliquee' => '200', 'avec_lavage' => true,
                    'avec_repassage' => true, 'commission_lavage' => '120', 'commission_repassage' => '80'],
                ['id' => (string) str()->uuid(), 'tarif_id' => $this->costume->id, 'quantite' => 1,
                    'prix_applique' => '5000', 'commission_appliquee' => '800', 'avec_lavage' => true,
                    'avec_repassage' => true, 'commission_lavage' => '500', 'commission_repassage' => '300'],
            ],
        ]);
    }

    private function operationLot(array $commandeIds, array $remplacer = []): array
    {
        return $this->operation('lots', $remplacer + [
            'id' => (string) str()->uuid(),
            'sens' => 'ALLER',
            'site_destination_id' => $this->atelier->id,
            'cloture_par' => $this->aicha->id,
            'cloture_le' => '2026-10-06T12:00:00Z',
            'commande_ids' => $commandeIds,
        ]);
    }

    private function operationReception(string $lotId, int $nombreRecu, ?Utilisateur $par = null): array
    {
        return $this->operation('receptions_lot', [
            'id' => $lotId,
            'recu_par' => ($par ?? $this->chef)->id,
            'recu_le' => '2026-10-06T15:00:00Z',
            'nombre_recu' => $nombreRecu,
        ]);
    }

    private function operationControle(string $commandeId, string $resultat = 'CONFORME', array $remplacer = []): array
    {
        return $this->operation('controles', $remplacer + [
            'id' => (string) str()->uuid(),
            'commande_id' => $commandeId,
            'utilisateur_id' => $this->chef->id,
            'resultat' => $resultat,
            'remarque' => null,
            'controle_le' => '2026-10-06T15:30:00Z',
        ]);
    }

    private function operationTravailleur(string $matricule = 'VP-A2B3C-T', array $remplacer = []): array
    {
        return $this->operation('travailleurs', $remplacer + [
            'id' => (string) str()->uuid(),
            'matricule' => $matricule,
            'nom' => 'Moussa Hamadou',
            'cni' => '112233445',
            'telephone' => '677001122',
            'quartier' => 'Pitoaré',
            'cree_par' => $this->chef->id,
            'enrole_le' => '2026-10-05T08:00:00Z',
        ]);
    }

    private function operationTache(string $ligneId, string $travailleurId, int $quantite, array $remplacer = [],
        string $etape = Tache::LAVAGE): array
    {
        return $this->operation('taches', $remplacer + [
            'id' => (string) str()->uuid(),
            'ligne_commande_id' => $ligneId,
            'travailleur_id' => $travailleurId,
            'etape' => $etape,
            'quantite' => $quantite,
            'attribuee_par' => $this->chef->id,
            'attribuee_le' => '2026-10-06T16:00:00Z',
            'terminee_le' => null,
        ]);
    }

    /** La meme tache, renvoyee une fois terminee. */
    private function terminee(array $tache, string $le = '2026-10-07T11:00:00Z'): array
    {
        return $this->operation('taches', ['terminee_le' => $le] + $tache['donnees']);
    }

    private function operationRemise(string $commandeId, Utilisateur $par): array
    {
        return $this->operation('remises', [
            'id' => $commandeId,
            'remise_par' => $par->id,
            'remise_le' => '2026-10-09T10:00:00Z',
        ]);
    }

    // ------------------------------------------------------------------ situations

    /** Commande de la boutique, deja recue par le serveur. */
    private function commandeDeLaBoutique(): Commande
    {
        $operation = $this->operationCommande($this->aicha);
        $this->pousser('jeton-cv', $operation)->assertJsonPath('resultats.0.resultat', 'appliquee');

        return Commande::findOrFail($operation['ligne_id']);
    }

    /** @return array{0: Commande, 1: string} la commande et le lot aller qui l'a emportee, en route vers l'atelier */
    private function commandeEnvoyee(): array
    {
        $commande = $this->commandeDeLaBoutique();
        $lot = $this->operationLot([$commande->id]);
        $this->pousser('jeton-cv', $lot)->assertJsonPath('resultats.0.resultat', 'appliquee');

        return [$commande, $lot['ligne_id']];
    }

    private function commandeRecue(): Commande
    {
        [$commande, $lotId] = $this->commandeEnvoyee();
        $this->pousser('jeton-atl', $this->operationReception($lotId, 1))->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $commande->refresh();
    }

    private function commandeControlee(): Commande
    {
        $commande = $this->commandeRecue();
        $this->pousser('jeton-atl', $this->operationControle($commande->id))->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $commande->refresh();
    }

    private function travailleur(string $matricule = 'VP-A2B3C-T'): string
    {
        $operation = $this->operationTravailleur($matricule);
        $this->pousser('jeton-atl', $operation)->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $operation['ligne_id'];
    }

    /** Commande controlee dont chaque article a ete lave puis repasse, par des taches terminees. */
    private function commandeTraitee(): Commande
    {
        $commande = $this->commandeControlee();
        $travailleur = Travailleur::value('id') ?? $this->travailleur();
        $operations = [];
        foreach ($commande->lignes as $ligne) {
            foreach (Tache::ETAPES as $etape) {
                $tache = $this->operationTache($ligne->id, $travailleur, $ligne->quantite, [], $etape);
                $operations[] = $tache;
                $operations[] = $this->terminee($tache);
            }
        }
        $reponse = $this->pousser('jeton-atl', ...$operations);
        foreach (array_keys($operations) as $rang) {
            $reponse->assertJsonPath('resultats.'.$rang.'.resultat', 'appliquee');
        }

        return $commande->refresh();
    }

    private function changements(string $jeton, string $table, int $depuis = 0)
    {
        return $this->changementsPour($jeton, $depuis)->where('table', $table)->values();
    }

    // ------------------------------------------------------------------ reception des lots

    public function test_l_atelier_accuse_reception_d_un_lot_et_ses_commandes_sont_receptionnees(): void
    {
        [$commande, $lotId] = $this->commandeEnvoyee();
        $autre = $this->commandeDeLaBoutique();
        $depuis = $this->changementsPour('jeton-cv')->max('seq');

        $this->pousser('jeton-atl', $this->operationReception($lotId, 1))->assertJsonPath('resultats.0.resultat', 'appliquee');

        $lot = Lot::findOrFail($lotId);
        $this->assertSame($this->chef->id, $lot->recu_par);
        $this->assertSame('2026-10-06T15:00:00Z', $lot->recu_le->toIso8601ZuluString());
        $this->assertSame(1, $lot->nombre_recu);
        $this->assertFalse($lot->aUnEcart());
        // RG-05 : la reception du lot fait passer ses commandes Receptionnees, et elles seules
        $this->assertSame(Commande::RECEPTIONNEE, $commande->refresh()->statut);
        $this->assertSame(Commande::ENREGISTREE, $autre->refresh()->statut);

        // La boutique l'apprend a sa synchronisation suivante
        $lotRecu = $this->changements('jeton-cv', 'lots', $depuis)->sole();
        $this->assertSame('2026-10-06T15:00:00Z', $lotRecu['donnees']['recu_le']);
        $this->assertSame(1, $lotRecu['donnees']['nombre_recu']);
        $this->assertSame('RECEPTIONNEE', $this->changements('jeton-cv', 'commandes', $depuis)->sole()['donnees']['statut']);
    }

    public function test_un_ecart_de_comptage_a_la_reception_est_garde_pour_le_promoteur(): void
    {
        $premiere = $this->commandeDeLaBoutique();
        $seconde = $this->commandeDeLaBoutique();
        $lot = $this->operationLot([$premiere->id, $seconde->id]);
        $this->pousser('jeton-cv', $lot);

        $this->pousser('jeton-atl', $this->operationReception($lot['ligne_id'], 1))->assertJsonPath('resultats.0.resultat', 'appliquee');

        // RG-19 : le nombre recu est compare au nombre annonce
        $recu = Lot::findOrFail($lot['ligne_id']);
        $this->assertSame(2, $recu->nombre_annonce);
        $this->assertSame(1, $recu->nombre_recu);
        $this->assertTrue($recu->aUnEcart());
        // Les commandes du lot sont tenues pour recues ; la commande manquante se repere au controle
        $this->assertSame(Commande::RECEPTIONNEE, $premiere->refresh()->statut);
        $this->assertSame(Commande::RECEPTIONNEE, $seconde->refresh()->statut);
    }

    public function test_une_reception_qui_ne_revient_pas_au_poste_est_refusee(): void
    {
        [$commande, $lotId] = $this->commandeEnvoyee();

        $this->pousser('jeton-atl', $this->operationReception((string) str()->uuid(), 1))
            ->assertJsonPath('resultats.0.motif', 'lot_inconnu');
        // La boutique qui a cloture le lot n'en est pas le destinataire
        $this->pousser('jeton-cv', $this->operationReception($lotId, 1, $this->aicha))
            ->assertJsonPath('resultats.0.motif', 'non_destinataire');
        $this->assertSame(Commande::ENVOYEE, $commande->refresh()->statut);

        $this->pousser('jeton-atl', $this->operationReception($lotId, 1))->assertJsonPath('resultats.0.resultat', 'appliquee');
        // Un lot ne se recoit qu'une fois
        $this->pousser('jeton-atl', $this->operationReception($lotId, 3))->assertJsonPath('resultats.0.motif', 'lot_deja_recu');
        $this->assertSame(1, Lot::findOrFail($lotId)->nombre_recu);
    }

    // ------------------------------------------------------------------ controle

    public function test_une_commande_conforme_ou_avec_une_simple_observation_est_controlee(): void
    {
        $conforme = $this->commandeRecue();
        $observee = $this->commandeRecue();
        $depuis = $this->changementsPour('jeton-cv')->max('seq');

        $this->pousser(
            'jeton-atl',
            $this->operationControle($conforme->id),
            $this->operationControle($observee->id, 'OBSERVATION', ['remarque' => 'Bouton manquant sur une chemise']),
        )->assertJsonPath('resultats.0.resultat', 'appliquee')->assertJsonPath('resultats.1.resultat', 'appliquee');

        $this->assertSame(Commande::CONTROLEE, $conforme->refresh()->statut);
        // RG-09 : une observation sans effet sur la facture ne bloque pas la commande
        $this->assertSame(Commande::CONTROLEE, $observee->refresh()->statut);
        $controle = Controle::where('commande_id', $observee->id)->sole();
        $this->assertSame('Bouton manquant sur une chemise', $controle->remarque);
        $this->assertNull($controle->etat);

        // La boutique suit ses commandes et voit le resultat du controle
        $this->assertSame(['CONTROLEE', 'CONTROLEE'],
            $this->changements('jeton-cv', 'commandes', $depuis)->pluck('donnees.statut')->all());
        $this->assertCount(2, $this->changements('jeton-cv', 'controles', $depuis));
    }

    public function test_un_ecart_de_quantite_propose_une_correction_et_bloque_la_commande(): void
    {
        $commande = $this->commandeRecue();
        $chemises = $commande->lignes->firstWhere('tarif_id', $this->chemise->id);
        $travailleur = $this->travailleur();

        $ecart = $this->operationControle($commande->id, 'ECART', [
            'ligne_commande_id' => $chemises->id,
            'quantite_apres' => 1,
            'remarque' => 'Une seule chemise dans le sac',
            // un poste trop confiant : la quantite enregistree est celle que le serveur connait
            'quantite_avant' => 9,
        ]);
        $this->pousser('jeton-atl', $ecart)->assertJsonPath('resultats.0.resultat', 'appliquee');

        // RG-09 : la commande attend l'accord du client, la facture ne change pas encore
        $commande->refresh();
        $this->assertSame(Commande::EN_ATTENTE_ACCORD, $commande->statut);
        $this->assertSame('8000.00', $commande->montant);
        $this->assertSame(2, $chemises->refresh()->quantite);

        $controle = Controle::findOrFail($ecart['ligne_id']);
        $this->assertSame(Controle::PROPOSEE, $controle->etat);
        $this->assertSame($chemises->id, $controle->ligne_commande_id);
        $this->assertSame($this->chemise->id, $controle->tarif_id);
        $this->assertSame(2, $controle->quantite_avant);
        $this->assertSame(1, $controle->quantite_apres);

        // RG-08 : bloquee, elle ne recoit pas de tache
        $this->pousser('jeton-atl', $this->operationTache($chemises->id, $travailleur, 1))
            ->assertJsonPath('resultats.0.motif', 'commande_non_attribuable');
        // La correction arrive a la boutique, qui doit contacter le client
        $recue = $this->changements('jeton-cv', 'controles')->sole()['donnees'];
        $this->assertSame('PROPOSEE', $recue['etat']);
        $this->assertSame(2, $recue['quantite_avant']);
        $this->assertSame(1, $recue['quantite_apres']);
    }

    public function test_un_controle_mal_adresse_est_refuse(): void
    {
        [$enRoute] = $this->commandeEnvoyee();
        $jamaisEnvoyee = $this->commandeDeLaBoutique();
        $recue = $this->commandeRecue();
        $ligne = $recue->lignes->first();

        $this->pousser(
            'jeton-atl',
            // le lot n'est pas encore recu : rien a controler
            $this->operationControle($enRoute->id),
            $this->operationControle($jamaisEnvoyee->id),
            $this->operationControle((string) str()->uuid()),
            // un ecart doit designer une ligne de la commande et une quantite differente
            $this->operationControle($recue->id, 'ECART', ['ligne_commande_id' => (string) str()->uuid(), 'quantite_apres' => 1]),
            $this->operationControle($recue->id, 'ECART', ['ligne_commande_id' => $ligne->id, 'quantite_apres' => $ligne->quantite]),
            $this->operationControle($recue->id, 'INCONNU'),
        )
            ->assertJsonPath('resultats.0.motif', 'commande_absente_de_l_atelier')
            ->assertJsonPath('resultats.1.motif', 'commande_absente_de_l_atelier')
            ->assertJsonPath('resultats.2.motif', 'commande_inconnue')
            ->assertJsonPath('resultats.3.motif', 'ligne_inconnue')
            ->assertJsonPath('resultats.4.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.5.motif', 'donnees_invalides');
        $this->assertSame(0, Controle::count());

        // Une commande ne se controle qu'une fois
        $this->pousser('jeton-atl', $this->operationControle($recue->id), $this->operationControle($recue->id))
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.motif', 'commande_non_controlable');
        // La boutique ne controle pas les commandes parties a l'atelier
        $autre = $this->commandeRecue();
        $this->pousser('jeton-cv', $this->operationControle($autre->id, 'CONFORME', ['utilisateur_id' => $this->aicha->id]))
            ->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');
    }

    public function test_une_correction_sans_accord_s_applique_d_office_apres_le_delai_du_promoteur(): void
    {
        $this->travelTo('2026-10-06 16:00:00');
        $commande = $this->commandeRecue();
        $chemises = $commande->lignes->firstWhere('tarif_id', $this->chemise->id);
        $this->pousser('jeton-atl', $this->operationControle($commande->id, 'ECART', [
            'ligne_commande_id' => $chemises->id, 'quantite_apres' => 1, 'remarque' => 'Une seule chemise dans le sac',
        ]));
        $corrections = app(Corrections::class);

        // RG-23 : tant que le Promoteur n'a pas saisi de delai, la commande attend l'accord du client
        $this->travelTo('2026-11-06 16:00:00');
        $this->assertNull($corrections->delaiJours());
        $this->assertSame(0, $corrections->appliquerEchues());
        $this->assertSame(Commande::EN_ATTENTE_ACCORD, $commande->refresh()->statut);

        Parametre::updateOrCreate(['cle' => Parametre::DELAI_ACCORD_ANOMALIE_JOURS], ['valeur' => '3', 'libelle' => 'Délai']);
        // Le delai court depuis l'arrivee de la correction au serveur (le 6 a 16 h), pas depuis
        // l'heure du controle sur le poste de l'atelier (15 h 30)
        $this->travelTo('2026-10-09 15:45:00');
        $this->assertSame(0, $corrections->appliquerEchues(), 'le delai de trois jours n\'est pas encore ecoule');

        $this->travelTo('2026-10-09 16:01:00');
        $depuisBoutique = $this->changementsPour('jeton-cv')->max('seq');
        $depuisAtelier = $this->changementsPour('jeton-atl')->max('seq');
        $this->artisan('pressing:corrections-appliquer')->expectsOutputToContain('1 commande débloquée')->assertSuccessful();
        // §11 : la correction de facture decidee par le serveur est au journal d'audit
        $this->assertSame(1, JournalAudit::where('action', Audit::CORRECTION_APPLIQUEE_D_OFFICE)->count());
        $this->assertSame('Système', JournalAudit::where('action', Audit::CORRECTION_APPLIQUEE_D_OFFICE)->value('auteur'));

        // RG-09 : la facture est recalculee selon la correction et la commande est debloquee
        $commande->refresh();
        $this->assertSame(Commande::CONTROLEE, $commande->statut);
        $this->assertSame(1, $chemises->refresh()->quantite);
        $this->assertSame('6500.00', $commande->montant);
        $controle = Controle::sole();
        $this->assertSame(Controle::APPLIQUEE_APRES_DELAI, $controle->etat);
        $this->assertSame('8000.00', $controle->montant_avant);
        $this->assertSame('6500.00', $controle->montant_apres);
        $this->assertNotNull($controle->decide_le);

        // La boutique et l'atelier recoivent la commande corrigee
        foreach (['jeton-cv' => $depuisBoutique, 'jeton-atl' => $depuisAtelier] as $jeton => $depuis) {
            $recue = $this->changements($jeton, 'commandes', $depuis)->last()['donnees'];
            $this->assertSame('CONTROLEE', $recue['statut'], $jeton);
            $this->assertSame('6500.00', $recue['montant'], $jeton);
            $this->assertSame([1, 1], collect($recue['lignes'])->pluck('quantite')->sort()->values()->all(), $jeton);
            $this->assertSame('APPLIQUEE_APRES_DELAI', $this->changements($jeton, 'controles', $depuis)->last()['donnees']['etat'], $jeton);
        }

        // §8 : le client est averti, avec l'ancien et le nouveau montant
        $message = Message::where('declencheur', Corrections::DECLENCHEUR_APRES_DELAI)->sole();
        $this->assertSame($commande->id, $message->commande_id);
        $this->assertStringContainsString("8\u{00A0}000\u{00A0}FCFA", $message->contenu);
        $this->assertStringContainsString("6\u{00A0}500\u{00A0}FCFA", $message->contenu);
        $this->assertStringContainsString('Une seule chemise dans le sac', $message->contenu);

        // Relancee, la commande n'est pas retouchee
        $this->assertSame(0, $corrections->appliquerEchues());
        $this->assertSame(1, Message::where('declencheur', Corrections::DECLENCHEUR_APRES_DELAI)->count());
    }

    public function test_une_correction_ne_peut_pas_retirer_le_dernier_article_d_une_commande(): void
    {
        // Commande d'un seul costume : le sac arrive vide
        $id = (string) str()->uuid();
        $this->pousser('jeton-cv', $this->operation('commandes', [
            'id' => $id, 'code' => 'CV-S41-10-2026-'.strtoupper(substr($id, 0, 4)), 'client_id' => $this->clientId,
            'utilisateur_id' => $this->aicha->id, 'statut' => 'ENREGISTREE', 'deposee_le' => '2026-10-06T09:15:00Z',
            'lignes' => [['id' => (string) str()->uuid(), 'tarif_id' => $this->costume->id, 'quantite' => 1,
                'prix_applique' => '5000', 'commission_appliquee' => '800']],
        ]));
        $lot = $this->operationLot([$id]);
        $this->pousser('jeton-cv', $lot);
        $this->pousser('jeton-atl', $this->operationReception($lot['ligne_id'], 1));
        $commande = Commande::findOrFail($id);

        // Sans plus aucun article, elle ne pourrait ni etre traitee ni repartir : elle s'annule (RG-24)
        $this->pousser('jeton-atl', $this->operationControle($id, 'ECART', [
            'ligne_commande_id' => $commande->lignes->first()->id, 'quantite_apres' => 0,
        ]))->assertJsonPath('resultats.0.motif', 'commande_sans_article');

        $this->assertSame(Commande::RECEPTIONNEE, $commande->refresh()->statut);
        $this->assertSame(0, Controle::count());
    }

    public function test_une_correction_qui_ramene_une_ligne_a_zero_la_retire_de_la_facture(): void
    {
        $this->travelTo('2026-10-06 16:00:00');
        Parametre::updateOrCreate(['cle' => Parametre::DELAI_ACCORD_ANOMALIE_JOURS], ['valeur' => '1', 'libelle' => 'Délai']);
        $commande = $this->commandeRecue();
        $costume = $commande->lignes->firstWhere('tarif_id', $this->costume->id);
        $this->pousser('jeton-atl', $this->operationControle($commande->id, 'ECART', [
            'ligne_commande_id' => $costume->id, 'quantite_apres' => 0,
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        $this->travelTo('2026-10-08 16:00:00');
        $this->assertSame(1, app(Corrections::class)->appliquerEchues());

        $commande->refresh();
        $this->assertCount(1, $commande->lignes);
        $this->assertSame('3000.00', $commande->montant);
        $this->assertSame(Commande::CONTROLEE, $commande->statut);
    }

    // ------------------------------------------------------------------ taches

    public function test_la_premiere_tache_met_la_commande_en_traitement_et_la_derniere_terminee_la_rend_traitee(): void
    {
        $commande = $this->commandeControlee();
        $travailleur = $this->travailleur();
        [$chemises, $costume] = [
            $commande->lignes->firstWhere('tarif_id', $this->chemise->id),
            $commande->lignes->firstWhere('tarif_id', $this->costume->id),
        ];
        $depuis = $this->changementsPour('jeton-cv')->max('seq');

        $lavageChemises = $this->operationTache($chemises->id, $travailleur, 2);
        $this->pousser('jeton-atl', $lavageChemises)->assertJsonPath('resultats.0.resultat', 'appliquee');
        // RG-05 : une premiere tache attribuee, la commande est En traitement
        $this->assertSame(Commande::EN_TRAITEMENT, $commande->refresh()->statut);
        $tache = Tache::findOrFail($lavageChemises['ligne_id']);
        $this->assertSame($commande->id, $tache->commande_id);
        $this->assertSame(Tache::LAVAGE, $tache->etape);
        // RG-03 : la commission de l'etape, figee sur la ligne au depot, est copiee a l'attribution
        $this->assertSame('120.00', $tache->commission_unitaire);
        $this->assertFalse($tache->estTerminee());

        // Chaque article est lave puis repasse : il manque encore le repassage du costume
        $repassageChemises = $this->operationTache($chemises->id, $travailleur, 2, [], Tache::REPASSAGE);
        $lavageCostume = $this->operationTache($costume->id, $travailleur, 1);
        $repassageCostume = $this->operationTache($costume->id, $travailleur, 1, [], Tache::REPASSAGE);
        $reponse = $this->pousser('jeton-atl', $this->terminee($lavageChemises), $repassageChemises,
            $this->terminee($repassageChemises), $lavageCostume, $this->terminee($lavageCostume), $repassageCostume);
        foreach (range(0, 5) as $rang) {
            $reponse->assertJsonPath('resultats.'.$rang.'.resultat', 'appliquee');
        }
        $this->assertSame('80.00', Tache::findOrFail($repassageChemises['ligne_id'])->commission_unitaire);
        $this->assertSame(Commande::EN_TRAITEMENT, $commande->refresh()->statut, 'le costume n\'est pas repasse');
        $this->assertNull($commande->traitee_le);

        $this->pousser('jeton-atl', $this->terminee($repassageCostume, '2026-10-07T17:30:00Z'))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        // Chaque etape de chaque ligne est couverte par des taches terminees : Traitee (RG-06 : le delai de retrait part d'ici)
        $commande->refresh();
        $this->assertSame(Commande::TRAITEE, $commande->statut);
        $this->assertSame('2026-10-07T17:30:00Z', $commande->traitee_le->toIso8601ZuluString());

        // La boutique suit l'avancement ; les taches, elles, appartiennent a l'atelier
        $this->assertSame(['EN_TRAITEMENT', 'TRAITEE'],
            $this->changements('jeton-cv', 'commandes', $depuis)->pluck('donnees.statut')->unique()->values()->all());
        $this->assertCount(0, $this->changements('jeton-cv', 'taches'));
        $this->assertCount(0, $this->changements('jeton-cv', 'travailleurs'));
    }

    public function test_chaque_etape_va_a_un_travailleur_qui_sait_la_faire_et_le_repassage_suit_le_lavage(): void
    {
        $commande = $this->commandeControlee();
        $chemises = $commande->lignes->firstWhere('tarif_id', $this->chemise->id);
        $laveur = $this->operationTravailleur('VP-L4V3R-T', ['role' => Travailleur::LAVEUR]);
        $repasseur = $this->operationTravailleur('VP-R3P4S-T', ['role' => Travailleur::REPASSEUR]);
        $this->pousser('jeton-atl', $laveur, $repasseur)->assertJsonPath('resultats.1.resultat', 'appliquee');
        $this->assertSame(Travailleur::REPASSEUR, Travailleur::findOrFail($repasseur['ligne_id'])->role);
        [$laveur, $repasseur] = [$laveur['ligne_id'], $repasseur['ligne_id']];

        $this->pousser(
            'jeton-atl',
            // un laveur ne repasse pas, un repasseur ne lave pas
            $this->operationTache($chemises->id, $laveur, 1, [], Tache::REPASSAGE),
            $this->operationTache($chemises->id, $repasseur, 1),
            // rien n'est encore lave : rien a repasser
            $this->operationTache($chemises->id, $repasseur, 1, [], Tache::REPASSAGE),
            $this->operationTache($chemises->id, $laveur, 1, [], 'SECHAGE'),
        )
            ->assertJsonPath('resultats.0.motif', 'role_incompatible')
            ->assertJsonPath('resultats.1.motif', 'role_incompatible')
            ->assertJsonPath('resultats.2.motif', 'lavage_non_termine')
            ->assertJsonPath('resultats.3.motif', 'donnees_invalides');

        // Une chemise lavee et terminee, l'autre en cours : une seule se repasse
        $premiere = $this->operationTache($chemises->id, $laveur, 1);
        $seconde = $this->operationTache($chemises->id, $laveur, 1);
        $this->pousser('jeton-atl', $premiere, $this->terminee($premiere), $seconde,
            $this->operationTache($chemises->id, $repasseur, 2, [], Tache::REPASSAGE),
            $this->operationTache($chemises->id, $repasseur, 1, [], Tache::REPASSAGE))
            ->assertJsonPath('resultats.3.motif', 'lavage_non_termine')
            ->assertJsonPath('resultats.4.resultat', 'appliquee');
        $this->assertSame(1, (int) Tache::where('ligne_commande_id', $chemises->id)->where('etape', Tache::REPASSAGE)->sum('quantite'));

        // Une ligne dont la prestation n'a pas de lavage se repasse directement, et ne se lave pas
        LigneCommande::whereKey($chemises->id)->update(['avec_lavage' => false]);
        $this->pousser('jeton-atl',
            $this->operationTache($chemises->id, $laveur, 1),
            $this->operationTache($chemises->id, $repasseur, 1, [], Tache::REPASSAGE))
            ->assertJsonPath('resultats.0.motif', 'etape_inconnue')
            ->assertJsonPath('resultats.1.resultat', 'appliquee');
    }

    public function test_les_etapes_d_une_ligne_envoyee_par_un_poste_d_avant_se_deduisent_de_sa_prestation(): void
    {
        $repassage = $this->creerTarif(800, 'Chemise', 'Repassage seul');
        Service::where('nom', 'Repassage seul')->update(['avec_lavage' => false]);
        $operation = $this->operationCommande($this->aicha);
        $lignes = $operation['donnees']['lignes'];
        foreach ($lignes as $rang => $ligne) {
            $lignes[$rang] = array_diff_key($ligne, array_flip(['avec_lavage', 'avec_repassage', 'commission_lavage', 'commission_repassage']));
        }
        $lignes[1] = ['tarif_id' => $repassage->id, 'prix_applique' => '800', 'commission_appliquee' => '150'] + $lignes[1];
        $operation['donnees']['lignes'] = $lignes;
        $this->pousser('jeton-cv', $operation)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $chemise = LigneCommande::findOrFail($lignes[0]['id']);
        $this->assertSame([Tache::LAVAGE, Tache::REPASSAGE], $chemise->etapes());
        $this->assertSame(['100.00', '100.00'], [$chemise->commission_lavage, $chemise->commission_repassage]);
        $repassee = LigneCommande::findOrFail($lignes[1]['id']);
        $this->assertSame([Tache::REPASSAGE], $repassee->etapes());
        $this->assertSame(['0.00', '150.00'], [$repassee->commission_lavage, $repassee->commission_repassage]);

        // Un poste a jour donne des commissions d'etapes dont la somme est celle de la ligne
        $incoherente = $this->operationCommande($this->aicha);
        $incoherente['donnees']['lignes'][0]['commission_lavage'] = '150';
        $this->pousser('jeton-cv', $incoherente)->assertJsonPath('resultats.0.motif', 'donnees_invalides');
    }

    public function test_les_quantites_attribuees_ne_depassent_jamais_celle_de_la_ligne(): void
    {
        $commande = $this->commandeControlee();
        $travailleur = $this->travailleur();
        $autre = $this->travailleur('VP-Z9Y8X-T');
        $chemises = $commande->lignes->firstWhere('tarif_id', $this->chemise->id);

        // RG-20 : deux chemises sur la ligne, une par travailleur, pas une de plus
        $this->pousser(
            'jeton-atl',
            $this->operationTache($chemises->id, $travailleur, 1),
            $this->operationTache($chemises->id, $autre, 2),
            $this->operationTache($chemises->id, $autre, 1),
            $this->operationTache($chemises->id, $travailleur, 1),
        )
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.motif', 'depasse_la_quantite')
            ->assertJsonPath('resultats.2.resultat', 'appliquee')
            ->assertJsonPath('resultats.3.motif', 'depasse_la_quantite');
        $this->assertSame(2, (int) Tache::where('ligne_commande_id', $chemises->id)->sum('quantite'));
    }

    public function test_une_tache_mal_adressee_est_refusee_et_une_tache_terminee_ne_change_plus(): void
    {
        $recue = $this->commandeRecue();
        $controlee = $this->commandeControlee();
        $travailleur = $this->travailleur();
        $ligne = $controlee->lignes->firstWhere('tarif_id', $this->costume->id);

        $this->pousser(
            'jeton-atl',
            $this->operationTache((string) str()->uuid(), $travailleur, 1),
            // pas encore controlee
            $this->operationTache($recue->lignes->first()->id, $travailleur, 1),
            $this->operationTache($ligne->id, (string) str()->uuid(), 1),
            $this->operationTache($ligne->id, $travailleur, 0),
        )
            ->assertJsonPath('resultats.0.motif', 'ligne_inconnue')
            ->assertJsonPath('resultats.1.motif', 'commande_non_attribuable')
            ->assertJsonPath('resultats.2.motif', 'travailleur_inconnu')
            ->assertJsonPath('resultats.3.motif', 'donnees_invalides');
        // Une boutique n'attribue pas de tache sur une commande partie a l'atelier
        $this->pousser('jeton-cv', $this->operationTache($ligne->id, $travailleur, 1, ['attribuee_par' => $this->aicha->id]))
            ->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');
        $this->assertSame(0, Tache::count());

        $tache = $this->operationTache($ligne->id, $travailleur, 1);
        $this->pousser('jeton-atl', $tache, $this->terminee($tache, '2026-10-07T11:00:00Z'));
        // Renvoyee avec une autre fin ou une autre quantite : ce qui est enregistre ne bouge plus
        $this->pousser('jeton-atl', $this->operation('taches', ['terminee_le' => '2026-10-08T09:00:00Z', 'quantite' => 5] + $tache['donnees']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $relue = Tache::findOrFail($tache['ligne_id']);
        $this->assertSame('2026-10-07T11:00:00Z', $relue->terminee_le->toIso8601ZuluString());
        $this->assertSame(1, $relue->quantite);
    }

    // ------------------------------------------------------------------ travailleurs et consommables

    public function test_un_travailleur_enrole_a_l_atelier_garde_son_identite_sur_le_serveur_seulement(): void
    {
        $operation = $this->operationTravailleur('VP-A2B3C-T');
        $this->pousser('jeton-atl', $operation)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $travailleur = Travailleur::findOrFail($operation['ligne_id']);
        $this->assertSame($this->atelier->id, $travailleur->site_id);
        $this->assertSame('VP-A2B3C-T', $travailleur->matricule);
        $this->assertSame('112233445', $travailleur->cni);
        $this->assertSame('677001122', $travailleur->telephone);
        $this->assertSame('Pitoaré', $travailleur->quartier);
        $this->assertTrue($travailleur->actif);

        // §11 : les donnees d'identite ne repartent pas vers le poste de l'atelier
        $instantane = collect($this->withToken('jeton-atl')->getJson('/api/sync/instantane')->assertOk()->json('changements'))
            ->firstWhere('table', 'travailleurs');
        $this->assertSame(['id', 'site_id', 'matricule', 'nom', 'role', 'actif'], array_keys($instantane['donnees']));

        $this->pousser(
            'jeton-atl',
            // RG-02 : le matricule est unique et suit le format VP-...-T
            $this->operationTravailleur('VP-A2B3C-T'),
            $this->operationTravailleur('MATRICULE'),
            // la fiche deja recue n'est pas modifiee
            $this->operationTravailleur('VP-A2B3C-T', ['id' => $operation['ligne_id'], 'nom' => 'Autre Nom', 'cni' => null]),
        )
            ->assertJsonPath('resultats.0.motif', 'doublon_matricule')
            ->assertJsonPath('resultats.1.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.2.resultat', 'appliquee');
        // Les travailleurs appartiennent a l'atelier
        $this->pousser('jeton-cv', $this->operationTravailleur('VP-B0UT1-T', ['cree_par' => $this->aicha->id]))
            ->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');

        $this->assertSame(1, Travailleur::count());
        $this->assertSame('Moussa Hamadou', $travailleur->refresh()->nom);
        $this->assertSame('112233445', $travailleur->cni);
    }

    public function test_une_sortie_de_consommables_est_enregistree_pour_l_atelier(): void
    {
        $sortie = $this->operation('consommables', [
            'id' => (string) str()->uuid(),
            'utilisateur_id' => $this->chef->id,
            'description' => 'Lessive 5 kg',
            'montant' => '4500',
            'sortie_le' => '2026-10-06T10:00:00Z',
        ]);

        $this->pousser('jeton-atl', $sortie)->assertJsonPath('resultats.0.resultat', 'appliquee');
        // Renvoyee sous un autre numero d'operation apres une coupure : comptee une seule fois
        $this->pousser('jeton-atl', $this->operation('consommables', $sortie['donnees']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        $consommable = Consommable::sole();
        $this->assertSame($this->atelier->id, $consommable->site_id);
        $this->assertSame('Lessive 5 kg', $consommable->description);
        $this->assertSame('4500.00', $consommable->montant);
        $this->assertSame($this->chef->id, $consommable->utilisateur_id);

        $this->pousser(
            'jeton-atl',
            $this->operation('consommables', ['id' => (string) str()->uuid(), 'montant' => '0'] + $sortie['donnees']),
            $this->operation('consommables', ['id' => (string) str()->uuid(), 'description' => ''] + $sortie['donnees']),
        )
            ->assertJsonPath('resultats.0.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.1.motif', 'donnees_invalides');
        $this->pousser('jeton-cv', $this->operation('consommables', ['id' => (string) str()->uuid(), 'utilisateur_id' => $this->aicha->id] + $sortie['donnees']))
            ->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');
        $this->assertSame(1, Consommable::count());
    }

    // ------------------------------------------------------------------ lots retour

    public function test_l_atelier_renvoie_les_commandes_traitees_a_leur_boutique_qui_en_accuse_reception(): void
    {
        $commande = $this->commandeTraitee();
        $depuisBoutique = $this->changementsPour('jeton-cv')->max('seq');

        $retour = $this->operationLot([$commande->id], [
            'sens' => 'RETOUR',
            'site_destination_id' => $this->boutique->id,
            'cloture_par' => $this->chef->id,
            'cloture_le' => '2026-10-08T09:00:00Z',
        ]);
        $this->pousser('jeton-atl', $retour)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $lot = Lot::findOrFail($retour['ligne_id']);
        $this->assertSame(Lot::RETOUR, $lot->sens);
        $this->assertSame($this->atelier->id, $lot->site_origine_id);
        $this->assertSame($this->boutique->id, $lot->site_destination_id);
        $this->assertSame(1, $lot->nombre_annonce);
        // RG-05 : la commande reste Traitee jusqu'a la reception du lot retour par la boutique
        $this->assertSame(Commande::TRAITEE, $commande->refresh()->statut);
        $recu = $this->changements('jeton-cv', 'lots', $depuisBoutique)->sole()['donnees'];
        $this->assertSame('RETOUR', $recu['sens']);
        $this->assertSame([$commande->id], $recu['commande_ids']);

        // Une commande ne repart qu'une fois
        $this->pousser('jeton-atl', $this->operationLot([$commande->id], [
            'sens' => 'RETOUR', 'site_destination_id' => $this->boutique->id, 'cloture_par' => $this->chef->id,
        ]))->assertJsonPath('resultats.0.motif', 'commande_non_envoyable');

        // La receptionniste accuse reception : la commande est Retournee, et l'atelier l'apprend
        $depuisAtelier = $this->changementsPour('jeton-atl')->max('seq');
        $this->pousser('jeton-cv', $this->operationReception($lot->id, 1, $this->aicha))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame(Commande::RETOURNEE, $commande->refresh()->statut);
        $this->assertSame('RETOURNEE', $this->changements('jeton-atl', 'commandes', $depuisAtelier)->sole()['donnees']['statut']);
        $this->assertNotNull($this->changements('jeton-atl', 'lots', $depuisAtelier)->sole()['donnees']['recu_le']);

        // Revenue en boutique, elle peut etre remise au client
        $this->pousser('jeton-cv', $this->operationRemise($commande->id, $this->aicha))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $commande->refresh();
        $this->assertSame(Commande::LIVREE, $commande->statut);
        $this->assertSame($this->aicha->id, $commande->livree_par);
        $this->assertSame('2026-10-09T10:00:00Z', $commande->livree_le->toIso8601ZuluString());
    }

    public function test_un_lot_retour_mal_compose_est_refuse(): void
    {
        $traitee = $this->commandeTraitee();
        $enCours = $this->commandeControlee();
        $voisine = $this->creerSite('MC');
        $retour = fn (array $commandes, array $remplacer = []) => $this->operationLot($commandes, $remplacer + [
            'sens' => 'RETOUR', 'site_destination_id' => $this->boutique->id, 'cloture_par' => $this->chef->id,
        ]);

        $this->pousser(
            'jeton-atl',
            // pas encore traitee
            $retour([$traitee->id, $enCours->id]),
            // vers une autre boutique que celle de la commande, ou vers l'atelier lui-meme
            $retour([$traitee->id], ['site_destination_id' => $voisine->id]),
            $retour([$traitee->id], ['site_destination_id' => $this->atelier->id]),
            $retour([(string) str()->uuid()]),
        )
            ->assertJsonPath('resultats.0.motif', 'commande_non_envoyable')
            ->assertJsonPath('resultats.1.motif', 'destination_invalide')
            ->assertJsonPath('resultats.2.motif', 'destination_invalide')
            ->assertJsonPath('resultats.3.motif', 'commande_inconnue');
        // Une boutique ne cloture pas de lot retour
        $this->pousser('jeton-cv', $retour([$traitee->id], ['cloture_par' => $this->aicha->id]))
            ->assertJsonPath('resultats.0.motif', 'reserve_a_l_atelier');

        $this->assertSame(0, Lot::where('sens', Lot::RETOUR)->count());
        $this->assertSame(Commande::TRAITEE, $traitee->refresh()->statut);
    }

    // ------------------------------------------------------------------ depot direct et perimetre du chef

    public function test_un_depot_direct_a_l_atelier_est_traite_puis_remis_sans_passer_par_un_lot(): void
    {
        $depot = $this->operationCommande($this->chef, 'ATL');
        $this->pousser('jeton-atl', $depot)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $commande = Commande::findOrFail($depot['ligne_id']);
        $this->assertSame($this->atelier->id, $commande->site_id);
        $this->assertTrue($commande->estDepotDirect());
        $travailleur = $this->travailleur();

        // Pas encore traite : il ne se remet pas
        $this->pousser('jeton-atl', $this->operationRemise($commande->id, $this->chef))
            ->assertJsonPath('resultats.0.motif', 'commande_non_remettable');

        // RG-05 : le chef a recu les articles en main propre ; la premiere tache suffit a le mettre En traitement
        $operations = [];
        foreach ($commande->lignes as $ligne) {
            foreach (Tache::ETAPES as $etape) {
                $tache = $this->operationTache($ligne->id, $travailleur, $ligne->quantite, [], $etape);
                array_push($operations, $tache, $this->terminee($tache));
            }
        }
        $this->pousser('jeton-atl', $operations[0])->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame(Commande::EN_TRAITEMENT, $commande->refresh()->statut);
        $this->pousser('jeton-atl', ...array_slice($operations, 1));
        $this->assertSame(Commande::TRAITEE, $commande->refresh()->statut);

        // Il ne repart vers aucune boutique : il est remis a l'atelier
        $this->pousser('jeton-atl', $this->operationLot([$commande->id], [
            'sens' => 'RETOUR', 'site_destination_id' => $this->boutique->id, 'cloture_par' => $this->chef->id,
        ]))->assertJsonPath('resultats.0.motif', 'destination_invalide');

        $this->pousser('jeton-atl', $this->operationRemise($commande->id, $this->chef))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame(Commande::LIVREE, $commande->refresh()->statut);
        // Renvoyee apres une coupure : rien a refaire
        $this->pousser('jeton-atl', $this->operationRemise($commande->id, $this->chef))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
    }

    public function test_le_chef_d_atelier_n_encaisse_et_ne_remet_que_ses_propres_commandes(): void
    {
        $autreChef = $this->creerUtilisateur($this->atelier, 'chef2', Utilisateur::CHEF_ATELIER);
        $session = $this->operation('sessions_caisse', [
            'id' => (string) str()->uuid(),
            'ouverte_par' => $this->chef->id,
            'ouverte_le' => '2026-10-06T08:00:00Z',
            'fond_initial' => '0',
        ]);
        $sienne = $this->operationCommande($this->chef, 'ATL');
        $autre = $this->operationCommande($autreChef, 'ATL');
        $this->pousser('jeton-atl', $session, $sienne, $autre);
        $paiement = fn (string $commandeId, Utilisateur $par) => $this->operation('paiements', [
            'id' => (string) str()->uuid(),
            'commande_id' => $commandeId,
            'session_caisse_id' => $session['ligne_id'],
            'utilisateur_id' => $par->id,
            'type' => 'ACOMPTE',
            'montant' => '1000',
            'encaisse_le' => '2026-10-06T09:30:00Z',
        ]);

        // RG-10 : verifie par le serveur, pas seulement par l'ecran
        $this->pousser('jeton-atl', $paiement($sienne['ligne_id'], $this->chef), $paiement($autre['ligne_id'], $this->chef))
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.motif', 'hors_perimetre');
        $this->assertSame('1000.00', Commande::findOrFail($sienne['ligne_id'])->total_paye);
        $this->assertSame('0.00', Commande::findOrFail($autre['ligne_id'])->total_paye);

        Commande::whereKey([$sienne['ligne_id'], $autre['ligne_id']])->update(['statut' => Commande::TRAITEE]);
        $this->pousser('jeton-atl', $this->operationRemise($autre['ligne_id'], $this->chef), $this->operationRemise($sienne['ligne_id'], $this->chef))
            ->assertJsonPath('resultats.0.motif', 'hors_perimetre')
            ->assertJsonPath('resultats.1.resultat', 'appliquee');
        $this->assertSame(Commande::TRAITEE, Commande::findOrFail($autre['ligne_id'])->statut);

        // Connecte sur le poste d'une boutique, il n'y encaisse pas davantage les commandes de la receptionniste
        $deLaBoutique = $this->commandeDeLaBoutique();
        $caisse = $this->operation('sessions_caisse', [
            'id' => (string) str()->uuid(), 'ouverte_par' => $this->aicha->id,
            'ouverte_le' => '2026-10-06T08:00:00Z', 'fond_initial' => '0',
        ]);
        $versement = fn (Utilisateur $par) => $this->operation('paiements', [
            'id' => (string) str()->uuid(), 'commande_id' => $deLaBoutique->id, 'session_caisse_id' => $caisse['ligne_id'],
            'utilisateur_id' => $par->id, 'type' => 'ACOMPTE', 'montant' => '1000', 'encaisse_le' => '2026-10-06T09:30:00Z',
        ]);
        $this->pousser('jeton-cv', $caisse, $versement($this->chef), $versement($this->aicha))
            ->assertJsonPath('resultats.1.motif', 'hors_perimetre')
            ->assertJsonPath('resultats.2.resultat', 'appliquee');
    }

    public function test_une_commande_de_boutique_ne_se_remet_qu_une_fois_revenue_de_l_atelier(): void
    {
        $commande = $this->commandeTraitee();

        // Traitee mais encore a l'atelier
        $this->pousser('jeton-cv', $this->operationRemise($commande->id, $this->aicha))
            ->assertJsonPath('resultats.0.motif', 'commande_non_remettable');
        // L'atelier ne remet pas une commande de boutique
        $this->pousser('jeton-atl', $this->operationRemise($commande->id, $this->chef))
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire');
        $this->assertSame(Commande::TRAITEE, $commande->refresh()->statut);
    }

    // ------------------------------------------------------------------ etat initial

    public function test_l_etat_initial_d_un_atelier_contient_son_travail_en_cours(): void
    {
        $commande = $this->commandeControlee();
        $travailleur = $this->travailleur();
        $tache = $this->operationTache($commande->lignes->first()->id, $travailleur, 1);
        $recente = $this->operation('consommables', [
            'id' => (string) str()->uuid(), 'utilisateur_id' => $this->chef->id, 'description' => 'Lessive',
            'montant' => '4500', 'sortie_le' => now()->subDays(2)->toIso8601ZuluString(),
        ]);
        $ancienne = $this->operation('consommables', ['id' => (string) str()->uuid(), 'sortie_le' => now()->subWeeks(20)->toIso8601ZuluString()] + $recente['donnees']);
        $this->pousser('jeton-atl', $tache, $recente, $ancienne);

        $atelier = collect($this->withToken('jeton-atl')->getJson('/api/sync/instantane')->assertOk()->json('changements'))
            ->groupBy('table');
        $this->assertSame([$commande->id], $atelier['commandes']->pluck('ligne_id')->all());
        $this->assertSame([$travailleur], $atelier['travailleurs']->pluck('ligne_id')->all());
        $this->assertSame([$tache['ligne_id']], $atelier['taches']->pluck('ligne_id')->all());
        $this->assertCount(1, $atelier['controles']);
        $this->assertSame([$recente['ligne_id']], $atelier['consommables']->pluck('ligne_id')->all());

        // La boutique retrouve le controle de sa commande, mais rien de ce qui n'appartient qu'a l'atelier
        $boutique = collect($this->withToken('jeton-cv')->getJson('/api/sync/instantane')->assertOk()->json('changements'))
            ->groupBy('table');
        $this->assertSame('EN_TRAITEMENT', $boutique['commandes']->sole()['donnees']['statut']);
        $this->assertCount(1, $boutique['controles']);
        foreach (['travailleurs', 'taches', 'consommables'] as $table) {
            $this->assertArrayNotHasKey($table, $boutique->all());
        }
    }
}
