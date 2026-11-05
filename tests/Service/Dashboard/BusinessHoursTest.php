<?php

declare(strict_types=1);

namespace App\Tests\Service\Dashboard;

use App\Service\Dashboard\BusinessHours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Heures ouvrées : week-ends et jours fériés français exclus, Pâques calculé sans l'extension calendar.
 *
 * Liens :
 * - Couvre : App\Service\Dashboard\BusinessHours
 */
final class BusinessHoursTest extends TestCase
{
    /**
     * Vérifie le dimanche de Pâques sur des années connues.
     */
    public function testEasterSunday(): void
    {
        $hours = new BusinessHours();
        foreach (['2024' => '03-31', '2025' => '04-20', '2026' => '04-05', '2027' => '03-28', '2038' => '04-25'] as $year => $date) {
            self::assertSame($date, $hours->getEasterSunday((int) $year)->format('m-d'), 'Pâques '.$year);
        }
    }

    /**
     * Vérifie les jours fériés mobiles de 2026 (lundi de Pâques, Ascension, Pentecôte).
     */
    public function testHolidays2026(): void
    {
        $holidays = (new BusinessHours())->getHolidays(2026);
        self::assertContains('04-06', $holidays);
        self::assertContains('05-14', $holidays);
        self::assertContains('05-25', $holidays);
        self::assertCount(11, $holidays);
    }

    /**
     * Vérifie le décompte des heures ouvrées.
     */
    #[DataProvider('provideSpans')]
    public function testBetween(string $from, string $to, float $expected): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        self::assertEqualsWithDelta($expected, (new BusinessHours())->between(new \DateTimeImmutable($from, $paris), new \DateTimeImmutable($to, $paris)), 0.001);
    }

    /**
     * Intervalles : même jour, week-end, jour férié, ordre inversé.
     *
     * @return iterable<string, array{string, string, float}>
     */
    public static function provideSpans(): iterable
    {
        yield 'mardi 10 h → mercredi 10 h' => ['2026-10-20 10:00', '2026-10-21 10:00', 24.0];
        yield 'vendredi 18 h → lundi 18 h (week-end exclu)' => ['2026-10-23 18:00', '2026-10-26 18:00', 24.0];
        yield 'vendredi 18 h → samedi 18 h' => ['2026-10-23 18:00', '2026-10-24 18:00', 6.0];
        yield 'mardi 10 nov. 12 h → jeudi 12 nov. 12 h (11 novembre férié)' => ['2026-11-10 12:00', '2026-11-12 12:00', 24.0];
        yield 'jeudi de l\'Ascension entier' => ['2026-05-14 00:00', '2026-05-15 00:00', 0.0];
        yield 'fin avant le début' => ['2026-10-21 10:00', '2026-10-20 10:00', 0.0];
    }

    /**
     * Vérifie le passage par l'heure d'été (fuseau de Paris) : on compte le temps réel écoulé.
     */
    public function testDaylightSavingDay(): void
    {
        $paris = new \DateTimeZone('Europe/Paris');
        // Dimanche 25 octobre 2026, passage à l'heure d'hiver : jour non ouvré, lundi compte 24 h
        $hours = (new BusinessHours())->between(new \DateTimeImmutable('2026-10-24 00:00', $paris), new \DateTimeImmutable('2026-10-27 00:00', $paris));
        self::assertEqualsWithDelta(24.0, $hours, 0.001);
        self::assertFalse((new BusinessHours())->isBusinessDay(new \DateTimeImmutable('2026-12-25 12:00', $paris)));
        self::assertTrue((new BusinessHours())->isBusinessDay(new \DateTimeImmutable('2026-12-24 12:00', $paris)));
    }
}
