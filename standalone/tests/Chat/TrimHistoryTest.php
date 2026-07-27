<?php

declare(strict_types=1);

/**
 * Test jednostkowy ChatService::trimHistory (CHAT-T-159, decyzja 196a).
 * Okno 20 wiadomości + skracanie treści starszych tool_result do stuba,
 * przy zachowaniu spójności pary tool_use ↔ tool_result i startu od user.
 *
 * trimHistory jest prywatną metodą bez zależności od readonly properties —
 * instancję tworzymy bez konstruktora (newInstanceWithoutConstructor) i wołamy
 * przez ReflectionMethod. Bez bazy, bez sieci.
 *
 * Uruchomienie: ea-php84 standalone/tests/Chat/TrimHistoryTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Chat\ChatService;

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

$ref = new ReflectionClass(ChatService::class);
$service = $ref->newInstanceWithoutConstructor();
$trim = $ref->getMethod('trimHistory'); // publiczna dla refleksji od PHP 8.1

/** @return array wynik trimHistory dla podanej historii */
function runTrim(object $service, ReflectionMethod $trim, array $history): array
{
    return $trim->invoke($service, $history);
}

// Odczyt stałych przez refleksję (są private).
$MAX = $ref->getConstant('MAX_HISTORY_MESSAGES');
$KEEP = $ref->getConstant('KEEP_FULL_TOOL_RESULTS');

assertTest('MAX_HISTORY_MESSAGES == 20', $MAX === 20, "jest {$MAX}");
assertTest('KEEP_FULL_TOOL_RESULTS == 6', $KEEP === 6, "jest {$KEEP}");

// Helpery budujące wpisy historii.
$user = fn(string $t) => ['role' => 'user', 'content' => $t];
$assistant = fn(string $t) => ['role' => 'assistant', 'content' => $t];
$assistantTool = fn(string $t, string $id) => [
    'role' => 'assistant',
    'content' => $t,
    'tool_calls' => [['id' => $id, 'name' => 'search_products', 'arguments' => []]],
];
$toolResult = fn(string $id, string $payload) => [
    'role' => 'tool_result',
    'tool_call_id' => $id,
    'name' => 'search_products',
    'content' => $payload,
];

function isStub(string $content): bool
{
    $d = json_decode($content, true);
    return is_array($d) && ($d['trimmed'] ?? false) === true;
}

// --- Test 1: krótka historia (<= 20) bez tool_result — zwracana bez zmian ---
$short = [$user('cześć'), $assistant('witaj'), $user('co masz?'), $assistant('mamy sprzęt')];
$out = runTrim($service, $trim, $short);
assertTest('krótka historia bez zmian (count)', count($out) === 4);
assertTest('krótka historia bez zmian (treść)', $out === $short);

// --- Test 2: start od user wymuszony (odcięcie osieroconego tool_result) ---
$orphan = [
    $toolResult('tX', '{"products":[1,2,3]}'), // osierocony na starcie
    $assistant('podsumowanie'),
    $user('dziękuję'),
    $assistant('proszę'),
];
$out = runTrim($service, $trim, $orphan);
assertTest('start od user (pierwszy wpis to user)', ($out[0]['role'] ?? '') === 'user', 'rola: ' . ($out[0]['role'] ?? '?'));

// --- Test 3: przycięcie do 20 gdy historia dłuższa ---
$long = [];
for ($i = 0; $i < 30; $i++) {
    $long[] = ($i % 2 === 0) ? $user("u{$i}") : $assistant("a{$i}");
}
$out = runTrim($service, $trim, $long);
assertTest('długa historia przycięta do <= 20', count($out) <= 20, 'count=' . count($out));
assertTest('długa historia: start od user', ($out[0]['role'] ?? '') === 'user');

// --- Test 4: stub starych tool_result, świeże w całości ---
// Budujemy okno: pary (assistant-tool_call, tool_result) przeplatane user/assistant.
$hist = [];
$hist[] = $user('szukam płetw');                       // 0
$hist[] = $assistantTool('szukam', 't1');              // 1
$hist[] = $toolResult('t1', '{"products":["A","B","C"] }'); // 2  STARY -> stub
$hist[] = $assistant('mam kilka opcji');               // 3
$hist[] = $user('a maski?');                           // 4
$hist[] = $assistantTool('szukam masek', 't2');        // 5
$hist[] = $toolResult('t2', '{"products":["D","E"] }');// 6  STARY -> stub
$hist[] = $assistant('oto maski');                     // 7
$hist[] = $user('a fajki?');                           // 8
$hist[] = $assistantTool('szukam fajek', 't3');        // 9
$hist[] = $toolResult('t3', '{"products":["F"] }');    // 10 ŚWIEŻY (w ostatnich 6) -> całość
$hist[] = $assistant('oto fajki');                     // 11
// count = 12, keepFromIdx = 12 - 6 = 6 → indeksy < 6 skracane
$out = runTrim($service, $trim, $hist);
assertTest('okno 12 wpisów zachowane (count)', count($out) === 12, 'count=' . count($out));
// tool_result @idx2 (< 6) -> stub
assertTest('stary tool_result @2 zamieniony na stub', isStub($out[2]['content']));
// tool_result @idx6 (== keepFromIdx, NIE < 6) -> całość
assertTest('graniczny tool_result @6 w całości', !isStub($out[6]['content']) && $out[6]['content'] === $hist[6]['content']);
// tool_result @idx10 (świeży) -> całość
assertTest('świeży tool_result @10 w całości', !isStub($out[10]['content']) && $out[10]['content'] === $hist[10]['content']);

