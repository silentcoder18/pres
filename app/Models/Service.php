<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Prestation du catalogue (nettoyage, repassage...). */
class Service extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'services';

    protected $guarded = [];

    // Valeurs par defaut connues du modele des sa creation, avant toute relecture en base
    protected $attributes = ['actif' => true, 'avec_lavage' => true, 'avec_repassage' => true];

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'avec_lavage' => 'boolean',
            'avec_repassage' => 'boolean',
        ];
    }

    /**
     * Etapes que comprend la prestation, dans l'ordre ou l'article les passe (decision du
     * 16 octobre 2026) : lavage puis repassage, ou l'une des deux seulement.
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

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'description' => $this->description,
            'actif' => (bool) $this->actif,
            'avec_lavage' => (bool) $this->avec_lavage,
            'avec_repassage' => (bool) $this->avec_repassage,
        ];
    }
}
