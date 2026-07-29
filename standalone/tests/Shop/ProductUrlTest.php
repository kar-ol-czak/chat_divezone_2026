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

// === Test 1: slug normalny bez kategorii -> pelny URL .html (fallback, wsteczna zgodnosc) ===
assertUrl(
    'slug normalny bez kategorii → pelny .html (fallback)',
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

// ====================================================================
// CHAT-T-160 (decyzja 197a): URL KANONICZNY z prefiksem kategorii.
// Bez prefiksu PS robi 301 gubiacy ?id_product_attribute=NNN — pomiar KROK 0.
// ====================================================================

// === Test 5: PL z kategoria -> kanoniczny {kategoria}/{produkt}.html ===
assertUrl(
    'PL z kategoria → kanoniczny z prefiksem',
    MysqlProductEnrichmentService::buildProductUrl('maska-scubatech-corsica', 'maski-dwuszybowe'),
    'https://divezone.pl/maski-dwuszybowe/maska-scubatech-corsica.html',
);

// === Test 6: PL kategoria "zagniezdzona" (slug wieloczlonowy, ale JEDEN segment sciezki) ===
assertUrl(
    'PL kategoria wieloczlonowa → jeden segment prefiksu',
    MysqlProductEnrichmentService::buildProductUrl('skrzydlo-halcyon-eclipse-system-3040-lbs-ssalu', 'skrzydla-z-uprzeza-do-poj-butli'),
    'https://divezone.pl/skrzydla-z-uprzeza-do-poj-butli/skrzydlo-halcyon-eclipse-system-3040-lbs-ssalu.html',
);

// === Test 7: PL brak kategorii (null / '') -> fallback bez prefiksu (nigdy null gdy jest produkt) ===
assertUrl(
    'PL kategoria null → fallback bez prefiksu',
    MysqlProductEnrichmentService::buildProductUrl('automat-scubapro-mk25-evo', null),
    'https://divezone.pl/automat-scubapro-mk25-evo.html',
);
assertUrl(
    "PL kategoria '' → fallback bez prefiksu",
    MysqlProductEnrichmentService::buildProductUrl('automat-scubapro-mk25-evo', ''),
    'https://divezone.pl/automat-scubapro-mk25-evo.html',
);

// === Test 8: PL pusty produkt + jest kategoria -> null (produkt jest kluczem, nie kategoria) ===
assertUrl(
    "PL produkt '' + kategoria → null",
    MysqlProductEnrichmentService::buildProductUrl('', 'maski-dwuszybowe'),
    null,
);
assertUrl(
    'PL produkt null + kategoria → null',
    MysqlProductEnrichmentService::buildProductUrl(null, 'maski-dwuszybowe'),
    null,
);

// === Test 9: EN z kategoria -> kanoniczny /en/{kategoria}/{produkt}.html ===
assertUrl(
    'EN z kategoria → kanoniczny /en/ z prefiksem',
    MysqlProductEnrichmentService::buildProductUrlEn('scubatech-corsica', 'double-glass-masks'),
    'https://divezone.pl/en/double-glass-masks/scubatech-corsica.html',
);

// === Test 10: EN bez kategorii -> fallback /en/{produkt}.html ===
assertUrl(
    'EN kategoria null → fallback /en/ bez prefiksu',
    MysqlProductEnrichmentService::buildProductUrlEn('scubatech-corsica', null),
    'https://divezone.pl/en/scubatech-corsica.html',
);

// === Test 11: EN pusty/null produkt -> null (nie budujemy martwego /en/) ===
assertUrl("EN produkt '' → null", MysqlProductEnrichmentService::buildProductUrlEn('', 'double-glass-masks'), null);
assertUrl('EN produkt null → null', MysqlProductEnrichmentService::buildProductUrlEn(null, null), null);

// === Test 12: kanoniczny nie ma podwojnego slasha ani golej domeny (zaden przypadek) ===
$canon = [
    MysqlProductEnrichmentService::buildProductUrl('p', 'c'),
    MysqlProductEnrichmentService::buildProductUrl('p', null),
    MysqlProductEnrichmentService::buildProductUrlEn('p', 'c'),
    MysqlProductEnrichmentService::buildProductUrlEn('p', null),
];
foreach ($canon as $i => $u) {
    assertBool("canon case {$i}: brak // po domenie", $u !== null && strpos(substr($u, strlen('https://')), '//') === false);
}

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
