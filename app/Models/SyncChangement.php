<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Une entree du fil des changements que les postes viennent chercher. */
class SyncChangement extends Model
{
    public const UPSERT = 'upsert';

    public const SUPPRESSION = 'suppression';

    protected $table = 'sync_changements';

    protected $primaryKey = 'seq';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'donnees' => 'array',
            'urgent' => 'boolean',
            'cree_le' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SyncChangement $changement) {
            $changement->cree_le ??= now();
        });
    }

    /** Forme envoyee au poste. */
    public function versPoste(): array
    {
        return [
            'seq' => $this->seq,
            'table' => $this->table_cible,
            'ligne_id' => $this->ligne_id,
            'operation' => $this->operation,
            'donnees' => $this->donnees,
            'urgent' => $this->urgent,
        ];
    }
}
