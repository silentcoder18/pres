<?php

namespace App\Support;

use App\Models\Commande;
use App\Models\Consommable;
use App\Models\Controle;
use App\Models\DemandeAnnulation;
use App\Models\Message;
use App\Models\Paiement;
use App\Models\Site;
use App\Models\Tache;
use App\Models\Travailleur;
use App\Models\Utilisateur;
use Carbon\CarbonInterface;

/**
 * Mise en forme pour les pages du Promoteur : montants, dates dans le fuseau du reseau,
 * libelles des codes qui circulent entre les postes et le serveur.
 */
final class Affichage
{
    private const STATUTS = [
        'ENREGISTREE' => 'Enregistrée',
        'ENVOYEE' => 'Envoyée',
        'RECEPTIONNEE' => 'Réceptionnée',
        'EN_ATTENTE_ACCORD' => "En attente d'accord",
        'CONTROLEE' => 'Contrôlée',
        'EN_TRAITEMENT' => 'En traitement',
        'TRAITEE' => 'Traitée',
        'RETOURNEE' => 'Retournée',
        'LIVREE' => 'Livrée',
        'NON_RECLAMEE' => 'Non réclamée',
        'DON' => 'Don',
        'ANNULEE' => 'Annulée',
    ];

    private const MOTIFS = [
        'table_inconnue' => 'Type de donnée inconnu du serveur',
        'donnees_invalides' => 'Données incomplètes ou invalides',
        'non_proprietaire' => 'Donnée appartenant à un autre site',
        'doublon_telephone' => 'Numéro déjà porté par une autre fiche client',
        'doublon_code' => 'Code de commande déjà utilisé',
        'client_inconnu' => 'Client inconnu du serveur',
        'tarif_inconnu' => 'Tarif inconnu du serveur',
        'utilisateur_inconnu' => 'Compte inconnu du serveur',
        'commande_inconnue' => 'Commande inconnue du serveur',
        'commande_verrouillee' => 'Commande déjà envoyée : elle ne se modifie plus',
        'commande_non_envoyable' => "Commande qui n'est pas prête à partir dans un lot",
        'montant_inferieur_au_paye' => 'Montant inférieur à ce qui est déjà encaissé',
        'session_inconnue' => 'Session de caisse inconnue du serveur',
        'session_cloturee' => 'Encaissement postérieur à la clôture de la caisse',
        'session_deja_ouverte' => 'Une autre caisse est déjà ouverte sur ce site',
        'session_deja_cloturee' => 'Caisse déjà clôturée',
        'depasse_le_reste' => 'Encaissement supérieur au reste à payer',
        'destination_invalide' => "Le lot n'est pas adressé au bon site",
        'lot_deja_cloture' => 'Lot déjà clôturé',
        'lot_inconnu' => 'Lot inconnu du serveur',
        'lot_deja_recu' => 'Lot déjà reçu',
        'non_destinataire' => 'Lot destiné à un autre site',
        'reserve_a_l_atelier' => "Opération réservée au poste de l'atelier",
        'commande_absente_de_l_atelier' => "Commande que l'atelier n'a pas reçue",
        'commande_non_controlable' => "Commande qui n'attend pas de contrôle",
        'commande_non_attribuable' => 'Commande qui ne peut pas recevoir de tâche',
        'commande_non_remettable' => "Commande qui n'est pas prête à être remise",
        'ligne_inconnue' => 'Ligne de commande inconnue du serveur',
        'travailleur_inconnu' => "Travailleur inconnu de l'atelier",
        'depasse_la_quantite' => 'Quantité attribuée supérieure à celle de la ligne',
        'doublon_matricule' => 'Matricule déjà porté par un autre travailleur',
        'hors_perimetre' => "Le chef d'atelier n'encaisse et ne remet que ses propres commandes",
        'controle_inconnu' => 'Contrôle inconnu du serveur',
        'commande_non_annulable' => "Commande entrée en traitement, ou sortie du suivi : elle ne s'annule plus",
        'demande_deja_en_cours' => "Une demande d'annulation attend déjà la décision du Promoteur",
        'commande_non_annulee' => "Avance restituée sur une commande qui n'est pas annulée",
        'montant_different_du_paye' => 'Avance restituée différente de ce que le client a versé',
        'pas_de_trop_percu' => 'Somme rendue au client alors qu\'il n\'a pas versé autant en trop',
        'commande_sans_article' => 'Correction qui retirerait le dernier article de la commande',
        // deux etapes par article (16 octobre 2026)
        'etape_inconnue' => 'Étape que la prestation de cette ligne ne comprend pas',
        'role_incompatible' => 'Étape que le rôle de ce travailleur ne lui permet pas de faire',
        'lavage_non_termine' => 'Repassage confié avant que le lavage de ces articles soit terminé',
        'reserve_a_la_boutique' => "Opération réservée au poste d'une boutique",
        'versement_deja_declare' => 'Versement déjà déclaré pour cette caisse',
        'versement_inconnu' => 'Versement inconnu du serveur',
        'versement_deja_recu' => "Versement déjà reçu à l'atelier",
        'article_inconnu' => "Consommable que l'atelier ne suit pas en stock",
    ];

