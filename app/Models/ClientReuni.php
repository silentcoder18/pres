<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fiche client creee hors ligne avec un numero deja connu du reseau (RG-21).
 *
 * Son identifiant est celui de la fiche en double : tout ce que le poste envoie encore sous cet
 * identifiant est rattache a la fiche conservee. Le poste qui l'avait creee est prevenu pour
 * faire la meme reunion chez lui.
 */
class ClientReuni extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'clients_reunis';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'a_trancher' => 'boolean',
            'tranche_le' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Identifiant de la fiche conservee si celui-ci designe une fiche reunie, sinon l'identifiant recu. */
    public static function resoudre(string $clientId): string
    {
        return static::whereKey($clientId)->value('client_id') ?? $clientId;
    }

    public function sitePortee(): ?string
    {
        return $this->site_id;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'client' => $this->client->donneesPoste(),
        ];
    }
}
