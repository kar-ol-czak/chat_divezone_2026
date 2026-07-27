<?php

declare(strict_types=1);

/**
 * Integration test EditorialPicksService (T-008, ADR-054).
 *
 * Wymaga: DATABASE_URL w .env (Railway PG, tabela divechat_editorial_picks).
 * Operuje na własnych test rekordach (product_id w zakresie 99999000+) — cleanup na końcu.
 *
 * Uruchomienie: php standalone/tests/Editorial/EditorialPicksServiceTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;

// Load .env
$envFile = dirname(__DIR__, 3) . '/.env';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    if (!isset($_ENV[trim($k)])) $_ENV[trim($k)] = trim($v);
}

$db = PostgresConnection::getInstance();
$service = new EditorialPicksService($db);

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

// Pre-cleanup test records
$db->query("DELETE FROM divechat_editorial_picks WHERE product_id BETWEEN 99999000 AND 99999999");

// === 1. INSERT pick + getActiveBoosts zwraca boost ===
$pick1 = $service->add(99999001, 'Test Product 1', null, 1.8, 'Test 1', 'test_user', 30);
assertTest('INSERT pick zwraca rekord z id', isset($pick1['id']) && $pick1['id'] > 0);
$boosts = $service->getActiveBoosts([99999001], null);
assertTest(
    'getActiveBoosts([99999001]) zwraca boost 1.8',
    isset($boosts[99999001]) && abs($boosts[99999001] - 1.8) < 0.01,
    'got: ' . json_encode($boosts),
);

// === 2. Pick z expires_at w przeszłości → getActiveBoosts pusty ===
// Wstawiamy pick z TTL=1, potem ręcznie zmieniamy expires_at na przeszłość
$pick2 = $service->add(99999002, 'Test Past Expire', null, 2.0, 'Test 2', 'test_user', 1);
$db->query(
    "UPDATE divechat_editorial_picks SET expires_at = NOW() - INTERVAL '1 day' WHERE id = ?",
    [$pick2['id']],
);
$boosts2 = $service->getActiveBoosts([99999002], null);
assertTest(
    'pick z expires_at < NOW() nie jest aktywny',
    !isset($boosts2[99999002]),
    'got: ' . json_encode($boosts2),
);

// === 3. expireDue() dezaktywuje wygasłe ===
$beforeActive = (int) $db->fetchOne(
    "SELECT count(*) AS c FROM divechat_editorial_picks WHERE id = ? AND active = TRUE",
    [$pick2['id']],
)['c'];
$expired = $service->expireDue();
assertTest(
    'expireDue() zwraca >=1 zdeaktywowany',
    $expired >= 1,
    "got: {$expired}",
);
$afterActive = (int) $db->fetchOne(
    "SELECT count(*) AS c FROM divechat_editorial_picks WHERE id = ? AND active = FALSE",
    [$pick2['id']],
)['c'];
assertTest(
    'pick z expired ma active=FALSE po expireDue()',
    $beforeActive === 1 && $afterActive === 1,
);

// === 4. 2 picki same product_id, różne category_hint → oba istnieją ===
$service->add(99999003, 'Test Multi-Cat A', 'Skafandry suche', 1.5, 'Test 3a', 'test_user', null);
$service->add(99999003, 'Test Multi-Cat B', 'Pianki mokre', 1.7, 'Test 3b', 'test_user', null);
$multiCount = (int) $db->fetchOne(
    "SELECT count(*) AS c FROM divechat_editorial_picks WHERE product_id = 99999003 AND active = TRUE",
)['c'];
assertTest(
    '2 picki same product_id + różne category_hint → 2 wiersze',
    $multiCount === 2,
    "got: {$multiCount}",
);

// === 5. category_hint match ===
$boostsSuche = $service->getActiveBoosts([99999003], 'Skafandry suche');
$boostsMokre = $service->getActiveBoosts([99999003], 'Pianki mokre');
$boostsInne = $service->getActiveBoosts([99999003], 'Komputery Nurkowe');
assertTest(
    'category_hint="Skafandry suche" → boost 1.5',
    isset($boostsSuche[99999003]) && abs($boostsSuche[99999003] - 1.5) < 0.01,
    'got: ' . json_encode($boostsSuche),
);
assertTest(
    'category_hint="Pianki mokre" → boost 1.7',
    isset($boostsMokre[99999003]) && abs($boostsMokre[99999003] - 1.7) < 0.01,
    'got: ' . json_encode($boostsMokre),
);
assertTest(
    'category_hint mismatch → brak boost',
    !isset($boostsInne[99999003]),
    'got: ' . json_encode($boostsInne),
);

// === 6. UPSERT po (product_id, category_hint) — drugi add aktualizuje ===
$service->add(99999004, 'Upsert Test', 'Skafandry suche', 1.5, 'first', 'test_user', null);
$service->add(99999004, 'Upsert Test', 'Skafandry suche', 2.0, 'updated', 'test_user', null);
$upserted = $db->fetchOne(
    "SELECT boost_factor, reason FROM divechat_editorial_picks WHERE product_id = 99999004 AND category_hint = 'Skafandry suche'"
);
assertTest(
    'UPSERT zmienia boost_factor z 1.5 na 2.0',
    abs((float) $upserted['boost_factor'] - 2.0) < 0.01,
    'got: ' . json_encode($upserted),
);
assertTest(
    'UPSERT zmienia reason na "updated"',
    $upserted['reason'] === 'updated',
);

// === 7. NULL boost (default) → wyższy wygrywa ===
// Wstawiamy pick z category_hint=NULL (boost wszystkim) + pick z konkretną kategorią o wyższym boost.
$service->add(99999005, 'Null Cat Test', null, 1.3, 'wszystkie kategorie', 'test_user', null);
$service->add(99999005, 'Null Cat Test', 'Skafandry suche', 1.9, 'konkretna kategoria', 'test_user', null);
$boostsConcrete = $service->getActiveBoosts([99999005], 'Skafandry suche');
assertTest(
    'pick z konkretną kategorią ma boost 1.9 dla matching category',
    isset($boostsConcrete[99999005]) && abs($boostsConcrete[99999005] - 1.9) < 0.01,
    'got: ' . json_encode($boostsConcrete),
);

// === 8. list() filter active ===
$listActive = $service->list(true);
$listInactive = $service->list(false);
assertTest(
    'list(active=true) zwraca tablicę',
    is_array($listActive) && count($listActive) >= 5,
    "got: " . count($listActive),
);

// === Cleanup ===
$db->query("DELETE FROM divechat_editorial_picks WHERE product_id BETWEEN 99999000 AND 99999999");

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
