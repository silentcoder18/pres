<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Une ligne du journal d'audit : qui a fait quoi, sur quoi, quand (§10). Jamais modifiee ni supprimee. */
class JournalAudit extends Model
{
    protected $table = 'journal_audit';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cree_le' => 'datetime',
            'montant' => 'decimal:2',
        ];
    }
}
