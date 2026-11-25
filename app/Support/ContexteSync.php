<?php

namespace App\Support;

use App\Models\Poste;
use App\Models\SessionPoste;

/**
 * Poste, et session de la personne, a l'origine de la requete en cours, s'il y en a.
 *
 * Sert a marquer chaque changement publie : un changement fait depuis la base d'une personne ne
 * lui est pas renvoye (il l'est aux autres personnes du meme poste), et un changement fait hors
 * d'un poste (Promoteur) est transmis en priorite (§4.6).
 */
final class ContexteSync
{
    private static ?Poste $poste = null;

    private static ?SessionPoste $session = null;

    public static function definir(?Poste $poste, ?SessionPoste $session = null): void
    {
        self::$poste = $poste;
        self::$session = $poste === null ? null : $session;
    }

    public static function poste(): ?Poste
    {
        return self::$poste;
    }

    public static function session(): ?SessionPoste
    {
        return self::$session;
    }
}
