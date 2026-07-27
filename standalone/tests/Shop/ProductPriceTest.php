<?php

declare(strict_types=1);

/**
 * Test jednostkowy kalkulacji ceny brutto (ADR-093: reduction_tax).
 * Czysta logika — bez MySQL (computeBruttoPrice wydzielona z enrich()).
 *
 * Uruchomienie: php standalone/tests/Shop/ProductPriceTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Shop\MysqlProductEnrichmentService;

$passed = 0;
$failed = 0;

function assertPrice(string $name, float $actual, float $expected): void
{
    global $passed, $failed;
    if (abs($actual - $expected) < 0.005) {
        echo "[OK] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name} — got {$actual}, expected {$expected}\n";
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

// === Test 1: ADR-093 — SUUNTO Eon Core (id 5463), rabat kwotowy BRUTTO ===
// netto 2226.83, VAT 23%, reduction=839, amount, reduction_tax=1.
// PrestaShop: 2739 − 839 = 1900. Stary bug liczył (2226.83−839)×1.23 = 1707.
$r = MysqlProductEnrichmentService::computeBruttoPrice(2226.83, 23.0, [
    'sp_price' => 0.0,
    'reduction' => 839.0,
    'reduction_type' => 'amount',
    'reduction_tax' => 1,
]);
assertPrice('5463: amount+reduction_tax=1 (brutto) → 1900.00', $r['price'], 1900.00);
assertPrice('5463: base_brutto = 2739.00', $r['base_brutto'], 2739.00);
assertBool('5463: has_promo=true', $r['has_promo'] === true);
assertBool('5463: price_before_discount widoczny (base > final)', $r['base_brutto'] > $r['price']);

// === Test 2 (regresja): rabat kwotowy NETTO — bez zmian zachowania ===
// netto 2226.83, VAT 23%, reduction=839, amount, reduction_tax=0.
// (2226.83 − 839) × 1.23 = 1387.83 × 1.23 = 1707.03.
$r = MysqlProductEnrichmentService::computeBruttoPrice(2226.83, 23.0, [
    'sp_price' => 0.0,
    'reduction' => 839.0,
    'reduction_type' => 'amount',
    'reduction_tax' => 0,
]);
assertPrice('regresja: amount+reduction_tax=0 (netto) → 1707.03', $r['price'], 1707.03);

// === Test 3 (regresja): rabat procentowy — niewrażliwy na reduction_tax ===
// netto 1000, VAT 23%, reduction=0.10 (10%). 1000×0.9×1.23 = 1107.00.
$r = MysqlProductEnrichmentService::computeBruttoPrice(1000.00, 23.0, [
    'sp_price' => 0.0,
    'reduction' => 0.10,
    'reduction_type' => 'percentage',
    'reduction_tax' => 1, // dla percentage nieistotne
]);
assertPrice('regresja: percentage 10% → 1107.00', $r['price'], 1107.00);

// === Test 4: brak promocji → cena katalogowa brutto ===
$r = MysqlProductEnrichmentService::computeBruttoPrice(1000.00, 23.0, null);
assertPrice('brak promo → 1230.00', $r['price'], 1230.00);
assertBool('brak promo → has_promo=false', $r['has_promo'] === false);

// === Test 5: sp_price nadpisuje bazę, brak rabatu (reduction=0) ===
// sp_price=800 netto → 800×1.23 = 984.00.
$r = MysqlProductEnrichmentService::computeBruttoPrice(1000.00, 23.0, [
    'sp_price' => 800.00,
    'reduction' => 0.0,
    'reduction_type' => 'amount',
    'reduction_tax' => 0,
]);
assertPrice('sp_price override → 984.00', $r['price'], 984.00);

// === Test 6: sp_price + rabat kwotowy BRUTTO ===
// base netto 800 → 984 brutto; reduction=84 brutto → 900.00.
$r = MysqlProductEnrichmentService::computeBruttoPrice(1000.00, 23.0, [
    'sp_price' => 800.00,
    'reduction' => 84.00,
    'reduction_type' => 'amount',
    'reduction_tax' => 1,
]);
assertPrice('sp_price + amount brutto → 900.00', $r['price'], 900.00);

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
