<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Trace d'une operation recue d'un poste ; son identifiant empeche de l'appliquer deux fois. */
class SyncOperation extends Model
{
    public const APPLIQUEE = 'appliquee';

    public const DEJA_APPLIQUEE = 'deja_appliquee';

    public const REJETEE = 'rejetee';

    protected $table = 'sync_operations';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    public function poste(): BelongsTo
    {
        return $this->belongsTo(Poste::class);
    }

    /** Personne dont la base a envoye l'ecriture, et le site pour lequel elle travaillait. */
    public function sessionPoste(): BelongsTo
    {
        return $this->belongsTo(SessionPoste::class);
    }

    protected function casts(): array
    {
        return ['recu_le' => 'datetime'];
    }
}
