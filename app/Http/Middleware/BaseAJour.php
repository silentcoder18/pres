<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Refuse de servir tant que la base n'a pas recu toutes ses mises a niveau.
 *
 * Sans cela, un serveur lance apres une mise a jour du code mais avant « migrate » repond par
 * une erreur au milieu d'une action (connexion faite mais journal d'audit introuvable, par
 * exemple). Mieux vaut le dire d'emblee : une page claire pour le Promoteur, et pour un poste
 * une reponse « indisponible » qu'il retentera a sa synchronisation suivante.
 */
class BaseAJour
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->aJour()) {
            $message = 'La base de données du serveur doit être mise à niveau avant de continuer.';

            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['erreur' => 'base_a_mettre_a_niveau', 'message' => $message], 503);
            }

            return response()->view('base-a-mettre-a-niveau', ['message' => $message], 503);
        }

        return $next($request);
    }

    /** Vrai quand chaque fichier de mise a niveau du dossier a bien ete applique a la base. */
    private function aJour(): bool
    {
        try {
            $migrateur = app('migrator');
            if (! $migrateur->repositoryExists()) {
                return false;
            }
            $prevues = array_keys($migrateur->getMigrationFiles(database_path('migrations')));

            return array_diff($prevues, $migrateur->getRepository()->getRan()) === [];
        } catch (Throwable) {
            // base injoignable ou illisible : le dire vaut mieux qu'une erreur plus loin
            return false;
        }
    }
}
