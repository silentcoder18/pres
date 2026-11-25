<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Encaissement en especes rattache a une session de caisse (RG-17). */
class Paiement extends Model
{
    use HasUuids, Synchronisable;

    public const ACOMPTE = 'ACOMPTE';

    public const SOLDE = 'SOLDE';

    public const REMBOURSEMENT = 'REMBOURSEMENT';

    // Affectations (RG-14) : decidees par le serveur a la reception du paiement, elles ne partent
    // pas vers les postes. La regle est dans App\Services\Finance\AffectationPaiements.

    /** Commande deposee la meme semaine que l'encaissement : recette de cette semaine. */
    public const RECETTE = 'RECETTE';

    /** Commande d'une semaine anterieure : recouvrement tardif, verse a la reserve. */
    public const RESERVE = 'RESERVE';

    protected $table = 'paiements';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'montant' => 'decimal:2',
            'encaisse_le' => 'datetime',
        ];
    }

    public function commande(): BelongsTo
    {
        return $this->belongsTo(Commande::class);
    }

    public function sessionCaisse(): BelongsTo
    {
        return $this->belongsTo(SessionCaisse::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function sitePortee(): ?string
    {
        return $this->commande?->site_id;
    }

    public function donneesPoste(): array
    {
        return [
            'id' => $this->id,
            'commande_id' => $this->commande_id,
            'session_caisse_id' => $this->session_caisse_id,
            'utilisateur_id' => $this->utilisateur_id,
            'type' => $this->type,
            'montant' => (string) $this->montant,
            'encaisse_le' => $this->encaisse_le->toIso8601ZuluString(),
        ];
    }
}
