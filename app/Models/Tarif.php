<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prix de vente et commission d'un couple article / service (RG-03). */
class Tarif extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'tarifs';

    protected $guarded = [];

    // Valeurs par defaut connues du modele des sa creation, avant toute relecture en base
    protected $attributes = ['actif' => true];

    protected function casts(): array
    {
        return [
            'prix_vente' => 'decimal:2',
            'commission_fixe' => 'decimal:2',
            'commission_lavage' => 'decimal:2',
            'commission_repassage' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }

    /**
     * Commission de chaque etape (decision du 16 octobre 2026) ; la commission fixe en est la
     * somme (RG-03). Une etape que la prestation ne comprend pas ne rapporte rien.
     *
     * @return array{commission_lavage: string, commission_repassage: string, commission_fixe: string}
     */
    public static function commissions(Service $service, string $lavage, string $repassage): array
    {
        $lavage = $service->avec_lavage ? bcadd($lavage, '0', 2) : '0.00';
        $repassage = $service->avec_repassage ? bcadd($repassage, '0', 2) : '0.00';

        return [
            'commission_lavage' => $lavage,
            'commission_repassage' => $repassage,
            'commission_fixe' => bcadd($lavage, $repassage, 2),
        ];
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'article_id' => $this->article_id,
            'service_id' => $this->service_id,
            'prix_vente' => (string) $this->prix_vente,
            'commission_fixe' => (string) $this->commission_fixe,
            'commission_lavage' => (string) $this->commission_lavage,
            'commission_repassage' => (string) $this->commission_repassage,
            'actif' => (bool) $this->actif,
        ];
    }
}
