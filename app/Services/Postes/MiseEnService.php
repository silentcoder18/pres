<?php

namespace App\Services\Postes;

use App\Models\Poste;
use App\Models\Site;

/**
 * Declaration des postes et codes de mise en service (§4.2).
 *
 * Le code ne sert qu'une fois et expire ; seul son hachage est garde. Il n'est donc connu qu'au
 * moment ou il est genere : c'est a ce moment-la qu'il faut le montrer.
 */
class MiseEnService
{
    // Sans 0/O ni 1/I, pour une saisie sans ambiguite
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const LONGUEUR = 8;

    /** @return array{0: Poste, 1: string} le poste cree et son code, sous la forme XXXX-XXXX */
    public function declarer(Site $site, string $nom): array
    {
        $code = $this->tirerCode();

        $poste = Poste::create([
            'site_id' => $site->id,
            'nom' => $nom,
            'code_enrolement_hash' => Poste::empreinte($code),
            'code_expire_le' => now()->addHours($this->validiteHeures()),
        ]);

        return [$poste, $this->presenter($code)];
    }

    /**
     * Donne un nouveau code a un poste existant : ordinateur remplace, code expire, ou acces a
     * retablir apres une revocation. L'ordinateur relie jusque-la au poste perd son acces.
     */
    public function renouvelerCode(Poste $poste): string
    {
        $code = $this->tirerCode();

        $poste->forceFill([
            'code_enrolement_hash' => Poste::empreinte($code),
            'code_expire_le' => now()->addHours($this->validiteHeures()),
            'jeton_hash' => null,
            'revoque_le' => null,
        ])->save();

        return $this->presenter($code);
    }

    public function validiteHeures(): int
    {
        return (int) config('pressing.enrolement.validite_heures');
    }

    private function tirerCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::LONGUEUR; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    private function presenter(string $code): string
    {
        return substr($code, 0, 4).'-'.substr($code, 4);
    }
}
