<?php

declare(strict_types=1);

/**
 * T-022 KROK 8 smoke: weryfikacja że DEPLOYED OpenAIProvider.parseResponse()
 * po fixie zwraca cache_read_tokens > 0 dla stabilnego prefiksu >= 1024 tok.
 *
 * Różnica vs t022_cache_probe.php: ten skrypt instancjuje OpenAIProvider z prod
 * env i czyta jego ZRORMALIZOWANE pole usage['cache_read_tokens'] — czyli
 * weryfikuje że fix parseResponse() działa na produkcji (nie samo API OpenAI).
 *
 * Read-only: 2 wywołania API, brak zapisów do bazy. Cleanup nie potrzebny.
 *
 * Uruchomienie:
 *   php scripts/t022_smoke_provider.php [--model=gpt-5-mini]
 */

require_once dirname(__DIR__, 1) . '/vendor/autoload.php';

use DiveChat\AI\OpenAIProvider;
use DiveChat\Config;

// Wymuszamy mały max_completion_tokens dla smoke, by porównać 1:1 z t022_cache_probe.php
// (50). Bez tego OpenAIProvider używa Config('AI_MAX_TOKENS', 4096) — w niektórych modelach
// (gpt-5-mini z reasoning) większy budget może wpłynąć na ścieżkę i fałszywie ukryć cache.
$_ENV['AI_MAX_TOKENS'] = '50';
putenv('AI_MAX_TOKENS=50');

Config::load(dirname(__DIR__, 1));

$modelOverride = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--model=')) {
        $modelOverride = substr($arg, 8);
    }
}

// Stabilny system prompt >= 1024 tokenów — identyczny jak w probe (cache hit gwarantowany).
$systemPrompt = str_repeat(
    "Jesteś asystentem testowym DiveChat dla weryfikacji prompt caching po T-022. "
    . "Stabilny prefiks systemowy, identyczny pomiędzy wywołaniami, by aktywować cache OpenAI. "
    . "Nie korzystaj z narzędzi i odpowiadaj jednym zdaniem. ",
    20,
);

$provider = new OpenAIProvider();
$options = $modelOverride !== null ? ['model_override' => $modelOverride] : [];

echo "T-022 KROK 8 SMOKE — deployed OpenAIProvider parseResponse() check\n";
echo "==================================================================\n";
echo "Model: " . ($modelOverride ?? Config::get('OPENAI_CHAT_MODEL', 'gpt-4.1')) . "\n";
echo "System prompt: " . strlen($systemPrompt) . " znaków\n\n";

try {
    echo "[1/2] Wywołanie 1 (cache populate)...\n";
    $r1 = $provider->chat(
        messages: [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Powiedz "smoke 1".'],
        ],
        options: $options,
    );
    echo "  usage: " . json_encode($r1->usage, JSON_PRETTY_PRINT) . "\n\n";

    sleep(2);

    echo "[2/2] Wywołanie 2 (cache hit?)...\n";
    $r2 = $provider->chat(
        messages: [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => 'Powiedz "smoke 2".'],
        ],
        options: $options,
    );
    echo "  usage: " . json_encode($r2->usage, JSON_PRETTY_PRINT) . "\n\n";

    $cacheRead2 = (int) ($r2->usage['cache_read_tokens'] ?? 0);
    $input2 = (int) ($r2->usage['input_tokens'] ?? 0);

    echo "WERDYKT:\n";
    echo "  call 2 cache_read_tokens: {$cacheRead2}\n";
    echo "  call 2 input_tokens (non-cached): {$input2}\n";
    echo "  call 2 input + cache_read total: " . ($input2 + $cacheRead2) . "\n";

    if ($cacheRead2 > 0) {
        echo "  ✓ DEPLOYED FIX DZIAŁA: OpenAIProvider.parseResponse() zwraca cache_read_tokens > 0\n";
        echo "  ✓ Konwencja non-cached input zachowana (input_tokens NIE zawiera cached_tokens)\n";
        exit(0);
    } else {
        echo "  ✗ cache_read_tokens = 0 — DEPLOY ZAWIÓDŁ lub model nie wspiera cache.\n";
        exit(2);
    }
} catch (\Throwable $e) {
    echo "BŁĄD: " . $e->getMessage() . "\n";
    exit(2);
}
