<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Demande d'annulation d'une commande (RG-24). La receptionniste la fait sur son poste ; elle
 * n'a d'effet qu'une fois confirmee par le Promoteur, sur l'interface web.
 */
class DemandeAnnulation extends Model
{
    use HasUuids, Synchronisable;

    public const DEMANDEE = 'DEMANDEE';

    public const CONFIRMEE = 'CONFIRMEE';

    public const REFUSEE = 'REFUSEE';

    protected $table = 'demandes_annulation';

    protected $guarded = [];

    // Valeur par defaut connue du modele des sa creation, avant toute relecture en base
    protected $attributes = ['etat' => self::DEMANDEE];

    protected function casts(): array
    {
        return [
            'demandee_le' => 'datetime',
            'decidee_le' => 'datetime',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function demandeePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'demandee_par');
    }

    public function decideePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'decidee_par');
    }

    /** Le Promoteur ne l'a pas encore tranchee. */
    public function estEnAttente(): bool
    {
        return $this->etat === self::DEMANDEE;
    }

    /** Le site qui a enregistre la commande, et qui a fait la demande. */
    public function sitePortee(): ?string
    {
        return $this->commande?->site_id;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'commande_id' => $this->commande_id,
            'demandee_par' => $this->demandee_par,
            'motif' => $this->motif,
            'demandee_le' => $this->demandee_le->toIso8601ZuluString(),
            'etat' => $this->etat,
            'decidee_le' => $this->decidee_le?->toIso8601ZuluString(),
            'motif_refus' => $this->motif_refus,
        ];
    }
}
