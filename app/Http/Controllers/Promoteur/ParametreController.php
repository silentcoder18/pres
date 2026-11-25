<?php

namespace App\Http\Controllers\Promoteur;

use App\Http\Controllers\Controller;
use App\Models\Parametre;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Parametres du reseau (RG-23), fixes ici puis transmis aux postes. */
class ParametreController extends Controller
{
    /**
     * Ce qui est attendu pour chaque parametre : bornes, unite, et s'il peut rester vide.
     * Un parametre facultatif laisse vide vaut « pas de delai ».
     */
    public const DEFINITIONS = [
        Parametre::DELAI_RETRAIT_SEMAINES => [
            'unite' => 'semaines', 'min' => 1, 'max' => 104, 'facultatif' => false,
            'aide' => 'Au-delà, une commande traitée et non retirée devient « Non réclamée » et reçoit la pénalité (RG-06).',
        ],
        Parametre::MONTANT_PENALITE => [
            'unite' => 'FCFA', 'min' => 0, 'max' => 10000000, 'facultatif' => false,
            'aide' => 'Ajouté à la facture d\'une commande non réclamée.',
        ],
        Parametre::DELAI_ALERTE_LOT_HEURES => [
            'unite' => 'heures', 'min' => 1, 'max' => 720, 'facultatif' => false,
            'aide' => 'Un lot sans accusé de réception au-delà de ce délai vous est signalé (RG-19).',
        ],
        Parametre::DUREE_MAX_HORS_LIGNE_HEURES => [
            'unite' => 'heures', 'min' => 1, 'max' => 720, 'facultatif' => false,
            'aide' => 'Au-delà, le poste avertit que ses données peuvent ne plus être à jour et invite à synchroniser ; il continue de travailler (RG-25).',
        ],
        Parametre::INTERVALLE_SYNCHRO_HEURES => [
            'unite' => 'heures', 'min' => 1, 'max' => 168, 'facultatif' => false,
            'aide' => 'Rythme de la synchronisation automatique des postes.',
        ],
        Parametre::DELAI_ACCORD_ANOMALIE_JOURS => [
            'unite' => 'jours', 'min' => 1, 'max' => 365, 'facultatif' => true,
            'aide' => 'Sans accord du client dans ce délai, la correction s\'applique d\'office (RG-09). Vide : aucune application d\'office.',
        ],
        Parametre::DELAI_TRAITEMENT_JOURS => [
            'unite' => 'jours', 'min' => 1, 'max' => 365, 'facultatif' => true,
            'aide' => 'Annoncé au client dans le message qui suit l\'enregistrement de sa commande. Vide : aucun délai annoncé.',
        ],
    ];

    public function index(): View
    {
        return view('promoteur.parametres', [
            'parametres' => $this->parametres(),
            'definitions' => self::DEFINITIONS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $parametres = $this->parametres();

        $regles = [];
        $noms = [];
        foreach ($parametres as $parametre) {
            $definition = self::DEFINITIONS[$parametre->cle];
            $regles['valeurs.'.$parametre->cle] = [
                $definition['facultatif'] ? 'nullable' : 'required',
                'integer', 'min:'.$definition['min'], 'max:'.$definition['max'],
            ];
            $noms['valeurs.'.$parametre->cle] = '« '.$parametre->libelle.' »';
        }
        $valeurs = $request->validate($regles, [], $noms)['valeurs'] ?? [];

        $modifies = 0;
        foreach ($parametres as $parametre) {
            $saisie = $valeurs[$parametre->cle] ?? null;
            $parametre->valeur = $saisie === null ? null : (string) (int) $saisie;
            if (! $parametre->isDirty('valeur')) {
                continue;
            }
            $parametre->save();
            Audit::noter(Audit::PARAMETRE_MODIFIE, $parametre, $parametre->libelle,
                Audit::changements($parametre, ['valeur' => 'Valeur']));
            $modifies++;
        }

        $message = match ($modifies) {
            0 => 'Aucun paramètre ne change.',
            1 => 'Le paramètre est enregistré. Les postes le recevront à leur prochaine synchronisation.',
            default => "Les {$modifies} paramètres sont enregistrés. Les postes les recevront à leur prochaine synchronisation.",
        };

        return redirect()->route('parametres.index')->with('succes', $message);
    }

    /** Les parametres connus, dans l'ordre de RG-23. */
    private function parametres()
    {
        $ordre = array_keys(self::DEFINITIONS);

        return Parametre::whereIn('cle', $ordre)->get()
            ->sortBy(fn (Parametre $p) => array_search($p->cle, $ordre, true))
            ->values();
    }
}
