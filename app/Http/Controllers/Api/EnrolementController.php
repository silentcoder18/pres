<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Poste;
use App\Support\Horloge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Mise en service d'un poste : echange du code a usage unique contre un jeton (§4.2). */
class EnrolementController extends Controller
{
    public function enroler(Request $request): JsonResponse
    {
        $donnees = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'nom_machine' => ['nullable', 'string', 'max:150'],
        ]);

        $poste = Poste::where('code_enrolement_hash', Poste::empreinte(Poste::normaliserCode($donnees['code'])))
            ->whereNull('jeton_hash')
            ->whereNull('revoque_le')
            ->where('code_expire_le', '>', now())
            ->first();

        if ($poste === null) {
            return response()->json([
                'erreur' => 'code_invalide',
                'message' => 'Ce code de mise en service est inconnu, déjà utilisé ou expiré.',
            ], 422);
        }

        $jeton = bin2hex(random_bytes(32));

        $poste->forceFill([
            'jeton_hash' => Poste::empreinte($jeton),
            'code_enrolement_hash' => null,
            'code_expire_le' => null,
            'nom_machine' => $donnees['nom_machine'] ?? null,
            'enrole_le' => now(),
            'dernier_contact_le' => now(),
        ])->save();

        return response()->json([
            'jeton' => $jeton,
            'poste' => ['id' => $poste->id, 'nom' => $poste->nom],
            'site' => $poste->site->donneesPoste(),
            'heure_serveur' => Horloge::heureServeur(),
        ]);
    }
}
