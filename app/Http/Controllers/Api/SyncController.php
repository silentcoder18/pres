<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sync\ApplicateurOperations;
use App\Services\Sync\FluxChangements;
use App\Services\Sync\Perimetre;
use App\Support\Horloge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Synchronisation a l'initiative du poste : il tire les changements et pousse ses ecritures (§4.3). */
class SyncController extends Controller
{
    public function instantane(Request $request, FluxChangements $flux): JsonResponse
    {
        $donnees = $request->validate([
            // "numero de section:derniere cle recue", tel que renvoye par la page precedente
            'suite' => ['nullable', 'string', 'max:80', 'regex:/^\d{1,2}:[A-Za-z0-9_\-]*$/'],
        ]);

        return $this->repondre($flux->instantane(Perimetre::depuisRequete($request), $donnees['suite'] ?? null));
    }

    public function tirer(Request $request, FluxChangements $flux): JsonResponse
    {
        $donnees = $request->validate([
            'curseur' => ['required', 'integer', 'min:0'],
        ]);

        return $this->repondre($flux->tirer(Perimetre::depuisRequete($request), (int) $donnees['curseur']));
    }

    public function pousser(Request $request, ApplicateurOperations $applicateur): JsonResponse
    {
        $donnees = $request->validate([
            'operations' => ['required', 'array', 'max:'.config('pressing.sync.operations_max')],
            'operations.*.id' => ['required', 'uuid'],
            'operations.*.table' => ['required', 'string', 'max:60'],
            'operations.*.ligne_id' => ['required', 'string', 'max:64'],
            'operations.*.operation' => ['required', 'in:upsert'],
            'operations.*.donnees' => ['required', 'array'],
        ]);

        $perimetre = Perimetre::depuisRequete($request);

        // Dans l'ordre d'envoi : c'est l'ordre dans lequel le poste a fait ses ecritures
        $resultats = [];
        foreach ($donnees['operations'] as $operation) {
            $resultats[] = $applicateur->appliquer($perimetre, $operation);
        }

        return $this->repondre(['resultats' => $resultats]);
    }

    private function repondre(array $corps): JsonResponse
    {
        return response()->json($corps + ['heure_serveur' => Horloge::heureServeur()]);
    }
}
