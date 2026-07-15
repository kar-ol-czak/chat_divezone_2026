<?php

declare(strict_types=1);

/**
 * Test jednostkowy budowy URL produktu PL (CHAT-T-139, ADR-121).
 * Czysta logika — bez MySQL (buildProductUrl wydzielona z enrich()).
 * Sedno karty 18: pusty/NULL slug -> null, NIGDY gola domena, NIGDY /.html.
 *
 * Uruchomienie: php standalone/tests/Shop/ProductUrlTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Shop\MysqlProductEnrichmentService;

$passed = 0;
$failed = 0;

function assertUrl(string $name, ?string $actual, ?string $expected): void
{
    global $passed, $failed;
    if ($actual === $expected) {
        echo "[OK] {$name}\n";
        $passed++;
    } else {
        $a = $actual === null ? 'null' : "'{$actual}'";
        $e = $expected === null ? 'null' : "'{$expected}'";
        echo "[FAIL] {$name} — got {$a}, expected {$e}\n";
        $failed++;
    }
}

function assertBool(string $name, bool $cond): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[OK] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}\n";
        $failed++;
    }
}

// === Test 1: slug normalny -> pelny URL .html ===
assertUrl(
    'slug normalny → pelny .html',
    MysqlProductEnrichmentService::buildProductUrl('automat-scubapro-mk25-evo'),
    'https://divezone.pl/automat-scubapro-mk25-evo.html',
);

// === Test 2: slug '' -> null (NIE gola domena) ===
assertUrl("slug '' → null", MysqlProductEnrichmentService::buildProductUrl(''), null);

// === Test 3: slug null -> null ===
assertUrl('slug null → null', MysqlProductEnrichmentService::buildProductUrl(null), null);

// === Test 4: zaden przypadek nie zwraca golej domeny ani /.html ===
$cases = ['automat-scubapro-mk25-evo', '', null];
$forbidden = ['https://divezone.pl', 'https://divezone.pl/', 'https://divezone.pl/.html'];
foreach ($cases as $i => $slug) {
    $url = MysqlProductEnrichmentService::buildProductUrl($slug);
    assertBool(
        "case {$i}: wynik nie jest gola domena ani /.html",
        $url === null || !in_array($url, $forbidden, true),
    );
}

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
