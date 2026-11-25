<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'articles';

    protected $guarded = [];

    // Valeurs par defaut connues du modele des sa creation, avant toute relecture en base
    protected $attributes = ['actif' => true];

    protected function casts(): array
    {
        return ['actif' => 'boolean'];
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description,
            'actif' => (bool) $this->actif,
        ];
    }
}
