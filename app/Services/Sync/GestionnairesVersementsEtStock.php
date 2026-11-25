<?php

namespace App\Services\Sync;

use App\Models\ArticleConsommable;
use App\Models\AvanceCommission;
use App\Models\MouvementConsommable;
use App\Models\SessionCaisse;
use App\Models\Site;
use App\Models\Travailleur;
use App\Models\VersementJournalier;

/**
 * Ameliorations du 23 octobre 2026 : versements journaliers des boutiques a l'atelier, et stock de
 * consommables de l'atelier. Comme le reste, ce sont des faits envoyes par les postes : ils
 * s'ajoutent, ils ne se modifient pas.
 */
trait GestionnairesVersementsEtStock
{
    /**
     * Versement declare par une boutique a la cloture de sa caisse : le montant compte part avec
     * le collecteur. Il va a la boutique et aux ateliers, qui en accuseront reception.
     */
    private function appliquerVersementJournalier(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'session_caisse_id' => ['required', 'uuid'],
            'date_versement' => ['required', 'date_format:Y-m-d'],
            'montant_declare' => ['required', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            'remis_par' => ['required', 'uuid'],
            'declare_le' => ['required', 'date'],
        ]);
        if (! Site::whereKey($perimetre->site_id)->where('type', Site::BOUTIQUE)->exists()) {
            throw new OperationRejetee('reserve_a_la_boutique');
        }

        $existant = VersementJournalier::find($donnees['id']);
        if ($existant !== null) {
            if ($existant->site_id !== $perimetre->site_id) {
                throw new OperationRejetee('non_proprietaire');
            }

            return; // deja declare : un versement ne se modifie pas
        }
        $session = SessionCaisse::find($donnees['session_caisse_id']);
        if ($session === null || $session->site_id !== $perimetre->site_id) {
            throw new OperationRejetee('session_inconnue');
        }
        if (VersementJournalier::where('session_caisse_id', $session->id)->exists()) {
            throw new OperationRejetee('versement_deja_declare');
        }
        $this->exigerUtilisateurConnu($donnees['remis_par']);

