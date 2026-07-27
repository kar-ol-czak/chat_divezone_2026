<?php

declare(strict_types=1);

/**
 * Test jednostkowy CHAT-T-176 (ADR-138): rozbicie system promptu na blok STAŁY
 * (cache'owany prefiks) i ZMIENNY (data, kontekst chipów) + składanie bloków
 * `system` przez ClaudeProvider z JEDNYM breakpointem cache.
 *
 * Sedno regresji, której pilnuje ten test: cache_control MUSI wisieć na ostatnim
 * bloku cache'owalnym, a NIE na ostatnim bloku w ogóle — inaczej zmienna data
 * wraca do cache'owanego prefiksu i unieważnia ~45 tys. tokenów przy każdej
 * zmianie doby (dokładnie ta usterka, którą T-176 naprawia).
 *
 * buildSystemBlocks jest prywatna i nie dotyka readonly properties — instancję
 * tworzymy bez konstruktora (brak .env, brak sieci, brak bazy).
 *
 * Uruchomienie: ea-php84 standalone/tests/AI/SystemBlocksTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\AI\ClaudeProvider;
use DiveChat\Chat\SystemPrompt;

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

/** @return list<array<string, mixed>> */
function blocks(array $parts): array
{
    $ref = new ReflectionMethod(ClaudeProvider::class, 'buildSystemBlocks');
    $provider = (new ReflectionClass(ClaudeProvider::class))->newInstanceWithoutConstructor();
    return $ref->invoke($provider, $parts);
}

// --- 1. Układ produkcyjny: stały (cacheable) + zmienny (nie) ---------------
$out = blocks([
    ['text' => 'STALY', 'cacheable' => true],
    ['text' => 'DATA', 'cacheable' => false],
]);
assertTest('dwa bloki na wyjściu', count($out) === 2);
assertTest('breakpoint na bloku STAŁYM', isset($out[0]['cache_control']));
assertTest('brak breakpointu na bloku ZMIENNYM', !isset($out[1]['cache_control']));
assertTest('kolejność zachowana', ($out[0]['text'] ?? '') === 'STALY' && ($out[1]['text'] ?? '') === 'DATA');
assertTest('typ bloku to text', ($out[0]['type'] ?? '') === 'text');

// --- 2. Wsteczna zgodność: jeden blok bez flagi = cacheable ----------------
$out = blocks([['text' => 'STARY POJEDYNCZY', 'cacheable' => true]]);
assertTest('pojedynczy blok dostaje cache_control', isset($out[0]['cache_control']));
assertTest('cache_control = ephemeral', ($out[0]['cache_control']['type'] ?? '') === 'ephemeral');

// --- 3. Puste bloki wypadają (Anthropic odrzuca pusty blok tekstowy) -------
$out = blocks([
    ['text' => 'STALY', 'cacheable' => true],
    ['text' => '', 'cacheable' => false],
]);
assertTest('pusty blok pominięty', count($out) === 1);
assertTest('breakpoint nadal na stałym', isset($out[0]['cache_control']));
assertTest('brak bloków gdy wszystko puste', blocks([['text' => '', 'cacheable' => true]]) === []);

// --- 4. Gdy nic nie jest cacheable — wysyłamy bez cache_control ------------
$out = blocks([['text' => 'A', 'cacheable' => false], ['text' => 'B', 'cacheable' => false]]);
assertTest('brak breakpointu gdy nic nie cacheable', !isset($out[0]['cache_control']) && !isset($out[1]['cache_control']));

// --- 5. Breakpoint na OSTATNIM cacheable, nie na pierwszym -----------------
$out = blocks([
    ['text' => 'A', 'cacheable' => true],
    ['text' => 'B', 'cacheable' => true],
    ['text' => 'C', 'cacheable' => false],
]);
assertTest('breakpoint na ostatnim cacheable (B)', !isset($out[0]['cache_control']) && isset($out[1]['cache_control']));

// --- 6. SystemPrompt: blok stały naprawdę stały ----------------------------
$tz = new DateTimeZone('Europe/Warsaw');
$static1 = SystemPrompt::buildStatic(true);
$static2 = SystemPrompt::buildStatic(true);
assertTest('buildStatic deterministyczny', $static1 === $static2);
assertTest('buildStatic bez kotwicy daty', !str_contains($static1, 'AKTUALNA DATA:'));
assertTest(
    'buildStatic bez wstrzykniętej daty bieżącej',
    !str_contains($static1, (new DateTimeImmutable('now', $tz))->format('Y-m-d')),
);
assertTest('buildStatic reaguje na emoji', SystemPrompt::buildStatic(false) !== $static1);

// --- 7. SystemPrompt: blok zmienny niesie kotwicę --------------------------
$vol = SystemPrompt::buildVolatile(new DateTimeImmutable('2026-07-27 10:00', $tz));
assertTest('buildVolatile ma dziś', str_contains($vol, 'poniedziałek 2026-07-27'));
assertTest('buildVolatile ma jutro', str_contains($vol, 'wtorek 2026-07-28'));
assertTest(
    'buildVolatile zmienia się z dobą',
    SystemPrompt::buildVolatile(new DateTimeImmutable('2026-07-28 10:00', $tz)) !== $vol,
);

// --- 8. build() = kompatybilność wsteczna ----------------------------------
$now = new DateTimeImmutable('2026-07-27 10:00', $tz);
assertTest(
    'build() skleja stały + zmienny',
    SystemPrompt::build(true, $now) === SystemPrompt::buildStatic(true) . "\n\n" . SystemPrompt::buildVolatile($now),
);

echo "\n==== WYNIK: {$passed} OK, {$failed} FAIL ====\n";
exit($failed === 0 ? 0 : 1);