// --- Test 5: stub zachowuje nazwę narzędzia i strukturę ---
$stub = json_decode($out[2]['content'], true);
assertTest('stub: trimmed=true', ($stub['trimmed'] ?? null) === true);
assertTest('stub: tool=search_products', ($stub['tool'] ?? null) === 'search_products');
assertTest('stub: ma note', !empty($stub['note']));

// --- Test 6: para tool_use ↔ tool_result nienaruszona (wpis NIE znika) ---
// Każdy tool_call z assistanta ma odpowiadający tool_result po skróceniu.
$toolCallIds = [];
$toolResultIds = [];
foreach ($out as $m) {
    if (($m['role'] ?? '') === 'assistant' && !empty($m['tool_calls'])) {
        foreach ($m['tool_calls'] as $tc) {
            $toolCallIds[] = $tc['id'];
        }
    }
    if (($m['role'] ?? '') === 'tool_result') {
        $toolResultIds[] = $m['tool_call_id'];
    }
}
sort($toolCallIds);
sort($toolResultIds);
assertTest('każdy tool_call ma tool_result (pary spójne)', $toolCallIds === $toolResultIds,
    'calls=' . implode(',', $toolCallIds) . ' results=' . implode(',', $toolResultIds));

// --- Test 7: user/assistant tekstowe zawsze w całości (nawet stare) ---
assertTest('stary user @0 w całości', $out[0]['content'] === 'szukam płetw');
assertTest('stary assistant @3 w całości', $out[3]['content'] === 'mam kilka opcji');
assertTest('assistant tool_calls @1 nietknięte', !empty($out[1]['tool_calls']) && $out[1]['tool_calls'][0]['id'] === 't1');

// --- Test 8: dłuższa historia z tool_result — po przycięciu do 20 nadal spójna ---
$big = [];
for ($i = 0; $i < 8; $i++) {
    $id = "b{$i}";
    $big[] = $user("pytanie {$i}");
    $big[] = $assistantTool("szukam {$i}", $id);
    $big[] = $toolResult($id, '{"products":["' . str_repeat('X', 200) . '"]}');
    $big[] = $assistant("odpowiedź {$i}");
}
// 32 wpisy -> slice 20 -> shift do user
$out = runTrim($service, $trim, $big);
assertTest('big: przycięte do <= 20', count($out) <= 20, 'count=' . count($out));
assertTest('big: start od user', ($out[0]['role'] ?? '') === 'user');
// Spójność par po przycięciu
$cIds = [];
$rIds = [];
foreach ($out as $m) {
    if (($m['role'] ?? '') === 'assistant' && !empty($m['tool_calls'])) {
        foreach ($m['tool_calls'] as $tc) {
            $cIds[] = $tc['id'];
        }
    }
    if (($m['role'] ?? '') === 'tool_result') {
        $rIds[] = $m['tool_call_id'];
    }
}
sort($cIds);
sort($rIds);
assertTest('big: pary spójne po przycięciu', $cIds === $rIds,
    'calls=' . implode(',', $cIds) . ' results=' . implode(',', $rIds));
// Najnowsze 6 wpisów bez stuba na tool_result; starsze tool_result zestubowane
$cnt = count($out);
$anyStub = false;
$anyFullOld = false;
foreach ($out as $idx => $m) {
    if (($m['role'] ?? '') !== 'tool_result') {
        continue;
    }
    if ($idx < $cnt - 6) {
        if (isStub($m['content'])) {
            $anyStub = true;
        } else {
            $anyFullOld = true;
        }
    }
}
assertTest('big: są zestubowane stare tool_result', $anyStub);
assertTest('big: żaden stary tool_result nie został w całości', !$anyFullOld);

echo "\n==== WYNIK: {$passed} OK, {$failed} FAIL ====\n";
exit($failed > 0 ? 1 : 0);
