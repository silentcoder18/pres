<?php

namespace App\Services\Sync;

use App\Models\Poste;
use App\Models\SessionPoste;
use Illuminate\Http\Request;

/**
 * Qui synchronise, et pour quel site (decision du Promoteur, 16 octobre 2026).
 *
 * Le poste est l'ordinateur reconnu a sa mise en service. Chaque personne qui s'y connecte a sa
 * propre base locale, synchronisee sous sa session : le site de travail est alors son site de
 * rattachement, quel que soit le site ou se trouve le poste.
 *
 * Sans session, c'est la base de l'ordinateur lui-meme : elle ne recoit que les donnees de
 * reference (comptes, sites, catalogue, parametres), qui servent a connecter les personnes. Ce
 * qu'elle envoie encore (ecritures d'un poste mis a jour avant d'avoir tout envoye) est rattache,
 * comme avant, au site du poste.
 */
final class Perimetre
{
    public function __construct(
        public readonly Poste $poste,
        public readonly ?SessionPoste $session,
        /** Site de travail : celui de la session, ou a defaut celui du poste. */
        public readonly ?string $site_id,
    ) {}

    public static function de(Poste $poste, ?SessionPoste $session = null): self
    {
        return new self($poste, $session, $session !== null ? $session->site_id : $poste->site_id);
    }

    public static function depuisRequete(Request $request): self
    {
        return self::de($request->attributes->get('poste'), $request->attributes->get('session_poste'));
    }

    /** Vrai pour la base de l'ordinateur, sans personne connectee. */
    public function estMateriel(): bool
    {
        return $this->session === null;
    }
}
