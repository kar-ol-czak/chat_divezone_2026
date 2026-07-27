<?php

declare(strict_types=1);

/**
 * Test ShippingInfo (CHAT-T-156, decyzja 192b).
 *
 * Dwie warstwy:
 * 1. Czysta logika (zawsze): tokeny nazwy, dopasowanie metoda→kurier, VAT netto→brutto,
 *    grupowanie zakresów pr_delivery → cena bazowa + próg darmowej dostawy per kurier.
 * 2. DB-backed (tylko gdy MySQL PrestaShop + Railway PG osiągalne — na serwerze):
 *    get_shipping_info(zone=PL) zwraca PER KURIER: InPost darmowy od 299, DPD od 399,
 *    ceny brutto (13,00 / 21,99).
 *
 * Uruchomienie: ea-php84 standalone/tests/Tools/ShippingInfoTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Config;
use DiveChat\Database\MysqlConnection;
use DiveChat\Database\PostgresConnection;
use DiveChat\Tools\ShippingInfo;

$passed = 0;
$failed = 0;
$skipped = 0;

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

// ============================================================
// 1. CZYSTA LOGIKA (bez DB)
// ============================================================

$ref = new ReflectionClass(ShippingInfo::class);
/** @var ShippingInfo $tool */
$tool = $ref->newInstanceWithoutConstructor();

assertTest('nazwa narzedzia', $tool->getName() === 'get_shipping_info');

$schema = $tool->getParametersSchema();
assertTest('schema: zone enum PL/EU', ($schema['properties']['zone']['enum'] ?? []) === ['PL', 'EU']);
assertTest('schema: cart_total number', ($schema['properties']['cart_total']['type'] ?? null) === 'number');

// --- vatBrutto: netto -> brutto, VAT 23% (POTWIERDZONE na zamowieniach) ---
assertTest('VAT 17,88 -> 21,99 (DPD)', ShippingInfo::vatBrutto(17.88) === 21.99,
    'got ' . ShippingInfo::vatBrutto(17.88));
assertTest('VAT 10,57 -> 13,00 (InPost)', ShippingInfo::vatBrutto(10.57) === 13.00,
    'got ' . ShippingInfo::vatBrutto(10.57));
assertTest('VAT 21,1382 -> 26,00 (pobranie)', ShippingInfo::vatBrutto(21.1382) === 26.00,
    'got ' . ShippingInfo::vatBrutto(21.1382));

// --- carrierTokens: normalizacja nazwy (bez cyfr, bez polskich znakow) ---
assertTest('tokeny: INPOST Paczkomaty 24',
    ShippingInfo::carrierTokens('INPOST Paczkomaty 24') === ['inpost', 'paczkomaty'],
    json_encode(ShippingInfo::carrierTokens('INPOST Paczkomaty 24')));
assertTest('tokeny: Kurier DPD', ShippingInfo::carrierTokens('Kurier DPD') === ['kurier', 'dpd']);
assertTest('tokeny: Odbior osobisty (polskie znaki)',
    ShippingInfo::carrierTokens('Odbiór osobisty') === ['odbior', 'osobisty'],
    json_encode(ShippingInfo::carrierTokens('Odbiór osobisty')));

// --- tokenMatches: prefiks min. 4 znaki (paczkomat/paczkomaty) ---
assertTest('match: paczkomat ~ paczkomaty', ShippingInfo::tokenMatches('paczkomat', 'paczkomaty') === true);
assertTest('match: inpost == inpost', ShippingInfo::tokenMatches('inpost', 'inpost') === true);
assertTest('match: kurier != dpd', ShippingInfo::tokenMatches('kurier', 'dpd') === false);
assertTest('match: dpd != dostawa (rozne)', ShippingInfo::tokenMatches('dpd', 'dostawa') === false);

// --- groupCarrierRanges: surowe wiersze pr_delivery -> cena bazowa + prog per kurier ---
$rows = [
    // DPD (397): 0-399 -> 17.88, 399-1283 -> 0, 1283+ -> 0. Prog 399.
    ['id_carrier' => 397, 'name' => 'Kurier DPD', 'max_weight' => '29.000000', 'delimiter1' => '0.000000', 'price' => '17.880000'],
    ['id_carrier' => 397, 'name' => 'Kurier DPD', 'max_weight' => '29.000000', 'delimiter1' => '399.000000', 'price' => '0.000000'],
    ['id_carrier' => 397, 'name' => 'Kurier DPD', 'max_weight' => '29.000000', 'delimiter1' => '1283.000000', 'price' => '0.000000'],
    // InPost Paczkomaty (399): 0-299 -> 10.57, 299+ -> 0. Prog 299.
    ['id_carrier' => 399, 'name' => 'INPOST Paczkomaty 24', 'max_weight' => '10.000000', 'delimiter1' => '0.000000', 'price' => '10.570000'],
    ['id_carrier' => 399, 'name' => 'INPOST Paczkomaty 24', 'max_weight' => '10.000000', 'delimiter1' => '299.000000', 'price' => '0.000000'],
    // Pobranie DPD (398) — COD, ma tez zakresy; NIE powinien wygrac dopasowania "Kurier DPD".
    ['id_carrier' => 398, 'name' => 'Pobranie - Kurier DPD', 'max_weight' => '26.000000', 'delimiter1' => '0.000000', 'price' => '21.138200'],
    ['id_carrier' => 398, 'name' => 'Pobranie - Kurier DPD', 'max_weight' => '26.000000', 'delimiter1' => '398.000000', 'price' => '0.000000'],
];
$carriers = ShippingInfo::groupCarrierRanges($rows);
assertTest('groupCarrierRanges: 3 kurierzy', count($carriers) === 3, json_encode(array_column($carriers, 'id')));

