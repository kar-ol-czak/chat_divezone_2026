<?php

declare(strict_types=1);

/**
 * Test jednostkowy filtra available_for_order (CHAT-T-143, ADR-123)
 * + kryterium zlozonego afo/visibility (CHAT-T-178, ADR-123 nota nr 2).
 * Czysta logika — bez MySQL/PG (ProductSearch::isDiscontinued i ::isLegacyHidden
 * to public static, wspolne predykaty obu miejsc aplikacji filtra: mergeRRF
 * i searchByPrice).
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

// ====================================================================
// CHAT-T-178 (ADR-123 nota nr 2): kryterium zlozone afo LUB visibility.
// ====================================================================

$legacyHidden = [ // vis='none' + afo=1 — nieprodukowany, ale koszyk DZIALA (13 szt. na PROD)
    'price' => 449.0,
    'in_stock' => true,
    'availability' => 'in_stock',
    'active' => true,
    'visible' => false,
    'available_for_order' => true,
];
$hiddenAndDiscontinued = [ // vis='none' + afo=0 — dominujacy przypadek (472/486)
    'price' => 349.0,
    'in_stock' => false,
    'availability' => 'unavailable',
    'active' => true,
    'visible' => false,
    'available_for_order' => false,
];

// === Test 11: vis='none' + afo=1 → isLegacyHidden=true ===
assertBool('vis=none + afo=1 → isLegacyHidden=true', ProductSearch::isLegacyHidden($legacyHidden) === true);
assertBool('vis=none + afo=1 → isDiscontinued=false (koszyk dziala)', ProductSearch::isDiscontinued($legacyHidden) === false);

// === Test 12: vis='none' + afo=0 → isDiscontinued ma PIERWSZENSTWO ===
assertBool('vis=none + afo=0 → isLegacyHidden=false (pierwszenstwo isDiscontinued)', ProductSearch::isLegacyHidden($hiddenAndDiscontinued) === false);
assertBool('vis=none + afo=0 → isDiscontinued=true', ProductSearch::isDiscontinued($hiddenAndDiscontinued) === true);

// === Test 13: normalny produkt (vis=both + afo=1) → zadna z flag ===
assertBool('vis=both + afo=1 → isLegacyHidden=false', ProductSearch::isLegacyHidden($orderable) === false);

// === Test 14: afo=0 + vis=both (47 szt. na PROD) → tylko isDiscontinued ===
assertBool('vis=both + afo=0 → isLegacyHidden=false', ProductSearch::isLegacyHidden($discontinued) === false);

// === Test 15 (wsteczna zgodnosc + null): brak klucza / brak danych → jak widoczny ===
assertBool('brak klucza visible (legacy shape) → isLegacyHidden=false', ProductSearch::isLegacyHidden($legacyShape) === false);
assertBool('null (brak danych MySQL) → isLegacyHidden=false', ProductSearch::isLegacyHidden(null) === false);

// === Test 16 (end-to-end, logika filtra 1:1 z searchByPrice/mergeRRF) ===
$keep = static fn(?array $d, bool $incl): bool
    => $incl || (!ProductSearch::isDiscontinued($d) && !ProductSearch::isLegacyHidden($d));
$flag = static function (?array $d): ?string {
    if (ProductSearch::isDiscontinued($d)) {
        return 'available_for_order=false';
    }
    if (ProductSearch::isLegacyHidden($d)) {
        return 'no_longer_manufactured=true';
    }
    return null;
};

assertBool('E2E: vis=none+afo=1 + exploratory → WYPADA', $keep($legacyHidden, false) === false);
assertBool('E2E: vis=none+afo=1 + include_discontinued → wchodzi', $keep($legacyHidden, true) === true);
assertBool('E2E: vis=none+afo=1 przepuszczony → flaga no_longer_manufactured', $flag($legacyHidden) === 'no_longer_manufactured=true');
assertBool('E2E: vis=none+afo=0 przepuszczony → flaga available_for_order=false (BEZ nowej)', $flag($hiddenAndDiscontinued) === 'available_for_order=false');
assertBool('E2E REGRESJA: vis=both+afo=0 → flaga available_for_order=false jak przed T-178', $flag($discontinued) === 'available_for_order=false');
assertBool('E2E REGRESJA: normalny produkt zostaje przy exploratory', $keep($orderable, false) === true);
assertBool('E2E REGRESJA: normalny produkt BEZ zadnej flagi', $flag($orderable) === null);
assertBool('E2E REGRESJA: brak danych MySQL zostaje i bez flagi', $keep(null, false) === true && $flag(null) === null);

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
