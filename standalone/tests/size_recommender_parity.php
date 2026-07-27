<?php

declare(strict_types=1);

/**
 * CHAT-T-100 / CHAT-T-103 — parytet SizeRecommender (PHP) z size_matcher.py.
 * CHAT-T-103: źródło danych = MySQL PrestaShop (divezone_attr_*), nie Railway/PG.
 * Wartości oczekiwane są identyczne jak przy PG (parytet danych 100%, ATTR-T-001) —
 * ten sam zestaw asercji co dowód PG = dowód parytetu MySQL vs PG.
 *
 * Uruchom NA SERWERZE (MySQL = localhost): php tests/size_recommender_parity.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DiveChat\Config;
use DiveChat\Database\MysqlConnection;
use DiveChat\Tools\SizeRecommender;

// Na serwerze Config::load czyta .env (DB_HOST/PORT/NAME_PROD/USER/PASSWORD = reader).
Config::load(dirname(__DIR__));

$ok = true;
$db = MysqlConnection::getInstance();
$tool = new SizeRecommender($db);

$check = function (string $desc, array $params, string $expDecision, ?array $expContains, ?bool $expGran = null) use ($tool, &$ok): void {
    $res = $tool->execute($params);
    $dec = $res['decision'] ?? ('ERROR:' . ($res['error'] ?? '?'));
    $sizes = $res['sizes'] ?? [];
    $good = ($dec === $expDecision);
    foreach ($expContains ?? [] as $want) {
        if (!in_array($want, $sizes, true)) {
            $good = false;
        }
    }
    if ($expGran !== null) {
        $good = $good && (($res['graniczny'] ?? null) === $expGran);
    }
    $ok = $ok && $good;
    $extra = isset($res['graniczny']) ? ' graniczny=' . var_export($res['graniczny'], true) : '';
    $full = isset($res['size_full']) ? ' full=' . json_encode($res['size_full'], JSON_UNESCAPED_UNICODE) : '';
    echo '  [' . ($good ? 'OK ' : 'FAIL') . "] {$desc}: -> {$dec} "
        . json_encode($sizes, JSON_UNESCAPED_UNICODE) . $extra . $full . "\n";
};

echo "PARYTET SizeRecommender (PHP) vs size_matcher.py — żywe dane MySQL (divezone_attr_*):\n";

// Dorośli (przedziałowy). Parytet z self-test PY + acceptance z handoffu.
$check('Scubapro M chest 104 h182 w88 -> L',
    ['brand' => 'Scubapro', 'gender' => 'M', 'chest' => 104, 'height' => 182, 'weight' => 88], 'match', ['L']);
$check('Scubapro M chest 200 -> out_of_scale',
    ['brand' => 'Scubapro', 'gender' => 'M', 'chest' => 200], 'out_of_scale', null);
$check('Scubapro M chest 88 -> out_of_scale (poniżej skali)',
    ['brand' => 'Scubapro', 'gender' => 'M', 'chest' => 88], 'out_of_scale', null);
$check('Bare K chest 88 h165 -> 6',
    ['brand' => 'Bare', 'gender' => 'K', 'chest' => 88, 'height' => 165], 'match', ['6']);

// Dziecięcy (punktowy, height wiodący).
$check('DZIECI height 134 -> [S,M] graniczny',
    ['brand' => 'Scubapro', 'gender' => 'DZIECI', 'height' => 134], 'boundary', ['S', 'M'], true);
$check('DZIECI height 140 -> M',
    ['brand' => 'Scubapro', 'gender' => 'DZIECI', 'height' => 140], 'match', ['M'], false);
$check('DZIECI height 170 -> out_of_scale (>XL)',
    ['brand' => 'Scubapro', 'gender' => 'DZIECI', 'height' => 170], 'out_of_scale', null, false);
$check('DZIECI height 100 -> out_of_scale (<XXS)',
    ['brand' => 'Scubapro', 'gender' => 'DZIECI', 'height' => 100], 'out_of_scale', null, false);

// CHAT-T-103: wybór charta przez product_id (mapowanie divezone_attr_product_chart).
// Produkty 4243/4244/6681 = bi-gender (mapowane do chartu Scubapro M + K) — płeć klienta wybiera.
// Wartości oczekiwane = baseline PG (parytet danych 100%).
$check('product 4243 + M chest 104 h182 w88 -> L (chart Scubapro M)',
    ['product_id' => 4243, 'gender' => 'M', 'chest' => 104, 'height' => 182, 'weight' => 88], 'match', ['L']);
$check('product 4243 + K chest 88 h165 -> ambiguous [S,ST] (chart Scubapro K)',
    ['product_id' => 4243, 'gender' => 'K', 'chest' => 88, 'height' => 165], 'ambiguous', ['S', 'ST']);
$check('product 6681 + M chest 104 (bez weryfikatorów) -> ambiguous [LS,L,LT]',
    ['product_id' => 6681, 'gender' => 'M', 'chest' => 104], 'ambiguous', ['LS', 'L', 'LT']);

// CHAT-T-103: warstwa aliasów etykiet (divezone_attr_size_label_alias).
$aliasRes = $tool->execute(['product_id' => 4243, 'gender' => 'K', 'chest' => 88, 'height' => 165]);
$aOk = ($aliasRes['aliases']['M tall'] ?? null) === 'MT';
$ok = $ok && $aOk;
echo '  [' . ($aOk ? 'OK ' : 'FAIL') . "] alias 'M tall'->'MT' obecny w wyniku (chart Scubapro K): "
    . json_encode($aliasRes['aliases'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

// Reguły walidacji (bot ma dopytać — narzędzie zwraca error, nie zgaduje).
$noGender = $tool->execute(['brand' => 'Scubapro', 'chest' => 104]);
$g1 = isset($noGender['error']);
$ok = $ok && $g1;
echo '  [' . ($g1 ? 'OK ' : 'FAIL') . '] brak płci -> error/dopytanie: ' . ($noGender['error'] ?? $noGender['decision'] ?? '?') . "\n";

$noChest = $tool->execute(['brand' => 'Scubapro', 'gender' => 'M']);
$g2 = isset($noChest['error']);
$ok = $ok && $g2;
echo '  [' . ($g2 ? 'OK ' : 'FAIL') . '] brak klatki -> error/dopytanie: ' . ($noChest['error'] ?? $noChest['decision'] ?? '?') . "\n";

// Normalizacja etykiet (KROK 4).
$n1 = SizeRecommender::normalizeLabel('6 Plus') === '6+';
$n2 = SizeRecommender::normalizeLabel('10 Plus') === '10+';
$n3 = SizeRecommender::normalizeLabel('L') === 'L';
$nOk = $n1 && $n2 && $n3;
$ok = $ok && $nOk;
echo '  [' . ($nOk ? 'OK ' : 'FAIL') . "] normalizeLabel: '6 Plus'->'6+', '10 Plus'->'10+', 'L'->'L'\n";

echo 'WYNIK: ' . ($ok ? 'wszystkie OK' : 'SĄ BŁĘDY') . "\n";
exit($ok ? 0 : 1);
