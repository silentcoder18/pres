<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Controle d'une commande recue a l'atelier (RG-08). Un ecart qui modifie la facture porte la
 * correction proposee par le chef d'atelier et ce qu'elle est devenue (RG-09).
 */
class Controle extends Model
{
    use HasUuids, Synchronisable;

    public const CONFORME = 'CONFORME';

    /** Anomalie notee, sans effet sur la facture : elle ne bloque pas la commande. */
    public const OBSERVATION = 'OBSERVATION';

    /** Quantite constatee differente de la quantite enregistree : la facture change. */
    public const ECART = 'ECART';

    public const RESULTATS = [self::CONFORME, self::OBSERVATION, self::ECART];

    public const PROPOSEE = 'PROPOSEE';

    public const ACCEPTEE = 'ACCEPTEE';

    public const APPLIQUEE_APRES_DELAI = 'APPLIQUEE_APRES_DELAI';

    protected $table = 'controles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'controle_le' => 'datetime',
            'decide_le' => 'datetime',
            'quantite_avant' => 'integer',
            'quantite_apres' => 'integer',
            'montant_avant' => 'decimal:2',
            'montant_apres' => 'decimal:2',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function tarif(): BelongsTo
    {
        return $this->belongsTo(Tarif::class);
    }

    public function decidePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'decide_par');
    }

    public function enAttenteDAccord(): bool
    {
        return $this->etat === self::PROPOSEE;
    }

    /**
     * Corrections qui bloquent encore une commande : proposees, sur une commande toujours En attente
     * d'accord. Une correction proposee sur une commande annulee depuis n'attend plus rien (RG-24).
     */
    public static function correctionsEnAttente(): Builder
    {
        return static::query()
            ->where('etat', self::PROPOSEE)
            ->whereHas('commande', fn (Builder $q) => $q->where('statut', Commande::EN_ATTENTE_ACCORD));
    }

    /** La boutique qui a enregistre la commande ; l'atelier est servi par une publication a part. */
    public function sitePortee(): ?string
    {
        return $this->commande?->site_id;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'commande_id' => $this->commande_id,
            'utilisateur_id' => $this->utilisateur_id,
            'resultat' => $this->resultat,
            'remarque' => $this->remarque,
            'controle_le' => $this->controle_le->toIso8601ZuluString(),
            'ligne_commande_id' => $this->ligne_commande_id,
            'quantite_avant' => $this->quantite_avant,
            'quantite_apres' => $this->quantite_apres,
            'etat' => $this->etat,
            'decide_le' => $this->decide_le?->toIso8601ZuluString(),
        ];
    }
}
