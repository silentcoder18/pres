<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Utilisateur;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/** Connexion du Promoteur a l'interface web. Les autres roles travaillent sur les postes. */
class ConnexionController extends Controller
{
    public function formulaire(): View
    {
        return view('promoteur.connexion');
    }

    public function connecter(Request $request): RedirectResponse
    {
        $saisie = $request->validate([
            'identifiant' => ['required', 'string', 'max:150'],
            'mot_de_passe' => ['required', 'string', 'max:200'],
        ]);

        // Le role et l'etat du compte font partie de la recherche : un compte de site ou un compte
        // desactive echoue exactement comme un mot de passe faux, sans rien reveler.
        $reconnu = Auth::attempt([
            'identifiant' => $saisie['identifiant'],
            'password' => $saisie['mot_de_passe'],
            'role' => Utilisateur::PROMOTEUR,
            'actif' => true,
        ]);

        if (! $reconnu) {
            return back()
                ->withErrors(['identifiant' => 'Identifiant ou mot de passe incorrect.'])
                ->onlyInput('identifiant');
        }

        $request->session()->regenerate();
        Audit::noter(Audit::CONNEXION);

        return redirect()->intended(route('tableau-de-bord'));
    }

    public function deconnecter(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('connexion');
    }
}
