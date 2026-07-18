<?php

declare(strict_types=1);

/**
 * Test InternationalShipping (CHAT-T-151, ADR-129).
 *
 * Dwie warstwy:
 * 1. Czysta logika (zawsze): schema, nazwa, helpery EUR/zakresy/próg, widoczność
 *    w obu providerach, unknown_country bez DB.
 * 2. DB-backed (tylko gdy MySQL PrestaShop osiągalny — na serwerze): Austria/Czechy/
 *    wyspy/nieznany kraj, wybór zakresu wg cart_total, limit wagi.
 *
 * Uruchomienie: ea-php84 standalone/tests/Tools/InternationalShippingTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\AI\ClaudeProvider;
use DiveChat\AI\OpenAIProvider;
use DiveChat\Config;
use DiveChat\Database\MysqlConnection;
use DiveChat\Tools\InternationalShipping;
use DiveChat\Tools\ToolRegistry;

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

$ref = new ReflectionClass(InternationalShipping::class);
/** @var InternationalShipping $tool */
$tool = $ref->newInstanceWithoutConstructor();

assertTest('nazwa narzedzia', $tool->getName() === 'get_international_shipping');

$schema = $tool->getParametersSchema();
assertTest('schema: country wymagany', ($schema['required'] ?? []) === ['country']);
assertTest('schema: country to string', ($schema['properties']['country']['type'] ?? null) === 'string');
assertTest('schema: cart_total to number', ($schema['properties']['cart_total']['type'] ?? null) === 'number');
assertTest('schema: cart_weight to number', ($schema['properties']['cart_weight']['type'] ?? null) === 'number');
assertTest('schema: weight_uncertain to boolean', ($schema['properties']['weight_uncertain']['type'] ?? null) === 'boolean');

// --- toEurCeil: zaokraglenie w gore do pelnego euro (decyzja 155a) ---
$rate = 0.234915; // pr_currency id=2 (dzien pomiaru)
assertTest('97 PLN -> 23 EUR (ceil)', InternationalShipping::toEurCeil(97.0, $rate) === 23,
    'got ' . InternationalShipping::toEurCeil(97.0, $rate));
assertTest('87 PLN -> 21 EUR (ceil)', InternationalShipping::toEurCeil(87.0, $rate) === 21,
    'got ' . InternationalShipping::toEurCeil(87.0, $rate));
assertTest('1283 PLN -> 302 EUR (prog free)', InternationalShipping::toEurCeil(1283.0, $rate) === 302,
    'got ' . InternationalShipping::toEurCeil(1283.0, $rate));
assertTest('0 PLN -> 0 EUR (free)', InternationalShipping::toEurCeil(0.0, $rate) === 0);
assertTest('rate 0 -> 0 EUR (brak kursu)', InternationalShipping::toEurCeil(97.0, 0.0) === 0);

// --- normalizeRanges + helpery na modelu Austrii (97/97/0) ---
$rows = [
    ['delimiter1' => '0.000000',    'delimiter2' => '399.000000',    'price' => '97.000000'],
    ['delimiter1' => '399.000000',  'delimiter2' => '1283.000000',   'price' => '97.000000'],
    ['delimiter1' => '1283.000000', 'delimiter2' => '999999.000000', 'price' => '0.000000'],
];
$ranges = InternationalShipping::normalizeRanges($rows, $rate);
assertTest('normalizeRanges: 3 zakresy', count($ranges) === 3);
assertTest('normalizeRanges: from/to/pln/eur float', $ranges[0]['from'] === 0.0 && $ranges[0]['to'] === 399.0
    && $ranges[0]['pln'] === 97.0 && $ranges[0]['eur'] === 23);

assertTest('hasPaidRange: Austria ma stawke', InternationalShipping::hasPaidRange($ranges) === true);
$freeOnly = InternationalShipping::normalizeRanges(
    [['delimiter1' => '0.000000', 'delimiter2' => '999999.000000', 'price' => '0.000000']], $rate);
assertTest('hasPaidRange: same zera -> false', InternationalShipping::hasPaidRange($freeOnly) === false);
assertTest('hasPaidRange: brak zakresow -> false', InternationalShipping::hasPaidRange([]) === false);

