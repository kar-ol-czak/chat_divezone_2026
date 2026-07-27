<?php

declare(strict_types=1);

/**
 * Test integracyjny UsageLogger.
 * Wymaga: DATABASE_URL + tabele divechat_message_usage, divechat_conversations,
 * divechat_model_pricing po TASK-052a.
 *
 * Tworzy testową rozmowę → loguje 2 wywołania → asercje na agregatach → cleanup.
 *
 * Uruchomienie: php standalone/tests/UsageLoggerTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use DiveChat\AI\ExchangeRateService;
use DiveChat\AI\PricingService;
use DiveChat\AI\UsageLogger;
use DiveChat\Database\PostgresConnection;

// Ładuj .env
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

$failed = 0;
$passed = 0;

function assertEq(string $name, mixed $actual, mixed $expected, float $eps = 0.0): void
{
    global $failed, $passed;
    $ok = is_float($expected)
        ? abs($actual - $expected) < ($eps > 0 ? $eps : 1e-6)
        : $actual === $expected;
    if ($ok) {
        $passed++;
        echo "  ✓ {$name}\n";
    } else {
        $failed++;
        $a = var_export($actual, true);
        $e = var_export($expected, true);
        echo "  ✗ {$name}: actual={$a}, expected={$e}\n";
    }
}

echo "=== UsageLogger ===\n";

$db = PostgresConnection::getInstance();
$pricing = new PricingService($db);
$rates = new ExchangeRateService($db);
$logger = new UsageLogger($db, $pricing, $rates);

// Setup: testowa konwersacja
$testSessionId = 'test-usage-logger-' . bin2hex(random_bytes(8));
$row = $db->fetchOne(
    "INSERT INTO divechat_conversations (session_id, ps_customer_id, messages)
     VALUES (?, NULL, '[]'::jsonb) RETURNING id",
    [$testSessionId],
);
$conversationId = (int) $row['id'];
echo "Test conversation_id: {$conversationId}\n";

try {
    // 1) logMessage – Claude Haiku z cache
    $cost1 = $logger->logMessage(
        $conversationId, null, 'claude-haiku-4-5',
        inputTokens: 10_000,
        outputTokens: 2_000,
        cacheReadTokens: 5_000,
        cacheCreationTokens: 0,
    );
    $expectedTotal1 = round(
        (10_000 / 1_000_000) * 1.0
        + (2_000 / 1_000_000) * 5.0
        + (5_000 / 1_000_000) * 0.1,
        6,
    );
    assertEq('1st call total', $cost1->costTotalUsd, $expectedTotal1, 1e-7);

    // 2) logMessage – GPT-4.1
    $cost2 = $logger->logMessage(
        $conversationId, null, 'gpt-4.1',
        inputTokens: 5_000,
        outputTokens: 1_000,
    );
    $expectedTotal2 = round(
        (5_000 / 1_000_000) * 2.0
        + (1_000 / 1_000_000) * 8.0,
        6,
    );
    assertEq('2nd call total', $cost2->costTotalUsd, $expectedTotal2, 1e-7);

    // 3) Sprawdź agregaty na rozmowie
    $convRow = $db->fetchOne(
        'SELECT tokens_input, tokens_output, cache_read_tokens, cache_creation_tokens, estimated_cost
         FROM divechat_conversations WHERE id = ?',
        [$conversationId],
    );
    assertEq('agg.tokens_input', (int) $convRow['tokens_input'], 15_000);
    assertEq('agg.tokens_output', (int) $convRow['tokens_output'], 3_000);
    assertEq('agg.cache_read_tokens', (int) $convRow['cache_read_tokens'], 5_000);
    assertEq('agg.cache_creation_tokens', (int) $convRow['cache_creation_tokens'], 0);
    $expectedAggCost = round($expectedTotal1 + $expectedTotal2, 6);
    assertEq('agg.estimated_cost', (float) $convRow['estimated_cost'], $expectedAggCost, 1e-5);

    // 4) Sprawdź wpisy w divechat_message_usage
    $usageRows = $db->fetchAll(
        'SELECT model_id, input_tokens, output_tokens, cost_total_usd
         FROM divechat_message_usage
         WHERE conversation_id = ? ORDER BY id',
        [$conversationId],
    );
    assertEq('usage rows count', count($usageRows), 2);
    assertEq('usage[0].model', $usageRows[0]['model_id'], 'claude-haiku-4-5');
    assertEq('usage[1].model', $usageRows[1]['model_id'], 'gpt-4.1');

    // 5) getConversationCost
    $convCost = $logger->getConversationCost($conversationId);
    assertEq('convCost.input_tokens', $convCost->totalInputTokens, 15_000);
    assertEq('convCost.output_tokens', $convCost->totalOutputTokens, 3_000);
    assertEq('convCost.message_count', $convCost->messageCount, 2);
    assertEq('convCost.usd', $convCost->totalCostUsd, $expectedAggCost, 1e-5);
    assertEq('convCost.pln > 0 (rate available)', $convCost->totalCostPln > 0, true);

    // 6) toArray() snapshot
    $arr = $convCost->toArray();
    assertEq('toArray.message_count', $arr['message_count'], 2);
    assertEq('toArray.input_tokens', $arr['input_tokens'], 15_000);
} finally {
    // Cleanup – usuń testową konwersację (CASCADE usuwa message_usage).
    $db->query('DELETE FROM divechat_conversations WHERE id = ?', [$conversationId]);
    echo "Cleanup: usunięto conversation_id {$conversationId}\n";
}

echo "\nResult: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
