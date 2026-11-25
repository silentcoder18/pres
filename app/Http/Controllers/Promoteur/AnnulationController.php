<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Commande;
use App\Models\DemandeAnnulation;
use App\Models\SyncChangement;
use App\Support\Affichage;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Demandes d'annulation (RG-24). La receptionniste demande l'annulation sur son poste ; elle n'est
 * effective qu'une fois confirmee ici. La commande est alors Annulee, et l'avance deja versee est
 * a restituer au client par le site, sur sa caisse du jour.
 */
class AnnulationController extends Controller
{
    public function index(): View
    {
        return view('promoteur.annulations.index', [
            'aTrancher' => DemandeAnnulation::with(['commande.site', 'commande.client', 'demandeePar'])
                ->where('etat', DemandeAnnulation::DEMANDEE)
                ->orderBy('demandee_le')->orderBy('id')
                ->get(),
            'decidees' => DemandeAnnulation::with(['commande.site', 'commande.client', 'demandeePar', 'decideePar'])
                ->where('etat', '!=', DemandeAnnulation::DEMANDEE)
                ->orderByDesc('decidee_le')->orderByDesc('id')
                ->paginate(25),
        ]);
    }

    public function confirmer(DemandeAnnulation $demande): RedirectResponse
    {
        $commande = $this->commandeADecider($demande);
        if ($commande === null) {
            return $this->dejaDecidee();
        }
        if (! $commande->estAnnulable()) {
            // entree en traitement depuis la demande, ou sortie du suivi : rien n'est modifie
            return redirect()->route('annulations.index')->withErrors([
                'annulation' => "La commande {$commande->code} ne peut plus être annulée : elle est passée au statut « "
                    .Affichage::statut($commande->statut).' » depuis la demande. Refusez la demande.',
            ]);
        }

        $commande->statut = Commande::ANNULEE;
        // Une commande annulee n'a plus de reste a payer. Enregistree par le Promoteur, elle part en
        // priorite vers sa boutique (§4.6)
        $commande->recalculer();
        // L'atelier a qui elle a ete envoyee ne doit pas la traiter, meme si son lot est encore en route
        $atelier = $commande->atelierDuLotAller();
        if ($atelier !== null) {
            $commande->publierChangement(SyncChangement::UPSERT, $atelier);
        }
        // La demande part apres la commande : le poste ne la voit confirmee qu'une fois la commande annulee
        $demande->fill($this->decision(DemandeAnnulation::CONFIRMEE))->save();

        $aRestituer = bccomp((string) $commande->total_paye, '0', 2) > 0;
        Audit::noter(Audit::ANNULATION_CONFIRMEE, $commande, $commande->code,
            'Motif de la demande : '.$demande->motif.' ; '
            .($aRestituer ? 'avance à restituer au client : '.Affichage::fcfa($commande->total_paye) : 'aucune avance à restituer'));

        return redirect()->route('annulations.index')->with('succes', "La commande {$commande->code} est annulée. "
            .($aRestituer
                ? ($commande->site?->nom ?? 'Le site').' doit restituer au client son avance de '.Affichage::fcfa($commande->total_paye).'.'
                : "Aucune avance n'est à restituer."));
    }

    public function refuser(Request $request, DemandeAnnulation $demande): RedirectResponse
    {
        $motif = $request->validate(['motif_refus' => ['nullable', 'string', 'max:255']])['motif_refus'] ?? null;

        $commande = $this->commandeADecider($demande);
        if ($commande === null) {
            return $this->dejaDecidee();
        }

        // La commande ne change pas : seule la demande, refusee, repart vers la boutique
        $demande->fill($this->decision(DemandeAnnulation::REFUSEE) + ['motif_refus' => $motif])->save();

        Audit::noter(Audit::ANNULATION_REFUSEE, $commande, $commande->code,
            'Motif de la demande : '.$demande->motif.' ; '.($motif === null ? 'refusée sans motif' : 'motif du refus : '.$motif));

        return redirect()->route('annulations.index')
            ->with('succes', "La demande d'annulation de la commande {$commande->code} est refusée : la commande suit son cours.");
    }

    /**
     * La commande de la demande, verrouillee le temps de la decision : elle ne doit pas entrer en
     * traitement pendant qu'on l'annule. Null si la demande a deja ete tranchee.
     */
    private function commandeADecider(DemandeAnnulation $demande): ?Commande
    {
        $commande = Commande::lockForUpdate()->findOrFail($demande->commande_id);

        return $demande->refresh()->estEnAttente() ? $commande : null;
    }

    /** @return array<string, mixed> */
    private function decision(string $etat): array
    {
        return ['etat' => $etat, 'decidee_par' => auth()->id(), 'decidee_le' => now()];
    }

    private function dejaDecidee(): RedirectResponse
    {
        return redirect()->route('annulations.index')->with('succes', 'Cette demande a déjà reçu une décision.');
    }
}
