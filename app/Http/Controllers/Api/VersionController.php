<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Horloge;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Version du poste publiee par le serveur, pour la mise a jour sans deplacement (amelioration du
 * 23 octobre 2026).
 *
 * Le Promoteur depose le jar du poste dans storage/app (config pressing.poste.fichier) et renseigne
 * sa version (PRESSING_POSTE_VERSION). Le poste compare, telecharge, verifie l'empreinte SHA-256
 * et n'installe le nouveau jar qu'a son prochain demarrage. Sans version ou sans fichier, rien
 * n'est propose : le serveur donne seulement son heure.
 */
class VersionController extends Controller
{
    public function version(): JsonResponse
    {
        $corps = [
            'version' => null,
            'jar_url' => null,
            'sha256' => null,
            'taille' => null,
            'date_serveur' => Horloge::heureServeur(),
            'serveur_timestamp' => now()->timestamp,
        ];

        $version = trim((string) config('pressing.poste.version'));
        $fichier = $this->fichier();
        if ($version !== '' && $fichier !== null) {
            $corps['version'] = $version;
            $corps['jar_url'] = route('api.poste.telecharger');
            $corps['sha256'] = $this->empreinte($fichier);
            $corps['taille'] = filesize($fichier);
        }

        return response()->json($corps);
    }

    public function telecharger(): BinaryFileResponse
    {
        $fichier = $this->fichier();
        abort_if($fichier === null, 404);

        return response()->download($fichier, 'GePressing-Poste.jar', ['Content-Type' => 'application/java-archive']);
    }

    private function fichier(): ?string
    {
        $chemin = storage_path('app/'.ltrim((string) config('pressing.poste.fichier'), '/'));

        return is_file($chemin) ? $chemin : null;
    }

    /** Calculee une fois par fichier depose : un jar fait une quarantaine de Mo. */
    private function empreinte(string $fichier): string
    {
        $cle = 'poste-jar-sha256:'.filemtime($fichier).':'.filesize($fichier);

        return Cache::rememberForever($cle, fn () => hash_file('sha256', $fichier));
    }
}
