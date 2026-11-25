<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Avance sur commission (decision du Promoteur, 23 octobre 2026) : le chef d'atelier remet a un
 * travailleur une partie de la commission qu'il a deja gagnee dans la semaine. Elle se deduit de ce
 * qui lui reste a payer pour cette semaine (RG-12) ; ce n'est pas une charge de plus. Elle est payee
 * avec les versements des boutiques recus a l'atelier.
 */
class AvanceCommission extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'avances_commission';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'versee_le' => 'datetime',
        ];
    }

    public function travailleur(): BelongsTo
    {
        return $this->belongsTo(Travailleur::class);
    }

    public function verseePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'versee_par');
    }

    public function sitePortee(): ?string
    {
        return $this->site_id;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'travailleur_id' => $this->travailleur_id,
            'montant' => (string) $this->montant,
            'versee_par' => $this->versee_par,
            'versee_le' => $this->versee_le->toIso8601ZuluString(),
            'motif' => $this->motif,
        ];
    }
}