        $versement = new VersementJournalier([
            'id' => $donnees['id'],
            'site_id' => $perimetre->site_id,
            'session_caisse_id' => $session->id,
            'date_versement' => $donnees['date_versement'],
            'montant_declare' => $donnees['montant_declare'],
            'remis_par' => $donnees['remis_par'],
            'declare_le' => $donnees['declare_le'],
        ]);
        $versement->save();
        $versement->publierAuxAteliers();
    }

    /**
     * L'atelier accuse reception d'un versement, avec le montant effectivement recu. Une seule
     * reception par versement : la seconde, faite hors ligne sur une autre base, est refusee.
     */
    private function appliquerReceptionVersement(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'recu_par' => ['required', 'uuid'],
            'recu_le' => ['required', 'date'],
            'montant_recu' => ['required', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            'observation' => ['nullable', 'string', 'max:255'],
        ]);
        $this->exigerAtelier($perimetre);

        $versement = VersementJournalier::find($donnees['id']);
        if ($versement === null) {
            throw new OperationRejetee('versement_inconnu');
        }
        if ($versement->estRecu()) {
            throw new OperationRejetee('versement_deja_recu');
        }
        $this->exigerUtilisateurConnu($donnees['recu_par']);

        $versement->fill([
            'site_reception_id' => $perimetre->site_id,
            'recu_par' => $donnees['recu_par'],
            'recu_le' => $donnees['recu_le'],
            'montant_recu' => $donnees['montant_recu'],
            'observation' => $donnees['observation'] ?? null,
        ]);
        // publie a la boutique, puis aux autres personnes des ateliers
        $versement->save();
        $versement->publierAuxAteliers();
    }

    /**
     * Avance sur commission remise a un travailleur de l'atelier (decision du 23 octobre 2026). Le
     * poste ne l'accorde que dans la limite de ce que le travailleur a gagne dans la semaine et pas
     * encore recu ; l'argent est remis : le serveur l'enregistre comme un fait.
     */
    private function appliquerAvanceCommission(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'travailleur_id' => ['required', 'uuid'],
            'montant' => ['required', 'numeric', 'gt:0', 'max:'.self::MONTANT_MAX],
            'versee_par' => ['required', 'uuid'],
            'versee_le' => ['required', 'date'],
            'motif' => ['nullable', 'string', 'max:255'],
        ]);
        $this->exigerAtelier($perimetre);

        if (AvanceCommission::whereKey($donnees['id'])->exists()) {
            return;
        }
        $travailleur = Travailleur::find($donnees['travailleur_id']);
        if ($travailleur === null || $travailleur->site_id !== $perimetre->site_id) {
            throw new OperationRejetee('travailleur_inconnu');
        }
        $this->exigerUtilisateurConnu($donnees['versee_par']);

        AvanceCommission::create([
            'id' => $donnees['id'],
            'site_id' => $perimetre->site_id,
            'travailleur_id' => $travailleur->id,
            'montant' => $donnees['montant'],
            'versee_par' => $donnees['versee_par'],
            'versee_le' => $donnees['versee_le'],
            'motif' => $donnees['motif'] ?? null,
        ]);
    }

    /** Consommable suivi en stock par l'atelier ; une fiche deja recue ne change pas. */
    private function appliquerArticleConsommable(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,30}$/'],
            'designation' => ['required', 'string', 'max:100'],
            'unite' => ['required', 'string', 'max:20'],
            'seuil_alerte' => ['required', 'numeric', 'min:0', 'max:999999999'],
        ]);
        $this->exigerAtelier($perimetre);

        $existant = ArticleConsommable::find($donnees['id']);
        if ($existant !== null) {
            if ($existant->site_id !== $perimetre->site_id) {
                throw new OperationRejetee('non_proprietaire');
            }

            return;
        }
        if (ArticleConsommable::where('site_id', $perimetre->site_id)->where('code', $donnees['code'])->exists()) {
            throw new OperationRejetee('doublon_code');
        }

        ArticleConsommable::create([
            'id' => $donnees['id'],
            'site_id' => $perimetre->site_id,
            'code' => $donnees['code'],
            'designation' => $donnees['designation'],
            'unite' => $donnees['unite'],
            'seuil_alerte' => $donnees['seuil_alerte'],
        ]);
    }

    /**
     * Entree ou sortie de stock. Le serveur ne refuse pas une sortie qui depasserait le stock qu'il
     * connait : elle a eu lieu, et une entree faite hors ligne sur une autre base peut l'expliquer.
     */
    private function appliquerMouvementConsommable(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'article_id' => ['required', 'uuid'],
            'type' => ['required', 'in:'.implode(',', MouvementConsommable::TYPES)],
            'quantite' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'cout_total' => ['nullable', 'numeric', 'min:0', 'max:'.self::MONTANT_MAX],
            'utilisateur_id' => ['required', 'uuid'],
            'mouvement_le' => ['required', 'date'],
            'remarque' => ['nullable', 'string', 'max:255'],
        ]);
        $this->exigerAtelier($perimetre);

        if (MouvementConsommable::whereKey($donnees['id'])->exists()) {
            return;
        }
        $article = ArticleConsommable::find($donnees['article_id']);
        if ($article === null || $article->site_id !== $perimetre->site_id) {
            throw new OperationRejetee('article_inconnu');
        }
        $this->exigerUtilisateurConnu($donnees['utilisateur_id']);

        MouvementConsommable::create([
            'id' => $donnees['id'],
            'site_id' => $perimetre->site_id,
            'article_id' => $article->id,
            'type' => $donnees['type'],
            'quantite' => $donnees['quantite'],
            'cout_total' => $donnees['type'] === MouvementConsommable::APPROVISIONNEMENT ? ($donnees['cout_total'] ?? null) : null,
            'utilisateur_id' => $donnees['utilisateur_id'],
            'mouvement_le' => $donnees['mouvement_le'],
            'remarque' => $donnees['remarque'] ?? null,
        ]);
    }
}
