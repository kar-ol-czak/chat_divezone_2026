<?php

declare(strict_types=1);

/**
 * Diagnoza TASK-CHAT-012 — direct test ProductSearch dla SANTI.
 * Uruchomienie: php standalone/tests/Tools/SantiSearchTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

// Load .env
$envFile = dirname(__DIR__, 3) . '/.env';
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    if (!isset($_ENV[trim($k)])) $_ENV[trim($k)] = trim($v);
}

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;
use DiveChat\Tools\ProductSearch;
use DiveChat\Tools\SynonymExpander;

$pg = PostgresConnection::getInstance();
$emb = new EmbeddingService();
$syn = new SynonymExpander($pg);
$search = new ProductSearch($emb, $pg, $syn);

function runVariant(string $label, ProductSearch $search, array $params): void
{
    echo "\n=== $label ===\n";
    echo "params: " . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n";
    $result = $search->execute($params);
    $count = $result['count'] ?? 0;
    echo "count: $count\n";
    if ($count > 0) {
        foreach (array_slice($result['products'], 0, 5) as $p) {
            echo sprintf("  - [%d] %s | brand=%s | cat=%s | in_stock=%s\n",
                $p['id'], substr($p['name'], 0, 50), $p['brand'], $p['category'], $p['in_stock'] ? 'Y' : 'N');
        }
    }
    $tracks = $result['search_debug']['tracks'] ?? [];
    if ($tracks) {
        echo "  tracks: name=" . ($tracks['name_count'] ?? 0)
           . " desc=" . ($tracks['desc_count'] ?? 0)
           . " jargon=" . ($tracks['jargon_count'] ?? 0)
           . " fts=" . ($tracks['fts_count'] ?? 0)
           . " trigram=" . ($tracks['trigram_count'] ?? 0) . "\n";
    }
}

// === Wariant 1: brand=SANTI + category="Skafandry suche" (jak każe SystemPrompt) ===
runVariant('V1: brand=SANTI + category="Skafandry suche"', $search, [
    'query' => 'suchy skafander',
    'category' => 'Skafandry suche',
    'search_plan' => ['intent' => 'navigational', 'reasoning' => 'test', 'exact_keywords' => ['SANTI']],
    'filters' => ['brand' => 'SANTI', 'in_stock_only' => false],
]);

// === Wariant 2: brand=SANTI bez category ===
runVariant('V2: brand=SANTI bez category', $search, [
    'query' => 'suchy skafander SANTI',
    'search_plan' => ['intent' => 'navigational', 'reasoning' => 'test', 'exact_keywords' => ['SANTI']],
    'filters' => ['brand' => 'SANTI', 'in_stock_only' => false],
]);

// === Wariant 3: query="SANTI suchy skafander" bez brand filter ===
runVariant('V3: query="SANTI suchy skafander" bez brand/category', $search, [
    'query' => 'SANTI suchy skafander',
    'search_plan' => ['intent' => 'navigational', 'reasoning' => 'test', 'exact_keywords' => ['SANTI']],
    'filters' => ['in_stock_only' => false],
]);

// === Wariant 4: exact_keywords=["SANTI"] + query="suchy skafander" ===
runVariant('V4: exact=["SANTI"] + query="suchy skafander"', $search, [
    'query' => 'suchy skafander',
    'search_plan' => ['intent' => 'exploratory', 'reasoning' => 'test', 'exact_keywords' => ['SANTI']],
    'filters' => ['in_stock_only' => false],
]);

// === Wariant 5 (dodatkowy): category="SUCHE Trylaminat" — faktyczna nazwa kategorii ===
runVariant('V5: brand=SANTI + category="SUCHE Trylaminat"', $search, [
    'query' => 'suchy skafander',
    'category' => 'SUCHE Trylaminat',
    'search_plan' => ['intent' => 'navigational', 'reasoning' => 'test', 'exact_keywords' => ['SANTI']],
    'filters' => ['brand' => 'SANTI', 'in_stock_only' => false],
]);

echo "\n";