$byId = [];
foreach ($carriers as $c) {
    $byId[$c['id']] = $c;
}
assertTest('DPD: base_netto 17.88', ($byId[397]['base_netto'] ?? null) === 17.88);
assertTest('DPD: free_from 399', ($byId[397]['free_from'] ?? null) === 399.0);
assertTest('DPD: max_weight 29', ($byId[397]['max_weight'] ?? null) === 29);
assertTest('InPost Paczkomat: base_netto 10.57', ($byId[399]['base_netto'] ?? null) === 10.57);
assertTest('InPost Paczkomat: free_from 299', ($byId[399]['free_from'] ?? null) === 299.0);
assertTest('InPost Paczkomat: max_weight 10', ($byId[399]['max_weight'] ?? null) === 10);

// --- matchCarrier: metoda PG -> wlasciwy kurier MySQL (po tokenach, nie id) ---
assertTest('map: "Kurier DPD" -> 397 (nie 398 pobranie)',
    (ShippingInfo::matchCarrier(ShippingInfo::carrierTokens('Kurier DPD'), $carriers)['id'] ?? null) === 397,
    json_encode(ShippingInfo::matchCarrier(ShippingInfo::carrierTokens('Kurier DPD'), $carriers)));
assertTest('map: "Paczkomat InPost" -> 399',
    (ShippingInfo::matchCarrier(ShippingInfo::carrierTokens('Paczkomat InPost'), $carriers)['id'] ?? null) === 399);
assertTest('map: "Odbior osobisty" -> null (brak stawki)',
    ShippingInfo::matchCarrier(ShippingInfo::carrierTokens('Odbior osobisty'), $carriers) === null);

// Dowod na anty-hardcode: te same nazwy, ale id skurierow zmienione (klon w PS) -> dopasowanie po nazwie dziala.
$cloned = array_map(static function (array $c): array {
    $c['id'] += 1000;
    return $c;
}, $carriers);
assertTest('map odporny na zmiane id (klon): "Kurier DPD" -> 1397',
    (ShippingInfo::matchCarrier(ShippingInfo::carrierTokens('Kurier DPD'), $cloned)['id'] ?? null) === 1397);

// ============================================================
// 2. DB-BACKED (tylko gdy MySQL PrestaShop + Railway PG osiagalne — serwer)
// ============================================================

$mysql = null;
$pg = null;
try {
    Config::load(dirname(dirname(__DIR__))); // standalone/ -> szuka .env
    $mysql = MysqlConnection::getInstance();
    if (!$mysql->isConnected()) {
        $mysql = null;
    }
    $pg = PostgresConnection::getInstance();
} catch (\Throwable $e) {
    $mysql = null;
    $pg = null;
}

if ($mysql === null || $pg === null) {
    echo "\n[SKIP] Testy DB-backed pominiete — MySQL PrestaShop / Railway PG nieosiagalny (uruchom na serwerze).\n";
    $skipped = 8;
} else {
    $dbTool = new ShippingInfo($pg, $mysql);

    $pl = $dbTool->execute(['zone' => 'PL']);
    assertTest('PL: sa metody', !empty($pl['methods']), json_encode($pl, JSON_UNESCAPED_UNICODE));

    // Zbierz metody wg dopasowanego kuriera po free_from.
    $dpd = null;
    $inpost = null;
    foreach ($pl['methods'] as $m) {
        $tokens = ShippingInfo::carrierTokens((string) $m['carrier_name']);
        if (in_array('dpd', $tokens, true)) {
            $dpd = $m;
        } elseif (in_array('inpost', $tokens, true) && $inpost === null) {
            $inpost = $m;
        }
    }

    assertTest('PL: DPD free_from 399', ($dpd['free_from'] ?? null) === 399.0,
        json_encode($dpd, JSON_UNESCAPED_UNICODE));
    assertTest('PL: DPD cena brutto 21,99', ($dpd['price'] ?? null) === 21.99,
        json_encode($dpd, JSON_UNESCAPED_UNICODE));
    assertTest('PL: InPost free_from 299', ($inpost['free_from'] ?? null) === 299.0,
        json_encode($inpost, JSON_UNESCAPED_UNICODE));
    assertTest('PL: InPost cena brutto 13,00', ($inpost['price'] ?? null) === 13.00,
        json_encode($inpost, JSON_UNESCAPED_UNICODE));
    assertTest('PL: progi RÓŻNE per kurier (nie jeden 299)',
        ($dpd['free_from'] ?? null) !== ($inpost['free_from'] ?? null));

    // cart_total 350: InPost juz darmowy (>=299), DPD jeszcze nie (<399).
    $pl350 = $dbTool->execute(['zone' => 'PL', 'cart_total' => 350]);
    $dpd350 = $inpost350 = null;
    foreach ($pl350['methods'] as $m) {
        $tokens = ShippingInfo::carrierTokens((string) $m['carrier_name']);
        if (in_array('dpd', $tokens, true)) {
            $dpd350 = $m;
        } elseif (in_array('inpost', $tokens, true) && $inpost350 === null) {
            $inpost350 = $m;
        }
    }
    assertTest('PL cart 350: InPost free_now=true', ($inpost350['free_now'] ?? null) === true,
        json_encode($inpost350, JSON_UNESCAPED_UNICODE));
    assertTest('PL cart 350: DPD free_now=false', ($dpd350['free_now'] ?? null) === false,
        json_encode($dpd350, JSON_UNESCAPED_UNICODE));
}

echo "\n{$passed} passed, {$failed} failed";
echo $skipped > 0 ? ", {$skipped} skipped (DB)\n" : "\n";
exit($failed > 0 ? 1 : 0);
