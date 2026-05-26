<?php

declare(strict_types=1);

/**
 * T-017 smoke test: ProductSearch auto-fallback bez kategorii.
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
        return;
    }

    $count = $result['count'] ?? count($result['products'] ?? []);
    echo "Wyników: {$count}\n";

    $debug = $result['search_debug'] ?? [];

    if (!empty($debug['category_fallback'])) {
        $orig = $debug['original_category'] ?? '?';
        echo "  >>> CATEGORY_FALLBACK ZADZIAŁAŁ — oryginalna kategoria: '{$orig}'\n";
    } else {
        echo "  (category_fallback: NIE — wyniki z pierwszej próby lub fallback nieaktywowany)\n";
    }

    foreach (array_slice($result['products'] ?? [], 0, 5) as $p) {
        $brand = $p['brand'] ?? '?';
        $price = $p['price'] ?? '?';
        $cat = $p['category_name'] ?? ($p['category'] ?? '?');
        echo "  - [{$p['id']}] {$brand} | {$p['name']} | {$price} zł | cat={$cat}\n";
    }
}

// 1. KEY CASE — brand+category mismatch (Santi to drysuits, NIE maski) → 0 → fallback ZNAJDUJE skafandry Santi
runScenario('1. Santi + WRONG category "Maski jednoszybowe" → 0 → fallback', $search, [
    'query' => 'Santi skafander suchy',
    'search_plan' => ['intent' => 'navigational', 'reasoning' => 'klient szuka Santi', 'exact_keywords' => ['Santi']],
    'filters' => ['brand' => 'Santi'],
    'category' => 'Maski jednoszybowe',
    'limit' => 5,
]);

// 2. KEY CASE 2 — Crystal Vu + brand=Scubapro + WRONG category → 0 → fallback finds Crystal Vu
runScenario('2. Scubapro Crystal Vu + brand=Scubapro + WRONG category "Skafandry suche" → 0 → fallback', $search, [
    'query' => 'Scubapro Crystal Vu',
    'search_plan' => ['intent' => 'navigational', 'reasoning' => 'klient szuka maski Crystal Vu', 'exact_keywords' => ['Scubapro', 'Crystal Vu']],
    'filters' => ['brand' => 'Scubapro'],
    'category' => 'Skafandry suche',
    'limit' => 5,
]);

// 3. REGRESJA — "płetwy" + category=Płetwy → fallback NIE uruchamia się (są wyniki)
runScenario('3. REGRESJA: płetwy + category=Płetwy (fallback NIE aktywuje)', $search, [
    'query' => 'płetwy',
    'search_plan' => ['intent' => 'exploratory', 'reasoning' => 'klient szuka płetw'],
    'category' => 'Płetwy',
    'limit' => 5,
]);

echo "\n===== smoke done =====\n";
