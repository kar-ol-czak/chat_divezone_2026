<?php

declare(strict_types=1);

/**
 * Test jednostkowy ChipTreeService::buildTree (ADR-096, CHAT-T-088).
 * Czyste złożenie zagnieżdżenia po parent_id — bez PostgreSQL.
 * (Filtr active=true to zadanie zapytania SQL; buildTree dostaje już aktywne wiersze.)
 *
 * Uruchomienie: php standalone/tests/Chip/ChipTreeServiceTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Chip\ChipTreeService;

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

// Płaskie wiersze jak z divechat_chip_nodes (buttons = JSON string z PG, parent_id int|null).
// Dzieci roota podane w KOLEJNOŚCI NIE-POSORTOWANEJ (dobor, zwroty, serwis, wysylka)
// — buildTree ma posortować po sort_order.
$rows = [
    ['id' => 1, 'node_key' => 'root', 'parent_id' => null, 'level' => 1, 'sort_order' => 0,
     'bot_text' => 'W czym mogę pomóc?', 'buttons' => '[]', 'context_hint' => null, 'model_level' => null],
    ['id' => 5, 'node_key' => 'dobor', 'parent_id' => 1, 'level' => 2, 'sort_order' => 4,
     'bot_text' => 'Pomogę dobrać sprzęt.', 'buttons' => '[{"label":"Napisz czego szukasz","target":"ai"}]', 'context_hint' => null, 'model_level' => null],
    ['id' => 2, 'node_key' => 'zwroty', 'parent_id' => 1, 'level' => 2, 'sort_order' => 1,
     'bot_text' => 'Masz 30 dni na zwrot.', 'buttons' => '[{"label":"Formularz i szczegóły","target":"link:link_zwroty"},{"label":"Inne pytanie","target":"ai"}]', 'context_hint' => null, 'model_level' => null],
    ['id' => 3, 'node_key' => 'serwis', 'parent_id' => 1, 'level' => 2, 'sort_order' => 2,
     'bot_text' => 'Serwisujemy automaty...', 'buttons' => '[{"label":"Pełny cennik","target":"link:link_serwis"},{"label":"Umów serwis","target":"link:link_kontakt"},{"label":"Inne pytanie","target":"ai"}]', 'context_hint' => null, 'model_level' => null],
    ['id' => 4, 'node_key' => 'wysylka', 'parent_id' => 1, 'level' => 2, 'sort_order' => 3,
     'bot_text' => 'Do 15:00 wysyłamy tego samego dnia.', 'buttons' => '[{"label":"Koszty i metody dostawy","target":"ai"},{"label":"Inne pytanie","target":"ai"}]', 'context_hint' => null, 'model_level' => null],
    // Poziom 3 pod dobor — test rekurencji.
    ['id' => 10, 'node_key' => 'dobor_skafander', 'parent_id' => 5, 'level' => 3, 'sort_order' => 1,
     'bot_text' => null, 'buttons' => '[]', 'context_hint' => null, 'model_level' => 'primary'],
];

$tree = ChipTreeService::buildTree($rows);

// === Struktura korzeni ===
assertT('1 korzeń', count($tree) === 1, 'got ' . count($tree));
$root = $tree[0];
assertT('root.node_key=root', $root['node_key'] === 'root');
assertT('root ma 4 dzieci', count($root['children']) === 4, 'got ' . count($root['children']));

// === Kolejność dzieci po sort_order (zwroty,serwis,wysylka,dobor) ===
$order = array_map(static fn(array $c): string => $c['node_key'], $root['children']);
assertT('kolejność po sort_order', $order === ['zwroty', 'serwis', 'wysylka', 'dobor'], 'got ' . implode(',', $order));

// === Węzeł hybrydowy: serwis ma bot_text ORAZ buttons ===
$serwis = $root['children'][1];
assertT('serwis: bot_text niepusty', is_string($serwis['bot_text']) && $serwis['bot_text'] !== '');
assertT('serwis: 3 przyciski (hybryda)', count($serwis['buttons']) === 3, 'got ' . count($serwis['buttons']));
assertT('serwis: przycisk link:link_serwis', $serwis['buttons'][0]['target'] === 'link:link_serwis');
assertT('serwis: przycisk Umów serwis → link:link_kontakt', $serwis['buttons'][1]['target'] === 'link:link_kontakt');

// === buttons zdekodowane z JSON string ===
$zwroty = $root['children'][0];
assertT('zwroty: buttons zdekodowane (2)', count($zwroty['buttons']) === 2);
assertT('zwroty: target link:link_zwroty', $zwroty['buttons'][0]['target'] === 'link:link_zwroty');

// === Rekurencja: dobor ma dziecko poziomu 3 ===
$dobor = $root['children'][3];
assertT('dobor ma 1 dziecko (poziom 3)', count($dobor['children']) === 1, 'got ' . count($dobor['children']));
assertT('dobor.dziecko.node_key=dobor_skafander', $dobor['children'][0]['node_key'] === 'dobor_skafander');
assertT('liść poziom 3: children pusty', $dobor['children'][0]['children'] === []);
assertT('liść poziom 3: bot_text null', $dobor['children'][0]['bot_text'] === null);
assertT('liść poziom 3: model_level=primary', $dobor['children'][0]['model_level'] === 'primary');

// === Kontrakt: pola wewnętrzne NIE wychodzą ===
$keys = array_keys($root);
sort($keys);
assertT('kontrakt: dokładnie 6 pól', $keys === ['bot_text', 'buttons', 'children', 'context_hint', 'model_level', 'node_key'], 'got ' . implode(',', $keys));
assertT('kontrakt: brak id', !array_key_exists('id', $root));
assertT('kontrakt: brak parent_id', !array_key_exists('parent_id', $root));
assertT('kontrakt: brak _sort', !array_key_exists('_sort', $root));

// === Robustność decodeButtons: wpis bez target pomijany ===
$bad = ChipTreeService::buildTree([
    ['id' => 1, 'node_key' => 'x', 'parent_id' => null, 'level' => 1, 'sort_order' => 0,
     'bot_text' => null, 'buttons' => '[{"label":"bez_target"},{"label":"ok","target":"ai"}]', 'context_hint' => null, 'model_level' => null],
]);
assertT('decodeButtons: pomija wpis bez target', count($bad[0]['buttons']) === 1);
assertT('decodeButtons: zachowuje poprawny wpis', $bad[0]['buttons'][0]['target'] === 'ai');

// === Pusty input → puste drzewo ===
assertT('pusty input → []', ChipTreeService::buildTree([]) === []);

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
