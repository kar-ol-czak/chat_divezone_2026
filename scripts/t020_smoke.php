<?php

declare(strict_types=1);

/**
 * T-020 smoke test: strict exact-match fallback (case 90) + int-cast fix (case 91).
 * Uruchamiany bezpośrednio na prod (chat.divezone.pl) z prod .env.
 */

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use DiveChat\AI\EmbeddingService;
use DiveChat\Config;
use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;
use DiveChat\Tools\ProductSearch;
use DiveChat\Tools\SynonymExpander;

Config::load(dirname(__DIR__, 1));

$pg = PostgresConnection::getInstance();
$emb = new EmbeddingService();
$syn = new SynonymExpander($pg);
$pick = new EditorialPicksService($pg);
$search = new ProductSearch($emb, $pg, $syn, $pick);

function runScenario(string $label, ProductSearch $search, array $params): void
{
    echo "\n===== {$label} =====\n";
    echo 'Params: ' . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n";

    try {
        $result = $search->execute($params);
    } catch (\Throwable $e) {
        echo 'ERROR: ' . $e->getMessage() . "\n";
        echo '  trace: ' . $e->getFile() . ':' . $e->getLine() . "\n";
        return;
    }

    $count = $result['count'] ?? count($result['products'] ?? []);
    echo "Wyników: {$count}\n";

    $debug = $result['search_debug'] ?? [];

    $flags = [];
    if (!empty($debug['category_fallback'])) {
        $flags[] = 'category_fallback=TRUE (original=' . ($debug['original_category'] ?? '?') . ')';
    }
    if (!empty($debug['navigational_miss'])) {
        $flags[] = 'navigational_miss=TRUE';
    }
    if (array_key_exists('exact_match_miss', $debug)) {
        $flags[] = 'exact_match_miss=' . ($debug['exact_match_miss'] ? 'TRUE' : 'FALSE');
    }
    if (empty($flags)) {
        echo "  (brak nowych flag T-020)\n";
    } else {
        foreach ($flags as $f) echo "  >>> {$f}\n";
    }

    foreach (array_slice($result['products'] ?? [], 0, 5) as $p) {
        $brand = $p['brand'] ?? '?';
        $price = $p['price'] ?? '?';
        $cat = $p['category_name'] ?? ($p['category'] ?? '?');
        echo "  - [{$p['id']}] {$brand} | {$p['name']} | {$price} zł | cat={$cat}\n";
    }
}

// 1. CASE 90 — strict fallback: Crystal Vu w błędnej "Maski jednoszybowe"
runScenario('1. CASE 90: Scubapro Crystal Vu + category=Maski jednoszybowe + navigational', $search, [
    'query' => 'Scubapro Crystal Vu',
    'search_plan' => [
        'intent' => 'navigational',
        'reasoning' => 'klient szuka konkretnego modelu Crystal Vu',
        'exact_keywords' => ['Scubapro', 'Crystal Vu'],
    ],
    'category' => 'Maski jednoszybowe',
    'limit' => 5,
]);

// 2. CASE 91 — int-cast fix: Santi BZ 4000 (literówka 4000→400)
runScenario('2. CASE 91: Santi BZ 4000 + exact_keywords with numeric (int-cast fix)', $search, [
    'query' => 'Santi BZ 4000 suchy skafander',
    'search_plan' => [
        'intent' => 'navigational',
        'reasoning' => 'klient szuka ocieplacza BZ 4000',
        'exact_keywords' => ['Santi', 'BZ', '4000'],
    ],
    'category' => 'Skafandry suche',
    'limit' => 5,
]);

// 3. CASE 91b — numeric tokens in query: obręcze do twina 2x12
runScenario('3. CASE 91b: obręcze do twina 2x12 (numeric tokens w query)', $search, [
    'query' => 'obręcze do twina 2x12',
    'search_plan' => [
        'intent' => 'exploratory',
        'reasoning' => 'klient szuka obrazy butlowej',
    ],
    'limit' => 5,
]);

// 4. REGRESJA — navigational TRAFNY (model istnieje, dobra kategoria) → fallback NIE odpala
runScenario('4. REGRESJA: navigational trafny — SCUBAPRO Ghost + Maski jednoszybowe', $search, [
    'query' => 'Scubapro Ghost',
    'search_plan' => [
        'intent' => 'navigational',
        'reasoning' => 'klient szuka konkretnej maski Ghost',
        'exact_keywords' => ['Scubapro', 'Ghost'],
    ],
    'category' => 'Maski jednoszybowe',
    'limit' => 5,
]);

// 5. REGRESJA — exploratory bez exact_keywords → ścieżka normalna
runScenario('5. REGRESJA: exploratory płetwy paskowe (brak exact_keywords, intent≠navigational)', $search, [
    'query' => 'płetwy paskowe',
    'search_plan' => [
        'intent' => 'exploratory',
        'reasoning' => 'klient szuka płetw paskowych',
    ],
    'category' => 'Płetwy',
    'limit' => 5,
]);

echo "\n===== smoke done =====\n";
