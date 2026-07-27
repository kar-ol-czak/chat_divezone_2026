<?php

declare(strict_types=1);

/**
 * Test detekcji + sanitizacji DbHealthAlert (CHAT-T-079, ADR-088).
 *
 * Skupiamy sie na pure-function classifyConnectionFailure() (public static) —
 * weryfikuje, ze:
 *  - MySQL 1045/2002/2003/2006/2013 -> target=mysql z poprawnym driver_code,
 *  - PG SQLSTATE 08* + 57P01/57P03 -> target=pgsql,
 *  - bledy logiczne (42703/42P01/syntax) + nie-PDO Throwable -> null (NIE alert),
 *  - sanitizeMessage redaguje password=/pwd= i ucina do 240 znakow,
 *  - error_excerpt po sanitize NIE zawiera oczywistego hasla.
 *
 * NIE testujemy tu dedup INSERT-em ani mail() — wymagaloby zywej PG + przechwytu
 * mail() (PHPUnit-less harness w projekcie). Dedup zweryfikuje smoke po deployu
 * (ON CONFLICT to sprawdzona scieszka 1:1 z CostGuard).
 *
 * Uruchomienie: php standalone/tests/DbHealthAlertTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use DiveChat\Usage\DbHealthAlert;

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[OK] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

/**
 * Buduje PDOException z konkretnym SQLSTATE (Exception::$code) i driver code
 * w errorInfo[1] — czyli tak, jak PDO ustawia po realnym bledzie polaczenia.
 */
function makePdoEx(string $sqlstate, ?int $driverCode, string $msg = ''): \PDOException
{
    $e = new \PDOException($msg !== '' ? $msg : "SQLSTATE[{$sqlstate}]");

    // Exception::$code jest protected — Reflection. Od PHP 8.1 setAccessible
    // jest no-opem na ReflectionProperty (deprecated od 8.5) — pomijamy.
    $codeProp = new \ReflectionProperty(\Exception::class, 'code');
    $codeProp->setValue($e, $sqlstate);

    // errorInfo jest public na PDOException (?array).
    if ($driverCode !== null) {
        $e->errorInfo = [$sqlstate, $driverCode, $msg];
    }
    return $e;
}

echo "\n=== classifyConnectionFailure: MySQL connection codes -> target=mysql ===\n";
foreach ([1045, 2002, 2003, 2006, 2013] as $code) {
    $e = makePdoEx('HY000', $code, "test driver code {$code}");
    $r = DbHealthAlert::classifyConnectionFailure($e);
    assertTest(
        "MySQL driver {$code} -> mysql",
        $r !== null && $r['target'] === 'mysql' && $r['driver_code'] === $code,
        'r=' . json_encode($r),
    );
}

// 1045 ma SQLSTATE 28000 w realu — sprawdzmy ze tez sie lapie.
$e = makePdoEx('28000', 1045, "Access denied for user 'x'@'localhost'");
$r = DbHealthAlert::classifyConnectionFailure($e);
assertTest('MySQL 1045 z SQLSTATE 28000 -> mysql', $r !== null && $r['target'] === 'mysql' && $r['driver_code'] === 1045);

echo "\n=== classifyConnectionFailure: PG connection SQLSTATEs -> target=pgsql ===\n";
foreach (['08000', '08003', '08006', '08001', '08004'] as $ss) {
    $e = makePdoEx($ss, null, "test PG {$ss}");
    $r = DbHealthAlert::classifyConnectionFailure($e);
    assertTest(
        "PG SQLSTATE {$ss} -> pgsql",
        $r !== null && $r['target'] === 'pgsql' && $r['sqlstate'] === $ss,
        'r=' . json_encode($r),
    );
}
foreach (['57P01', '57P03'] as $ss) {
    $e = makePdoEx($ss, null);
    $r = DbHealthAlert::classifyConnectionFailure($e);
    assertTest(
        "PG SQLSTATE {$ss} -> pgsql",
        $r !== null && $r['target'] === 'pgsql' && $r['sqlstate'] === $ss,
    );
}

