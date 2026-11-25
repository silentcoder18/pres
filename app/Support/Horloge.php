<?php

namespace App\Support;

final class Horloge
{
    /** Heure du serveur en UTC, au format attendu par les postes (ex. 2026-10-06T08:30:00Z). */
    public static function heureServeur(): string
    {
        return now()->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
