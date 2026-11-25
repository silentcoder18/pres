<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Lot de commandes regroupees pour un transport (RG-19). Sa liste de commandes voyage avec lui. */
class Lot extends Model
{
    use HasUuids, Synchronisable;

    public const ALLER = 'ALLER';

    public const RETOUR = 'RETOUR';

    protected $table = 'lots';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'cloture_le' => 'datetime',
            'recu_le' => 'datetime',
            'nombre_annonce' => 'integer',
            'nombre_recu' => 'integer',
        ];
    }

    public function commandes(): BelongsToMany
    {
        return $this->belongsToMany(Commande::class, 'lot_commandes');
    }

    public function siteOrigine(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_origine_id');
    }

    public function siteDestination(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_destination_id');
    }

    public function cloturePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cloture_par');
    }

    public function recuPar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'recu_par');
    }

    /** RG-19 : nombre recu different du nombre annonce. */
    public function aUnEcart(): bool
    {
        return $this->recu_le !== null && $this->nombre_recu !== $this->nombre_annonce;
    }

    /** Le site qui a cloture le lot ; le site destinataire est servi par une publication a part. */
    public function sitePortee(): ?string
    {
        return $this->site_origine_id;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'sens' => $this->sens,
            'site_origine_id' => $this->site_origine_id,
            'site_destination_id' => $this->site_destination_id,
            'cloture_par' => $this->cloture_par,
            'cloture_le' => $this->cloture_le->toIso8601ZuluString(),
            'nombre_annonce' => (int) $this->nombre_annonce,
            'recu_par' => $this->recu_par,
            'recu_le' => $this->recu_le?->toIso8601ZuluString(),
            'nombre_recu' => $this->nombre_recu === null ? null : (int) $this->nombre_recu,
            'commande_ids' => $this->commandes()->orderBy('commandes.code')->pluck('commandes.id')->all(),
        ];
    }
}
