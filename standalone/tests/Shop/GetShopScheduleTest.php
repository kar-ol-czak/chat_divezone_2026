<?php

declare(strict_types=1);

/**
 * Integration test GetShopSchedule.
 * Wywołuje tool::execute() z testowym OverrideProvider (in-memory).
 *
 * Uruchomienie: php standalone/tests/Shop/GetShopScheduleTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Shop\OverrideProvider;
use DiveChat\Shop\ShopCalendar;
use DiveChat\Tools\GetShopSchedule;

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

$emptyProvider = new class implements OverrideProvider {
    public function findByDate(DateTimeImmutable $date): ?array { return null; }
};

$tool = new GetShopSchedule(new ShopCalendar($emptyProvider));

// Test 1: sobota 2026-06-06 → working_day=false, closed_reason="weekend (sobota)"
$result = $tool->execute(['date' => '2026-06-06']);
assertTest(
    'sobota 2026-06-06 → working_day=false',
    $result['working_day'] === false,
);
assertTest(
    'sobota 2026-06-06 → closed_reason="weekend (sobota)"',
    $result['closed_reason'] === 'weekend (sobota)',
    "got: " . ($result['closed_reason'] ?? 'null'),
);
assertTest(
    'sobota 2026-06-06 → opens_at=null, closes_at=null',
    $result['opens_at'] === null && $result['closes_at'] === null,
);
assertTest(
    'sobota 2026-06-06 → next_working_day=2026-06-08 (poniedziałek)',
    $result['next_working_day'] === '2026-06-08',
    "got: " . $result['next_working_day'],
);

// Test 2: Poniedziałek Wielkanocny 2026-04-06 → working_day=false, holiday_name=Poniedziałek Wielkanocny
$result = $tool->execute(['date' => '2026-04-06']);
assertTest(
    'Poniedziałek Wielkanocny 2026-04-06 → working_day=false',
    $result['working_day'] === false,
);
assertTest(
    'Poniedziałek Wielkanocny 2026-04-06 → holiday_name="Poniedziałek Wielkanocny"',
    $result['holiday_name'] === 'Poniedziałek Wielkanocny',
);
assertTest(
    'Poniedziałek Wielkanocny 2026-04-06 → closed_reason zaczyna się od "święto:"',
    str_starts_with((string) $result['closed_reason'], 'święto:'),
    "got: " . ($result['closed_reason'] ?? 'null'),
);

// Test 3: bez parametru date → użyj dzisiejszej daty
$result = $tool->execute([]);
$today = (new DateTimeImmutable('today', new DateTimeZone(ShopCalendar::TIMEZONE)))->format('Y-m-d');
assertTest(
    'bez parametru date → date=dzisiaj',
    $result['date'] === $today,
    "got: {$result['date']}, expected: {$today}",
);

// Test 4: dzień roboczy → opens_at=09:00, closes_at=17:00
$result = $tool->execute(['date' => '2026-05-15']); // piątek
assertTest(
    'piątek 2026-05-15 → working_day=true',
    $result['working_day'] === true,
);
assertTest(
    'piątek 2026-05-15 → opens_at=09:00, closes_at=17:00',
    $result['opens_at'] === '09:00' && $result['closes_at'] === '17:00',
    "got opens_at={$result['opens_at']}, closes_at={$result['closes_at']}",
);
assertTest(
    'piątek 2026-05-15 → holiday_name=null, closed_reason=null',
    $result['holiday_name'] === null && $result['closed_reason'] === null,
);

// Test 5: niepoprawny format daty → error
$result = $tool->execute(['date' => '06.06.2026']);
assertTest(
    'błędny format daty → zwraca pole error',
    isset($result['error']),
);

// Test 6: schema toola
assertTest(
    'getName() = get_shop_schedule',
    $tool->getName() === 'get_shop_schedule',
);
$schema = $tool->getParametersSchema();
assertTest(
    'getParametersSchema zawiera pole "date" w properties',
    isset($schema['properties']['date']),
);
assertTest(
    'pole "date" nie jest required',
    !in_array('date', $schema['required'] ?? [], true),
);

// === Summary ===
echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
