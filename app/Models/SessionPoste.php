<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Une personne sur un poste (decision du Promoteur, 16 octobre 2026) : le poste tient pour elle
 * une base locale a part, synchronisee sous cette session, sur les donnees de son site de
 * rattachement. Le jeton n'est garde ici que par son empreinte ; il ne se montre qu'une fois, au
 * poste, a la verification en ligne du compte.
 */
class SessionPoste extends Model
{
    use HasUuids;

    protected $table = 'sessions_poste';

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['jeton_hash'];

    protected function casts(): array
    {
        return [
            'version_acces' => 'integer',
            'ouverte_le' => 'datetime',
            'dernier_contact_le' => 'datetime',
            'fermee_le' => 'datetime',
        ];
    }

    public function poste(): BelongsTo
    {
        return $this->belongsTo(Poste::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Ouvre la session de ce compte sur ce poste ; celle qu'il y avait deja est fermee : le poste
     * garde la base de la personne et n'y change que le jeton.
     *
     * @return array{0: SessionPoste, 1: string} la session et son jeton, a remettre au poste
     */
    public static function ouvrir(Poste $poste, Utilisateur $utilisateur): array
    {
        self::where('poste_id', $poste->id)
            ->where('utilisateur_id', $utilisateur->id)
            ->whereNull('fermee_le')
            ->update(['fermee_le' => now()]);

        $jeton = Str::random(64);
        $session = self::create([
            'poste_id' => $poste->id,
            'utilisateur_id' => $utilisateur->id,
            'site_id' => $utilisateur->site_id,
            'jeton_hash' => Poste::empreinte($jeton),
            'version_acces' => $utilisateur->version_acces,
            'ouverte_le' => now(),
        ]);

        return [$session, $jeton];
    }

    /**
     * Pourquoi la session ne vaut plus, ou null si elle vaut : fermee par une nouvelle connexion,
     * compte desactive, mot de passe ou site de rattachement change depuis son ouverture.
     */
    public function motifDeRefus(): ?string
    {
        $utilisateur = $this->utilisateur;
        if ($this->fermee_le !== null) {
            return 'Cette session a été remplacée par une connexion plus récente sur ce poste. Reconnectez-vous.';
        }
        if ($utilisateur === null || ! $utilisateur->actif) {
            return 'Ce compte a été désactivé par le Promoteur.';
        }
        if ($utilisateur->version_acces !== $this->version_acces || $utilisateur->site_id !== $this->site_id) {
            return 'Le mot de passe ou le site de ce compte a changé. Reconnectez-vous.';
        }

        return null;
    }
}
