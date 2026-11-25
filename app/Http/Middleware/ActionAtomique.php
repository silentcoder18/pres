<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Une action du Promoteur est enregistree entierement ou pas du tout (§11, Integrite) : la
 * modification, sa publication vers les postes et sa ligne au journal d'audit vont ensemble.
 * Si l'une echoue, rien n'est garde.
 *
 * Une saisie qui n'est pas du texte lisible (UTF-8) est refusee avant toute ecriture : elle ne
 * pourrait pas etre transmise aux postes.
 */
class ActionAtomique
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        if (! $this->lisible($request->all())) {
            return back()->withErrors(['saisie' => 'La saisie contient des caractères illisibles : saisissez-la de nouveau.']);
        }

        DB::beginTransaction();
        try {
            $reponse = $next($request);
        } catch (Throwable $erreur) {
            DB::rollBack();
            throw $erreur;
        }

        // Une erreur survenue dans l'action arrive ici deja changee en reponse, avec sa cause
        if (($reponse->exception ?? null) !== null || $reponse->getStatusCode() >= 500) {
            DB::rollBack();
        } else {
            DB::commit();
        }

        return $reponse;
    }

    private function lisible(mixed $valeur): bool
    {
        if (is_array($valeur)) {
            foreach ($valeur as $cle => $element) {
                if (! $this->lisible($cle) || ! $this->lisible($element)) {
                    return false;
                }
            }

            return true;
        }

        return ! is_string($valeur) || mb_check_encoding($valeur, 'UTF-8');
    }
}
