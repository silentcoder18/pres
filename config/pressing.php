<?php

return [

    // Fuseau horaire du reseau. Les dates sont gardees en UTC ; l'interface web les montre dans ce
    // fuseau, et « aujourd'hui » y commence a minuit.
    'fuseau' => env('PRESSING_FUSEAU', 'Africa/Douala'),

    'sync' => [
        // Nombre maximal de changements renvoyes par appel a /api/sync/tirer
        'taille_lot' => 500,

        // Nombre maximal d'operations acceptees par appel a /api/sync/pousser
        'operations_max' => 200,

        // Les changements plus recents que cette marge sont envoyes au poste, mais son
        // curseur n'avance pas au-dela : une ecriture encore en cours de validation au
        // moment de la lecture n'est ainsi jamais sautee. Le poste recoit donc parfois
        // deux fois le meme changement, ce qui est sans effet.
        'marge_secondes' => (int) env('PRESSING_SYNC_MARGE_SECONDES', 10),
    ],

    // Mise a jour du poste sans deplacement (amelioration du 23 octobre 2026) : le jar depose sur le
    // serveur et sa version. Sans version, ou sans fichier, aucune mise a jour n'est proposee.
    'poste' => [
        'version' => env('PRESSING_POSTE_VERSION'),
        // chemin sous storage/app
        'fichier' => env('PRESSING_POSTE_JAR', 'poste/GePressing-Poste.jar'),
    ],

    'enrolement' => [
        // Duree de validite d'un code de mise en service
        'validite_heures' => 48,
    ],

];