    private const TABLES = [
        'clients' => 'Fiche client',
        'commandes' => 'Commande',
        'paiements' => 'Encaissement',
        'sessions_caisse' => 'Session de caisse',
        'lots' => 'Lot',
        'messages' => 'Message',
        'receptions_lot' => "Réception d'un lot",
        'controles' => "Contrôle d'une commande",
        'taches' => 'Tâche',
        'travailleurs' => 'Travailleur',
        'consommables' => 'Achat',
        'versements_journaliers' => 'Versement journalier',
        'receptions_versement' => "Réception d'un versement",
        'articles_consommables' => 'Consommable suivi en stock',
        'mouvements_consommables' => 'Mouvement de stock',
        'avances_commission' => 'Avance sur commission',
        'remises' => 'Remise au client',
        'accords' => 'Accord du client sur une correction',
        'demandes_annulation' => "Demande d'annulation",
    ];

    private const ACTIONS = [
        Audit::CONNEXION => 'Connexion',
        Audit::SITE_CREE => 'Site créé',
        Audit::SITE_MODIFIE => 'Site modifié',
        Audit::POSTE_DECLARE => 'Poste déclaré',
        Audit::POSTE_NOUVEAU_CODE => 'Nouveau code de mise en service',
        Audit::POSTE_REVOQUE => 'Poste révoqué',
        Audit::COMPTE_CREE => 'Compte créé',
        Audit::COMPTE_MODIFIE => 'Compte modifié',
        Audit::COMPTE_MOT_DE_PASSE => 'Mot de passe réinitialisé',
        Audit::CATALOGUE_CREE => 'Catalogue : ajout',
        Audit::CATALOGUE_MODIFIE => 'Catalogue : modification',
        Audit::PARAMETRE_MODIFIE => 'Paramètre modifié',
        Audit::DOUBLON_TRANCHE => 'Fiche en double tranchée',
        Audit::ANNULATION_CONFIRMEE => 'Annulation confirmée',
        Audit::ANNULATION_REFUSEE => "Demande d'annulation refusée",
        Audit::CORRECTION_APPLIQUEE_D_OFFICE => "Correction appliquée d'office après le délai",
        Audit::RAPPORT_FINANCIER_EXPORTE => 'Rapport financier exporté (PDF)',
    ];

    /** Fuseau horaire du reseau : les dates sont gardees en UTC et montrees dans ce fuseau. */
    public static function fuseau(): string
    {
        return (string) config('pressing.fuseau');
    }

    /** "8 000 FCFA" ; l'espace insecable empeche de couper un montant en fin de ligne. */
    public static function fcfa(mixed $montant): string
    {
        return self::nombre($montant)."\u{00A0}FCFA";
    }

    public static function nombre(mixed $montant): string
    {
        return number_format((float) ($montant ?? 0), 0, ',', "\u{00A0}");
    }

    public static function date(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->copy()->setTimezone(self::fuseau())->format('d/m/Y');
    }

    public static function dateHeure(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->copy()->setTimezone(self::fuseau())->format('d/m/Y H:i');
    }

    /** "lundi 5 octobre 2026" : une date ecrite en clair, pour un titre ou un rapport. */
    public static function dateEnClair(?CarbonInterface $date): string
    {
        return $date === null ? '—' : $date->copy()->setTimezone(self::fuseau())->locale('fr')->isoFormat('dddd Do MMMM YYYY');
    }

    /** "Lundi 05/10" : une ligne d'un tableau par jour. */
    public static function jour(?CarbonInterface $date): string
    {
        return $date === null ? '—' : ucfirst($date->copy()->setTimezone(self::fuseau())->locale('fr')->isoFormat('dddd DD/MM'));
    }

    /** "il y a 3 heures" */
    public static function depuis(?CarbonInterface $date): string
    {
        return $date === null ? 'jamais' : $date->copy()->locale('fr')->diffForHumans();
    }

    public static function statut(?string $code): string
    {
        return self::STATUTS[$code] ?? (string) $code;
    }

    /** @return array<string, string> code => libelle, dans l'ordre du cycle (RG-05) */
    public static function statuts(): array
    {
        return self::STATUTS;
    }

    /** Classe de pastille : ou en est la commande, pas un jugement. */
    public static function classeStatut(?string $code): string
    {
        return match (true) {
            $code === Commande::ENREGISTREE => 'pastille-neutre',
            in_array($code, ['LIVREE'], true) => 'pastille-bon',
            in_array($code, ['EN_ATTENTE_ACCORD', 'NON_RECLAMEE'], true) => 'pastille-attention',
            in_array($code, ['ANNULEE', 'DON'], true) => 'pastille-terne',
            default => 'pastille-info',
        };
    }

    public static function typePaiement(?string $code): string
    {
        return match ($code) {
            Paiement::ACOMPTE => 'Avance',
            Paiement::SOLDE => 'Solde',
            Paiement::REMBOURSEMENT => 'Avance restituée',
            default => (string) $code,
        };
    }

