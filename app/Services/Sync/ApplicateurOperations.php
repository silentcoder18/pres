<?php

namespace App\Services\Sync;

use App\Models\Client;
use App\Models\ClientReuni;
use App\Models\Commande;
use App\Models\LigneCommande;
use App\Models\Lot;
use App\Models\Message;
use App\Models\Paiement;
use App\Models\Poste;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\SyncOperation;
use App\Models\Tarif;
use App\Models\Utilisateur;
use App\Services\Finance\AffectationPaiements;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Applique les ecritures envoyees par les postes (§4.3).
 *
 * Chaque operation est traitee dans sa propre transaction et tracee par son
 * identifiant : la recevoir deux fois ne l'applique qu'une fois. Une
 * operation refusee est tracee aussi, avec son motif.
 *
 * Pour accepter une nouvelle table, ajouter son nom a GESTIONNAIRES et la
 * methode correspondante.
 */
class ApplicateurOperations
{
    use GestionnairesAtelier, GestionnairesReception, GestionnairesVersementsEtStock;

    /**
     * Plus grand montant accepte : dix chiffres avant la virgule, ce que tiennent les colonnes
     * decimal(12, 2). Au-dela, la base refuserait l'ecriture et l'envoi du poste resterait bloque.
     */
    public const MONTANT_MAX = 9999999999;

    private const GESTIONNAIRES = [
        'clients' => 'appliquerClient',
        'sessions_caisse' => 'appliquerSessionCaisse',
        'commandes' => 'appliquerCommande',
        'paiements' => 'appliquerPaiement',
        'lots' => 'appliquerLot',
        'messages' => 'appliquerMessage',
        // etape 3 : atelier
        'receptions_lot' => 'appliquerReceptionLot',
        'controles' => 'appliquerControle',
        'taches' => 'appliquerTache',
        'travailleurs' => 'appliquerTravailleur',
        'consommables' => 'appliquerConsommable',
        'remises' => 'appliquerRemise',
        // etape 3 : receptionniste
        'accords' => 'appliquerAccord',
        'demandes_annulation' => 'appliquerDemandeAnnulation',
        // ameliorations du 23 octobre 2026
        'versements_journaliers' => 'appliquerVersementJournalier',
        'receptions_versement' => 'appliquerReceptionVersement',
        'articles_consommables' => 'appliquerArticleConsommable',
        'mouvements_consommables' => 'appliquerMouvementConsommable',
        'avances_commission' => 'appliquerAvanceCommission',
    ];

    /** @return array{id: string, resultat: string, motif: ?string} */
    public function appliquer(Perimetre $perimetre, array $operation): array
    {
        $dejaRecue = SyncOperation::find($operation['id']);
        if ($dejaRecue !== null) {
            $rejetee = $dejaRecue->resultat === SyncOperation::REJETEE;

            return $this->resultat(
                $operation['id'],
                $rejetee ? SyncOperation::REJETEE : SyncOperation::DEJA_APPLIQUEE,
                $rejetee ? $dejaRecue->motif : null,
            );
        }

        try {
            DB::transaction(function () use ($perimetre, $operation) {
                $gestionnaire = self::GESTIONNAIRES[$operation['table']] ?? null;
                if ($gestionnaire === null) {
                    throw new OperationRejetee('table_inconnue');
                }
                $this->{$gestionnaire}($perimetre, $operation);
                $this->tracer($perimetre, $operation, SyncOperation::APPLIQUEE, null);
            });
        } catch (OperationRejetee $e) {
            $this->tracer($perimetre, $operation, SyncOperation::REJETEE, $e->getMessage());

            return $this->resultat($operation['id'], SyncOperation::REJETEE, $e->getMessage());
        }

        return $this->resultat($operation['id'], SyncOperation::APPLIQUEE, null);
    }

