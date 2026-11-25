<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Achat de l'atelier : une charge de la semaine ou il a eu lieu (§7, RG-11). D'abord limite aux
 * sorties de consommables, il couvre depuis le 23 octobre 2026 toutes les depenses, classees par
 * categorie (decision du Promoteur) ; un poste d'avant les categories envoie des consommables.
 */
class Consommable extends Model
{
    use HasUuids, Synchronisable;

    public const CONSOMMABLES = 'CONSOMMABLES';

    /** Categories d'achat et leur libelle, dans l'ordre ou elles se presentent. */
    public const CATEGORIES = [
        self::CONSOMMABLES => 'Consommables',
        'MATERIEL' => 'Matériel',
        'ENTRETIEN' => 'Entretien et réparation',
        'ENERGIE' => 'Énergie (eau, électricité, carburant)',
        'TRANSPORT' => 'Transport',
        'AUTRE' => 'Autre',
    ];

    protected $table = 'consommables';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'sortie_le' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function sitePortee(): ?string
    {
        return $this->site_id;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'utilisateur_id' => $this->utilisateur_id,
            'categorie' => $this->categorie ?? self::CONSOMMABLES,
            'description' => $this->description,
            'montant' => (string) $this->montant,
            'sortie_le' => $this->sortie_le->toIso8601ZuluString(),
        ];
    }
}
