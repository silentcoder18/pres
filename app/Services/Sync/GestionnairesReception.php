<?php

namespace App\Services\Sync;

use App\Models\Controle;
use App\Models\DemandeAnnulation;
use App\Services\Atelier\Corrections;
use Illuminate\Support\Carbon;

/**
 * Etape 3 : ce que la receptionniste envoie sur les commandes parties a l'atelier. L'accord du
 * client sur une correction (RG-09) et la demande d'annulation (RG-24). La restitution de l'avance
 * d'une commande annulee est un paiement : voir ApplicateurOperations::appliquerPaiement.
 */
trait GestionnairesReception
{
    /**
     * RG-09 : le client accepte la correction proposee par l'atelier, et la boutique qui a
     * enregistre la commande le note. La facture est recalculee ici, la commande est debloquee, et
     * les deux postes recoivent le resultat, y compris celui qui vient d'envoyer l'accord.
     */
    private function appliquerAccord(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'accepte_par' => ['required', 'uuid'],
            'accepte_le' => ['required', 'date'],
        ]);

        $controle = Controle::find($donnees['id']);
        if ($controle === null) {
            throw new OperationRejetee('controle_inconnu');
        }
        $this->commandeDuSite($perimetre, $controle->commande_id);
        $this->exigerUtilisateurConnu($donnees['accepte_par']);
        if ($controle->etat === null) {
            // controle conforme ou simple observation : il n'a jamais propose de correction
            throw new OperationRejetee('donnees_invalides');
        }
        if (! $controle->enAttenteDAccord()) {
            return; // deja acceptee, ou deja appliquee apres le delai : rien a refaire
        }

        // Sans effet si la commande n'attend plus d'accord : annulee entre-temps (RG-24)
        app(Corrections::class)->accepter($controle, $donnees['accepte_par'], Carbon::parse($donnees['accepte_le']));
    }

    /**
     * RG-24 : la receptionniste demande l'annulation d'une commande de son site, tant qu'elle
     * n'est pas entree en traitement. La commande ne change pas : le Promoteur tranche sur le web.
     */
    private function appliquerDemandeAnnulation(Perimetre $perimetre, array $operation): void
    {
        $donnees = $this->valider($operation, [
            'id' => ['required', 'uuid'],
            'commande_id' => ['required', 'uuid'],
            'demandee_par' => ['required', 'uuid'],
            'motif' => ['required', 'string', 'max:500'],
            'demandee_le' => ['required', 'date'],
        ]);

        $commande = $this->commandeDuSite($perimetre, $donnees['commande_id']);
        $dejaRecue = DemandeAnnulation::find($donnees['id']);
        if ($dejaRecue !== null) {
            if ($dejaRecue->commande_id !== $commande->id) {
                throw new OperationRejetee('donnees_invalides');
            }

            return; // deja enregistree : rien a refaire, meme si elle a ete tranchee depuis
        }
        $this->exigerUtilisateurConnu($donnees['demandee_par']);
        if (! $commande->estAnnulable()) {
            throw new OperationRejetee('commande_non_annulable');
        }
        $enCours = DemandeAnnulation::where('commande_id', $commande->id)
            ->where('etat', DemandeAnnulation::DEMANDEE)
            ->exists();
        if ($enCours) {
            throw new OperationRejetee('demande_deja_en_cours');
        }

        DemandeAnnulation::create([
            'id' => $donnees['id'],
            'commande_id' => $commande->id,
            'demandee_par' => $donnees['demandee_par'],
            'motif' => $donnees['motif'],
            'demandee_le' => $donnees['demandee_le'],
            'etat' => DemandeAnnulation::DEMANDEE,
        ]);
    }
}