    private function appliquerClient(Perimetre $perimetre, array $operation): void
    {
        $donnees = $operation['donnees'];

        $validation = Validator::make($donnees, [
            'id' => ['required', 'uuid'],
            'nom' => ['required', 'string', 'max:150'],
            'telephone' => ['required', 'string', 'max:30'],
            'quartier' => ['nullable', 'string', 'max:100'],
        ]);
        if ($validation->fails() || $donnees['id'] !== $operation['ligne_id']) {
            throw new OperationRejetee('donnees_invalides');
        }

        $client = Client::find($donnees['id']);
        if ($client !== null && $client->site_origine_id !== $perimetre->site_id) {
            throw new OperationRejetee('non_proprietaire');
        }
        if (ClientReuni::whereKey($donnees['id'])->exists()) {
            return; // fiche deja reunie a une autre : rien a refaire
        }

        // RG-21 : le numero identifie le client dans tout le reseau
        $memeNumero = Client::where('telephone', $donnees['telephone'])->where('id', '!=', $donnees['id'])->first();
        if ($memeNumero !== null) {
            if ($client !== null) {
                // une fiche deja connue ne peut pas prendre le numero d'une autre
                throw new OperationRejetee('doublon_telephone');
            }
            $this->reunirClients($perimetre, $donnees, $memeNumero);

            return;
        }

        Client::updateOrCreate(['id' => $donnees['id']], [
            'nom' => $donnees['nom'],
            'telephone' => $donnees['telephone'],
            'quartier' => $donnees['quartier'] ?? null,
            'site_origine_id' => $perimetre->site_id,
        ]);
    }

    /**
     * RG-21 : une fiche creee hors ligne avec un numero deja connu est reunie a la fiche existante,
     * qui est conservee. Si les noms different, le cas est garde pour le Promoteur (§4.4).
     */
    private function reunirClients(Perimetre $perimetre, array $donnees, Client $conserve): void
    {
        $reunion = new ClientReuni([
            'id' => $donnees['id'],
            'client_id' => $conserve->id,
            'site_id' => $perimetre->site_id,
            'nom_propose' => $donnees['nom'],
            'quartier_propose' => $donnees['quartier'] ?? null,
            'a_trancher' => $this->nomCompare($donnees['nom']) !== $this->nomCompare($conserve->nom),
        ]);
        $reunion->saveQuietly();
        // le poste qui a cree le doublon doit faire la meme reunion chez lui
        $reunion->publierChangement(SyncChangement::UPSERT, ycomprisOrigine: true);
    }

    private function nomCompare(string $nom): string
    {
        return (string) str($nom)->ascii()->lower()->squish();
    }

    // ------------------------------------------------------------------ etape 2 : boutique

    /**
     * RG-18 : ouverture puis cloture de la caisse du jour. Une caisse par personne (decision du
     * 16 octobre 2026) : chacune a sa base sur le poste et ne voit pas, hors ligne, la caisse d'une
     * autre ; une seule session ouverte par personne et par site.
     */
    private function appliquerSessionCaisse(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'ouverte_par' => ['required', 'uuid'],
            'ouverte_le' => ['required', 'date'],
            'fond_initial' => ['required', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            'cloturee_par' => ['nullable', 'uuid'],
            'cloturee_le' => ['nullable', 'date'],
            'total_attendu' => ['nullable', 'numeric'],
            'total_compte' => ['nullable', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            'ecart' => ['nullable', 'numeric'],
            'observations' => ['nullable', 'string', 'max:255'],
        ]);

        $session = SessionCaisse::find($donnees['id']);
        if ($session !== null && $session->site_id !== $perimetre->site_id) {
            throw new OperationRejetee('non_proprietaire');
        }
        if ($session !== null && ! $session->estOuverte()) {
            throw new OperationRejetee('session_deja_cloturee');
        }
        $this->exigerUtilisateurConnu($donnees['ouverte_par']);
        if (! empty($donnees['cloturee_par'])) {
            $this->exigerUtilisateurConnu($donnees['cloturee_par']);
        }

        $autreOuverte = SessionCaisse::where('site_id', $perimetre->site_id)
            ->where('ouverte_par', $donnees['ouverte_par'])
            ->whereNull('cloturee_le')
            ->where('id', '!=', $donnees['id'])
            ->exists();
        if ($autreOuverte) {
            throw new OperationRejetee('session_deja_ouverte');
        }

        $cloture = ! empty($donnees['cloturee_le']);
        if ($cloture && (empty($donnees['cloturee_par']) || ! isset($donnees['total_compte']))) {
            throw new OperationRejetee('donnees_invalides');
        }

        $session ??= new SessionCaisse(['id' => $donnees['id']]);
        $session->fill([
            'site_id' => $perimetre->site_id,
            'ouverte_par' => $donnees['ouverte_par'],
            'ouverte_le' => $donnees['ouverte_le'],
            'fond_initial' => $donnees['fond_initial'],
            'cloturee_par' => $cloture ? $donnees['cloturee_par'] : null,
            'cloturee_le' => $cloture ? $donnees['cloturee_le'] : null,
            'total_compte' => $cloture ? $donnees['total_compte'] : null,
            'observations' => $donnees['observations'] ?? null,
        ]);
        $session->saveQuietly();
        // total attendu et ecart : calcules ici, pas recus
        $session->recalculer(silencieux: true);
        $session->publierChangement(SyncChangement::UPSERT);
    }

