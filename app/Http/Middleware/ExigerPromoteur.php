<?php

namespace App\Http\Middleware;

use App\Models\Utilisateur;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * L'interface web est reservee au Promoteur (§2, §4.6). Le compte est reverifie a chaque page :
 * un compte desactive ou qui n'est plus Promoteur perd l'acces sans attendre la fin de sa session.
 */
class ExigerPromoteur
{
    public function handle(Request $request, Closure $next): Response
    {
        $utilisateur = $request->user();

        if (! $utilisateur instanceof Utilisateur || $utilisateur->role !== Utilisateur::PROMOTEUR || ! $utilisateur->actif) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('connexion')
                ->withErrors(['identifiant' => 'Cet espace est réservé au Promoteur.']);
        }

        return $next($request);
    }
}
