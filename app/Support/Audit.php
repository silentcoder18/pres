<?php

namespace App\Support;

use App\Models\JournalAudit;
use App\Models\Utilisateur;
use Illuminate\Database\Eloquent\Model;

/** Ecrit dans le journal d'audit les actions faites sur l'interface web. */
final class Audit
{
    public const CONNEXION = 'connexion';

    public const SITE_CREE = 'site_cree';

    public const SITE_MODIFIE = 'site_modifie';

    public const POSTE_DECLARE = 'poste_declare';

    public const POSTE_NOUVEAU_CODE = 'poste_nouveau_code';

    public const POSTE_REVOQUE = 'poste_revoque';

    public const COMPTE_CREE = 'compte_cree';

    public const COMPTE_MODIFIE = 'compte_modifie';

    public const COMPTE_MOT_DE_PASSE = 'compte_mot_de_passe';

    public const CATALOGUE_CREE = 'catalogue_cree';

    public const CATALOGUE_MODIFIE = 'catalogue_modifie';

    public const PARAMETRE_MODIFIE = 'parametre_modifie';

    public const DOUBLON_TRANCHE = 'doublon_tranche';

    public const ANNULATION_CONFIRMEE = 'annulation_confirmee';

    public const ANNULATION_REFUSEE = 'annulation_refusee';

    public const CORRECTION_APPLIQUEE_D_OFFICE = 'correction_appliquee_d_office';

    public const RAPPORT_FINANCIER_EXPORTE = 'rapport_financier_exporte';

    /**
     * @param  ?Model  $objet  ce sur quoi porte l'action
     * @param  ?string  $libelle  nom de l'objet tel qu'il se lit au moment de l'action
     * @param  ?string  $detail  ce qui a change, en clair ; jamais un mot de passe ni un code
     */
    public static function noter(string $action, ?Model $objet = null, ?string $libelle = null, ?string $detail = null, ?Utilisateur $auteur = null): void
    {
        $auteur ??= auth()->user();

        JournalAudit::create([
            'utilisateur_id' => $auteur?->getKey(),
            'auteur' => $auteur?->nom ?? 'Système',
            'action' => $action,
            'objet_type' => $objet?->getTable(),
            'objet_id' => $objet === null ? null : (string) $objet->getKey(),
            'objet_libelle' => $libelle === null ? null : mb_substr($libelle, 0, 255),
            'detail' => $detail,
            'adresse_ip' => request()?->ip(),
            'cree_le' => now(),
        ]);
    }

    /**
     * Resume en clair les champs qui viennent de changer sur un modele enregistre.
     *
     * @param  array<string, string>  $libelles  nom de colonne => nom affiche
     */
    public static function changements(Model $modele, array $libelles): ?string
    {
        $avant = $modele->getPrevious();
        $lignes = [];
        foreach ($libelles as $colonne => $libelle) {
            if (! $modele->wasChanged($colonne)) {
                continue;
            }
            $ancienne = $avant[$colonne] ?? null;
            $nouvelle = $modele->getAttribute($colonne);
            if ($ancienne !== null && $modele->hasCast($colonne, ['bool', 'boolean'])) {
                $ancienne = (bool) $ancienne;
            }
            // Un montant se lit de la meme facon avant et apres, quelle que soit sa forme en base
            if (str_starts_with((string) ($modele->getCasts()[$colonne] ?? ''), 'decimal')) {
                $ancienne = $ancienne === null ? null : Affichage::nombre($ancienne);
                $nouvelle = $nouvelle === null ? null : Affichage::nombre($nouvelle);
            }
            $lignes[] = $libelle.' : '.self::valeur($ancienne).' → '.self::valeur($nouvelle);
        }

        return $lignes === [] ? null : implode(' ; ', $lignes);
    }

    private static function valeur(mixed $valeur): string
    {
        return match (true) {
            $valeur === null, $valeur === '' => '(vide)',
            is_bool($valeur) => $valeur ? 'oui' : 'non',
            default => (string) $valeur,
        };
    }
}
