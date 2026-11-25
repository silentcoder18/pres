<?php

// Messages de validation de l'interface du Promoteur : seules les regles employees y figurent.

return [

    'required' => 'Le champ :attribute est obligatoire.',
    'string' => 'Le champ :attribute doit être un texte.',
    'integer' => 'Le champ :attribute doit être un nombre entier.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'boolean' => 'Le champ :attribute doit valoir oui ou non.',
    'uuid' => 'Le champ :attribute n\'est pas valide.',
    'in' => 'La valeur choisie pour :attribute n\'est pas admise.',
    'exists' => 'La valeur choisie pour :attribute n\'existe plus.',
    'unique' => 'Cette valeur est déjà utilisée pour :attribute.',
    'regex' => 'Le format de :attribute n\'est pas valide.',
    'confirmed' => 'Les deux saisies de :attribute ne sont pas identiques.',
    'date_format' => 'Le champ :attribute n\'est pas une date valide.',
    'after_or_equal' => 'La date :attribute doit être égale ou postérieure à la date :date.',
    'semaine_iso' => 'La semaine demandée n\'existe pas. Elle s\'écrit avec l\'année et le numéro de semaine, par exemple 2026-W41.',
    'lte' => [
        'numeric' => 'Le champ :attribute ne doit pas dépasser :value.',
    ],
    'min' => [
        'numeric' => 'Le champ :attribute doit valoir au moins :min.',
        'string' => 'Le champ :attribute doit comporter au moins :min caractères.',
    ],
    'max' => [
        'numeric' => 'Le champ :attribute ne doit pas dépasser :max.',
        'string' => 'Le champ :attribute ne doit pas dépasser :max caractères.',
    ],

    'attributes' => [
        'identifiant' => 'identifiant',
        'mot_de_passe' => 'mot de passe',
        'nom' => 'nom',
        'code' => 'code',
        'adresse' => 'adresse',
        'type' => 'type',
        'actif' => 'état',
        'role' => 'rôle',
        'site_id' => 'site',
        'site' => 'site',
        'telephone' => 'téléphone',
        'description' => 'description',
        'article_id' => 'article',
        'service_id' => 'prestation',
        'prix_vente' => 'prix de vente',
        'commission_fixe' => 'commission',
        'recherche' => 'recherche',
        'statut' => 'statut',
        'etat' => 'état',
        'du' => 'du',
        'au' => 'au',
        'semaine' => 'semaine',
    ],

];
