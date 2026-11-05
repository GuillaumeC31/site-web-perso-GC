<?php

declare(strict_types=1);

namespace App\Service\Dashboard;

/**
 * Heures ouvrées : jours ouvrés du lundi au vendredi, hors jours fériés français (métropole), comptés
 * heure par heure dans le fuseau de Paris. Sert au délai de réponse aux demandes d'accès (« sous 24 h
 * ouvrées ») : une demande arrivée le vendredi à 18 h est en retard le mardi à 18 h, pas le samedi.
 *
 * Jours fériés : 1er janvier, lundi de Pâques, 1er mai, 8 mai, Ascension, lundi de Pentecôte, 14 juillet,
 * 15 août, 1er novembre, 11 novembre, 25 décembre. Pâques est calculé ici (algorithme grégorien anonyme),
 * sans dépendre de l'extension PHP calendar.
 *
 * Liens :
 * - Utilisé par : DashboardMetrics (tuile Recruteurs, demande la plus ancienne)
 * - Testé par : tests/Service/Dashboard/BusinessHoursTest
 */
final class BusinessHours
{
    /** Fuseau des jours ouvrés. */
    private const TIMEZONE = 'Europe/Paris';

    /**
     * Compte les heures ouvrées écoulées entre deux instants (0 si $to précède $from).
     *
     * Liens :
     * - Appelée par : DashboardMetrics::recruiters()
     *
     * Exemple : du vendredi 18 h au lundi 18 h (hors fériés) → 24.
     *
     * @param \DateTimeImmutable $from début (ex. date de la demande)
     * @param \DateTimeImmutable $to   fin (ex. maintenant)
     *
     * @return float heures ouvrées écoulées
     */
    public function between(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $from = $from->setTimezone($timezone);
        $to = $to->setTimezone($timezone);
        if ($to <= $from) {
            return 0.0;
        }

        $seconds = 0;
        $dayStart = $from->setTime(0, 0);
        while ($dayStart < $to) {
            $dayEnd = $dayStart->modify('+1 day');
            if ($this->isBusinessDay($dayStart)) {
                $start = max($from, $dayStart);
                $end = min($to, $dayEnd);
                $seconds += max(0, $end->getTimestamp() - $start->getTimestamp());
            }
            $dayStart = $dayEnd;
        }

        return $seconds / 3600;
    }

    /**
     * Indique si un jour est ouvré : du lundi au vendredi, et pas férié.
     *
     * @param \DateTimeImmutable $day jour à tester (l'heure est ignorée)
     */
    public function isBusinessDay(\DateTimeImmutable $day): bool
    {
        $day = $day->setTimezone(new \DateTimeZone(self::TIMEZONE));

        return (int) $day->format('N') <= 5 && !\in_array($day->format('m-d'), $this->getHolidays((int) $day->format('Y')), true);
    }

    /**
     * Renvoie les jours fériés français d'une année (métropole).
     *
     * @param int $year année (ex. 2026)
     *
     * @return list<string> jours au format « mm-jj »
     */
    public function getHolidays(int $year): array
    {
        $easter = $this->getEasterSunday($year);

        return [
            '01-01',
            $easter->modify('+1 day')->format('m-d'),   // lundi de Pâques
            '05-01',
            '05-08',
            $easter->modify('+39 days')->format('m-d'), // Ascension
            $easter->modify('+50 days')->format('m-d'), // lundi de Pentecôte
            '07-14',
            '08-15',
            '11-01',
            '11-11',
            '12-25',
        ];
    }

    /**
     * Calcule le dimanche de Pâques (calendrier grégorien, algorithme anonyme de Meeus-Jones-Butcher).
     *
     * @param int $year année
     *
     * @return \DateTimeImmutable dimanche de Pâques à minuit, fuseau de Paris
     */
    public function getEasterSunday(int $year): \DateTimeImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return new \DateTimeImmutable(\sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new \DateTimeZone(self::TIMEZONE));
    }
}