    /**
     * Commande et ses lignes, recues ensemble. Une boutique ne cree ou ne modifie une commande que
     * tant qu'elle est Enregistree : une fois dans un lot, elle n'est plus modifiable (RG-05).
     */
    private function appliquerCommande(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'max:40'],
            'client_id' => ['required', 'uuid'],
            'utilisateur_id' => ['required', 'uuid'],
            'statut' => ['required', 'in:'.Commande::ENREGISTREE],
            'deposee_le' => ['required', 'date'],
            'accord_messages' => ['sometimes', 'boolean'],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.id' => ['required', 'uuid'],
            'lignes.*.tarif_id' => ['required', 'uuid'],
            'lignes.*.quantite' => ['required', 'integer', 'min:1'],
            'lignes.*.prix_applique' => ['required', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            'lignes.*.commission_appliquee' => ['required', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            // deux etapes par article (16 octobre 2026) ; un poste d'avant les envoie sans elles
            'lignes.*.avec_lavage' => ['sometimes', 'boolean'],
            'lignes.*.avec_repassage' => ['sometimes', 'boolean'],
            'lignes.*.commission_lavage' => ['sometimes', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            'lignes.*.commission_repassage' => ['sometimes', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            // tache, accroc, bouton manquant... note au depot ; elle suit la commande jusqu'a l'atelier
            'lignes.*.remarque' => ['nullable', 'string', 'max:500'],
        ]);

        $commande = Commande::find($donnees['id']);
        if ($commande !== null && $commande->site_id !== $perimetre->site_id) {
            throw new OperationRejetee('non_proprietaire');
        }
        if ($commande !== null && $commande->statut !== Commande::ENREGISTREE) {
            throw new OperationRejetee('commande_verrouillee');
        }
        $clientId = $this->clientConnu($donnees['client_id']);
        $this->exigerUtilisateurConnu($donnees['utilisateur_id']);
        $tarifs = Tarif::with('service')->whereIn('id', array_unique(array_column($donnees['lignes'], 'tarif_id')))
            ->get()->keyBy('id');
        if ($tarifs->count() !== count(array_unique(array_column($donnees['lignes'], 'tarif_id')))) {
            throw new OperationRejetee('tarif_inconnu');
        }
        $etapes = [];
        foreach ($donnees['lignes'] as $ligne) {
            $etapes[$ligne['id']] = $this->etapesDeLaLigne($ligne, $tarifs[$ligne['tarif_id']]);
        }
        // RG-01 : le code identifie la commande au retrait
        if (Commande::where('code', $donnees['code'])->where('id', '!=', $donnees['id'])->exists()) {
            throw new OperationRejetee('doublon_code');
        }

        $commande ??= new Commande(['id' => $donnees['id']]);
        $commande->fill([
            'code' => $donnees['code'],
            'site_id' => $perimetre->site_id,
            'client_id' => $clientId,
            'utilisateur_id' => $donnees['utilisateur_id'],
            'statut' => Commande::ENREGISTREE,
            'deposee_le' => $donnees['deposee_le'],
            'accord_messages' => $donnees['accord_messages'] ?? true,
        ]);
        $commande->saveQuietly();

        // RG-03 : prix et commission sont ceux du catalogue du poste au moment de la saisie
        $commande->lignes()->delete();
        foreach ($donnees['lignes'] as $ligne) {
            LigneCommande::create([
                'id' => $ligne['id'],
                'commande_id' => $commande->id,
                'tarif_id' => $ligne['tarif_id'],
                'quantite' => $ligne['quantite'],
                'prix_applique' => $ligne['prix_applique'],
                'commission_appliquee' => $ligne['commission_appliquee'],
                'remarque' => $this->texteOuNull($ligne['remarque'] ?? null),
            ] + $etapes[$ligne['id']]);
        }
        $commande->recalculer(silencieux: true);
        if (bccomp((string) $commande->reste, '0', 2) < 0) {
            // une commande modifiee ne peut pas valoir moins que ce qui a deja ete encaisse
            throw new OperationRejetee('montant_inferieur_au_paye');
        }
        // une seule publication, commande et lignes a jour ; un client reuni a une autre fiche
        // change l'identifiant envoye par le poste, qui doit alors recevoir la commande corrigee
        $commande->publierChangement(SyncChangement::UPSERT, ycomprisOrigine: $clientId !== $donnees['client_id']);
    }

    /**
     * RG-17 : encaissement en especes, rattache a une session de caisse du site, sans depasser le
     * reste. RG-24 : l'avance d'une commande annulee est restituee en une fois ; cette sortie de
     * caisse est un paiement de type Remboursement, rattache lui aussi a une session du site.
     */
    private function appliquerPaiement(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'commande_id' => ['required', 'uuid'],
            'session_caisse_id' => ['required', 'uuid'],
            'utilisateur_id' => ['required', 'uuid'],
            'type' => ['required', 'in:'.Paiement::ACOMPTE.','.Paiement::SOLDE.','.Paiement::REMBOURSEMENT],
            'montant' => ['required', 'numeric', 'gt:0', 'max:'.self::MONTANT_MAX],
            'encaisse_le' => ['required', 'date'],
        ]);

