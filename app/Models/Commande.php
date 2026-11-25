<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Commande d'un client (RG-01, RG-04, RG-05).
 *
 * Ses lignes voyagent avec elle : un poste recoit ou envoie la commande entiere, jamais
 * une ligne seule, pour qu'aucune commande n'existe sans ses articles.
 */
class Commande extends Model
{
    use HasUuids, Synchronisable;

    public const ENREGISTREE = 'ENREGISTREE';

    public const ENVOYEE = 'ENVOYEE';

    public const RECEPTIONNEE = 'RECEPTIONNEE';

    public const EN_ATTENTE_ACCORD = 'EN_ATTENTE_ACCORD';

    public const CONTROLEE = 'CONTROLEE';

    public const EN_TRAITEMENT = 'EN_TRAITEMENT';

    public const TRAITEE = 'TRAITEE';

    public const RETOURNEE = 'RETOURNEE';

    public const LIVREE = 'LIVREE';

    public const NON_RECLAMEE = 'NON_RECLAMEE';

    public const ANNULEE = 'ANNULEE';

    /** Statuts apres lesquels une commande ne concerne plus le travail courant d'un site. */
    public const DON = 'DON';

    public const TERMINAUX = ['LIVREE', 'DON', 'ANNULEE'];

    /** RG-24 : une commande s'annule tant qu'elle n'est pas entree en traitement. */
    public const ANNULABLES = [
        self::ENREGISTREE, self::ENVOYEE, self::RECEPTIONNEE, self::EN_ATTENTE_ACCORD, self::CONTROLEE,
    ];

    protected $table = 'commandes';

    protected $guarded = [];

    // Valeurs par defaut connues du modele des sa creation, avant toute relecture en base
    protected $attributes = ['accord_messages' => true, 'montant' => 0, 'total_paye' => 0, 'reste' => 0];

