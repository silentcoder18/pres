<?php

namespace Tests\Feature\Finance;

use App\Services\Finance\SemaineComptable;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Semaine comptable : du lundi 00:00 au lundi suivant 00:00, dans le fuseau du reseau (RG-14). */
class SemaineComptableTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pressing.fuseau' => 'Africa/Douala']);
    }

    public function test_une_semaine_va_du_lundi_minuit_au_lundi_suivant_dans_le_fuseau_du_reseau(): void
    {
        $semaine = SemaineComptable::depuisIso('2026-W41');

        $this->assertSame('2026-10-05T00:00:00+01:00', $semaine->debut()->toIso8601String());
        $this->assertSame('2026-10-12T00:00:00+01:00', $semaine->fin()->toIso8601String());
        // Douala est en avance d'une heure : en UTC la semaine commence le dimanche a 23:00
        $this->assertSame('2026-10-04 23:00:00', $semaine->debutUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11 23:00:00', $semaine->finUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-11', $semaine->dernierJour()->format('Y-m-d'));
        $this->assertSame(
            ['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11'],
            array_map(fn (CarbonImmutable $jour) => $jour->format('Y-m-d'), $semaine->jours()),
        );

        $this->assertSame('2026-W41', $semaine->iso());
        $this->assertSame('2026-S41', $semaine->reference());
        $this->assertSame('Semaine 41 de 2026', $semaine->libelle());
        $this->assertSame('du 05/10/2026 au 11/10/2026', $semaine->periode());
        $this->assertSame('2026-W40', $semaine->precedente()->iso());
        $this->assertSame('2026-W42', $semaine->suivante()->iso());
    }

    public function test_un_instant_appartient_a_la_semaine_du_reseau_pas_a_celle_d_utc(): void
    {
        // Dimanche 23:30 et lundi 00:30 a Douala : le meme dimanche en UTC, deux semaines ici
        $dimancheSoir = CarbonImmutable::parse('2026-10-11 22:30:00', 'UTC');
        $lundiMatin = CarbonImmutable::parse('2026-10-11 23:30:00', 'UTC');

        $this->assertSame('2026-W41', SemaineComptable::contenant($dimancheSoir)->iso());
        $this->assertSame('2026-W42', SemaineComptable::contenant($lundiMatin)->iso());
        $this->assertSame('2026-10-11', SemaineComptable::jourDe($dimancheSoir));
        $this->assertSame('2026-10-12', SemaineComptable::jourDe($lundiMatin));

        $semaine = SemaineComptable::depuisIso('2026-W41');
        $this->assertTrue($semaine->contient(CarbonImmutable::parse('2026-10-04 23:00:00', 'UTC')), 'lundi 00:00 : debut inclus');
        $this->assertFalse($semaine->contient(CarbonImmutable::parse('2026-10-04 22:59:59', 'UTC')));
        $this->assertTrue($semaine->contient(CarbonImmutable::parse('2026-10-11 22:59:59', 'UTC')));
        $this->assertFalse($semaine->contient(CarbonImmutable::parse('2026-10-11 23:00:00', 'UTC')), 'lundi suivant 00:00 : fin exclue');
    }

    public function test_le_fuseau_du_reseau_est_celui_de_la_configuration(): void
    {
        config(['pressing.fuseau' => 'UTC']);

        $lundiMatinADouala = CarbonImmutable::parse('2026-10-11 23:30:00', 'UTC');

        $this->assertSame('2026-W41', SemaineComptable::contenant($lundiMatinADouala)->iso());
        $this->assertSame('2026-10-05 00:00:00', SemaineComptable::depuisIso('2026-W41')->debutUtc()->format('Y-m-d H:i:s'));
    }

    public function test_la_semaine_suit_la_numerotation_iso_au_changement_d_annee(): void
    {
        // 2026 compte 53 semaines : le jeudi 31 decembre et le dimanche 3 janvier sont en 2026-W53
        $this->assertSame('2026-W53', SemaineComptable::contenant(CarbonImmutable::parse('2026-12-31 12:00', 'Africa/Douala'))->iso());
        $this->assertSame('2026-W53', SemaineComptable::contenant(CarbonImmutable::parse('2027-01-03 12:00', 'Africa/Douala'))->iso());
        $this->assertSame('2027-W01', SemaineComptable::contenant(CarbonImmutable::parse('2027-01-04 12:00', 'Africa/Douala'))->iso());
        $this->assertSame('2026-12-28', SemaineComptable::depuisIso('2026-W53')->debut()->format('Y-m-d'));
        $this->assertSame('2027-W01', SemaineComptable::depuisIso('2026-W53')->suivante()->iso());
    }

    public function test_la_semaine_en_cours_et_les_semaines_a_venir_se_reconnaissent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'Africa/Douala'));

        $this->assertSame('2026-W41', SemaineComptable::courante()->iso());
        $this->assertTrue(SemaineComptable::depuisIso('2026-W41')->estEnCours());
        $this->assertFalse(SemaineComptable::depuisIso('2026-W41')->estAVenir());
        $this->assertFalse(SemaineComptable::depuisIso('2026-W40')->estEnCours());
        $this->assertTrue(SemaineComptable::depuisIso('2026-W42')->estAVenir());
        $this->assertTrue(SemaineComptable::depuisIso('2026-W40')->estAvant(SemaineComptable::courante()));
        $this->assertTrue(SemaineComptable::depuisIso('2026-W41')->egale(SemaineComptable::courante()));
    }

    #[DataProvider('ecrituresRefusees')]
    public function test_une_semaine_mal_ecrite_ou_inexistante_est_refusee(string $code): void
    {
        $this->assertNull(SemaineComptable::depuisIso($code));
    }

    public static function ecrituresRefusees(): array
    {
        return [
            'vide' => [''],
            'sans W' => ['2026-41'],
            'w minuscule' => ['2026-w41'],
            'un seul chiffre' => ['2026-W4'],
            'semaine zero' => ['2026-W00'],
            'semaine 54' => ['2026-W54'],
            'semaine 53 d\'une annee qui n\'en a que 52' => ['2027-W53'],
            'une date' => ['2026-10-05'],
            'du texte' => ['cette-semaine'],
            'entouree d\'autre chose' => ['x2026-W41'],
            'suivie d\'un saut de ligne' => ["2026-W41\n"],
            'annee hors du calendrier du reseau' => ['1999-W10'],
        ];
    }
}
