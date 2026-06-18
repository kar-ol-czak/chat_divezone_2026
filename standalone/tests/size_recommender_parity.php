<?php

declare(strict_types=1);

/**
 * CHAT-T-100 — parytet SizeRecommender (PHP) z size_matcher.py, na ŻYWYCH danych Railway.
 * Uruchom: php tests/size_recommender_parity.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DiveChat\Config;
use DiveChat\Database\PostgresConnection;
use DiveChat\Tools\SizeRecommender;

// Lokalny .env ma niepoprawną nazwę zmiennej (DATAFORSEO_API_PASSWORD-BASE64) która wywraca
// phpdotenv — to defekt lokalnego pliku, niezwiązany z tym taskiem. Do testu wyłuskujemy
// SAMO DATABASE_URL (na PROD Config::load działa normalnie). Fallback: pełny Config::load.
$envPath = dirname(__DIR__, 2) . '/.env';
if (is_readable($envPath) && preg_match('/^DATABASE_URL=(.*)$/m', (string) file_get_contents($envPath), $m)) {
    $_ENV['DATABASE_URL'] = trim($m[1], " \t\"'");
} else {
    Config::load(dirname(__DIR__));
}

$ok = true;
$pg = PostgresConnection::getInstance();
$tool = new SizeRecommender($pg);

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

echo "PARYTET SizeRecommender (PHP) vs size_matcher.py — żywe dane Railway:\n";

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
