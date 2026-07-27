<?php

declare(strict_types=1);

/**
 * Testy jednostkowe ShopCalendar.
 * Override'y testujemy przez fake PostgresConnection (in-process, bez DB).
 *
 * Uruchomienie: php standalone/tests/Shop/ShopCalendarTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Shop\OverrideProvider;
use DiveChat\Shop\ShopCalendar;

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        echo "[OK] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

function d(string $ymd): DateTimeImmutable
{
    return new DateTimeImmutable($ymd, new DateTimeZone(ShopCalendar::TIMEZONE));
}

// In-memory OverrideProvider — testuje override'y bez DB.
$fakeOverrides = [];

$fakeProvider = new class($fakeOverrides) implements OverrideProvider {
    public function __construct(private array &$overrides) {}
    public function findByDate(DateTimeImmutable $date): ?array {
        $ymd = $date->format('Y-m-d');
        if (!isset($this->overrides[$ymd])) {
            return null;
        }
        $o = $this->overrides[$ymd];
        return [
            'is_working_day' => $o['is_working_day'],
            'reason' => $o['reason'],
            'opens_at' => $o['opens_at'] ?? null,
            'closes_at' => $o['closes_at'] ?? null,
        ];
    }
};

$calendar = new ShopCalendar();
$calendarWithDb = new ShopCalendar($fakeProvider);

// === 1. Zwykły dzień roboczy ===
$friday = d('2026-05-15'); // piątek
assertTest(
    'piątek 2026-05-15 jest dniem roboczym',
    $calendar->isWorkingDay($friday) === true,
);

// === 2. Weekend ===
$saturday = d('2026-06-06');
$sunday = d('2026-06-07');
assertTest('sobota 2026-06-06 nie jest dniem roboczym', $calendar->isWorkingDay($saturday) === false);
assertTest('niedziela 2026-06-07 nie jest dniem roboczym', $calendar->isWorkingDay($sunday) === false);

// === 3. Święto stałe ===
$mayDay = d('2026-05-01'); // Święto Pracy
assertTest('Święto Pracy 2026-05-01 nie jest dniem roboczym', $calendar->isWorkingDay($mayDay) === false);
assertTest(
    'holidayName(2026-05-01) = Święto Pracy',
    $calendar->holidayName($mayDay) === 'Święto Pracy',
);

// === 4. Święto ruchome — Poniedziałek Wielkanocny 2026 (6 kwietnia) ===
$easterMonday2026 = d('2026-04-06');
assertTest(
    'Poniedziałek Wielkanocny 2026-04-06 nie jest dniem roboczym',
    $calendar->isWorkingDay($easterMonday2026) === false,
);
assertTest(
    'holidayName(2026-04-06) = Poniedziałek Wielkanocny',
    $calendar->holidayName($easterMonday2026) === 'Poniedziałek Wielkanocny',
);

// === 5. Override pracujący w weekend ===
$fakeOverrides['2026-06-13'] = [
    'is_working_day' => true,
    'reason' => 'Odpracowanie dnia 8 czerwca',
    'opens_at' => '10:00',
    'closes_at' => '14:00',
];
$workSaturday = d('2026-06-13'); // sobota z override pracującym
assertTest(
    'override pracujący w sobotę zmienia isWorkingDay na true',
    $calendarWithDb->isWorkingDay($workSaturday) === true,
);
$schedule = $calendarWithDb->scheduleForDate($workSaturday);
assertTest(
    'override pracujący przekazuje opens_at/closes_at',
    $schedule->workingDay === true && $schedule->opensAt === '10:00' && $schedule->closesAt === '14:00',
    "got opensAt={$schedule->opensAt}, closesAt={$schedule->closesAt}",
);

// === 6. Override zamknięty w piątek (urlop firmowy) ===
$fakeOverrides['2026-12-31'] = [
    'is_working_day' => false,
    'reason' => 'Urlop firmowy między świętami',
];
$closedFriday = d('2026-12-31'); // czwartek — celowo
assertTest(
    'override zamknięty zmienia isWorkingDay na false (nawet w dzień roboczy)',
    $calendarWithDb->isWorkingDay($closedFriday) === false,
);
$schedule = $calendarWithDb->scheduleForDate($closedFriday);
assertTest(
    'override zamknięty raportuje reason w closedReason',
    $schedule->workingDay === false && $schedule->closedReason === 'Urlop firmowy między świętami',
    "got closedReason={$schedule->closedReason}",
);

// === 7-8. Godziny przed otwarciem / po zamknięciu (currentlyOpen z wstrzykniętą datą) ===
$morningFriday = new DateTimeImmutable('2026-05-15 08:30:00', new DateTimeZone(ShopCalendar::TIMEZONE));
$eveningFriday = new DateTimeImmutable('2026-05-15 18:00:00', new DateTimeZone(ShopCalendar::TIMEZONE));
$middayFriday = new DateTimeImmutable('2026-05-15 12:00:00', new DateTimeZone(ShopCalendar::TIMEZONE));
assertTest('08:30 piątek — zamknięte (przed 9:00)', $calendar->currentlyOpen($morningFriday) === false);
assertTest('18:00 piątek — zamknięte (po 17:00)', $calendar->currentlyOpen($eveningFriday) === false);
assertTest('12:00 piątek — otwarte', $calendar->currentlyOpen($middayFriday) === true);

// === 9. nextWorkingDay przeskakuje weekend ===
$fridayBeforeWeekend = d('2026-06-05'); // piątek
$next = $calendar->nextWorkingDay($fridayBeforeWeekend);
assertTest(
    'nextWorkingDay(piątek 2026-06-05) przeskakuje weekend → poniedziałek 2026-06-08',
    $next->format('Y-m-d') === '2026-06-08',
    "got {$next->format('Y-m-d')}",
);

// nextWorkingDay od Bożego Ciała (czw 2026-06-04) → piątek 5 czerwca (zwykły dzień roboczy)
$next = $calendar->nextWorkingDay(d('2026-06-04'));
assertTest(
    'nextWorkingDay(Boże Ciało czw) → piątek 2026-06-05 (zwykły dzień roboczy)',
    $next->format('Y-m-d') === '2026-06-05',
    "got {$next->format('Y-m-d')}",
);

// === 10. scheduleForDate dla weekendu zwraca poprawny closed_reason ===
$schedule = $calendar->scheduleForDate($saturday);
assertTest(
    'scheduleForDate(sobota) → closedReason="weekend (sobota)"',
    $schedule->closedReason === 'weekend (sobota)',
    "got {$schedule->closedReason}",
);
$schedule = $calendar->scheduleForDate($sunday);
assertTest(
    'scheduleForDate(niedziela) → closedReason="weekend (niedziela)"',
    $schedule->closedReason === 'weekend (niedziela)',
    "got {$schedule->closedReason}",
);

// === 11. Weryfikacja dat Wielkanocy 2026-2030 (algorytm Gaussa) ===
$easterExpected = [
    2026 => '04-05', // 5 kwietnia
    2027 => '03-28', // 28 marca
    2028 => '04-16', // 16 kwietnia
    2029 => '04-01', // 1 kwietnia
    2030 => '04-21', // 21 kwietnia
];
foreach ($easterExpected as $year => $expectedMd) {
    $easterMonday = d("{$year}-" . str_replace('-', '-', $expectedMd))->modify('+1 day');
    $name = $calendar->holidayName($easterMonday);
    assertTest(
        "Wielkanoc {$year} = {$expectedMd} (Poniedziałek Wielkanocny dzień później)",
        $name === 'Poniedziałek Wielkanocny',
        "holidayName(Easter+1) = " . ($name ?? 'null'),
    );
}

// === 12. Boże Ciało 2026 = 4 czerwca (Wielkanoc + 60 dni) ===
$corpusChristi2026 = d('2026-06-04');
assertTest(
    'holidayName(2026-06-04) = Boże Ciało',
    $calendar->holidayName($corpusChristi2026) === 'Boże Ciało',
);

// === Summary ===
echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
