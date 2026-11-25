<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ligne d'une commande ; prix et commission figes a sa creation (RG-03). Voyage avec sa commande. */
class LigneCommande extends Model
{
    use HasUuids;

    protected $table = 'lignes_commande';

    protected $guarded = [];

    public function tarif(): BelongsTo
    {
        return $this->belongsTo(Tarif::class);
    }

    protected function casts(): array
    {
        return [
            'quantite' => 'integer',
            'prix_applique' => 'decimal:2',
            'commission_appliquee' => 'decimal:2',
            'avec_lavage' => 'boolean',
            'avec_repassage' => 'boolean',
            'commission_lavage' => 'decimal:2',
            'commission_repassage' => 'decimal:2',
        ];
    }

    /**
     * Etapes que l'article de cette ligne doit passer, figees au depot avec la prestation.
     *
     * @return list<string>
     */
    public function etapes(): array
    {
        return array_values(array_filter([
            $this->avec_lavage ? Tache::LAVAGE : null,
            $this->avec_repassage ? Tache::REPASSAGE : null,
        ]));
    }

    public function commissionDeLEtape(string $etape): string
    {
        return (string) ($etape === Tache::LAVAGE ? $this->commission_lavage : $this->commission_repassage);
    }
}
