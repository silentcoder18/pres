<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ordinateur d'un site, autorise a se synchroniser avec le serveur. */
class Poste extends Model
{
    use HasUuids;

    protected $table = 'postes';

    protected $guarded = [];

    protected $hidden = ['code_enrolement_hash', 'jeton_hash'];

    protected function casts(): array
    {
        return [
            'code_expire_le' => 'datetime',
            'enrole_le' => 'datetime',
            'dernier_contact_le' => 'datetime',
            'revoque_le' => 'datetime',
        ];
    }

    public const EN_SERVICE = 'en_service';

    public const EN_ATTENTE = 'en_attente';

    public const CODE_EXPIRE = 'code_expire';

    public const REVOQUE = 'revoque';

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Ou en est le poste : revoque, relie a un ordinateur, ou en attente de sa mise en service. */
    public function etat(): string
    {
        return match (true) {
            $this->revoque_le !== null => self::REVOQUE,
            $this->jeton_hash !== null => self::EN_SERVICE,
            $this->code_expire_le !== null && $this->code_expire_le->isFuture() => self::EN_ATTENTE,
            default => self::CODE_EXPIRE,
        };
    }

    /** Empreinte gardee en base a la place du code ou du jeton lui-meme. */
    public static function empreinte(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** Le code de mise en service se saisit avec ou sans tiret, en majuscules ou non. */
    public static function normaliserCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }
}
