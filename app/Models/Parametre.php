<?php

namespace App\Models;

use App\Models\Concerns\Synchronisable;
use Illuminate\Database\Eloquent\Model;

/** Parametre fixe par le Promoteur et transmis aux postes (RG-23). */
class Parametre extends Model
{
    use Synchronisable;

    public const DELAI_RETRAIT_SEMAINES = 'delai_retrait_semaines';

    public const MONTANT_PENALITE = 'montant_penalite';

    public const DELAI_ALERTE_LOT_HEURES = 'delai_alerte_lot_heures';

    public const DUREE_MAX_HORS_LIGNE_HEURES = 'duree_max_hors_ligne_heures';

    public const INTERVALLE_SYNCHRO_HEURES = 'intervalle_synchro_heures';

    public const DELAI_ACCORD_ANOMALIE_JOURS = 'delai_accord_anomalie_jours';

    public const DELAI_TRAITEMENT_JOURS = 'delai_traitement_jours';

    protected $table = 'parametres';

    protected $primaryKey = 'cle';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function donneesPoste(): array
    {
        return [
            'cle' => $this->cle,
            'valeur' => $this->valeur,
            'libelle' => $this->libelle,
        ];
    }
}