// --- selectRange wg cart_total (from <= x < to) ---
assertTest('cart 100 -> zakres 0-399', InternationalShipping::selectRange($ranges, 100.0)['pln'] === 97.0);
assertTest('cart 399 -> zakres 399-1283 (granica)', InternationalShipping::selectRange($ranges, 399.0)['from'] === 399.0);
assertTest('cart 500 -> zakres srodkowy 97', InternationalShipping::selectRange($ranges, 500.0)['pln'] === 97.0);
assertTest('cart 1283 -> zakres free (pln 0)', InternationalShipping::selectRange($ranges, 1283.0)['pln'] === 0.0);
assertTest('cart 1500 -> free', InternationalShipping::selectRange($ranges, 1500.0)['pln'] === 0.0);

// --- freeShippingThreshold: dolna granica zakresu z cena 0 (z danych) ---
assertTest('freeShippingThreshold = 1283', InternationalShipping::freeShippingThreshold($ranges) === 1283.0);
assertTest('freeShippingThreshold brak zera -> null',
    InternationalShipping::freeShippingThreshold(array_slice($ranges, 0, 2)) === null);

// --- unknown_country bez DB (pusty country -> early return) ---
$empty = $tool->execute(['country' => '']);
assertTest('pusty country -> unknown_country', ($empty['status'] ?? null) === 'unknown_country');
assertTest('pusty country -> country null', array_key_exists('country', $empty) && $empty['country'] === null);
$emptyNoArg = $tool->execute([]);
assertTest('brak country -> unknown_country', ($emptyNoArg['status'] ?? null) === 'unknown_country');

// ============================================================
// 2. WIDOCZNOSC W OBU PROVIDERACH (Claude + OpenAI)
// ============================================================

$registry = new ToolRegistry();
$registry->register($ref->newInstanceWithoutConstructor());
$defs = $registry->getToolDefinitions();
$intlDef = null;
foreach ($defs as $d) {
    if ($d['name'] === 'get_international_shipping') {
        $intlDef = $d;
        break;
    }
}
assertTest('registry: narzedzie widoczne w getToolDefinitions', $intlDef !== null);
assertTest('registry: definicja ma description+parameters',
    $intlDef !== null && isset($intlDef['description'], $intlDef['parameters']));

// Claude formatTools (private) via reflection — bez API key.
$claudeRef = new ReflectionClass(ClaudeProvider::class);
$claude = $claudeRef->newInstanceWithoutConstructor();
$claudeFmt = $claudeRef->getMethod('formatTools');
$claudeTools = $claudeFmt->invoke($claude, $defs);
assertTest('Claude formatTools: nazwa + input_schema',
    $claudeTools[0]['name'] === 'get_international_shipping' && isset($claudeTools[0]['input_schema']));

// OpenAI formatTools (private) via reflection.
$openaiRef = new ReflectionClass(OpenAIProvider::class);
$openai = $openaiRef->newInstanceWithoutConstructor();
$openaiFmt = $openaiRef->getMethod('formatTools');
$openaiTools = $openaiFmt->invoke($openai, $defs);
assertTest('OpenAI formatTools: type function + nazwa',
    $openaiTools[0]['type'] === 'function' && $openaiTools[0]['function']['name'] === 'get_international_shipping');

// ============================================================
// 2.5 cleanName — nazwy krajow PrestaShop z wiodacym U+00A0 (nbsp, C2A0)
//     ktorego SQL TRIM (0x20) nie usuwa. Static, bez DB.
// ============================================================

assertTest('cleanName: nbsp wiodacy usuniety',
    InternationalShipping::cleanName("\xC2\xA0Austria") === 'Austria');
assertTest('cleanName: nbsp + spacja mieszane',
    InternationalShipping::cleanName("\xC2\xA0 Austria \xC2\xA0") === 'Austria');
assertTest('cleanName: zwykla spacja nadal trimowana',
    InternationalShipping::cleanName('  Czechy  ') === 'Czechy');
assertTest('cleanName: czysta nazwa bez zmian',
    InternationalShipping::cleanName('Niemcy') === 'Niemcy');

// ============================================================
// 3. DB-BACKED (tylko gdy MySQL PrestaShop osiagalny — serwer)
// ============================================================

