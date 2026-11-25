<?php

namespace App\Services\Atelier;

use App\Models\Commande;
use App\Models\Controle;
use App\Models\LigneCommande;
use App\Models\Message;
use App\Models\Parametre;
use App\Models\SyncChangement;
use App\Support\Affichage;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * RG-09 : devenir d'une correction proposee par le chef d'atelier. Acceptee par le client, la
 * receptionniste l'enregistre sur son poste. Restee sans accord au-dela du delai fixe par le
 * Promoteur, elle s'applique d'office et le client est averti. Dans les deux cas la facture est
 * recalculee ici et la commande est debloquee.
 */
class Corrections
{
    public const DECLENCHEUR_APRES_DELAI = 'CORRECTION_APRES_DELAI';

    /** Delai d'accord en jours, ou null tant que le Promoteur ne l'a pas saisi (RG-23). */
    public function delaiJours(): ?int
    {
        $valeur = trim((string) Parametre::find(Parametre::DELAI_ACCORD_ANOMALIE_JOURS)?->valeur);

        return ctype_digit($valeur) && (int) $valeur > 0 ? (int) $valeur : null;
    }

    /**
     * Applique les corrections dont le delai d'accord est ecoule.
     *
     * @return int nombre de commandes debloquees
     */
    public function appliquerEchues(): int
    {
        $jours = $this->delaiJours();
        if ($jours === null) {
            return 0;
        }

        // Une correction proposee sur une commande annulee depuis n'a plus lieu d'etre appliquee (RG-24).
        // Le delai court depuis l'arrivee de la correction ici, d'ou la boutique peut l'apprendre :
        // un atelier reste hors ligne, ou dont l'horloge retarde, ne le raccourcit pas
        $echues = Controle::correctionsEnAttente()
            ->where('created_at', '<=', now()->subDays($jours))
            ->orderBy('created_at')
            ->get();

        $appliquees = 0;
        foreach ($echues as $controle) {
            $appliquees += DB::transaction(function () use ($controle) {
                $commande = $this->appliquer($controle, Controle::APPLIQUEE_APRES_DELAI, now());
                if ($commande === null) {
                    return 0;
                }
                if ($commande->accord_messages) {
                    $this->avertirClient($commande, $controle);
                }
                // §11 : une correction de facture est un acte sensible, meme decide par le serveur
                Audit::noter(Audit::CORRECTION_APPLIQUEE_D_OFFICE, $commande, $commande->code,
                    'Facture de '.Affichage::fcfa($controle->montant_avant).' à '.Affichage::fcfa($controle->montant_apres)
                    .", sans accord du client au bout de {$this->delaiJours()} jour(s)");

                return 1;
            });
        }

        return $appliquees;
    }

    /**
     * RG-09 : le client a donne son accord, que la receptionniste a enregistre sur son poste. Le
     * message « Correction acceptee » est cree par le poste (§8) : aucun ne part d'ici.
     *
     * @return bool false si la commande n'attendait plus d'accord : rien n'a ete fait
     */
    public function accepter(Controle $controle, string $acceptePar, CarbonInterface $accepteLe): bool
    {
        return $this->appliquer($controle, Controle::ACCEPTEE, $accepteLe, $acceptePar) !== null;
    }

    /**
     * Met la ligne a la quantite constatee, recalcule la facture et debloque la commande.
     *
     * @return ?Commande la commande corrigee, ou null si elle n'attendait plus d'accord
     */
    private function appliquer(Controle $controle, string $etat, CarbonInterface $decideLe, ?string $decidePar = null): ?Commande
    {
        // Verrouillee : un accord et l'application apres delai ne se font pas tous les deux
        $commande = Commande::lockForUpdate()->find($controle->commande_id);
        if ($commande === null || $commande->statut !== Commande::EN_ATTENTE_ACCORD) {
            return null;
        }
        $ancienMontant = (string) $commande->montant;

        $ligne = LigneCommande::find($controle->ligne_commande_id);
        if ($ligne !== null) {
            if ($controle->quantite_apres <= 0) {
                $ligne->delete();
            } else {
                $ligne->update(['quantite' => $controle->quantite_apres]);
            }
        }
        // RG-04 : la facture suit la correction
        $commande->recalculer(silencieux: true);

        $controle->fill([
            'etat' => $etat,
            'decide_le' => $decideLe,
            'decide_par' => $decidePar,
            'montant_avant' => $ancienMontant,
            'montant_apres' => $commande->montant,
        ]);
        $controle->saveQuietly();

        $commande->statut = Commande::CONTROLEE;
        $commande->saveQuietly();

        // La boutique, y compris le poste qui vient d'envoyer l'accord : il recoit la facture
        // calculee ici. L'atelier, qui attend pour traiter, est servi par une publication a part
        $atelier = $commande->atelierId();
        foreach ([$controle, $commande] as $modele) {
            $modele->publierChangement(SyncChangement::UPSERT, ycomprisOrigine: true);
            if ($atelier !== null) {
                $modele->publierChangement(SyncChangement::UPSERT, $atelier);
            }
        }

        return $commande;
    }

    /** §8 : avertissement, ancien montant, nouveau montant, motif. */
    private function avertirClient(Commande $commande, Controle $controle): void
    {
        $designation = trim(($controle->tarif?->article?->nom ?? 'Article').' ('.($controle->tarif?->service?->nom ?? '').')');
        $contenu = $commande->site->nom.' — commande '.$commande->code."\n"
            .'Bonjour '.$commande->client->nom.', sans réponse de votre part, votre facture a été corrigée après contrôle à l\'atelier.'."\n"
            .$designation.' : '.$controle->quantite_avant.' enregistré(s), '.$controle->quantite_apres.' constaté(s).'."\n"
            .'Ancien montant : '.Affichage::fcfa($controle->montant_avant)."\n"
            .'Nouveau montant : '.Affichage::fcfa($commande->montant)
            .($controle->remarque ? "\n".'Motif : '.$controle->remarque : '');

        Message::create([
            'site_id' => $commande->site_id,
            'client_id' => $commande->client_id,
            'commande_id' => $commande->id,
            'declencheur' => self::DECLENCHEUR_APRES_DELAI,
            'contenu' => $contenu,
            'cree_le' => now(),
        ]);
    }
}
