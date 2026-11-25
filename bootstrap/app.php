<?php

use App\Http\Middleware\ActionAtomique;
use App\Http\Middleware\AuthentifierPoste;
use App\Http\Middleware\BaseAJour;
use App\Http\Middleware\ExigerPromoteur;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'poste' => AuthentifierPoste::class,
            'promoteur' => ExigerPromoteur::class,
            'atomique' => ActionAtomique::class,
        ]);
        // Avant tout le reste, y compris la session : une base en retard d'une mise a niveau le dit
        $middleware->web(prepend: [BaseAJour::class]);
        $middleware->api(prepend: [BaseAJour::class]);
        // Interface web du Promoteur : sans session, on arrive sur la page de connexion
        $middleware->redirectGuestsTo(fn () => route('connexion'));
        $middleware->redirectUsersTo(fn () => route('tableau-de-bord'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
