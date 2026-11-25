<?php

namespace App\Services\Finance;

use App\Support\Affichage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Semaine comptable du reseau : du lundi 00:00 au lundi suivant 00:00, dans le fuseau du reseau.
 *
 * Les dates sont gardees en UTC dans la base. C'est ici, et nulle part ailleurs, que se decide a
 * quelle semaine appartient un depot ou un encaissement : la recette d'une semaine, un
 * recouvrement tardif et l'affectation d'un paiement en dependent (RG-14).
 */
final class SemaineComptable
{
    /** Ecriture d'une semaine dans une adresse : annee et numero ISO, par exemple 2026-W41. */
    private const FORMAT_ISO = '/^(\d{4})-W(\d{2})\z/';

    /** @param  CarbonImmutable  $debut  lundi 00:00, dans le fuseau du reseau */
    private function __construct(private readonly CarbonImmutable $debut) {}

    public static function courante(): self
    {
        return self::contenant(CarbonImmutable::now());
    }

    /** Semaine dans laquelle tombe un instant, quel que soit le fuseau dans lequel il est exprime. */
    public static function contenant(CarbonInterface $instant): self
    {
        return new self(
            CarbonImmutable::instance($instant)->setTimezone(Affichage::fuseau())->startOfWeek(CarbonInterface::MONDAY),
        );
    }

    /** Semaine ecrite 2026-W41 ; null si l'ecriture est mal formee ou si l'annee n'a pas cette semaine. */
    public static function depuisIso(string $code): ?self
    {
        if (! preg_match(self::FORMAT_ISO, $code, $parties)) {
            return null;
        }
        [$annee, $numero] = [(int) $parties[1], (int) $parties[2]];
        if ($annee < 2000 || $annee > 2199 || $numero < 1 || $numero > 53) {
            return null;
        }

        $debut = CarbonImmutable::now(Affichage::fuseau())->setISODate($annee, $numero, 1)->startOfDay();
        // Une annee n'a une semaine 53 qu'une fois sur cinq ou six : 2026-W53 existe, 2027-W53 non
        if ($debut->isoWeekYear !== $annee || $debut->isoWeek !== $numero) {
            return null;
        }

        return new self($debut);
    }

    /** Lundi 00:00, dans le fuseau du reseau. */
    public function debut(): CarbonImmutable
    {
        return $this->debut;
    }

    /** Lundi suivant 00:00, dans le fuseau du reseau : premier instant qui n'est plus dans la semaine. */
    public function fin(): CarbonImmutable
    {
        return $this->debut->addWeek();
    }

    /** Bornes en UTC, comme les dates enregistrees : debut inclus, fin exclue. */
    public function debutUtc(): CarbonImmutable
    {
        return $this->debut->utc();
    }

    public function finUtc(): CarbonImmutable
    {
        return $this->fin()->utc();
    }

    /** Dimanche, dernier jour de la semaine. */
    public function dernierJour(): CarbonImmutable
    {
        return $this->debut->addDays(6);
    }

    /** @return list<CarbonImmutable> les sept jours, du lundi au dimanche, a 00:00 dans le fuseau du reseau */
    public function jours(): array
    {
        $jours = [];
        for ($i = 0; $i < 7; $i++) {
            $jours[] = $this->debut->addDays($i);
        }

        return $jours;
    }

    /** Jour du reseau ou tombe un instant, ecrit 2026-10-11 : il sert de cle au detail par jour. */
    public static function jourDe(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->setTimezone(Affichage::fuseau())->format('Y-m-d');
    }

    public function contient(CarbonInterface $instant): bool
    {
        return $instant->greaterThanOrEqualTo($this->debut) && $instant->lessThan($this->fin());
    }

    /** La semaine n'est pas finie : ses chiffres bougent encore. */
    public function estEnCours(): bool
    {
        return $this->contient(CarbonImmutable::now());
    }

    public function estAVenir(): bool
    {
        return $this->debut->greaterThan(CarbonImmutable::now());
    }

    public function precedente(): self
    {
        return new self($this->debut->subWeek());
    }

    public function suivante(): self
    {
        return new self($this->debut->addWeek());
    }

    public function estAvant(self $autre): bool
    {
        return $this->debut->lessThan($autre->debut);
    }

    public function egale(self $autre): bool
    {
        return $this->debut->equalTo($autre->debut);
    }

    public function annee(): int
    {
        return $this->debut->isoWeekYear;
    }

    public function numero(): int
    {
        return $this->debut->isoWeek;
    }

    /** Ecriture attendue dans une adresse : 2026-W41. */
    public function iso(): string
    {
        return sprintf('%04d-W%02d', $this->annee(), $this->numero());
    }

    /** Ecriture courte, comme dans les codes de commande et les noms de fichier : 2026-S41. */
    public function reference(): string
    {
        return sprintf('%04d-S%02d', $this->annee(), $this->numero());
    }

    /** "Semaine 41 de 2026" */
    public function libelle(): string
    {
        return 'Semaine '.$this->numero().' de '.$this->annee();
    }

    /** "du 05/10/2026 au 11/10/2026" */
    public function periode(): string
    {
        return 'du '.$this->debut->format('d/m/Y').' au '.$this->dernierJour()->format('d/m/Y');
    }
}
