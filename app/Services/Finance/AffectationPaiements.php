<?php

namespace App\Services\Finance;

use App\Models\Paiement;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Affectation d'un encaissement, decidee par le serveur et jamais par le poste (RG-14, §7) :
 * a la caisse, la receptionniste encaisse sans savoir a quoi l'argent servira, et les comptes
 * de fin de journee de la boutique n'en dependent pas.
 */
class AffectationPaiements
{
    /**
     * RG-14 : la semaine comptable d'un paiement est celle de son encaissement. Il est tardif
     * quand sa commande a ete deposee une semaine anterieure.
     */
    public static function estTardif(CarbonInterface $encaisseLe, CarbonInterface $commandeDeposeeLe): bool
    {
        return SemaineComptable::contenant($commandeDeposeeLe)->estAvant(SemaineComptable::contenant($encaisseLe));
    }

    /**
     * Affectation d'un paiement d'apres sa nature, sa date d'encaissement et la date de depot de
     * sa commande.
     */
    public static function decider(string $type, CarbonInterface $encaisseLe, CarbonInterface $commandeDeposeeLe): ?string
    {
        // RG-24 : une avance restituee n'a pas d'affectation. Dans les comptes hebdomadaires, ce
        // sont les versements qu'elle rend qui sont repris, chacun sur sa semaine : voir
        // RapportHebdomadaire
        if (! in_array($type, [Paiement::ACOMPTE, Paiement::SOLDE], true)) {
            return null;
        }

        if (! self::estTardif($encaisseLe, $commandeDeposeeLe)) {
            return Paiement::RECETTE;
        }

        // RG-14 : un recouvrement tardif rembourse d'abord l'emprunt de la semaine et du site
        // d'origine de la commande, et seul le surplus alimente la reserve. Les emprunts
        // n'existent pas encore sur le serveur (etape 5) : pour l'instant tout recouvrement
        // tardif va a la reserve.
        return Paiement::RESERVE;
    }

    /**
     * Remplit les affectations manquantes : paiements recus avant que le serveur ne les decide.
     * Relancable sans effet, et sans rien publier vers les postes, qui ne recoivent pas l'affectation.
     *
     * @return array<string, int> nombre de paiements affectes, par affectation
     */
    public function rattraper(): array
    {
        $affectes = [Paiement::RECETTE => 0, Paiement::RESERVE => 0];

        Paiement::query()
            ->whereNull('affectation')
            ->whereIn('type', [Paiement::ACOMPTE, Paiement::SOLDE])
            ->with('commande')
            ->chunkById(500, function (Collection $paiements) use (&$affectes) {
                foreach ($paiements as $paiement) {
                    if ($paiement->commande === null) {
                        continue;
                    }
                    $affectation = self::decider($paiement->type, $paiement->encaisse_le, $paiement->commande->deposee_le);
                    // Mise a jour directe, sans evenement du modele : rien n'est republie
                    Paiement::whereKey($paiement->id)->whereNull('affectation')->update(['affectation' => $affectation]);
                    $affectes[$affectation]++;
                }
            });

        return $affectes;
    }
}
