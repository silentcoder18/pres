<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SessionPoste;
use App\Models\Utilisateur;
use App\Support\Horloge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Premiere connexion d'une personne sur un poste : le serveur verifie l'identifiant avant que le
 * poste ne l'enregistre localement (§4.2, RG-15), et ouvre la session sous laquelle le poste
 * synchronisera la base de cette personne.
 */
class AuthPosteController extends Controller
{
    public function verifier(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'identifiant' => ['required', 'string', 'max:150'],
            'mot_de_passe' => ['required', 'string', 'max:200'],
        ]);

        $poste = $request->attributes->get('poste');
        $utilisateur = Utilisateur::where('identifiant', $donnees['identifiant'])->first();

        // Meme temps de calcul que le compte existe ou non
        $empreinte = $utilisateur?->mot_de_passe ?? Hash::make(Str::random(16));
        $motDePasseJuste = Hash::check($donnees['mot_de_passe'], $empreinte);

        if ($utilisateur === null || ! $motDePasseJuste) {
            return response()->json([
                'erreur' => 'identifiants_invalides',
                'message' => 'Identifiant ou mot de passe incorrect.',
            ], 401);
        }

        // Decisions du Promoteur (9 et 16 octobre 2026) : un poste connecte n'importe quel compte d'un
        // site, quel que soit le site ou il se trouve. Le mot de passe etant juste, on peut dire
        // pourquoi un compte est refuse.
        if (! $utilisateur->actif) {
            return $this->refuser('Ce compte est désactivé. Adressez-vous au Promoteur.');
        }
        if ($utilisateur->role === Utilisateur::PROMOTEUR) {
            return $this->refuser('Les fonctions du Promoteur sont sur l\'interface web : ce compte ne se connecte pas sur un poste.');
        }
        if ($utilisateur->site_id === null) {
            return $this->refuser('Ce compte n\'est rattaché à aucun site. Le Promoteur doit lui en donner un.');
        }

        // La personne aura sa propre base sur ce poste, synchronisee sous cette session, sur les
        // donnees de son site de rattachement
        [$session, $jeton] = SessionPoste::ouvrir($request->attributes->get('poste'), $utilisateur);

        return response()->json([
            'utilisateur' => $utilisateur->donneesPoste(),
            'session' => [
                'jeton' => $jeton,
                'site_id' => $session->site_id,
            ],
            'heure_serveur' => Horloge::heureServeur(),
        ]);
    }

    private function refuser(string $message): JsonResponse
    {
        return response()->json(['erreur' => 'acces_refuse', 'message' => $message], 403);
    }
}
