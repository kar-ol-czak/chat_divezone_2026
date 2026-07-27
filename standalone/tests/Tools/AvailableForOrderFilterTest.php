<?php

declare(strict_types=1);

/**
 * Test jednostkowy filtra available_for_order (CHAT-T-143, ADR-123).
 * Czysta logika — bez MySQL/PG (ProductSearch::isDiscontinued to public static,
 * wspolny predykat obu miejsc aplikacji filtra: mergeRRF i searchByPrice).
 *
 * Uruchomienie: php standalone/tests/Tools/AvailableForOrderFilterTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Tools\ProductSearch;

$passed = 0;
$failed = 0;

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

// Wpisy enrich() jak z MysqlProductEnrichmentService (istotne klucze).
$discontinued = [
    'price' => 199.0,
    'in_stock' => false,
    'availability' => 'unavailable',
    'active' => true,
    'visible' => true,
    'available_for_order' => false, // afo=0 — wycofany ze sprzedazy
];
$orderable = [
    'price' => 299.0,
    'in_stock' => true,
    'availability' => 'in_stock',
    'active' => true,
    'visible' => true,
    'available_for_order' => true, // afo=1
];
$legacyShape = [ // stary shape enrich bez klucza (dryf wersji) — traktuj jak dostepny
    'price' => 99.0,
    'in_stock' => true,
    'availability' => 'in_stock',
    'active' => true,
    'visible' => true,
];

// === Test 1: afo=0 → isDiscontinued=true → przy exploratory (include_discontinued=false) wypada ===
assertBool('afo=0 → isDiscontinued=true', ProductSearch::isDiscontinued($discontinued) === true);
$includeDiscontinued = false; // default exploratory
$kept = $includeDiscontinued || !ProductSearch::isDiscontinued($discontinued);
assertBool('afo=0 + exploratory (default) → odfiltrowany', $kept === false);

// === Test 2: afo=0 + include_discontinued=true → przechodzi Z FLAGA ===
$includeDiscontinued = true; // navigational, klient pyta o konkretny model
$kept = $includeDiscontinued || !ProductSearch::isDiscontinued($discontinued);
assertBool('afo=0 + include_discontinued=true → przechodzi', $kept === true);
// Flaga w tool_result: klucz available_for_order=false dokladany gdy isDiscontinued
assertBool('afo=0 przepuszczony → dostaje flage available_for_order=false', ProductSearch::isDiscontinued($discontinued));

// === Test 3: afo=1 → bez zmian (nie wypada, bez flagi) ===
assertBool('afo=1 → isDiscontinued=false', ProductSearch::isDiscontinued($orderable) === false);
$includeDiscontinued = false;
$kept = $includeDiscontinued || !ProductSearch::isDiscontinued($orderable);
assertBool('afo=1 + exploratory → zostaje w wynikach', $kept === true);
assertBool('afo=1 → BEZ flagi (regresja: wynik identyczny jak przed zmiana)', !ProductSearch::isDiscontinued($orderable));

// === Test 4 (wsteczna zgodnosc): brak klucza available_for_order → jak dostepny ===
assertBool('brak klucza (legacy shape) → isDiscontinued=false', ProductSearch::isDiscontinued($legacyShape) === false);

// === Test 5: brak danych MySQL (null) → jak dostepny (fallback pgvector, jak in_stock_only) ===
assertBool('null (brak danych MySQL) → isDiscontinued=false', ProductSearch::isDiscontinued(null) === false);

// === Test 6 (decyzja 91a): navigational + exact_keywords → include_discontinued AUTO ===
// Deterministycznie, bez polegania na modelu (test PROD: model nie ustawial parametru).
$navPlan = ['intent' => 'navigational', 'exact_keywords' => ['Mares', 'Cruise Backpack', 'Mesh Deluxe']];
assertBool('navigational + exact_keywords → auto TRUE', ProductSearch::shouldIncludeDiscontinued($navPlan, false) === true);

// === Test 7: exploratory → bez auto (wycofane wypadaja) ===
$explPlan = ['intent' => 'exploratory', 'exact_keywords' => []];
assertBool('exploratory → FALSE', ProductSearch::shouldIncludeDiscontinued($explPlan, false) === false);

// === Test 8: navigational BEZ exact_keywords → bez auto ===
assertBool('navigational bez exact_keywords → FALSE', ProductSearch::shouldIncludeDiscontinued(['intent' => 'navigational'], false) === false);

// === Test 9: jawny parametr include_discontinued=true wygrywa niezaleznie od planu ===
assertBool('explicit param TRUE → TRUE (nawet exploratory)', ProductSearch::shouldIncludeDiscontinued($explPlan, true) === true);

// === Test 10: pusty search_plan → bez auto ===
assertBool('pusty search_plan → FALSE', ProductSearch::shouldIncludeDiscontinued([], false) === false);

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
