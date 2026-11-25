<?php

namespace App\Console\Commands;

use App\Services\Atelier\Corrections;
use Illuminate\Console\Command;

/**
 * Applique d'office les corrections restees sans accord du client au-dela du delai fixe par le
 * Promoteur (RG-09). Lancee toutes les heures par les taches planifiees ; peut etre relancee a
 * la main : une correction deja appliquee n'est pas retouchee.
 */
class CorrectionsAppliquer extends Command
{
    protected $signature = 'pressing:corrections-appliquer';

    protected $description = 'Applique les corrections de l\'atelier restées sans accord au-delà du délai (RG-09)';

    public function handle(Corrections $corrections): int
    {
        if ($corrections->delaiJours() === null) {
            $this->info('Aucun délai d\'accord n\'est fixé : les commandes restent en attente de l\'accord du client.');

            return self::SUCCESS;
        }

        $appliquees = $corrections->appliquerEchues();
        $this->info($appliquees === 0
            ? 'Aucune correction n\'a dépassé le délai d\'accord.'
            : sprintf('%d %s.', $appliquees, $appliquees > 1 ? 'commandes débloquées' : 'commande débloquée'));

        return self::SUCCESS;
    }
}
