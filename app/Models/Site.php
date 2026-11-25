<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Boutique ou atelier. */
class Site extends Model
{
    use HasUuids, Synchronisable;

    public const BOUTIQUE = 'boutique';

    public const ATELIER = 'atelier';

    protected $table = 'sites';

    protected $guarded = [];

    // Valeurs par defaut connues du modele des sa creation, avant toute relecture en base
    protected $attributes = ['actif' => true];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function postes(): HasMany
    {
        return $this->hasMany(Poste::class);
    }

    public function utilisateurs(): HasMany
    {
        return $this->hasMany(Utilisateur::class);
    }

    public function estAtelier(): bool
    {
        return $this->type === self::ATELIER;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'nom' => $this->nom,
            'adresse' => $this->adresse,
            'type' => $this->type,
            'actif' => (bool) $this->actif,
        ];
    }
}
