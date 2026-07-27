<?php

declare(strict_types=1);

/**
 * Test jednostkowy PopularProducts (CHAT-T-132, ADR-115).
 * Czysta logika — mapowanie category_key, normalizacja months, schema.
 * Bez MySQL (metody statyczne + schema nie dotykaja bazy).
 *
 * Uruchomienie: php standalone/tests/Tools/PopularProductsTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Tools\PopularProducts;

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

// --- Mapowanie category_key -> id_category (decyzja 17b) ---
assertTest('fins_recreational -> 473', PopularProducts::resolveCategoryId('fins_recreational') === 473);
assertTest('fins_jet -> 415', PopularProducts::resolveCategoryId('fins_jet') === 415);
assertTest('fins_snorkel -> 472', PopularProducts::resolveCategoryId('fins_snorkel') === 472);
assertTest('nieznany klucz -> null', PopularProducts::resolveCategoryId('fins_paskowe') === null);
assertTest('pusty klucz -> null', PopularProducts::resolveCategoryId('') === null);

// --- Normalizacja okna sprzedazy (default 6, clamp 1-24) ---
assertTest('months null -> default 6', PopularProducts::normalizeMonths(null) === 6);
assertTest('months "abc" -> default 6', PopularProducts::normalizeMonths('abc') === 6);
assertTest('months 12 -> 12', PopularProducts::normalizeMonths(12) === 12);
assertTest('months "3" (string) -> 3', PopularProducts::normalizeMonths('3') === 3);
assertTest('months 0 -> clamp 1', PopularProducts::normalizeMonths(0) === 1);
assertTest('months -5 -> clamp 1', PopularProducts::normalizeMonths(-5) === 1);
assertTest('months 99 -> clamp 24', PopularProducts::normalizeMonths(99) === 24);

// --- Schema: enum spojny z mapa kategorii ---
// getParametersSchema nie dotyka MySQL — refleksja bez konstruktora.
$ref = new ReflectionClass(PopularProducts::class);
/** @var PopularProducts $tool */
$tool = $ref->newInstanceWithoutConstructor();

$schema = $tool->getParametersSchema();
assertTest(
    'schema enum == klucze mapy CATEGORIES',
    ($schema['properties']['category']['enum'] ?? null) === array_keys(PopularProducts::CATEGORIES),
);
assertTest('schema: category wymagany', ($schema['required'] ?? []) === ['category']);
assertTest('schema: max_price to number', ($schema['properties']['max_price']['type'] ?? null) === 'number');
assertTest('schema: months to integer', ($schema['properties']['months']['type'] ?? null) === 'integer');
assertTest('nazwa narzedzia', $tool->getName() === 'get_popular_products');

// --- execute z nieznanym kluczem: nie dotyka MySQL, zwraca unknown_category ---
$result = $tool->execute(['category' => 'nope']);
assertTest('execute(nieznany) -> unknown_category', ($result['status'] ?? null) === 'unknown_category');
assertTest('execute(nieznany) -> puste sekcje', $result['bestsellers'] === [] && $result['new_arrivals'] === []);
$resultEmpty = $tool->execute([]);
assertTest('execute(brak category) -> unknown_category', ($resultEmpty['status'] ?? null) === 'unknown_category');

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
