<?php

declare(strict_types=1);

namespace DiveChat\Shop;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Kalendarz pracy sklepu divezone.pl.
 * Pon-pt 9:00-17:00, polskie święta stałe + ruchome, override z bazy (urlopy, inwentaryzacje).
 * Strefa czasowa: Europe/Warsaw.
 */
final class ShopCalendar
{
    public const TIMEZONE = 'Europe/Warsaw';
    public const DEFAULT_OPENS_AT = '09:00';
    public const DEFAULT_CLOSES_AT = '17:00';

    private const FIXED_HOLIDAYS = [
        '01-01' => 'Nowy Rok',
        '01-06' => 'Trzech Króli',
        '05-01' => 'Święto Pracy',
        '05-03' => 'Święto Konstytucji 3 Maja',
        '08-15' => 'Wniebowzięcie Najświętszej Maryi Panny',
        '11-01' => 'Wszystkich Świętych',
        '11-11' => 'Narodowe Święto Niepodległości',
        '12-24' => 'Wigilia Bożego Narodzenia',
        '12-25' => 'Boże Narodzenie',
        '12-26' => 'Drugi Dzień Bożego Narodzenia',
    ];

    /** @var array<int, array<string, string>> Cache świąt ruchomych per rok (md => name) */
    private static array $movingHolidaysCache = [];

    public function __construct(
        private readonly ?OverrideProvider $overrideProvider = null,
    ) {}

    public function isWorkingDay(DateTimeImmutable $date): bool
    {
        $override = $this->fetchOverride($date);
        if ($override !== null) {
            return $override['is_working_day'];
        }

        $dayOfWeek = (int) $date->format('N');
        if ($dayOfWeek >= 6) {
            return false;
        }

        return $this->holidayName($date) === null;
    }

    public function nextWorkingDay(DateTimeImmutable $date): DateTimeImmutable
    {
        $next = $date->modify('+1 day');
        for ($i = 0; $i < 30; $i++) {
            if ($this->isWorkingDay($next)) {
                return $next;
            }
            $next = $next->modify('+1 day');
        }

        throw new \RuntimeException('Nie znaleziono dnia roboczego w ciągu 30 dni od ' . $date->format('Y-m-d'));
    }

    public function currentlyOpen(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));

        if (!$this->isWorkingDay($now)) {
            return false;
        }

        $override = $this->fetchOverride($now);
        $opensAt = $override['opens_at'] ?? self::DEFAULT_OPENS_AT;
        $closesAt = $override['closes_at'] ?? self::DEFAULT_CLOSES_AT;

        $currentTime = $now->format('H:i');
        return $currentTime >= $opensAt && $currentTime < $closesAt;
    }

    public function holidayName(DateTimeImmutable $date): ?string
    {
        $md = $date->format('m-d');
        if (isset(self::FIXED_HOLIDAYS[$md])) {
            return self::FIXED_HOLIDAYS[$md];
        }

        $movingHolidays = self::movingHolidaysForYear((int) $date->format('Y'));
        return $movingHolidays[$md] ?? null;
    }

    public function scheduleForDate(DateTimeImmutable $date): ScheduleResult
    {
        $override = $this->fetchOverride($date);
        $holidayName = $this->holidayName($date);
        $dayOfWeek = (int) $date->format('N');

        if ($override !== null) {
            $workingDay = $override['is_working_day'];
            $opensAt = $override['opens_at'] ?? self::DEFAULT_OPENS_AT;
            $closesAt = $override['closes_at'] ?? self::DEFAULT_CLOSES_AT;
            $closedReason = $workingDay ? null : $override['reason'];
        } elseif ($dayOfWeek >= 6) {
            $workingDay = false;
            $opensAt = self::DEFAULT_OPENS_AT;
            $closesAt = self::DEFAULT_CLOSES_AT;
            $closedReason = $dayOfWeek === 6 ? 'weekend (sobota)' : 'weekend (niedziela)';
        } elseif ($holidayName !== null) {
            $workingDay = false;
            $opensAt = self::DEFAULT_OPENS_AT;
            $closesAt = self::DEFAULT_CLOSES_AT;
            $closedReason = 'święto: ' . $holidayName;
        } else {
            $workingDay = true;
            $opensAt = self::DEFAULT_OPENS_AT;
            $closesAt = self::DEFAULT_CLOSES_AT;
            $closedReason = null;
        }

        $isOpen = false;
        if ($workingDay) {
            $now = new DateTimeImmutable('now', new DateTimeZone(self::TIMEZONE));
            if ($now->format('Y-m-d') === $date->format('Y-m-d')) {
                $currentTime = $now->format('H:i');
                $isOpen = $currentTime >= $opensAt && $currentTime < $closesAt;
            }
        }

        return new ScheduleResult(
            isOpen: $isOpen,
            workingDay: $workingDay,
            holidayName: $holidayName,
            opensAt: $opensAt,
            closesAt: $closesAt,
            closedReason: $closedReason,
        );
    }

    /**
     * @return array{is_working_day: bool, reason: string, opens_at: ?string, closes_at: ?string}|null
     */
    private function fetchOverride(DateTimeImmutable $date): ?array
    {
        return $this->overrideProvider?->findByDate($date);
    }

    /**
     * Święta ruchome dla danego roku: Niedziela Wielkanocna, Poniedziałek Wielkanocny,
     * Zielone Świątki (Wielkanoc + 49), Boże Ciało (Wielkanoc + 60).
     *
     * @return array<string, string> md => name
     */
    private static function movingHolidaysForYear(int $year): array
    {
        if (isset(self::$movingHolidaysCache[$year])) {
            return self::$movingHolidaysCache[$year];
        }

        $easter = self::easterSunday($year);
        $easterMonday = $easter->modify('+1 day');
        $pentecost = $easter->modify('+49 days');
        $corpusChristi = $easter->modify('+60 days');

        $result = [
            $easter->format('m-d') => 'Niedziela Wielkanocna',
            $easterMonday->format('m-d') => 'Poniedziałek Wielkanocny',
            $pentecost->format('m-d') => 'Zielone Świątki',
            $corpusChristi->format('m-d') => 'Boże Ciało',
        ];

        return self::$movingHolidaysCache[$year] = $result;
    }

    /**
     * Niedziela Wielkanocna metodą Gaussa (algorytm Anonymous Gregorian / Meeus-Jones-Butcher).
     * Niezależny od ext-calendar — działa wszędzie, deterministyczny.
     */
    private static function easterSunday(int $year): DateTimeImmutable
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
        $L = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $L, 451);
        $month = intdiv($h + $L - 7 * $m + 114, 31);
        $day = (($h + $L - 7 * $m + 114) % 31) + 1;

        return new DateTimeImmutable(
            sprintf('%04d-%02d-%02d', $year, $month, $day),
            new DateTimeZone(self::TIMEZONE),
        );
    }
}
