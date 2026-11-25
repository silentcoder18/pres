<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Travailleur enrole a l'atelier (RG-02). Il n'a aucun acces : les taches lui sont attribuees. */
class Travailleur extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'travailleurs';

    /** Roles (decision du 16 octobre 2026) : qui lave, qui repasse, qui fait les deux. */
    public const LAVEUR = 'LAVEUR';

    public const REPASSEUR = 'REPASSEUR';

    public const POLYVALENT = 'POLYVALENT';

    public const ROLES = [self::LAVEUR, self::REPASSEUR, self::POLYVALENT];

    protected $guarded = [];

    // Valeurs par defaut connues du modele des sa creation, avant toute relecture en base
    protected $attributes = ['actif' => true, 'role' => self::POLYVALENT];

    /** Un laveur ne recoit que des lavages, un repasseur que des repassages, un polyvalent les deux. */
    public function peutFaire(string $etape): bool
    {
        return match ($this->role) {
            self::LAVEUR => $etape === Tache::LAVAGE,
            self::REPASSEUR => $etape === Tache::REPASSAGE,
            default => true,
        };
    }

    protected function casts(): array
    {
        return [
            'actif' => 'boolean',
            'enrole_le' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function creePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cree_par');
    }

    public function taches(): HasMany
    {
        return $this->hasMany(Tache::class);
    }

    public function sitePortee(): ?string
    {
        return $this->site_id;
    }

    /** §11 : les donnees d'identite ne repartent pas vers le poste de l'atelier. */
    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'matricule' => $this->matricule,
            'nom' => $this->nom,
            'role' => $this->role,
            'actif' => (bool) $this->actif,
        ];
    }
}