        $commande = $this->commandeDuSite($perimetre, $donnees['commande_id']);
        $session = SessionCaisse::find($donnees['session_caisse_id']);
        if ($session === null || $session->site_id !== $perimetre->site_id) {
            throw new OperationRejetee('session_inconnue');
        }
        if (! $session->estOuverte() && Carbon::parse($donnees['encaisse_le'])->gt($session->cloturee_le)) {
            throw new OperationRejetee('session_cloturee');
        }
        $this->exigerPerimetre($this->exigerUtilisateurConnu($donnees['utilisateur_id']), $commande);

        if (Paiement::whereKey($donnees['id'])->exists()) {
            return; // deja enregistre : rien a refaire
        }
        if ($donnees['type'] === Paiement::REMBOURSEMENT) {
            if ($commande->statut === Commande::ANNULEE) {
                // RG-24 : l'avance d'une commande annulee se restitue en entier
                if (bccomp((string) $donnees['montant'], (string) $commande->total_paye, 2) !== 0) {
                    throw new OperationRejetee('montant_different_du_paye');
                }
            } elseif (bccomp((string) $commande->reste, '0', 2) >= 0
                || bccomp((string) $donnees['montant'], bcmul((string) $commande->reste, '-1', 2), 2) > 0) {
                // sinon, on ne rend au client que ce qu'il a verse en trop (reste negatif)
                throw new OperationRejetee('pas_de_trop_percu');
            }
        }
        // Un versement en especes est un fait : le poste l'a accepte avec ce qu'il savait du reste a
        // payer (RG-17). S'il depasse le reste connu ici (facture corrigee entre-temps, commande
        // annulee entre-temps), il est enregistre quand meme : le trop-percu se lit dans le reste
        // negatif, et se rend au client par une restitution.

