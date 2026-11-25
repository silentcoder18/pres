<?php

use Illuminate\Support\Facades\Schedule;

// Taches planifiees (§11, Hebergement) : l'hebergeur lance « php artisan schedule:run » chaque minute.

// RG-09 : une correction restee sans accord au-dela du delai s'applique d'office
Schedule::command('pressing:corrections-appliquer')->hourly();
