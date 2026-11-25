<?php

namespace App\Services\Sync;

use App\Models\Commande;
use App\Models\Consommable;
use App\Models\Controle;
use App\Models\LigneCommande;
use App\Models\Lot;
use App\Models\Poste;
use App\Models\Site;
use App\Models\SyncChangement;
use App\Models\Tache;
use App\Models\Travailleur;
use App\Models\Utilisateur;

/**
 * Etape 3 : ce que le poste de l'atelier envoie (§6.3 a §6.5). Reception des lots, controle,
 * taches, lots retour, travailleurs, consommables, et remise au client d'un depot direct.
 *
 * Les statuts de la commande sont deduits ici de ce que l'atelier a fait : le poste ne les
 * envoie pas (RG-05).
 */
trait GestionnairesAtelier
{
    /**
     * RG-19 : le destinataire accuse reception d'un lot. Le nombre recu est garde pour etre compare
     * au nombre annonce ; les commandes du lot passent au statut suivant.
     */
    private function appliquerReceptionLot(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'recu_par' => ['required', 'uuid'],
            'recu_le' => ['required', 'date'],
            'nombre_recu' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        $lot = Lot::find($donnees['id']);
        if ($lot === null) {
            throw new OperationRejetee('lot_inconnu');
        }
        if ($lot->site_destination_id !== $perimetre->site_id) {
            throw new OperationRejetee('non_destinataire');
        }
        if ($lot->recu_le !== null) {
            throw new OperationRejetee('lot_deja_recu');
        }
        $this->exigerUtilisateurConnu($donnees['recu_par']);

        $lot->fill([
            'recu_par' => $donnees['recu_par'],
            'recu_le' => $donnees['recu_le'],
            'nombre_recu' => $donnees['nombre_recu'],
        ]);
        $lot->save();

        $aller = $lot->sens === Lot::ALLER;
        $enRoute = $aller ? Commande::ENVOYEE : Commande::TRAITEE;
        $arrivee = $aller ? Commande::RECEPTIONNEE : Commande::RETOURNEE;
        foreach ($lot->commandes()->get() as $commande) {
            // une commande annulee pendant le transport ne change plus de statut
            if ($commande->statut !== $enRoute) {
                continue;
            }
            $commande->statut = $arrivee;
            $commande->save();
            if (! $aller) {
                // lot retour : l'atelier apprend que la commande est revenue en boutique
                $commande->publierChangement(SyncChangement::UPSERT, $lot->site_origine_id);
            }
        }
    }

    /**
     * RG-08, RG-09 : controle d'une commande recue. Conforme ou simple observation, elle est
     * Controlee ; un ecart sur une ligne donne une correction proposee et la bloque En attente
     * d'accord, sans rien changer a la facture.
     */
    private function appliquerControle(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'commande_id' => ['required', 'uuid'],
            'utilisateur_id' => ['required', 'uuid'],
            'resultat' => ['required', 'in:'.implode(',', Controle::RESULTATS)],
            'remarque' => ['nullable', 'string', 'max:500'],
            'controle_le' => ['required', 'date'],
            'ligne_commande_id' => ['nullable', 'uuid'],
            'quantite_apres' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
        $this->exigerAtelier($perimetre);

        if (Controle::whereKey($donnees['id'])->exists()) {
            return; // deja enregistre : rien a refaire
        }
        $commande = $this->commandeALAtelier($perimetre, $donnees['commande_id']);
        if ($commande->statut !== Commande::RECEPTIONNEE) {
            throw new OperationRejetee('commande_non_controlable');
        }
        $this->exigerUtilisateurConnu($donnees['utilisateur_id']);

        $controle = new Controle([
            'id' => $donnees['id'],
            'commande_id' => $commande->id,
            'utilisateur_id' => $donnees['utilisateur_id'],
            'resultat' => $donnees['resultat'],
            'remarque' => $donnees['remarque'] ?? null,
            'controle_le' => $donnees['controle_le'],
        ]);

        if ($donnees['resultat'] === Controle::ECART) {
            $ligne = $commande->lignes()->whereKey($donnees['ligne_commande_id'] ?? '')->first();
            if ($ligne === null) {
                throw new OperationRejetee('ligne_inconnue');
            }
            if (! isset($donnees['quantite_apres']) || (int) $donnees['quantite_apres'] === $ligne->quantite) {
                throw new OperationRejetee('donnees_invalides');
            }
            // Une commande sans plus aucun article ne pourrait ni etre traitee ni repartir : elle
            // s'annule (RG-24), elle ne se corrige pas
            if ((int) $donnees['quantite_apres'] === 0 && $commande->lignes()->count() === 1) {
                throw new OperationRejetee('commande_sans_article');
            }
            $controle->fill([
                'ligne_commande_id' => $ligne->id,
                'tarif_id' => $ligne->tarif_id,
                'quantite_avant' => $ligne->quantite,
                'quantite_apres' => (int) $donnees['quantite_apres'],
                'etat' => Controle::PROPOSEE,
            ]);
        }
        $controle->save();

        $commande->statut = $controle->enAttenteDAccord() ? Commande::EN_ATTENTE_ACCORD : Commande::CONTROLEE;
        $commande->save();
    }

