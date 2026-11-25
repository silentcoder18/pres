<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * Compte utilisateur (RG-15, RG-16).
 *
 * Decisions du Promoteur (9 et 16 octobre 2026) : n'importe quel compte d'un site se connecte sur
 * n'importe quel poste mis en service ; ses menus dependent de son role, ses donnees sont celles
 * de son site de rattachement, dans une base locale a lui. Le Promoteur ne se connecte pas sur un
 * poste : ses fonctions sont sur l'interface web. Tous les comptes sont transmis a tous les postes,
 * sans mot de passe : c'est ce qui permet a un acces retire ou a un mot de passe change de se
 * fermer partout a la synchronisation suivante (§4.6).
 */
class Utilisateur extends Authenticatable
{
    use HasUuids, Synchronisable;

    public const PROMOTEUR = 'PROMOTEUR';

    public const CHEF_ATELIER = 'CHEF_ATELIER';

    public const RECEPTIONNISTE = 'RECEPTIONNISTE';

    public const ROLES = [self::PROMOTEUR, self::CHEF_ATELIER, self::RECEPTIONNISTE];

    protected $table = 'utilisateurs';

    protected $guarded = [];

    // Valeurs par defaut connues du modele des sa creation, avant toute relecture en base
    protected $attributes = ['actif' => true, 'version_acces' => 1];

    protected $hidden = ['mot_de_passe', 'remember_token'];

    protected function casts(): array
    {
        return [
            'mot_de_passe' => 'hashed',
            'actif' => 'boolean',
            'version_acces' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Utilisateur $utilisateur) {
            // un site de rattachement change ne touche pas au mot de passe garde sur les postes : la
            // session de la personne, elle, tombe (SessionPoste::motifDeRefus)
            if ($utilisateur->isDirty('mot_de_passe') || $utilisateur->isDirty('actif')) {
                $utilisateur->version_acces++;
            }
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function getAuthPasswordName(): string
    {
        return 'mot_de_passe';
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'nom' => $this->nom,
            'identifiant' => $this->identifiant,
            'role' => $this->role,
            'site_id' => $this->site_id,
            'actif' => (bool) $this->actif,
            'version_acces' => (int) $this->version_acces,
        ];
    }
}
