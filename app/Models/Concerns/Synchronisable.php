<?php

namespace App\Models\Concerns;

use App\Models\SyncChangement;
use App\Support\ContexteSync;

/**
 * Publie dans le fil des changements toute creation, modification ou
 * suppression du modele, pour que les postes viennent la chercher.
 */
trait Synchronisable
{
    public static function bootSynchronisable(): void
    {
        static::saved(function ($modele) {
            if ($modele->wasRecentlyCreated || $modele->wasChanged()) {
                $modele->publierChangement(SyncChangement::UPSERT);
            }
        });

        static::deleted(function ($modele) {
            $modele->publierChangement(SyncChangement::SUPPRESSION);
        });
    }

    /** Donnees envoyees aux postes ; les cles sont les colonnes de la base locale. */
    abstract public function donneesPoste(): array;

    /** Nom de la table sur le poste. */
    public function tablePoste(): string
    {
        return $this->getTable();
    }

    /** Site destinataire, ou null pour tous les sites (§4.3, principe 7). */
    public function sitePortee(): ?string
    {
        return null;
    }

    /** false : la ligne ne quitte jamais le serveur. */
    public function estPubliable(): bool
    {
        return true;
    }

    /**
     * @param  bool  $ycomprisOrigine  true quand le serveur a decide autre chose que ce que le poste
     *                                 a envoye : le poste a l'origine de l'echange doit alors le recevoir aussi
     */
    public function publierChangement(string $operation, ?string $siteId = null, bool $ycomprisOrigine = false): void
    {
        if (! $this->estPubliable()) {
            return;
        }

        $poste = ContexteSync::poste();
        $session = ContexteSync::session();

        SyncChangement::create([
            'table_cible' => $this->tablePoste(),
            'ligne_id' => (string) $this->getKey(),
            'operation' => $operation,
            'donnees' => $operation === SyncChangement::UPSERT ? $this->donneesPoste() : null,
            'site_id' => $siteId ?? $this->sitePortee(),
            'urgent' => $poste === null,
            'origine_poste_id' => $ycomprisOrigine ? null : $poste?->id,
            'origine_session_id' => $ycomprisOrigine ? null : $session?->id,
        ]);
    }
}
