<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Versement journalier des especes d'une boutique a l'atelier (amelioration du 23 octobre 2026).
 *
 * La receptionniste le declare en cloturant sa caisse : le montant compte part avec le collecteur.
 * Le chef d'atelier en accuse reception avec le montant effectivement recu. Le versement va a sa
 * boutique et a tous les ateliers du reseau ; sa reception revient a la boutique.
 */
class VersementJournalier extends Model
{
    use HasUuids, Synchronisable;

    public const EN_ATTENTE = 'EN_ATTENTE';

    public const RECU = 'RECU';

    protected $table = 'versements_journaliers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'date_versement' => 'date',
            'montant_declare' => 'decimal:2',
            'declare_le' => 'datetime',
            'recu_le' => 'datetime',
            'montant_recu' => 'decimal:2',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function sessionCaisse(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class);
    }

    public function remisPar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'remis_par');
    }

    public function recuPar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'recu_par');
    }

    public function estRecu(): bool
    {
        return $this->recu_le !== null;
    }

    public function statut(): string
    {
        return $this->estRecu() ? self::RECU : self::EN_ATTENTE;
    }

    /** Recu moins declare ; null tant que le versement n'est pas recu. */
    public function ecart(): ?string
    {
        return $this->estRecu() ? bcsub((string) $this->montant_recu, (string) $this->montant_declare, 2) : null;
    }

    public function sitePortee(): ?string
    {
        return $this->site_id;
    }

    /** Les ateliers, qui recoivent les versements de toutes les boutiques. */
    public function publierAuxAteliers(): void
    {
        foreach (Site::where('type', Site::ATELIER)->pluck('id') as $atelierId) {
            $this->publierChangement(SyncChangement::UPSERT, $atelierId);
        }
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'session_caisse_id' => $this->session_caisse_id,
            'date_versement' => $this->date_versement->toDateString(),
            'montant_declare' => (string) $this->montant_declare,
            'remis_par' => $this->remis_par,
            'statut' => $this->statut(),
            'recu_le' => $this->recu_le?->toIso8601ZuluString(),
            'recu_par' => $this->recu_par,
            'montant_recu' => $this->montant_recu === null ? null : (string) $this->montant_recu,
            'observation' => $this->observation,
        ];
    }
}
