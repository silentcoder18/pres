<?php

namespace Database\Seeders;

use App\Models\Parametre;
use Illuminate\Database\Seeder;

/** Valeurs de depart des parametres (RG-23). Ne remplace jamais une valeur deja fixee par le Promoteur. */
class ParametresSeeder extends Seeder
{
    public function run(): void
    {
        $parametres = [
            [Parametre::DELAI_RETRAIT_SEMAINES, '4', 'Délai de retrait avant pénalité (semaines)'],
            [Parametre::MONTANT_PENALITE, '1000', 'Montant de la pénalité (FCFA)'],
            [Parametre::DELAI_ALERTE_LOT_HEURES, '24', "Délai d'alerte pour un lot sans accusé de réception (heures)"],
            [Parametre::DUREE_MAX_HORS_LIGNE_HEURES, '48', "Durée sans contact avec le serveur avant qu'un poste n'avertisse (heures)"],
            [Parametre::INTERVALLE_SYNCHRO_HEURES, '4', 'Intervalle de synchronisation automatique (heures)'],
            [Parametre::DELAI_ACCORD_ANOMALIE_JOURS, null, "Délai d'accord sur anomalie (jours)"],
            [Parametre::DELAI_TRAITEMENT_JOURS, null, 'Délai de traitement annoncé au client (jours)'],
        ];

        foreach ($parametres as [$cle, $valeur, $libelle]) {
            Parametre::firstOrCreate(['cle' => $cle], ['valeur' => $valeur, 'libelle' => $libelle]);
        }
    }
}
