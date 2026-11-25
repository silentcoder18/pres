<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Entree ou sortie de stock d'un consommable de l'atelier (amelioration du 23 octobre 2026). Un
 * mouvement est un fait : il ne se modifie pas ; une erreur se corrige par un mouvement inverse.
 */
class MouvementConsommable extends Model
{
    use HasUuids, Synchronisable;

    public const APPROVISIONNEMENT = 'APPROVISIONNEMENT';

    public const SORTIE = 'SORTIE';

    public const TYPES = [self::APPROVISIONNEMENT, self::SORTIE];

    protected $table = 'mouvements_consommables';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'quantite' => 'decimal:3',
            'cout_total' => 'decimal:2',
            'mouvement_le' => 'datetime',
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(ArticleConsommable::class, 'article_id');
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
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
            'article_id' => $this->article_id,
            'type' => $this->type,
            'quantite' => (string) $this->quantite,
            'cout_total' => $this->cout_total === null ? null : (string) $this->cout_total,
            'utilisateur_id' => $this->utilisateur_id,
            'mouvement_le' => $this->mouvement_le->toIso8601ZuluString(),
            'remarque' => $this->remarque,
        ];
    }
}
