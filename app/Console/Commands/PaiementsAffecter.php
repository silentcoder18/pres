<?php

namespace App\Console\Commands;

use App\Models\Paiement;
use App\Services\Finance\AffectationPaiements;
use Illuminate\Console\Command;

/**
 * Remplit l'affectation des paiements qui n'en ont pas (RG-14) : ceux que les postes ont envoyes
 * avant que le serveur ne la decide a la reception. Peut etre relancee : un paiement deja affecte
 * n'est pas retouche, et rien n'est renvoye aux postes.
 */
class PaiementsAffecter extends Command
{
    protected $signature = 'pressing:paiements-affecter';

    protected $description = 'Affecte les encaissements sans affectation : recette de leur semaine ou réserve (RG-14)';

    public function handle(AffectationPaiements $affectations): int
    {
        $affectes = $affectations->rattraper();
        $nombre = array_sum($affectes);

        if ($nombre === 0) {
            $this->info('Aucun encaissement à affecter.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d %s : %d à la recette de leur semaine, %d à la réserve (recouvrements tardifs).',
            $nombre,
            $nombre > 1 ? 'encaissements affectés' : 'encaissement affecté',
            $affectes[Paiement::RECETTE],
            $affectes[Paiement::RESERVE],
        ));

        return self::SUCCESS;
    }
}
