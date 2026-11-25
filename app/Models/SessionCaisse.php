<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Session de caisse journaliere d'un site (RG-18). */
class SessionCaisse extends Model
{
    use HasUuids, Synchronisable;

    protected $table = 'sessions_caisse';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'ouverte_le' => 'datetime',
            'cloturee_le' => 'datetime',
            'fond_initial' => 'decimal:2',
            'total_attendu' => 'decimal:2',
            'total_compte' => 'decimal:2',
            'ecart' => 'decimal:2',
        ];
    }

    public function estOuverte(): bool
    {
        return $this->cloturee_le === null;
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function ouvertePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'ouverte_par');
    }

    public function clotureePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'cloturee_par');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    /**
     * RG-18 : le total attendu et l'ecart d'une session cloturee se deduisent des encaissements
     * que le serveur a effectivement recus ; seul le montant compte vient de la receptionniste.
     */
    public function recalculer(bool $silencieux = false): void
    {
        if ($this->estOuverte()) {
            $this->total_attendu = null;
            $this->ecart = null;
            $silencieux ? $this->saveQuietly() : $this->save();

            return;
        }
        $attendu = (string) $this->fond_initial;
        foreach ($this->paiements()->get() as $paiement) {
            $attendu = $paiement->type === Paiement::REMBOURSEMENT
                ? bcsub($attendu, (string) $paiement->montant, 2)
                : bcadd($attendu, (string) $paiement->montant, 2);
        }
        $this->total_attendu = $attendu;
        $this->ecart = bcsub((string) $this->total_compte, $attendu, 2);
        $silencieux ? $this->saveQuietly() : $this->save();
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
            'ouverte_par' => $this->ouverte_par,
            'ouverte_le' => $this->ouverte_le->toIso8601ZuluString(),
            'fond_initial' => (string) $this->fond_initial,
            'cloturee_par' => $this->cloturee_par,
            'cloturee_le' => $this->cloturee_le?->toIso8601ZuluString(),
            'total_attendu' => $this->total_attendu === null ? null : (string) $this->total_attendu,
            'total_compte' => $this->total_compte === null ? null : (string) $this->total_compte,
            'ecart' => $this->ecart === null ? null : (string) $this->ecart,
            'observations' => $this->observations,
        ];
    }
}