$mysql = null;
try {
    Config::load(dirname(dirname(__DIR__))); // standalone/ -> szuka .env
    $mysql = MysqlConnection::getInstance();
    if (!$mysql->isConnected()) {
        $mysql = null;
    }
} catch (\Throwable $e) {
    $mysql = null;
}

if ($mysql === null) {
    echo "\n[SKIP] Testy DB-backed pominiete — MySQL PrestaShop nieosiagalny (uruchom na serwerze).\n";
    $skipped = 12;
} else {
    $dbTool = new InternationalShipping($mysql);

    // Austria — strefa ze stawka 97 PLN / 23 EUR, 3 zakresy.
    $at = $dbTool->execute(['country' => 'Austria']);
    assertTest('Austria -> ok', ($at['status'] ?? null) === 'ok', json_encode($at, JSON_UNESCAPED_UNICODE));
    assertTest('Austria -> 3 zakresy', count($at['ranges'] ?? []) === 3);
    assertTest('Austria -> ranges[0] 97 PLN / 23 EUR',
        ($at['ranges'][0]['pln'] ?? null) === 97.0 && ($at['ranges'][0]['eur'] ?? null) === 23);
    assertTest('Austria -> free_threshold 1283 PLN / 302 EUR',
        ($at['free_shipping_threshold_pln'] ?? null) === 1283.0 && ($at['free_shipping_threshold_eur'] ?? null) === 302);
    assertTest('Austria -> max_weight z bazy (29)', ($at['max_weight_kg'] ?? null) === 29.0);

    // Kod ISO tez dziala.
    $atIso = $dbTool->execute(['country' => 'AT']);
    assertTest('AT (ISO) -> Austria ok', ($atIso['status'] ?? null) === 'ok');

    // Czechy — inna stawka (87), dowod ze czyta per strefa.
    $cz = $dbTool->execute(['country' => 'Czechy']);
    assertTest('Czechy -> 87 PLN (inna stawka niz Austria)', ($cz['ranges'][0]['pln'] ?? null) === 87.0,
        json_encode($cz['ranges'][0] ?? null, JSON_UNESCAPED_UNICODE));

    // Wyspa (Grenlandia, strefa 7 = 0 wierszy DPD) -> not_supported.
    $gl = $dbTool->execute(['country' => 'Grenlandia']);
    assertTest('Grenlandia -> not_supported', ($gl['status'] ?? null) === 'not_supported',
        json_encode($gl, JSON_UNESCAPED_UNICODE));

    // Kraj nieznany -> unknown_country.
    $nope = $dbTool->execute(['country' => 'Blorpland']);
    assertTest('Blorpland -> unknown_country', ($nope['status'] ?? null) === 'unknown_country');

    // cart_total 500 -> zakres srodkowy; 1500 -> free.
    $at500 = $dbTool->execute(['country' => 'Austria', 'cart_total' => 500]);
    assertTest('Austria cart 500 -> rate_eur 23, nie free',
        ($at500['rate_eur'] ?? null) === 23 && ($at500['free_shipping'] ?? null) === false);
    $at1500 = $dbTool->execute(['country' => 'Austria', 'cart_total' => 1500]);
    assertTest('Austria cart 1500 -> free (rate 0)',
        ($at1500['rate_pln'] ?? null) === 0.0 && ($at1500['free_shipping'] ?? null) === true);

    // cart_weight 35 -> over_weight_limit, max_weight z bazy.
    $heavy = $dbTool->execute(['country' => 'Austria', 'cart_weight' => 35]);
    assertTest('Austria waga 35 -> over_weight_limit true',
        ($heavy['over_weight_limit'] ?? null) === true && ($heavy['max_weight_kg'] ?? null) === 29.0);

    // weight_uncertain przepisane do zwrotki.
    $unc = $dbTool->execute(['country' => 'Austria', 'weight_uncertain' => true]);
    assertTest('weight_uncertain przepisane', ($unc['weight_uncertain'] ?? null) === true);
}

echo "\n{$passed} passed, {$failed} failed";
echo $skipped > 0 ? ", {$skipped} skipped (DB)\n" : "\n";
exit($failed > 0 ? 1 : 0);
