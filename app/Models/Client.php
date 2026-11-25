<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Client du reseau, identifie par son numero de telephone (RG-21). */
class Client extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'clients';

    protected $guarded = [];

    public function siteOrigine(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_origine_id');
    }

    public function commandes(): HasMany
    {
        return $this->hasMany(Commande::class);
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'telephone' => $this->telephone,
            'quartier' => $this->quartier,
            'site_origine_id' => $this->site_origine_id,
        ];
    }
}