    protected function casts(): array
    {
        return [
            'deposee_le' => 'datetime',
            'traitee_le' => 'datetime',
            'livree_le' => 'datetime',
            'accord_messages' => 'boolean',
            'montant' => 'decimal:2',
            'total_paye' => 'decimal:2',
            'reste' => 'decimal:2',
        ];
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneCommande::class)->orderBy('created_at')->orderBy('id');
    }

    public function paiements(): HasMany
    {
        return $this->hasMany(Paiement::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function utilisateur(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function lots(): BelongsToMany
    {
        return $this->belongsToMany(Lot::class, 'lot_commandes');
    }

    public function controles(): HasMany
    {
        return $this->hasMany(Controle::class)->orderBy('controle_le');
    }

    public function taches(): HasMany
    {
        return $this->hasMany(Tache::class)->orderBy('attribuee_le')->orderBy('id');
    }

    public function demandesAnnulation(): HasMany
    {
        return $this->hasMany(DemandeAnnulation::class)->orderBy('demandee_le');
    }

    public function livreePar(): BelongsTo
    {
        return $this->belongsTo(Utilisateur::class, 'livree_par');
    }

    public function estAnnulable(): bool
    {
        return in_array($this->statut, self::ANNULABLES, true);
    }

    /** Commande deposee directement a l'atelier : elle ne passe par aucun lot (RG-05). */
    public function estDepotDirect(): bool
    {
        return $this->site?->estAtelier() ?? false;
    }

    /** Atelier qui a recu la commande par un lot aller, ou null si elle n'y est pas arrivee. */
    public function atelierId(): ?string
    {
        return $this->lots()
            ->where('sens', Lot::ALLER)
            ->whereNotNull('recu_le')
            ->orderByDesc('recu_le')
            ->value('site_destination_id');
    }

    /**
     * Atelier auquel la commande a ete envoyee par un lot aller, que le lot soit deja recu ou encore
     * en route : il doit apprendre une annulation avant de la traiter (RG-24).
     */
    public function atelierDuLotAller(): ?string
    {
        return $this->lots()
            ->where('sens', Lot::ALLER)
            ->orderByDesc('cloture_le')
            ->value('site_destination_id');
    }

    /** La commande est deja repartie vers sa boutique, que le lot retour soit recu ou non. */
    public function estRepartie(): bool
    {
        return $this->lots()->where('sens', Lot::RETOUR)->exists();
    }

    /** Les taches se posent sur une commande controlee, ou sur un depot direct que le chef a lui-meme recu. */
    public function accepteDesTaches(): bool
    {
        return in_array($this->statut, [self::CONTROLEE, self::EN_TRAITEMENT], true)
            || ($this->statut === self::ENREGISTREE && $this->estDepotDirect());
    }

    /**
     * RG-05 : la premiere tache fait passer la commande En traitement ; elle devient Traitee quand
     * chaque etape de chaque ligne (lavage, repassage) est couverte par des taches terminees, sur
     * toute sa quantite. Pour un depot direct, le controle est tenu pour fait a l'enregistrement.
     */
    public function actualiserDepuisTaches(): void
    {
        $taches = $this->taches()->get();
        if ($taches->isEmpty()) {
            return;
        }
        $lignes = $this->lignes()->get();
        $toutTermine = $lignes->isNotEmpty() && $lignes->every(
            fn (LigneCommande $ligne) => collect($ligne->etapes())->every(
                fn (string $etape) => $taches->where('ligne_commande_id', $ligne->id)
                    ->where('etape', $etape)
                    ->whereNotNull('terminee_le')
                    ->sum('quantite') >= $ligne->quantite
            )
        );

        if ($this->statut === self::ENREGISTREE && $this->estDepotDirect()) {
            $this->statut = self::CONTROLEE;
        }
        if ($this->statut === self::CONTROLEE) {
            $this->statut = self::EN_TRAITEMENT;
        }
        if ($toutTermine && $this->statut === self::EN_TRAITEMENT) {
            $this->statut = self::TRAITEE;
            $this->traitee_le = $taches->max('terminee_le');
        }
        if ($this->isDirty()) {
            $this->save();
        }
    }

    /**
     * RG-04 : montant, total paye et reste sont calcules, jamais recus tels quels. RG-24 : une
     * commande annulee n'a plus de reste a payer ; son total paye est l'avance a restituer.
     */
    public function recalculer(bool $silencieux = false): void
    {
        $montant = '0';
        foreach ($this->lignes()->get() as $ligne) {
            $montant = bcadd($montant, bcmul((string) $ligne->prix_applique, (string) $ligne->quantite, 2), 2);
        }
        $paye = '0';
        foreach ($this->paiements()->get() as $paiement) {
            $paye = $paiement->type === Paiement::REMBOURSEMENT
                ? bcsub($paye, (string) $paiement->montant, 2)
                : bcadd($paye, (string) $paiement->montant, 2);
        }
        $this->montant = $montant;
        $this->total_paye = $paye;
        $this->reste = $this->statut === self::ANNULEE ? '0' : bcsub($montant, $paye, 2);
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
            'code' => $this->code,
            'site_id' => $this->site_id,
            'client_id' => $this->client_id,
            'utilisateur_id' => $this->utilisateur_id,
            'statut' => $this->statut,
            'deposee_le' => $this->deposee_le->toIso8601ZuluString(),
            'montant' => (string) $this->montant,
            'total_paye' => (string) $this->total_paye,
            'reste' => (string) $this->reste,
            'accord_messages' => (bool) $this->accord_messages,
            'lignes' => $this->lignes()->get()->map(fn (LigneCommande $ligne) => [
                'id' => $ligne->id,
                'tarif_id' => $ligne->tarif_id,
                'quantite' => (int) $ligne->quantite,
                'prix_applique' => (string) $ligne->prix_applique,
                'commission_appliquee' => (string) $ligne->commission_appliquee,
                'avec_lavage' => (bool) $ligne->avec_lavage,
                'avec_repassage' => (bool) $ligne->avec_repassage,
                'commission_lavage' => (string) $ligne->commission_lavage,
                'commission_repassage' => (string) $ligne->commission_repassage,
                'remarque' => $ligne->remarque,
            ])->all(),
        ];
    }
}
