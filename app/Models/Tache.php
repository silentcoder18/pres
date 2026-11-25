<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Tache attribuee a un travailleur sur une ligne de commande (RG-20) ; elle fonde sa commission (RG-12). */
class Tache extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'taches';

    /** Etapes d'un article (decision du 16 octobre 2026) : il est lave, puis repasse. */
    public const LAVAGE = 'LAVAGE';

    public const REPASSAGE = 'REPASSAGE';

    public const ETAPES = [self::LAVAGE, self::REPASSAGE];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'commission_unitaire' => 'decimal:2',
            'attribuee_le' => 'datetime',
            'terminee_le' => 'datetime',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function ligne(): BelongsTo
    {
        return $this->belongsTo(LigneCommande::class, 'ligne_commande_id');
    }

    public function travailleur(): BelongsTo
    {
        return $this->belongsTo(Travailleur::class);
    }

    public function attribueePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'attribuee_par');
    }

    public function estTerminee(): bool
    {
        return $this->terminee_le !== null;
    }

    /** Les taches appartiennent a l'atelier (§4.3, principe 3). */
    public function sitePortee(): ?string
    {
        return $this->travailleur?->site_id;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'commande_id' => $this->commande_id,
            'ligne_commande_id' => $this->ligne_commande_id,
            'travailleur_id' => $this->travailleur_id,
            'etape' => $this->etape,
            'quantite' => (int) $this->quantite,
            'attribuee_par' => $this->attribuee_par,
            'attribuee_le' => $this->attribuee_le->toIso8601ZuluString(),
            'terminee_le' => $this->terminee_le?->toIso8601ZuluString(),
        ];
    }
}
