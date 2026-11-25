<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Message WhatsApp destine a un client (§8). Cree sur un poste au moment de l'evenement,
 * il attend ici d'etre envoye par le serveur ; il ne retourne pas vers les postes.
 */
class Message extends Model
{
    use HasUuids;

    public const EN_ATTENTE = 'EN_ATTENTE';

    public const ENVOYE = 'ENVOYE';

    public const ECHOUE = 'ECHOUE';

    protected $table = 'messages';

    protected $guarded = [];

    protected $attributes = ['etat' => self::EN_ATTENTE, 'tentatives' => 0];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    protected function casts(): array
    {
        return [
            'cree_le' => 'datetime',
            'envoye_le' => 'datetime',
            'tentatives' => 'integer',
        ];
    }
}