echo "\n=== classifyConnectionFailure: bledy logiczne i nie-PDO -> NULL (brak alertu) ===\n";
// Uwaga: numeryczne klucze stringow (np. '42703') PHP automatycznie castuje na int
// w tablicach asocjacyjnych — uzywamy listy par zeby zachowac string SQLSTATE.
$logicalErrors = [
    ['42703', 'undefined_column (bug w kodzie/migracji, NIE infra)'],
    ['42P01', 'undefined_table'],
    ['42601', 'syntax_error'],
    ['23505', 'unique_violation'],
    ['22001', 'string_data_right_truncation'],
];
foreach ($logicalErrors as [$ss, $desc]) {
    $e = makePdoEx($ss, null);
    $r = DbHealthAlert::classifyConnectionFailure($e);
    assertTest("PG SQLSTATE {$ss} ({$desc}) -> null", $r === null, 'r=' . json_encode($r));
}

// Nie-PDO Throwable -> null.
$e = new \RuntimeException('zwykly runtime');
assertTest('RuntimeException -> null', DbHealthAlert::classifyConnectionFailure($e) === null);

$e = new \LogicException('logika');
assertTest('LogicException -> null', DbHealthAlert::classifyConnectionFailure($e) === null);

$e = new \InvalidArgumentException('arg');
assertTest('InvalidArgumentException -> null', DbHealthAlert::classifyConnectionFailure($e) === null);

// PDOException z code spoza connection codes (np. brak rekordu, ale tylko dla kompletu)
$e = makePdoEx('HY000', 1062, 'duplicate entry');
$r = DbHealthAlert::classifyConnectionFailure($e);
assertTest('MySQL 1062 (duplicate entry) -> null', $r === null, 'r=' . json_encode($r));

echo "\n=== isConnectionFailure wrapper ===\n";
assertTest(
    'isConnectionFailure(mysql 1045) == true',
    DbHealthAlert::isConnectionFailure(makePdoEx('28000', 1045)) === true,
);
assertTest(
    'isConnectionFailure(pg 08006) == true',
    DbHealthAlert::isConnectionFailure(makePdoEx('08006', null)) === true,
);
assertTest(
    'isConnectionFailure(pg 42703) == false',
    DbHealthAlert::isConnectionFailure(makePdoEx('42703', null)) === false,
);
assertTest(
    'isConnectionFailure(RuntimeException) == false',
    DbHealthAlert::isConnectionFailure(new \RuntimeException('x')) === false,
);

echo "\n=== sanitizeMessage: brak hasel/DSN w excerpt ===\n";

$raw1 = "SQLSTATE[HY000] [1045] Access denied for user 'divezone_sklep_tmp2'@'localhost' (using password: YES)";
$clean1 = DbHealthAlert::sanitizeMessage($raw1);
assertTest('1045 message excerpt zachowuje SQLSTATE+1045', str_contains($clean1, '1045'));
assertTest('1045 message excerpt < 240 znakow', mb_strlen($clean1) <= 240);

$raw2 = "SQLSTATE[HY000] connection failed password=SuperSekret123 host=...";
$clean2 = DbHealthAlert::sanitizeMessage($raw2);
assertTest('password=... zostalo zredaktowane', !str_contains($clean2, 'SuperSekret123'), 'clean=' . $clean2);
assertTest('password=[REDACTED] obecny', str_contains($clean2, '[REDACTED]'));

$raw3 = 'PDO: connection error pwd="SecretPass!@#" port=14368';
$clean3 = DbHealthAlert::sanitizeMessage($raw3);
assertTest('pwd="..." zostalo zredaktowane', !str_contains($clean3, 'SecretPass'), 'clean=' . $clean3);

$rawLong = str_repeat('A', 500);
$cleanLong = DbHealthAlert::sanitizeMessage($rawLong);
assertTest('Truncate do 240 znakow', mb_strlen($cleanLong) === 240);

echo "\n=== Wyniki: passed={$passed} failed={$failed} ===\n";
exit($failed > 0 ? 1 : 0);