    /** RG-14 : a quoi le serveur a affecte un encaissement. */
    public static function affectation(?string $code): string
    {
        return match ($code) {
            Paiement::RECETTE => 'Recette de la semaine',
            Paiement::RESERVE => 'Réserve',
            null => 'Pas encore décidée',
            default => $code,
        };
    }

    /** Sens d'un solde, dit en toutes lettres : ni le signe ni une couleur ne suffisent. */
    public static function sensSolde(mixed $montant): string
    {
        return match ((float) ($montant ?? 0) <=> 0.0) {
            1 => 'Excédent',
            -1 => 'Insuffisance',
            default => 'Équilibre',
        };
    }

    public static function role(?string $code): string
    {
        return match ($code) {
            Utilisateur::PROMOTEUR => 'Promoteur',
            Utilisateur::CHEF_ATELIER => "Chef d'atelier",
            Utilisateur::RECEPTIONNISTE => 'Réceptionniste',
            default => (string) $code,
        };
    }

    /** Role d'un travailleur de l'atelier (decision du 16 octobre 2026). */
    public static function roleTravailleur(?string $code): string
    {
        return match ($code) {
            Travailleur::LAVEUR => 'Laveur',
            Travailleur::REPASSEUR => 'Repasseur',
            Travailleur::POLYVALENT => 'Polyvalent',
            default => (string) $code,
        };
    }

    /** Etape d'une tache : un article est lave, puis repasse. */
    public static function etape(?string $code): string
    {
        return match ($code) {
            Tache::LAVAGE => 'Lavage',
            Tache::REPASSAGE => 'Repassage',
            default => (string) $code,
        };
    }

    /** Categorie d'un achat de l'atelier (decision du 23 octobre 2026). */
    public static function categorieAchat(?string $code): string
    {
        return Consommable::CATEGORIES[$code] ?? (string) $code;
    }

    public static function typeSite(?string $code): string
    {
        return $code === Site::ATELIER ? 'Atelier' : 'Boutique';
    }

    public static function etatMessage(?string $code): string
    {
        return match ($code) {
            Message::EN_ATTENTE => 'En attente',
            Message::ENVOYE => 'Envoyé',
            Message::ECHOUE => 'Échoué',
            default => (string) $code,
        };
    }

    public static function declencheur(?string $code): string
    {
        return match ($code) {
            'COMMANDE_ENREGISTREE' => 'Commande enregistrée',
            'CORRECTION_ACCEPTEE' => 'Correction acceptée',
            'CORRECTION_APRES_DELAI' => 'Correction appliquée après délai',
            'PENALITE' => 'Pénalité pour non-retrait',
            default => (string) $code,
        };
    }

    /**
     * Numero court d'un lot, le meme que sur le poste et sur la decharge du collecteur : la fin de
     * son identifiant, qui est tiree au hasard (le debut d'un identifiant ordonne dans le temps est
     * le meme pour des lots crees a quelques secondes d'ecart).
     */
    public static function referenceLot(string $lotId): string
    {
        return 'L-'.strtoupper(substr($lotId, -8));
    }

    /** RG-08, RG-09 : ce que le contrôle à l'atelier a constaté. */
    public static function resultatControle(?string $code): string
    {
        return match ($code) {
            Controle::CONFORME => 'Conforme',
            Controle::OBSERVATION => 'Observation, sans effet sur la facture',
            Controle::ECART => 'Écart de quantité',
            default => (string) $code,
        };
    }

    /** RG-09 : ce qu'une correction proposée est devenue. */
    public static function etatCorrection(?string $code): string
    {
        return match ($code) {
            Controle::PROPOSEE => "En attente de l'accord du client",
            Controle::ACCEPTEE => 'Acceptée par le client',
            Controle::APPLIQUEE_APRES_DELAI => "Appliquée d'office après le délai",
            default => (string) $code,
        };
    }

    /** RG-24 : où en est une demande d'annulation. */
    public static function etatDemande(?string $code): string
    {
        return match ($code) {
            DemandeAnnulation::DEMANDEE => 'En attente de votre décision',
            DemandeAnnulation::CONFIRMEE => 'Annulation confirmée',
            DemandeAnnulation::REFUSEE => 'Demande refusée',
            default => (string) $code,
        };
    }

    /** Classe de pastille d'une demande d'annulation : elle attend, ou elle est tranchée. */
    public static function classeEtatDemande(?string $code): string
    {
        return match ($code) {
            DemandeAnnulation::DEMANDEE => 'pastille-attention',
            DemandeAnnulation::CONFIRMEE => 'pastille-terne',
            default => 'pastille-info',
        };
    }

    public static function motif(?string $code): string
    {
        return self::MOTIFS[$code] ?? (string) $code;
    }

    public static function table(?string $code): string
    {
        return self::TABLES[$code] ?? (string) $code;
    }

    public static function action(?string $code): string
    {
        return self::ACTIONS[$code] ?? (string) $code;
    }

    /** @return array<string, string> */
    public static function actions(): array
    {
        return self::ACTIONS;
    }
}