        $paiement = new Paiement([
            'id' => $donnees['id'],
            'commande_id' => $commande->id,
            'session_caisse_id' => $session->id,
            'utilisateur_id' => $donnees['utilisateur_id'],
            'type' => $donnees['type'],
            'montant' => $donnees['montant'],
            'encaisse_le' => $donnees['encaisse_le'],
        ]);
        // RG-14, §7 : recette de la semaine ou recouvrement tardif, c'est le serveur qui le decide,
        // ici, d'apres les dates telles qu'il les enregistre ; le poste n'en sait rien. Une avance
        // restituee n'a pas d'affectation
        $paiement->affectation = AffectationPaiements::decider($paiement->type, $paiement->encaisse_le, $commande->deposee_le);
        $paiement->save();
        $commande->recalculer();
        // encaissement arrive apres la cloture de sa session : les comptes de la journee le reprennent
        $session->recalculer();
    }

    /**
     * RG-19 : lot cloture par le site du poste, dont le contenu est fige. Lot aller d'une boutique :
     * ses commandes passent Envoyees et sont transmises, avec le lot, au poste de l'atelier. Lot
     * retour de l'atelier : voir appliquerLotRetour.
     */
    private function appliquerLot(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'sens' => ['required', 'in:'.Lot::ALLER.','.Lot::RETOUR],
            'site_destination_id' => ['required', 'uuid'],
            'cloture_par' => ['required', 'uuid'],
            'cloture_le' => ['required', 'date'],
            'commande_ids' => ['required', 'array', 'min:1'],
            'commande_ids.*' => ['required', 'uuid', 'distinct'],
        ]);

        if (Lot::whereKey($donnees['id'])->exists()) {
            throw new OperationRejetee('lot_deja_cloture');
        }
        if ($donnees['sens'] === Lot::RETOUR) {
            $this->appliquerLotRetour($perimetre, $donnees);

            return;
        }
        $versAtelier = Site::whereKey($donnees['site_destination_id'])->where('type', Site::ATELIER)->exists();
        if (! $versAtelier) {
            throw new OperationRejetee('destination_invalide');
        }
        $this->exigerUtilisateurConnu($donnees['cloture_par']);

        $commandes = Commande::whereIn('id', $donnees['commande_ids'])->get();
        if ($commandes->count() !== count($donnees['commande_ids'])) {
            throw new OperationRejetee('commande_inconnue');
        }
        foreach ($commandes as $commande) {
            if ($commande->site_id !== $perimetre->site_id) {
                throw new OperationRejetee('non_proprietaire');
            }
            if ($commande->statut !== Commande::ENREGISTREE) {
                throw new OperationRejetee('commande_non_envoyable');
            }
        }

        $lot = new Lot([
            'id' => $donnees['id'],
            'sens' => Lot::ALLER,
            'site_origine_id' => $perimetre->site_id,
            'site_destination_id' => $donnees['site_destination_id'],
            'cloture_par' => $donnees['cloture_par'],
            'cloture_le' => $donnees['cloture_le'],
            'nombre_annonce' => $commandes->count(),
        ]);
        $lot->saveQuietly();
        $lot->commandes()->attach($commandes->modelKeys());

        foreach ($commandes as $commande) {
            $commande->statut = Commande::ENVOYEE;
            $commande->save();
            // l'atelier recoit la commande entiere avec le lot (§6.2)
            $commande->publierChangement(SyncChangement::UPSERT, $lot->site_destination_id);
        }
        $lot->publierChangement(SyncChangement::UPSERT);
        $lot->publierChangement(SyncChangement::UPSERT, $lot->site_destination_id);
    }

    /**
     * §8 : message WhatsApp cree sur le poste ; il attend ici d'etre envoye par le serveur. Il porte
     * sur une commande du site, ou, pour l'atelier, sur une commande qu'il a recue : une anomalie
     * constatee en cours de traitement est annoncee au client sans bloquer la commande.
     */
    private function appliquerMessage(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'client_id' => ['required', 'uuid'],
            'commande_id' => ['nullable', 'uuid'],
            'declencheur' => ['required', 'string', 'max:40'],
            'contenu' => ['required', 'string', 'max:4000'],
            'cree_le' => ['required', 'date'],
        ]);

        if (Message::whereKey($donnees['id'])->exists()) {
            return;
        }
        $clientId = $this->clientConnu($donnees['client_id']);
        if (! empty($donnees['commande_id'])) {
            $commande = Commande::find($donnees['commande_id']);
            if ($commande === null) {
                throw new OperationRejetee('commande_inconnue');
            }
            if ($commande->site_id !== $perimetre->site_id && $commande->atelierId() !== $perimetre->site_id) {
                throw new OperationRejetee('non_proprietaire');
            }
        }

        Message::create([
            'id' => $donnees['id'],
            'site_id' => $perimetre->site_id,
            'client_id' => $clientId,
            'commande_id' => $donnees['commande_id'] ?? null,
            'declencheur' => $donnees['declencheur'],
            'contenu' => $donnees['contenu'],
            'cree_le' => $donnees['cree_le'],
        ]);
    }

    /**
     * Etapes d'une ligne et commission de chacune, figees au depot comme son prix (RG-03, decision du
     * 16 octobre 2026). Le poste les donne d'apres son catalogue ; leur somme est la commission de la
     * ligne. Un poste d'avant les deux etapes ne les donne pas : elles se deduisent alors de la
     * prestation, la commission d'une prestation a deux etapes etant partagee par moitie.
     *
     * @return array{avec_lavage: bool, avec_repassage: bool, commission_lavage: string, commission_repassage: string}
     */
    private function etapesDeLaLigne(array $ligne, Tarif $tarif): array
    {
        $commission = bcadd((string) $ligne['commission_appliquee'], '0', 2);
        if (! array_key_exists('avec_lavage', $ligne)) {
            $avecLavage = (bool) $tarif->service->avec_lavage;
            $avecRepassage = (bool) $tarif->service->avec_repassage;
            $lavage = ! $avecLavage ? '0.00' : ($avecRepassage ? bcdiv(bcdiv($commission, '2', 0), '1', 2) : $commission);

            return [
                'avec_lavage' => $avecLavage,
                'avec_repassage' => $avecRepassage,
                'commission_lavage' => $lavage,
                'commission_repassage' => bcsub($commission, $lavage, 2),
            ];
        }

        $avecLavage = (bool) $ligne['avec_lavage'];
        $avecRepassage = (bool) ($ligne['avec_repassage'] ?? false);
        $lavage = $avecLavage ? bcadd((string) ($ligne['commission_lavage'] ?? '0'), '0', 2) : '0.00';
        $repassage = $avecRepassage ? bcadd((string) ($ligne['commission_repassage'] ?? '0'), '0', 2) : '0.00';
        if (! $avecLavage && ! $avecRepassage || bccomp(bcadd($lavage, $repassage, 2), $commission, 2) !== 0) {
            throw new OperationRejetee('donnees_invalides');
        }

        return [
            'avec_lavage' => $avecLavage,
            'avec_repassage' => $avecRepassage,
            'commission_lavage' => $lavage,
            'commission_repassage' => $repassage,
        ];
    }

    /** Donnees de l'operation, une fois validees ; l'identifiant doit etre celui de la ligne annoncee. */
    private function valider(array $operation, array $regles): array
    {
        $donnees = $operation['donnees'];
        if (Validator::make($donnees, $regles)->fails() || ($donnees['id'] ?? null) !== $operation['ligne_id']) {
            throw new OperationRejetee('donnees_invalides');
        }

        return $donnees;
    }

    private function texteOuNull(?string $texte): ?string
    {
        $texte = $texte === null ? null : trim($texte);

        return $texte === '' ? null : $texte;
    }

    /** Identifiant de la fiche a utiliser : celle qui a ete conservee si la fiche annoncee a ete reunie (RG-21). */
    private function clientConnu(string $clientId): string
    {
        $clientId = ClientReuni::resoudre($clientId);
        if (! Client::whereKey($clientId)->exists()) {
            throw new OperationRejetee('client_inconnu');
        }

        return $clientId;
    }

    /**
     * L'auteur d'une ecriture doit etre un compte du serveur. Il n'a pas a etre rattache au site du
     * poste : n'importe quel compte peut se connecter sur n'importe quel poste (decision du 9 octobre
     * 2026). Un compte desactive depuis reste accepte : l'operation a bien eu lieu quand il travaillait.
     */
    private function exigerUtilisateurConnu(string $utilisateurId): Utilisateur
    {
        $utilisateur = Utilisateur::find($utilisateurId);
        if ($utilisateur === null) {
            throw new OperationRejetee('utilisateur_inconnu');
        }

        return $utilisateur;
    }

    private function commandeDuSite(Perimetre $perimetre, string $commandeId): Commande
    {
        $commande = Commande::find($commandeId);
        if ($commande === null) {
            throw new OperationRejetee('commande_inconnue');
        }
        if ($commande->site_id !== $perimetre->site_id) {
            throw new OperationRejetee('non_proprietaire');
        }

        return $commande;
    }

    private function tracer(Perimetre $perimetre, array $operation, string $resultat, ?string $motif): void
    {
        SyncOperation::create([
            'id' => $operation['id'],
            'poste_id' => $perimetre->poste->id,
            'session_poste_id' => $perimetre->session?->id,
            'table_cible' => $operation['table'],
            'ligne_id' => $operation['ligne_id'],
            'resultat' => $resultat,
            'motif' => $motif,
            'recu_le' => now(),
        ]);
    }

    private function resultat(string $id, string $resultat, ?string $motif): array
    {
        return ['id' => $id, 'resultat' => $resultat, 'motif' => $motif];
    }
}
