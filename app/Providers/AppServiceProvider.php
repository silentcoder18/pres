<?php

namespace App\Providers;

use App\Models\ClientReuni;
use App\Models\Controle;
use App\Models\DemandeAnnulation;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Hebergement en ligne : si l'adresse du serveur (APP_URL) est en https, liens et redirections
        // le sont aussi, meme quand l'hebergeur termine le https devant PHP
        URL::forceHttps(str_starts_with((string) config('app.url'), 'https://'));

        // Menu de l'interface du Promoteur : ce qui attend une decision ou un regard y reste visible.
        // Fiches client a trancher, demandes d'annulation a trancher, commandes en attente d'accord
        View::composer('layouts.promoteur', function ($vue) {
            $vue->with('menuDoublons', ClientReuni::where('a_trancher', true)->count());
            $vue->with('menuAnnulations', DemandeAnnulation::where('etat', DemandeAnnulation::DEMANDEE)->count());
            $vue->with('menuAccords', Controle::correctionsEnAttente()->count());
        });
    }
}
