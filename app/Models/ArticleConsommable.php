<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Consommable suivi en stock a l'atelier (cintres, film, lessive...), amelioration du 23 octobre
 * 2026. Le stock n'est pas garde : c'est la somme de ses mouvements.
 */
class ArticleConsommable extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'articles_consommables';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'seuil_alerte' => 'decimal:3',
            'actif' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function mouvements(): HasMany
    {
        return $this->hasMany(MouvementConsommable::class, 'article_id');
    }

    /** Entrees moins sorties, a trois decimales. */
    public function stock(): string
    {
        $stock = '0.000';
        foreach ($this->mouvements()->get(['type', 'quantite']) as $mouvement) {
            $stock = $mouvement->type === MouvementConsommable::SORTIE
                ? bcsub($stock, (string) $mouvement->quantite, 3)
                : bcadd($stock, (string) $mouvement->quantite, 3);
        }

        return $stock;
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
            'code' => $this->code,
            'designation' => $this->designation,
            'unite' => $this->unite,
            'seuil_alerte' => (string) $this->seuil_alerte,
            'actif' => (bool) $this->actif,
        ];
    }
}
