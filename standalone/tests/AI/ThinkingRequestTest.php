<?php

declare(strict_types=1);

/**
 * Test jednostkowy CHAT-T-177 (ADR-139): migracja Claude Sonnet 4.6 → Sonnet 5,
 * budget_tokens → adaptive thinking, usunięcie niedomyślnej temperatury.
 *
 * Sedno regresji, której pilnuje ten test: Sonnet 5 odrzuca BŁĘDEM 400 zarówno
 * `thinking: {type: enabled}` + `budget_tokens`, jak i niedomyślne `temperature`.
 * Każde z tych pól w żądaniu to całkowity zgon czatu na produkcji — dlatego
 * asercje są sformułowane negatywnie („tego pola NIE MA"), a nie tylko pozytywnie.
 *
 * buildRequestBody jest prywatna i nie dotyka readonly properties — instancję
 * tworzymy bez konstruktora (brak .env, brak sieci, brak bazy).
 *
 * Uruchomienie: ea-php84 standalone/tests/AI/ThinkingRequestTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\AI\ClaudeProvider;
use DiveChat\Enum\AIModel;

$passed = 0;
$failed = 0;

function assertTest(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[OK] {$name}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function body(string $model, array $options, int $effectiveMax = 1536): array
{
    $ref = new ReflectionMethod(ClaudeProvider::class, 'buildRequestBody');
    $provider = (new ReflectionClass(ClaudeProvider::class))->newInstanceWithoutConstructor();

    return $ref->invoke(
        $provider,
        $model,
        AIModel::tryFrom($model),
        $effectiveMax,
        [['role' => 'user', 'content' => 'Czy macie ABC Scuba?']],
        [['text' => 'PROMPT', 'cacheable' => true]],
        [],
        $options,
    );
}

// --- 1. Sonnet 5: adaptive thinking zamiast budget_tokens ------------------
// Produkcyjny wariant po migracji: settings.reasoning_effort = "minimal".
$out = body('claude-sonnet-5', ['effort' => 'minimal', 'temperature' => 0.6]);

assertTest('S5: NIE zawiera budget_tokens', !isset($out['thinking']['budget_tokens']), json_encode($out['thinking'] ?? null));
assertTest('S5: NIE zawiera type=enabled', ($out['thinking']['type'] ?? '') !== 'enabled');
assertTest('S5: NIE zawiera temperature', !array_key_exists('temperature', $out));
assertTest('S5: NIE zawiera top_p', !array_key_exists('top_p', $out));
assertTest('S5: NIE zawiera top_k', !array_key_exists('top_k', $out));
assertTest('S5: thinking type=adaptive', ($out['thinking']['type'] ?? '') === 'adaptive');
assertTest('S5: display=omitted (nic nie czyta thinking)', ($out['thinking']['display'] ?? '') === 'omitted');
assertTest('S5: effort w output_config, nie top-level', isset($out['output_config']['effort']) && !isset($out['effort']));
assertTest('S5: minimal → low (decyzja Q32a)', ($out['output_config']['effort'] ?? '') === 'low', (string) ($out['output_config']['effort'] ?? 'brak'));
assertTest('S5: model w body', ($out['model'] ?? '') === 'claude-sonnet-5');

// --- 2. Sonnet 5: max_tokens mieści myślenie ORAZ odpowiedź ----------------
// max_tokens=1536 z ustawień nie może zostać w body — na Claude obejmuje
// myślenie i tekst razem, więc bez rezerwy adaptive ucinałoby odpowiedź.
assertTest('S5: max_tokens podbity ponad ustawienia', ($out['max_tokens'] ?? 0) > 1536, (string) ($out['max_tokens'] ?? 0));
assertTest('S5: max_tokens = headroom(minimal) + 4096 (jak przed migracją)', ($out['max_tokens'] ?? 0) === 1024 + 4096);
assertTest(
    'S5: wyższe max_tokens z ustawień wygrywa',
    (body('claude-sonnet-5', ['effort' => 'minimal'], 20000)['max_tokens'] ?? 0) === 20000,
);

// --- 3. Sonnet 5: brak effortu NIE zostawia domyślnego high ----------------
$noEffort = body('claude-sonnet-5', []);
assertTest('S5: bez effortu nadal thinking adaptive', ($noEffort['thinking']['type'] ?? '') === 'adaptive');
assertTest('S5: bez effortu effort=low, nie domyślny high', ($noEffort['output_config']['effort'] ?? '') === 'low');
assertTest('S5: bez effortu brak budget_tokens', !isset($noEffort['thinking']['budget_tokens']));

// --- 4. Sonnet 5: legacy int effort też nie przecieka jako budget_tokens ---
$legacyInt = body('claude-sonnet-5', ['effort' => 8192]);
assertTest('S5: int effort NIE staje się budget_tokens', !isset($legacyInt['thinking']['budget_tokens']));
assertTest('S5: int effort → nadal adaptive', ($legacyInt['thinking']['type'] ?? '') === 'adaptive');
assertTest('S5: int effort zasila rezerwę max_tokens', ($legacyInt['max_tokens'] ?? 0) === 8192 + 4096);

// --- 5. Wsteczna kompatybilność: Sonnet 4.6 bez zmian ---------------------
$old = body('claude-sonnet-4-6', ['effort' => 'minimal', 'temperature' => 0.6]);
assertTest('S4.6: thinking type=enabled', ($old['thinking']['type'] ?? '') === 'enabled');
assertTest('S4.6: budget_tokens = 1024', ($old['thinking']['budget_tokens'] ?? 0) === 1024);
assertTest('S4.6: brak output_config', !isset($old['output_config']));
assertTest('S4.6: brak temperature (jest thinking)', !array_key_exists('temperature', $old));
assertTest('S4.6: max_tokens = 1024 + 4096', ($old['max_tokens'] ?? 0) === 1024 + 4096);

$opus = body('claude-opus-4-7', ['effort' => 'high']);
assertTest('Opus 4.7: nadal budget_tokens = 16384', ($opus['thinking']['budget_tokens'] ?? 0) === 16384);
assertTest('Opus 4.7: brak output_config', !isset($opus['output_config']));

// --- 6. Model spoza rejestru: bez thinking, bez wywrotki ------------------
$unknown = body('claude-jakis-nowy', ['effort' => 'minimal', 'temperature' => 0.6]);
assertTest('nieznany model: brak thinking', !isset($unknown['thinking']));
assertTest('nieznany model: brak output_config', !isset($unknown['output_config']));
assertTest('nieznany model: brak temperature', !array_key_exists('temperature', $unknown));
assertTest('nieznany model: max_tokens z ustawień', ($unknown['max_tokens'] ?? 0) === 1536);

// --- 7. Blok system (T-176) przeżył refaktor ------------------------------
assertTest('system nadal składany z cache_control', isset($out['system'][0]['cache_control']));
assertTest('system to bloki text', ($out['system'][0]['type'] ?? '') === 'text');

// --- 8. Flagi modelu (AIModel) -------------------------------------------
assertTest('AIModel: Sonnet 5 ma adaptive', AIModel::CLAUDE_SONNET_5->supportsAdaptiveThinking());
assertTest('AIModel: Sonnet 5 odrzuca temperaturę', AIModel::CLAUDE_SONNET_5->rejectsNonDefaultTemperature());
assertTest('AIModel: Sonnet 5 wspiera effort', AIModel::CLAUDE_SONNET_5->supportsReasoningEffort());
assertTest('AIModel: Sonnet 5 to provider claude', AIModel::CLAUDE_SONNET_5->provider() === 'claude');
assertTest('AIModel: Sonnet 5 to tier primary', AIModel::CLAUDE_SONNET_5->tier() === 'primary');
assertTest('AIModel: Sonnet 4.6 BEZ adaptive', !AIModel::CLAUDE_SONNET_46->supportsAdaptiveThinking());
assertTest('AIModel: Sonnet 4.6 nie odrzuca temperatury', !AIModel::CLAUDE_SONNET_46->rejectsNonDefaultTemperature());
assertTest('AIModel: Opus 4.7 BEZ adaptive', !AIModel::CLAUDE_OPUS_47->supportsAdaptiveThinking());
assertTest('AIModel: S5 minimal → low', AIModel::CLAUDE_SONNET_5->mapEffortToProviderValue('minimal') === 'low');
assertTest('AIModel: S5 medium → medium', AIModel::CLAUDE_SONNET_5->mapEffortToProviderValue('medium') === 'medium');
assertTest('AIModel: S5 high → high', AIModel::CLAUDE_SONNET_5->mapEffortToProviderValue('high') === 'high');
assertTest('AIModel: S4.6 minimal → 1024 (int)', AIModel::CLAUDE_SONNET_46->mapEffortToProviderValue('minimal') === 1024);
assertTest('AIModel: GPT-4.1 bez effortu', AIModel::GPT_41->mapEffortToProviderValue('minimal') === null);
assertTest('AIModel: GPT-5.5 effort nadal string', AIModel::GPT_55->mapEffortToProviderValue('minimal') === 'minimal');

echo "\n==== WYNIK: {$passed} OK, {$failed} FAIL ====\n";
exit($failed === 0 ? 0 : 1);
