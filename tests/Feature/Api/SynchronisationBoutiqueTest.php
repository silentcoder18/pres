<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\ClientReuni;
use App\Models\Commande;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Paiement;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\Tarif;
use App\Models\Utilisateur;

/** Etape 2 : ce qu'une boutique envoie au serveur (commandes, avances, caisse, lots, messages). */
class SynchronisationBoutiqueTest extends ApiPosteTestCase
{
    private Site $boutique;

    private Site $atelier;

    private Utilisateur $aicha;

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
        $this->creerUtilisateur($this->atelier, 'chef', Utilisateur::CHEF_ATELIER);
        $this->chemise = $this->creerTarif(1500);
        $this->costume = $this->creerTarif(5000, 'Costume 2 pièces', 'Nettoyage à sec');

        $this->clientId = (string) str()->uuid();
        $this->pousser('jeton-cv', $this->operationClient($this->clientId, '699112233'));
    }

    private function operationCommande(?string $id = null, array $remplacer = []): array
    {
        $id ??= (string) str()->uuid();

        return $this->operation('commandes', $remplacer + [
            'id' => $id,
            'code' => 'CV-S41-10-2026-'.strtoupper(substr($id, 0, 4)),
            'client_id' => $this->clientId,
            'utilisateur_id' => $this->aicha->id,
            'statut' => 'ENREGISTREE',
            'deposee_le' => '2026-10-06T09:15:00Z',
            'accord_messages' => true,
            // envoyes par un poste trop confiant : le serveur n'en tient pas compte
            'montant' => '1.00',
            'reste' => '1.00',
            'lignes' => [
                $this->ligne($this->chemise, 2, 1500),
                $this->ligne($this->costume, 1, 5000),
            ],
        ]);
    }

    private function ligne(Tarif $tarif, int $quantite, int $prix): array
    {
        return [
            'id' => (string) str()->uuid(),
            'tarif_id' => $tarif->id,
            'quantite' => $quantite,
            'prix_applique' => (string) $prix,
            'commission_appliquee' => '200',
        ];
    }

    private function operationSession(?string $id = null, array $remplacer = []): array
    {
        return $this->operation('sessions_caisse', $remplacer + [
            'id' => $id ?? (string) str()->uuid(),
            'ouverte_par' => $this->aicha->id,
            'ouverte_le' => '2026-10-06T08:00:00Z',
            'fond_initial' => '5000',
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

    /** Une commande deja recue par le serveur ; renvoie son identifiant. */
    private function commandeEnregistree(): string
    {
        $operation = $this->operationCommande();
        $this->pousser('jeton-cv', $operation)->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $operation['ligne_id'];
    }

    private function sessionOuverte(): string
    {
        $operation = $this->operationSession();
        $this->pousser('jeton-cv', $operation)->assertJsonPath('resultats.0.resultat', 'appliquee');

        return $operation['ligne_id'];
    }

    public function test_une_commande_arrive_avec_ses_lignes_et_ses_montants_sont_calcules_par_le_serveur(): void
    {
        $id = $this->commandeEnregistree();

        $commande = Commande::findOrFail($id);
        $this->assertSame($this->boutique->id, $commande->site_id);
        $this->assertSame(Commande::ENREGISTREE, $commande->statut);
        $this->assertCount(2, $commande->lignes);
        // RG-04 : 2 x 1 500 + 1 x 5 000
        $this->assertSame('8000.00', $commande->montant);
        $this->assertSame('0.00', $commande->total_paye);
        $this->assertSame('8000.00', $commande->reste);
    }

    public function test_une_commande_incomplete_ou_incoherente_est_refusee_avec_son_motif(): void
    {
        $sansLigne = $this->operationCommande(null, ['lignes' => []]);
        $clientInconnu = $this->operationCommande(null, ['client_id' => (string) str()->uuid()]);
        $tarifInconnu = $this->operationCommande(null, ['lignes' => [
            ['id' => (string) str()->uuid(), 'tarif_id' => (string) str()->uuid(), 'quantite' => 1,
                'prix_applique' => '100', 'commission_appliquee' => '0'],
        ]]);
        $auteurInconnu = $this->operationCommande(null, ['utilisateur_id' => (string) str()->uuid()]);

        $this->pousser('jeton-cv', $sansLigne, $clientInconnu, $tarifInconnu, $auteurInconnu)
            ->assertJsonPath('resultats.0.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.1.motif', 'client_inconnu')
            ->assertJsonPath('resultats.2.motif', 'tarif_inconnu')
            ->assertJsonPath('resultats.3.motif', 'utilisateur_inconnu');

        $this->assertSame(0, Commande::count());
    }

    public function test_un_compte_d_un_autre_site_connecte_sur_ce_poste_y_travaille_sous_son_nom(): void
    {
        // Le chef d'atelier s'est connecte sur le poste de la boutique : caisse, commande et avance sont a son nom
        $chef = Utilisateur::where('identifiant', 'chef')->sole();
        $session = $this->operationSession(null, ['ouverte_par' => $chef->id]);
        $commande = $this->operationCommande(null, ['utilisateur_id' => $chef->id]);
        $avance = $this->operationPaiement($commande['ligne_id'], $session['ligne_id'], 3000, ['utilisateur_id' => $chef->id]);

        $this->pousser('jeton-cv', $session, $commande, $avance)
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.resultat', 'appliquee')
            ->assertJsonPath('resultats.2.resultat', 'appliquee');

        // L'operation appartient au site du poste, pas au site de rattachement du compte
        $enregistree = Commande::findOrFail($commande['ligne_id']);
        $this->assertSame($this->boutique->id, $enregistree->site_id);
        $this->assertSame($chef->id, $enregistree->utilisateur_id);
        $this->assertSame($this->boutique->id, SessionCaisse::findOrFail($session['ligne_id'])->site_id);
    }

    public function test_deux_commandes_ne_peuvent_pas_porter_le_meme_code(): void
    {
        $premiere = $this->operationCommande(null, ['code' => 'CV-S41-10-2026-AAAA']);
        $seconde = $this->operationCommande(null, ['code' => 'CV-S41-10-2026-AAAA']);

        $this->pousser('jeton-cv', $premiere, $seconde)
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.motif', 'doublon_code');
    }

    public function test_un_encaissement_exige_une_session_de_caisse_du_site_et_ne_depasse_pas_le_reste(): void
    {
        $commande = $this->commandeEnregistree();

        // RG-17 : pas d'encaissement sans session de caisse
        $this->pousser('jeton-cv', $this->operationPaiement($commande, (string) str()->uuid(), 3000))
            ->assertJsonPath('resultats.0.motif', 'session_inconnue');

        $session = $this->sessionOuverte();
        $avance = $this->operationPaiement($commande, $session, 3000);
        $this->pousser('jeton-cv', $avance)->assertJsonPath('resultats.0.resultat', 'appliquee');
        // Renvoye apres une coupure : compte une seule fois
        $this->pousser('jeton-cv', $avance)->assertJsonPath('resultats.0.resultat', 'deja_appliquee');

        $relue = Commande::findOrFail($commande);
        $this->assertSame('3000.00', $relue->total_paye);
        $this->assertSame('5000.00', $relue->reste);

        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 0))
            ->assertJsonPath('resultats.0.motif', 'donnees_invalides');
        // Au-dela de ce que tiennent les colonnes : refuse avant d'atteindre la base
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 10000000000))
            ->assertJsonPath('resultats.0.motif', 'donnees_invalides');
        // On ne rend rien au client d'une commande qu'il n'a pas payee en trop ; une nature inconnue est refusee
        $this->pousser(
            'jeton-cv',
            $this->operationPaiement($commande, $session, 3000, ['type' => 'REMBOURSEMENT']),
            $this->operationPaiement($commande, $session, 100, ['type' => 'CADEAU']),
        )
            ->assertJsonPath('resultats.0.motif', 'pas_de_trop_percu')
            ->assertJsonPath('resultats.1.motif', 'donnees_invalides');

        $this->assertSame(1, Paiement::count());
    }

    public function test_un_versement_en_especes_superieur_au_reste_connu_est_enregistre_et_le_trop_percu_se_rend(): void
    {
        // Le poste l'a accepte avec ce qu'il savait du reste (RG-17) ; ici la facture a pu baisser
        // entre-temps (correction d'office) : l'argent est en caisse, le serveur l'enregistre
        $commande = $this->commandeEnregistree();
        $session = $this->sessionOuverte();
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 9000))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        $relue = Commande::findOrFail($commande);
        $this->assertSame('9000.00', $relue->total_paye);
        $this->assertSame('-1000.00', $relue->reste);

        // On ne rend pas plus que le trop-percu
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 1500, ['type' => 'REMBOURSEMENT']))
            ->assertJsonPath('resultats.0.motif', 'pas_de_trop_percu');
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 1000, ['type' => 'REMBOURSEMENT']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        $relue->refresh();
        $this->assertSame('8000.00', $relue->total_paye);
        $this->assertSame('0.00', $relue->reste);
        $this->assertNull(Paiement::where('type', 'REMBOURSEMENT')->sole()->affectation);
    }

    public function test_chaque_personne_a_sa_caisse_et_n_en_ouvre_qu_une_a_la_fois(): void
    {
        // Decision du 16 octobre 2026 : deux personnes du meme site, chacune dans sa base, ouvrent
        // chacune leur caisse, sans se voir hors ligne ; la seconde n'est pas refusee
        $mariam = $this->creerUtilisateur($this->boutique, 'mariam');
        $this->sessionOuverte();
        $sienne = $this->operationSession(null, ['ouverte_par' => $mariam->id, 'ouverte_le' => '2026-10-06T08:30:00Z']);
        $this->pousser('jeton-cv', $sienne)->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame(2, SessionCaisse::where('site_id', $this->boutique->id)->whereNull('cloturee_le')->count());

        // ... mais une personne n'a qu'une caisse ouverte a la fois
        $this->pousser('jeton-cv', $this->operationSession(null, ['ouverte_par' => $mariam->id]))
            ->assertJsonPath('resultats.0.motif', 'session_deja_ouverte');

        // Chaque personne encaisse sur sa caisse ; le serveur compte chacune a part
        $commande = $this->commandeEnregistree();
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $sienne['ligne_id'], 2000, ['utilisateur_id' => $mariam->id]))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame(1, Paiement::where('session_caisse_id', $sienne['ligne_id'])->count());
        $this->pousser('jeton-cv', $this->operationSession($sienne['ligne_id'], [
            'ouverte_par' => $mariam->id, 'ouverte_le' => '2026-10-06T08:30:00Z', 'cloturee_par' => $mariam->id,
            'cloturee_le' => '2026-10-06T18:00:00Z', 'total_compte' => '7000',
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame('7000.00', SessionCaisse::findOrFail($sienne['ligne_id'])->total_attendu);
    }

    public function test_une_seule_session_de_caisse_est_ouverte_a_la_fois_par_personne(): void
    {
        $premiere = $this->sessionOuverte();

        $this->pousser('jeton-cv', $this->operationSession())
            ->assertJsonPath('resultats.0.motif', 'session_deja_ouverte');

        $this->pousser('jeton-cv', $this->operationSession($premiere, [
            'cloturee_par' => $this->aicha->id,
            'cloturee_le' => '2026-10-06T18:00:00Z',
            'total_compte' => '5000',
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        // La premiere est cloturee : une nouvelle peut s'ouvrir, mais la premiere ne se rouvre pas
        $this->pousser('jeton-cv', $this->operationSession(null, ['ouverte_le' => '2026-10-07T08:00:00Z']))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->pousser('jeton-cv', $this->operationSession($premiere))
            ->assertJsonPath('resultats.0.motif', 'session_deja_cloturee');

        $this->assertSame(1, SessionCaisse::whereNull('cloturee_le')->count());
    }

    public function test_a_la_cloture_le_total_attendu_et_l_ecart_sont_calcules_par_le_serveur(): void
    {
        $commande = $this->commandeEnregistree();
        $session = $this->sessionOuverte();
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 3000));

        $this->pousser('jeton-cv', $this->operationSession($session, [
            'cloturee_par' => $this->aicha->id,
            'cloturee_le' => '2026-10-06T18:00:00Z',
            // le poste annonce autre chose : seul le montant compte est retenu
            'total_attendu' => '1',
            'total_compte' => '7500',
            'ecart' => '7499',
            'observations' => 'Monnaie rendue en trop',
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');

        $relue = SessionCaisse::findOrFail($session);
        // RG-18 : fond 5 000 + avance 3 000
        $this->assertSame('8000.00', $relue->total_attendu);
        $this->assertSame('7500.00', $relue->total_compte);
        $this->assertSame('-500.00', $relue->ecart);
        $this->assertSame('Monnaie rendue en trop', $relue->observations);

        // Un encaissement date d'apres la cloture ne peut pas s'y rattacher
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 500, ['encaisse_le' => '2026-10-06T19:00:00Z']))
            ->assertJsonPath('resultats.0.motif', 'session_cloturee');
    }

    public function test_un_lot_cloture_envoie_ses_commandes_et_arrive_au_poste_de_l_atelier(): void
    {
        $premiere = $this->commandeEnregistree();
        $seconde = $this->commandeEnregistree();
        $resteEnBoutique = $this->commandeEnregistree();
        $curseurBoutique = (int) SyncChangement::max('seq');

        $lot = $this->operationLot([$premiere, $seconde]);
        $this->pousser('jeton-cv', $lot)->assertJsonPath('resultats.0.resultat', 'appliquee');

        // RG-05 et RG-19 : contenu fige, commandes Envoyees
        $enregistre = Lot::findOrFail($lot['ligne_id']);
        $this->assertSame($this->boutique->id, $enregistre->site_origine_id);
        $this->assertSame(2, $enregistre->nombre_annonce);
        $this->assertNull($enregistre->recu_le);
        $this->assertSame(Commande::ENVOYEE, Commande::findOrFail($premiere)->statut);
        $this->assertSame(Commande::ENVOYEE, Commande::findOrFail($seconde)->statut);
        $this->assertSame(Commande::ENREGISTREE, Commande::findOrFail($resteEnBoutique)->statut);

        // §4.2 : l'atelier voit le lot et ses commandes entieres, pas les autres commandes de la boutique
        $pourAtelier = $this->changementsPour('jeton-atl');
        $lotRecu = $pourAtelier->firstWhere('table', 'lots');
        $this->assertSame($lot['ligne_id'], $lotRecu['ligne_id']);
        $this->assertEqualsCanonicalizing([$premiere, $seconde], $lotRecu['donnees']['commande_ids']);
        $commandesRecues = $pourAtelier->where('table', 'commandes');
        $this->assertEqualsCanonicalizing([$premiere, $seconde], $commandesRecues->pluck('ligne_id')->all());
        $this->assertSame('ENVOYEE', $commandesRecues->first()['donnees']['statut']);
        $this->assertCount(2, $commandesRecues->first()['donnees']['lignes']);
        $this->assertFalse($pourAtelier->contains('table', 'paiements'));

        // Le poste de la boutique ne recoit pas en retour ce qu'il vient d'envoyer
        $this->assertCount(0, $this->changementsPour('jeton-cv', $curseurBoutique));

        // Une commande envoyee n'est plus modifiable par la boutique, ni envoyable une seconde fois
        $this->pousser('jeton-cv', $this->operationCommande($premiere))
            ->assertJsonPath('resultats.0.motif', 'commande_verrouillee');
        $this->pousser('jeton-cv', $this->operationLot([$premiere, $resteEnBoutique]))
            ->assertJsonPath('resultats.0.motif', 'commande_non_envoyable');
        $this->assertSame(Commande::ENREGISTREE, Commande::findOrFail($resteEnBoutique)->statut);
    }

    public function test_un_lot_mal_adresse_ou_vide_est_refuse(): void
    {
        $commande = $this->commandeEnregistree();
        $autreBoutique = $this->creerSite('MC');

        $this->pousser(
            'jeton-cv',
            $this->operationLot([$commande], ['site_destination_id' => $autreBoutique->id]),
            $this->operationLot([]),
            $this->operationLot([(string) str()->uuid()]),
            // un lot retour ne part que de l'atelier
            $this->operationLot([$commande], ['sens' => 'RETOUR']),
            $this->operationLot([$commande], ['sens' => 'AILLEURS']),
        )
            ->assertJsonPath('resultats.0.motif', 'destination_invalide')
            ->assertJsonPath('resultats.1.motif', 'donnees_invalides')
            ->assertJsonPath('resultats.2.motif', 'commande_inconnue')
            ->assertJsonPath('resultats.3.motif', 'reserve_a_l_atelier')
            ->assertJsonPath('resultats.4.motif', 'donnees_invalides');

        $this->assertSame(0, Lot::count());
        $this->assertSame(Commande::ENREGISTREE, Commande::findOrFail($commande)->statut);
    }

    public function test_un_site_ne_touche_pas_aux_commandes_d_un_autre(): void
    {
        $commande = $this->commandeEnregistree();
        $autre = $this->creerSite('MC');
        $this->creerPoste($autre, 'jeton-mc');
        $bello = $this->creerUtilisateur($autre, 'bello');
        $sessionAutre = $this->operationSession(null, ['ouverte_par' => $bello->id]);
        $this->pousser('jeton-mc', $sessionAutre)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $this->pousser(
            'jeton-mc',
            $this->operationCommande($commande, ['utilisateur_id' => $bello->id]),
            $this->operationPaiement($commande, $sessionAutre['ligne_id'], 500, ['utilisateur_id' => $bello->id]),
            $this->operationLot([$commande], ['cloture_par' => $bello->id]),
        )
            ->assertJsonPath('resultats.0.motif', 'non_proprietaire')
            ->assertJsonPath('resultats.1.motif', 'non_proprietaire')
            ->assertJsonPath('resultats.2.motif', 'non_proprietaire');

        // §4.3, principe 7 : la commande de Centre-Ville n'est jamais envoyee au poste de l'autre boutique
        $this->assertFalse($this->changementsPour('jeton-mc')->contains('table', 'commandes'));
    }

    public function test_la_facture_attend_dans_la_file_des_messages(): void
    {
        $commande = $this->commandeEnregistree();
        $message = $this->operation('messages', [
            'id' => (string) str()->uuid(),
            'client_id' => $this->clientId,
            'commande_id' => $commande,
            'declencheur' => 'COMMANDE_ENREGISTREE',
            'contenu' => 'Votre commande est enregistrée. Total : 8 000 FCFA.',
            'cree_le' => '2026-10-06T09:16:00Z',
        ]);

        $this->pousser('jeton-cv', $message)->assertJsonPath('resultats.0.resultat', 'appliquee');

        $enFile = Message::findOrFail($message['ligne_id']);
        $this->assertSame(Message::EN_ATTENTE, $enFile->etat);
        $this->assertSame(0, $enFile->tentatives);
        $this->assertSame($this->boutique->id, $enFile->site_id);
        // Un message reste sur le serveur : aucun poste ne le recoit
        $this->assertFalse($this->changementsPour('jeton-atl')->contains('table', 'messages'));
    }

    public function test_deux_fiches_creees_hors_ligne_avec_le_meme_numero_sont_reunies(): void
    {
        $autre = $this->creerSite('MC');
        $this->creerPoste($autre, 'jeton-mc');
        $bello = $this->creerUtilisateur($autre, 'bello');

        // La boutique du marche a enregistre hors ligne le meme client, puis une commande pour lui
        $doublon = (string) str()->uuid();
        $commande = $this->operationCommande(null, [
            'code' => 'MC-S41-10-2026-ZZ01',
            'client_id' => $doublon,
            'utilisateur_id' => $bello->id,
        ]);
        $this->pousser('jeton-mc', $this->operationClient($doublon, '699112233', 'ALICE  mbia'), $commande)
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.resultat', 'appliquee');

        // RG-21 : une seule fiche dans le reseau, et la commande y est rattachee
        $this->assertSame(1, Client::count());
        $this->assertSame($this->clientId, Commande::findOrFail($commande['ligne_id'])->client_id);
        $reunion = ClientReuni::findOrFail($doublon);
        $this->assertSame($this->clientId, $reunion->client_id);
        $this->assertFalse($reunion->a_trancher, 'meme nom a la casse et aux espaces pres : rien a trancher');

        // Le poste qui avait cree le doublon recoit la reunion et sa commande corrigee
        $pourOrigine = $this->changementsPour('jeton-mc');
        $recue = $pourOrigine->firstWhere('table', 'clients_reunis');
        $this->assertSame($doublon, $recue['ligne_id']);
        $this->assertSame($this->clientId, $recue['donnees']['client_id']);
        $this->assertSame('Alice Mbia', $recue['donnees']['client']['nom']);
        $this->assertSame($this->clientId, $pourOrigine->firstWhere('table', 'commandes')['donnees']['client_id']);

        // Renvoyee sous un autre numero d'operation, la fiche reunie ne recree rien
        $this->pousser('jeton-mc', $this->operationClient($doublon, '699112233', 'ALICE  mbia'))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame(1, Client::count());
    }

    public function test_meme_numero_mais_noms_differents_le_cas_est_garde_pour_le_promoteur(): void
    {
        $doublon = (string) str()->uuid();

        $this->pousser('jeton-cv', $this->operationClient($doublon, '699112233', 'Bouba Hamadou'))
            ->assertJsonPath('resultats.0.resultat', 'appliquee');

        // §4.4 : la fiche existante garde son nom ; le nom propose attend la decision du Promoteur
        $this->assertSame('Alice Mbia', Client::findOrFail($this->clientId)->nom);
        $reunion = ClientReuni::findOrFail($doublon);
        $this->assertTrue($reunion->a_trancher);
        $this->assertSame('Bouba Hamadou', $reunion->nom_propose);
    }

    public function test_l_etat_initial_d_une_boutique_contient_son_travail_en_cours(): void
    {
        $enCours = $this->commandeEnregistree();
        $envoyee = $this->commandeEnregistree();
        $livree = $this->commandeEnregistree();
        $session = $this->sessionOuverte();
        $this->pousser('jeton-cv', $this->operationPaiement($enCours, $session, 3000));
        $lot = $this->operationLot([$envoyee]);
        $this->pousser('jeton-cv', $lot);
        // Livree il y a longtemps : elle ne concerne plus le poste. Livree hier : le poste remis en
        // service la retrouve, pour les comptes de sa caisse et de sa semaine
        Commande::whereKey($livree)->update(['statut' => 'LIVREE', 'updated_at' => now()->subWeeks(9)]);
        $livreeHier = $this->commandeEnregistree();
        Commande::whereKey($livreeHier)->update(['statut' => 'LIVREE', 'updated_at' => now()->subDay()]);

        $autre = $this->creerSite('MC');
        $this->creerPoste($autre, 'jeton-mc');

        $boutique = collect($this->withToken('jeton-cv')->getJson('/api/sync/instantane')->assertOk()->json('changements'))
            ->groupBy('table');
        $this->assertEqualsCanonicalizing([$enCours, $envoyee, $livreeHier], $boutique['commandes']->pluck('ligne_id')->all());
        $this->assertSame('3000.00', $boutique['commandes']->firstWhere('ligne_id', $enCours)['donnees']['total_paye']);
        $this->assertSame([$session], $boutique['sessions_caisse']->pluck('ligne_id')->all());
        $this->assertCount(1, $boutique['paiements']);
        $this->assertSame([$lot['ligne_id']], $boutique['lots']->pluck('ligne_id')->all());

        $atelier = collect($this->withToken('jeton-atl')->getJson('/api/sync/instantane')->json('changements'))
            ->groupBy('table');
        $this->assertSame([$envoyee], $atelier['commandes']->pluck('ligne_id')->all());
        $this->assertSame([$lot['ligne_id']], $atelier['lots']->pluck('ligne_id')->all());
        $this->assertArrayNotHasKey('paiements', $atelier->all());
        $this->assertArrayNotHasKey('sessions_caisse', $atelier->all());

        $voisine = collect($this->withToken('jeton-mc')->getJson('/api/sync/instantane')->json('changements'))
            ->groupBy('table');
        $this->assertArrayNotHasKey('commandes', $voisine->all());
        $this->assertArrayNotHasKey('lots', $voisine->all());
        $this->assertCount(1, $voisine['clients']);
    }

    public function test_l_etat_initial_est_envoye_par_pages_sans_rien_omettre_ni_repeter(): void
    {
        config(['pressing.sync.taille_lot' => 4]);
        for ($i = 0; $i < 9; $i++) {
            $this->pousser('jeton-cv', $this->operationClient((string) str()->uuid(), '69900000'.$i, 'Client '.$i));
        }
        $this->commandeEnregistree();
        $curseurAttendu = (int) SyncChangement::max('seq');

        $toutes = collect();
        $suite = null;
        $pages = 0;
        $premierCurseur = null;
        do {
            $reponse = $this->withToken('jeton-cv')
                ->getJson('/api/sync/instantane'.($suite === null ? '' : '?suite='.urlencode($suite)))
                ->assertOk();
            $this->assertLessThanOrEqual(4, count($reponse->json('changements')));
            $premierCurseur ??= $reponse->json('curseur');
            $toutes = $toutes->concat($reponse->json('changements'));
            $suite = $reponse->json('suite');
            $pages++;
        } while ($reponse->json('reste') && $pages < 50);

        $this->assertNull($suite);
        $this->assertGreaterThan(3, $pages);
        $this->assertSame($curseurAttendu, $premierCurseur);

        $cles = $toutes->map(fn ($c) => $c['table'].'/'.$c['ligne_id']);
        $this->assertSame($cles->count(), $cles->unique()->count(), 'aucune ligne en double');
        $parTable = $toutes->groupBy('table');
        $this->assertCount(2, $parTable['sites']);
        $this->assertCount(2, $parTable['tarifs']);
        $this->assertCount(10, $parTable['clients']);
        $this->assertCount(2, $parTable['utilisateurs'], 'tous les comptes du reseau, pas seulement ceux du site');
        $this->assertCount(1, $parTable['commandes']);

        // Un tarif ne peut pas arriver avant son article et son service, meme d'une page a l'autre
        $ordre = $toutes->pluck('table')->unique()->values()->all();
        $this->assertLessThan(array_search('tarifs', $ordre), array_search('articles', $ordre));
        $this->assertLessThan(array_search('commandes', $ordre), array_search('clients', $ordre));
    }

    public function test_une_suite_mal_formee_est_refusee(): void
    {
        $this->withToken('jeton-cv')->getJson('/api/sync/instantane?suite=abc')->assertStatus(422);
    }

    public function test_le_serveur_decide_l_affectation_d_un_encaissement_a_sa_reception(): void
    {
        config(['pressing.fuseau' => 'Africa/Douala']);
        // Commande deposee le mardi 6 octobre 2026 : semaine 41 du reseau
        $commande = $this->commandeEnregistree();
        $session = $this->sessionOuverte();

        $jourDuDepot = $this->operationPaiement($commande, $session, 3000);
        // Dimanche 23:30 a Douala : encore la semaine du depot
        $dimancheSoir = $this->operationPaiement($commande, $session, 1000, ['encaisse_le' => '2026-10-11T22:30:00Z']);
        // Lundi 00:30 a Douala, le meme dimanche en UTC : deja la semaine suivante
        $lundiMatin = $this->operationPaiement($commande, $session, 1000, ['type' => 'SOLDE', 'encaisse_le' => '2026-10-11T23:30:00Z']);
        // Un poste qui annoncerait lui-meme une affectation n'est pas ecoute
        $plusTard = $this->operationPaiement($commande, $session, 500, [
            'type' => 'SOLDE', 'encaisse_le' => '2026-10-20T10:00:00Z', 'affectation' => 'RECETTE',
        ]);

        $this->pousser('jeton-cv', $jourDuDepot, $dimancheSoir, $lundiMatin, $plusTard)
            ->assertJsonPath('resultats.0.resultat', 'appliquee')
            ->assertJsonPath('resultats.1.resultat', 'appliquee')
            ->assertJsonPath('resultats.2.resultat', 'appliquee')
            ->assertJsonPath('resultats.3.resultat', 'appliquee');

        // RG-14 : meme semaine que le depot, recette ; semaine posterieure, recouvrement tardif verse a la reserve
        $this->assertSame(Paiement::RECETTE, Paiement::findOrFail($jourDuDepot['ligne_id'])->affectation);
        $this->assertSame(Paiement::RECETTE, Paiement::findOrFail($dimancheSoir['ligne_id'])->affectation);
        $this->assertSame(Paiement::RESERVE, Paiement::findOrFail($lundiMatin['ligne_id'])->affectation);
        $this->assertSame(Paiement::RESERVE, Paiement::findOrFail($plusTard['ligne_id'])->affectation);

        // Renvoye apres une coupure sous un autre numero d'operation : la decision prise est gardee
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 1000, [
            'id' => $lundiMatin['ligne_id'], 'type' => 'SOLDE', 'encaisse_le' => '2026-10-11T23:30:00Z',
        ]))->assertJsonPath('resultats.0.resultat', 'appliquee');
        $this->assertSame(4, Paiement::count());
        $this->assertSame(Paiement::RESERVE, Paiement::findOrFail($lundiMatin['ligne_id'])->affectation);
    }

    public function test_l_affectation_d_un_encaissement_reste_sur_le_serveur(): void
    {
        $commande = $this->commandeEnregistree();
        $session = $this->sessionOuverte();
        $this->pousser('jeton-cv', $this->operationPaiement($commande, $session, 3000));

        $this->assertSame(Paiement::RECETTE, Paiement::sole()->affectation);

        // §7 : les comptes de la boutique n'en dependent pas ; ni le fil ni l'etat initial ne la portent
        $attendues = ['id', 'commande_id', 'session_caisse_id', 'utilisateur_id', 'type', 'montant', 'encaisse_le'];
        $publie = SyncChangement::where('table_cible', 'paiements')->sole();
        $this->assertSame($attendues, array_keys($publie->donnees));
        $instantane = collect($this->withToken('jeton-cv')->getJson('/api/sync/instantane')->assertOk()->json('changements'))
            ->firstWhere('table', 'paiements');
        $this->assertSame($attendues, array_keys($instantane['donnees']));
    }
}