    /**
     * RG-20 : tache attribuee a un travailleur sur une ligne, puis marquee terminee. Une fois
     * creee, seule sa fin s'ajoute. Le statut de la commande suit (RG-05).
     */
    private function appliquerTache(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'ligne_commande_id' => ['required', 'uuid'],
            'travailleur_id' => ['required', 'uuid'],
            // un poste d'avant les deux etapes n'en envoie pas : sa tache est la premiere etape de la ligne
            'etape' => ['sometimes', 'in:'.implode(',', Tache::ETAPES)],
            'quantite' => ['required', 'integer', 'min:1', 'max:999'],
            'attribuee_par' => ['required', 'uuid'],
            'attribuee_le' => ['required', 'date'],
            'terminee_le' => ['nullable', 'date'],
        ]);
        $this->exigerAtelier($perimetre);

        $tache = Tache::find($donnees['id']);
        if ($tache !== null) {
            $commande = $this->commandeALAtelier($perimetre, $tache->commande_id);
            if (! $tache->estTerminee() && ! empty($donnees['terminee_le'])) {
                $tache->terminee_le = $donnees['terminee_le'];
                $tache->save();
            }
            $commande->actualiserDepuisTaches();

            return;
        }

        $ligne = LigneCommande::find($donnees['ligne_commande_id']);
        if ($ligne === null) {
            throw new OperationRejetee('ligne_inconnue');
        }
        $commande = $this->commandeALAtelier($perimetre, $ligne->commande_id);
        if (! $commande->accepteDesTaches()) {
            throw new OperationRejetee('commande_non_attribuable');
        }
        $travailleur = Travailleur::find($donnees['travailleur_id']);
        if ($travailleur === null || $travailleur->site_id !== $perimetre->site_id) {
            throw new OperationRejetee('travailleur_inconnu');
        }
        $this->exigerUtilisateurConnu($donnees['attribuee_par']);

        // Deux etapes par article (16 octobre 2026) : l'etape doit etre l'une de la ligne, et
        // le travailleur doit pouvoir la faire
        $etape = $donnees['etape'] ?? ($ligne->etapes()[0] ?? Tache::LAVAGE);
        if (! in_array($etape, $ligne->etapes(), true)) {
            throw new OperationRejetee('etape_inconnue');
        }
        if (! $travailleur->peutFaire($etape)) {
            throw new OperationRejetee('role_incompatible');
        }

        $taches = Tache::where('ligne_commande_id', $ligne->id)->get();
        $dejaAttribue = (int) $taches->where('etape', $etape)->sum('quantite');
        if ($dejaAttribue + (int) $donnees['quantite'] > $ligne->quantite) {
            throw new OperationRejetee('depasse_la_quantite');
        }
        // un article se repasse une fois lave : on ne confie au repassage que ce que le lavage a rendu
        if ($etape === Tache::REPASSAGE && $ligne->avec_lavage) {
            $lave = (int) $taches->where('etape', Tache::LAVAGE)->whereNotNull('terminee_le')->sum('quantite');
            if ($dejaAttribue + (int) $donnees['quantite'] > $lave) {
                throw new OperationRejetee('lavage_non_termine');
            }
        }

        Tache::create([
            'id' => $donnees['id'],
            'commande_id' => $commande->id,
            'ligne_commande_id' => $ligne->id,
            'travailleur_id' => $travailleur->id,
            'etape' => $etape,
            'quantite' => (int) $donnees['quantite'],
            'commission_unitaire' => $ligne->commissionDeLEtape($etape),
            'attribuee_par' => $donnees['attribuee_par'],
            'attribuee_le' => $donnees['attribuee_le'],
            'terminee_le' => $donnees['terminee_le'] ?? null,
        ]);
        $commande->actualiserDepuisTaches();
    }

    /**
     * RG-02 : travailleur enrole a l'atelier. Ses donnees d'identite arrivent ici et n'en repartent
     * pas (§11). Une fiche deja recue n'est pas modifiee.
     */
    private function appliquerTravailleur(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'matricule' => ['required', 'string', 'regex:/^VP-[A-Z0-9]{3,12}-T$/'],
            'nom' => ['required', 'string', 'max:150'],
            // laveur, repasseur ou polyvalent (16 octobre 2026) ; polyvalent pour un poste d'avant
            'role' => ['sometimes', 'in:'.implode(',', Travailleur::ROLES)],
            'cni' => ['nullable', 'string', 'max:50'],
            'telephone' => ['nullable', 'string', 'max:30'],
            'quartier' => ['nullable', 'string', 'max:100'],
            'cree_par' => ['required', 'uuid'],
            'enrole_le' => ['required', 'date'],
        ]);
        $this->exigerAtelier($perimetre);

        $existant = Travailleur::find($donnees['id']);
        if ($existant !== null) {
            if ($existant->site_id !== $perimetre->site_id) {
                throw new OperationRejetee('non_proprietaire');
            }

            return;
        }
        if (Travailleur::where('matricule', $donnees['matricule'])->exists()) {
            throw new OperationRejetee('doublon_matricule');
        }
        $this->exigerUtilisateurConnu($donnees['cree_par']);

        Travailleur::create([
            'id' => $donnees['id'],
            'site_id' => $perimetre->site_id,
            'matricule' => $donnees['matricule'],
            'nom' => $donnees['nom'],
            'role' => $donnees['role'] ?? Travailleur::POLYVALENT,
            'cni' => $donnees['cni'] ?? null,
            'telephone' => $donnees['telephone'] ?? null,
            'quartier' => $donnees['quartier'] ?? null,
            'cree_par' => $donnees['cree_par'],
            'enrole_le' => $donnees['enrole_le'],
        ]);
    }

    /**
     * §7 : achat de l'atelier, avec sa date, sa categorie (decision du 23 octobre 2026 ; un poste
     * d'avant n'en envoie pas : consommables), sa description et son montant.
     */
    private function appliquerConsommable(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'utilisateur_id' => ['required', 'uuid'],
            'categorie' => ['sometimes', 'in:'.implode(',', array_keys(Consommable::CATEGORIES))],
            'description' => ['required', 'string', 'max:255'],
            'montant' => ['required', 'numeric', 'gt:0', 'max:'.self::MONTANT_MAX],
            'sortie_le' => ['required', 'date'],
        ]);
        $this->exigerAtelier($perimetre);

        if (Consommable::whereKey($donnees['id'])->exists()) {
            return;
        }
        $this->exigerUtilisateurConnu($donnees['utilisateur_id']);

        Consommable::create([
            'id' => $donnees['id'],
            'site_id' => $perimetre->site_id,
            'utilisateur_id' => $donnees['utilisateur_id'],
            'categorie' => $donnees['categorie'] ?? Consommable::CONSOMMABLES,
            'description' => $donnees['description'],
            'montant' => $donnees['montant'],
            'sortie_le' => $donnees['sortie_le'],
        ]);
    }

    /**
     * RG-05 : remise de la commande au client. Une commande de boutique doit etre revenue de
     * l'atelier ; un depot direct a l'atelier, etre traite.
     */
    private function appliquerRemise(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'remise_par' => ['required', 'uuid'],
            'remise_le' => ['required', 'date'],
        ]);

        $commande = $this->commandeDuSite($perimetre, $donnees['id']);
        if ($commande->statut === Commande::LIVREE) {
            return; // deja remise : rien a refaire
        }
        $prete = $commande->estDepotDirect() ? Commande::TRAITEE : Commande::RETOURNEE;
        if (! in_array($commande->statut, [$prete, Commande::NON_RECLAMEE], true)) {
            throw new OperationRejetee('commande_non_remettable');
        }
        $this->exigerPerimetre($this->exigerUtilisateurConnu($donnees['remise_par']), $commande);

        $commande->fill([
            'statut' => Commande::LIVREE,
            'livree_le' => $donnees['remise_le'],
            'livree_par' => $donnees['remise_par'],
        ]);
        $commande->save();
    }

    /**
     * RG-19 : lot retour cloture par l'atelier, vers la boutique d'origine de ses commandes. Elles
     * restent Traitees jusqu'a ce que la receptionniste accuse reception du lot.
     */
    private function appliquerLotRetour(Perimetre $perimetre, array $donnees): void
    {
        $this->exigerAtelier($perimetre);
        $versBoutique = Site::whereKey($donnees['site_destination_id'])->where('type', Site::BOUTIQUE)->exists();
        if (! $versBoutique) {
            throw new OperationRejetee('destination_invalide');
        }
        $this->exigerUtilisateurConnu($donnees['cloture_par']);

        $commandes = Commande::whereIn('id', $donnees['commande_ids'])->get();
        if ($commandes->count() !== count($donnees['commande_ids'])) {
            throw new OperationRejetee('commande_inconnue');
        }
        foreach ($commandes as $commande) {
            if ($commande->site_id !== $donnees['site_destination_id']) {
                throw new OperationRejetee('destination_invalide');
            }
            if ($commande->atelierId() !== $perimetre->site_id) {
                throw new OperationRejetee('commande_absente_de_l_atelier');
            }
            if ($commande->statut !== Commande::TRAITEE || $commande->estRepartie()) {
                throw new OperationRejetee('commande_non_envoyable');
            }
        }

        $lot = new Lot([
            'id' => $donnees['id'],
            'sens' => Lot::RETOUR,
            'site_origine_id' => $perimetre->site_id,
            'site_destination_id' => $donnees['site_destination_id'],
            'cloture_par' => $donnees['cloture_par'],
            'cloture_le' => $donnees['cloture_le'],
            'nombre_annonce' => $commandes->count(),
        ]);
        $lot->saveQuietly();
        $lot->commandes()->attach($commandes->modelKeys());

        $lot->publierChangement(SyncChangement::UPSERT);
        $lot->publierChangement(SyncChangement::UPSERT, $lot->site_destination_id);
    }

    /**
     * La commande doit se trouver a l'atelier de ce poste : il l'a recue par un lot aller, ou elle
     * y a ete deposee directement.
     */
    private function commandeALAtelier(Perimetre $perimetre, string $commandeId): Commande
    {
        $commande = Commande::find($commandeId);
        if ($commande === null) {
            throw new OperationRejetee('commande_inconnue');
        }
        if ($commande->site_id !== $perimetre->site_id && $commande->atelierId() !== $perimetre->site_id) {
            throw new OperationRejetee('commande_absente_de_l_atelier');
        }

        return $commande;
    }

    /** Les taches, les travailleurs et les consommables appartiennent a l'atelier (§4.3, principe 3). */
    private function exigerAtelier(Perimetre $perimetre): void
    {
        if (! Site::whereKey($perimetre->site_id)->where('type', Site::ATELIER)->exists()) {
            throw new OperationRejetee('reserve_a_l_atelier');
        }
    }

    /**
     * RG-10 : le chef d'atelier n'encaisse et ne remet que les commandes qu'il a lui-meme
     * enregistrees. Le serveur le verifie, pas seulement l'ecran (§11).
     */
    private function exigerPerimetre(Utilisateur $auteur, Commande $commande): void
    {
        if ($auteur->role === Utilisateur::CHEF_ATELIER && $commande->utilisateur_id !== $auteur->id) {
            throw new OperationRejetee('hors_perimetre');
        }
    }
}
