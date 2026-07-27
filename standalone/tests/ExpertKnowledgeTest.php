<?php

declare(strict_types=1);

/**
 * Test integracyjny ExpertKnowledge z encyclopedia_chunks.
 * Wymaga: DATABASE_URL + OPENAI_API_KEY w .env
 *
 * Uruchomienie: php standalone/tests/ExpertKnowledgeTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;
use DiveChat\Tools\ExpertKnowledge;

// Ładuj .env ręcznie (vlucas/phpdotenv nie obsługuje myślników w nazwach zmiennych)
$envFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (!str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        if (!isset($_ENV[$key])) {
            $_ENV[$key] = trim($val);
        }
    }
}

$db = PostgresConnection::getInstance();
$embeddingService = new EmbeddingService();
$tool = new ExpertKnowledge($embeddingService, $db);

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

// Test 1: FAQ — jaki automat oddechowy wybrać
echo "\n=== Test 1: FAQ automat oddechowy ===\n";
$result1 = $tool->execute([
    'query' => 'jaki automat oddechowy wybrać',
    'chunk_types' => ['faq'],
]);
assertTest('Wyniki > 0', $result1['count'] > 0, "count={$result1['count']}");
if ($result1['count'] > 0) {
    $first = $result1['knowledge'][0];
    assertTest('Similarity > 0.45', $first['similarity'] > 0.45, "sim={$first['similarity']}");
    assertTest('chunk_type = faq', $first['chunk_type'] === 'faq', "type={$first['chunk_type']}");
    assertTest('concept_key sensowny', str_contains(strtoupper($first['concept_key']), 'AUTOMAT'), "key={$first['concept_key']}");
    echo "  → concept: {$first['concept_key']}, name: {$first['name']}, sim: {$first['similarity']}\n";
}

// Test 2: definition — jacket skrzydło różnica
echo "\n=== Test 2: definition jacket/skrzydło ===\n";
$result2 = $tool->execute([
    'query' => 'jacket skrzydło różnica',
    'chunk_types' => ['definition'],
]);
assertTest('Wyniki > 0', $result2['count'] > 0, "count={$result2['count']}");
if ($result2['count'] > 0) {
    $first = $result2['knowledge'][0];
    assertTest('Similarity > 0.45', $first['similarity'] > 0.45, "sim={$first['similarity']}");
    assertTest('chunk_type = definition', $first['chunk_type'] === 'definition', "type={$first['chunk_type']}");
    echo "  → concept: {$first['concept_key']}, name: {$first['name']}, sim: {$first['similarity']}\n";
}

// Test 3: purchase — suchy skafander ocieplacz
echo "\n=== Test 3: purchase suchy skafander ===\n";
$result3 = $tool->execute([
    'query' => 'suchy skafander ocieplacz',
    'chunk_types' => ['purchase'],
]);
assertTest('Wyniki > 0', $result3['count'] > 0, "count={$result3['count']}");
if ($result3['count'] > 0) {
    $first = $result3['knowledge'][0];
    assertTest('Similarity > 0.45', $first['similarity'] > 0.45, "sim={$first['similarity']}");
    assertTest('chunk_type = purchase', $first['chunk_type'] === 'purchase', "type={$first['chunk_type']}");
    echo "  → concept: {$first['concept_key']}, name: {$first['name']}, sim: {$first['similarity']}\n";
}

// Test 4: brak wyników (bzdurne zapytanie)
echo "\n=== Test 4: brak wyników ===\n";
$result4 = $tool->execute([
    'query' => 'xyzzy foo bar baz quantum',
    'chunk_types' => ['definition'],
]);
$count4 = $result4['count'] ?? 0;
assertTest('Pusty wynik lub niski similarity', $count4 === 0 || $result4['knowledge'][0]['similarity'] < 0.55);

// Test 5: concept_key filter
echo "\n=== Test 5: filtr concept_key ===\n";
$result5 = $tool->execute([
    'query' => 'automat oddechowy budowa działanie',
    'chunk_types' => ['definition', 'faq', 'purchase'],
    'concept_key' => 'AUTOMAT_ODDECHOWY',
]);
assertTest('Wyniki > 0', ($result5['count'] ?? 0) > 0, "count=" . ($result5['count'] ?? 0));
if (($result5['count'] ?? 0) > 0) {
    $allCorrectKey = true;
    foreach ($result5['knowledge'] as $item) {
        if ($item['concept_key'] !== 'AUTOMAT_ODDECHOWY') {
            $allCorrectKey = false;
            break;
        }
    }
    assertTest('Wszystkie wyniki mają concept_key=AUTOMAT_ODDECHOWY', $allCorrectKey);
}

// Podsumowanie
echo "\n=============================\n";
echo "WYNIK: {$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
