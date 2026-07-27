<?php

declare(strict_types=1);

/**
 * Test walidacji ChatController::resolveChipPath (CHAT-T-122, ADR-110).
 * Czysta walidacja wejscia — bez PostgreSQL ani zaleznosci; instancje tworzymy
 * przez newInstanceWithoutConstructor (metoda nie uzywa stanu obiektu), a
 * prywatna resolveChipPath cwiczona przez Reflection.
 *
 * Kontrakt (HANDOFF_chip_path_kontrakt.md §Kontrakt backendu):
 *  - nie-tablica / pusta -> null
 *  - element: node_key (^[a-z0-9_]+$, cap 64), label (cap 120), level int 1..6
 *  - zly element pomijany; cap dlugosci tablicy 8; pusto po walidacji -> null
 *
 * Uruchomienie: php standalone/tests/Controller/ChatControllerChipPathTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Controller\ChatController;

$passed = 0;
$failed = 0;

function assertT(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[OK] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

$ref = new ReflectionClass(ChatController::class);
$ctrl = $ref->newInstanceWithoutConstructor();
$m = $ref->getMethod('resolveChipPath'); // prywatna dostepna przez Reflection (PHP 8.1+)

$resolve = static fn (mixed $in): ?array => $m->invoke($ctrl, $in);

// --- Wejscia puste / zle typy -> null ---
assertT('null -> null', $resolve(null) === null);
assertT('[] -> null', $resolve([]) === null);
assertT('string -> null', $resolve('doborsprzetu') === null);
assertT('int -> null', $resolve(123) === null);

// --- Poprawna sciezka ---
$ok = $resolve([
    ['node_key' => 'doborsprzetu', 'label' => 'Dobór sprzętu', 'level' => 2],
    ['node_key' => 'komputernurkowy', 'label' => 'Komputer nurkowy', 'level' => 3],
]);
assertT('poprawna sciezka: 2 elementy', is_array($ok) && count($ok) === 2, var_export($ok, true));
assertT('zachowuje kolejnosc + pola', $ok[0]['node_key'] === 'doborsprzetu' && $ok[1]['level'] === 3);
assertT('polskie znaki w label bez utraty', $ok[0]['label'] === 'Dobór sprzętu');

// --- level jako numeryczny string akceptowany ---
$lvlStr = $resolve([['node_key' => 'a', 'label' => 'A', 'level' => '2']]);
assertT('level "2" (string) -> int 2', is_array($lvlStr) && $lvlStr[0]['level'] === 2);

// --- Zly element pomijany (nie wywala calosci) ---
$mixed = $resolve([
    ['node_key' => 'DobOr!', 'label' => 'zly klucz', 'level' => 2], // node_key nie pasuje
    ['node_key' => 'komputer', 'label' => 'Komputer', 'level' => 3], // OK
    ['node_key' => 'x', 'label' => 'zly level', 'level' => 9],        // level > 6
    ['node_key' => 'y', 'label' => '', 'level' => 2],                 // pusty label
]);
assertT('zle elementy pominiete, zostaje 1', is_array($mixed) && count($mixed) === 1, var_export($mixed, true));
assertT('pozostaje wlasciwy element', $mixed[0]['node_key'] === 'komputer');

// --- Same zle elementy -> null (nie pusta tablica) ---
$allBad = $resolve([['node_key' => 'ZŁY', 'label' => 'x', 'level' => 99]]);
assertT('same zle elementy -> null', $allBad === null);

// --- Cap dlugosci tablicy = 8 ---
$long = [];
for ($i = 0; $i < 12; $i++) {
    $long[] = ['node_key' => 'n' . $i, 'label' => 'L' . $i, 'level' => 2];
}
$capped = $resolve($long);
assertT('cap tablicy do 8', is_array($capped) && count($capped) === 8, (string) (is_array($capped) ? count($capped) : 'null'));

// --- Cap dlugosci label = 120 ---
$longLabel = str_repeat('ą', 200);
$capLabel = $resolve([['node_key' => 'a', 'label' => $longLabel, 'level' => 2]]);
assertT('cap label do 120 znakow', is_array($capLabel) && mb_strlen($capLabel[0]['label']) === 120);

// --- node_key cap 64: 64 ok, 65 odrzucony ---
$k64 = str_repeat('a', 64);
$k65 = str_repeat('a', 65);
assertT('node_key 64 znaki OK', $resolve([['node_key' => $k64, 'label' => 'x', 'level' => 1]]) !== null);
assertT('node_key 65 znakow odrzucony -> null', $resolve([['node_key' => $k65, 'label' => 'x', 'level' => 1]]) === null);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
