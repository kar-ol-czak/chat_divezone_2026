<?php

declare(strict_types=1);

/**
 * Test integracyjny PricingService.
 * Wymaga: DATABASE_URL + tabela divechat_model_pricing zaseedowana (TASK-052a).
 *
 * Uruchomienie: php standalone/tests/PricingServiceTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use DiveChat\AI\PricingService;
use DiveChat\Database\PostgresConnection;

// Ładuj .env (analogicznie do ExpertKnowledgeTest)
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

echo "=== PricingService ===\n";

$db = PostgresConnection::getInstance();
$pricing = new PricingService($db);

// 1) getPrice / getAllActive
$haiku = $pricing->getPrice('claude-haiku-4-5');
assertEq('haiku exists', $haiku !== null, true);
assertEq('haiku.input', $haiku->inputPricePerMillion, 1.0, 1e-4);
assertEq('haiku.output', $haiku->outputPricePerMillion, 5.0, 1e-4);
assertEq('haiku.cache_read', $haiku->cacheReadPricePerMillion, 0.1, 1e-4);
assertEq('haiku.is_active', $haiku->isActive, true);
assertEq('haiku.is_escalation', $haiku->isEscalation, false);

$gpt41 = $pricing->getPrice('gpt-4.1');
assertEq('gpt-4.1.input', $gpt41->inputPricePerMillion, 2.0, 1e-4);
assertEq('gpt-4.1.cache_read=null', $gpt41->cacheReadPricePerMillion, null);
assertEq('gpt-4.1.supports_temperature', $gpt41->supportsTemperature, true);
assertEq('gpt-4.1.supports_reasoning_effort', $gpt41->supportsReasoningEffort, false);

$opus = $pricing->getPrice('claude-opus-4-7');
assertEq('opus.is_escalation', $opus->isEscalation, true);

$unknown = $pricing->getPrice('non-existent-model');
assertEq('unknown=null', $unknown, null);

$active = $pricing->getAllActive();
assertEq('active count = 8', count($active), 8);

// 2) calculateCost – haiku, 1M in / 1M out / 0 cache
$cost = $pricing->calculateCost('claude-haiku-4-5', 1_000_000, 1_000_000);
assertEq('haiku 1M/1M input cost', $cost->costInputUsd, 1.0, 1e-4);
assertEq('haiku 1M/1M output cost', $cost->costOutputUsd, 5.0, 1e-4);
assertEq('haiku 1M/1M cache cost', $cost->costCacheUsd, 0.0);
assertEq('haiku 1M/1M total cost', $cost->costTotalUsd, 6.0, 1e-4);

// 3) calculateCost – haiku z cache_read 100k
$cost2 = $pricing->calculateCost('claude-haiku-4-5', 1000, 500, 100_000, 0);
$expectedInput = (1000 / 1_000_000) * 1.0;       // 0.001
$expectedOutput = (500 / 1_000_000) * 5.0;       // 0.0025
$expectedCache = (100_000 / 1_000_000) * 0.1;    // 0.01
$expectedTotal = round($expectedInput + $expectedOutput + $expectedCache, 6);
assertEq('haiku w/cache input', $cost2->costInputUsd, round($expectedInput, 6), 1e-7);
assertEq('haiku w/cache output', $cost2->costOutputUsd, round($expectedOutput, 6), 1e-7);
assertEq('haiku w/cache cache', $cost2->costCacheUsd, round($expectedCache, 6), 1e-7);
assertEq('haiku w/cache total', $cost2->costTotalUsd, $expectedTotal, 1e-7);

// 4) calculateCost – gpt-4.1 (brak cache pricing)
$cost3 = $pricing->calculateCost('gpt-4.1', 500_000, 100_000, 50_000, 0);
$expectedInput3 = (500_000 / 1_000_000) * 2.0;   // 1.0
$expectedOutput3 = (100_000 / 1_000_000) * 8.0;  // 0.8
assertEq('gpt-4.1 input', $cost3->costInputUsd, $expectedInput3, 1e-7);
assertEq('gpt-4.1 output', $cost3->costOutputUsd, $expectedOutput3, 1e-7);
assertEq('gpt-4.1 cache=0 (no pricing)', $cost3->costCacheUsd, 0.0);

// 5) calculateCost – nieznany model → 0
$cost4 = $pricing->calculateCost('non-existent-model', 1000, 1000);
assertEq('unknown.total=0', $cost4->costTotalUsd, 0.0);

// 6) updatePrice + cache invalidation
$origHaiku = $pricing->getPrice('claude-haiku-4-5')->inputPricePerMillion;
$pricing->updatePrice('claude-haiku-4-5', ['input_price_per_million' => 1.5000]);
$updated = $pricing->getPrice('claude-haiku-4-5')->inputPricePerMillion;
assertEq('haiku price after update', $updated, 1.5, 1e-4);
// rollback
$pricing->updatePrice('claude-haiku-4-5', ['input_price_per_million' => $origHaiku]);
$restored = $pricing->getPrice('claude-haiku-4-5')->inputPricePerMillion;
assertEq('haiku price restored', $restored, 1.0, 1e-4);

echo "\nResult: {$passed} passed, {$failed} failed.\n";
exit($failed === 0 ? 0 : 1);
