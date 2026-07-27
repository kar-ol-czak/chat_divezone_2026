<?php

declare(strict_types=1);

/**
 * Test jednostkowy ChipTreeService::buildTree (ADR-096, CHAT-T-088 + 088b).
 * Czyste złożenie zagnieżdżenia po parent_id + label + rozwijanie link: na URL —
 * bez PostgreSQL (linkMap wstrzykiwana jako argument).
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
     'label' => 'W czym mogę pomóc?', 'bot_text' => 'W czym mogę pomóc?', 'buttons' => '[]', 'context_hint' => null, 'model_level' => null],
    ['id' => 5, 'node_key' => 'dobor', 'parent_id' => 1, 'level' => 2, 'sort_order' => 4,
     'label' => 'Dobór sprzętu', 'bot_text' => 'Pomogę dobrać sprzęt.', 'buttons' => '[{"label":"Napisz czego szukasz","target":"ai"}]', 'context_hint' => null, 'model_level' => null, 'ai_prompt' => null],
    ['id' => 2, 'node_key' => 'zwroty', 'parent_id' => 1, 'level' => 2, 'sort_order' => 1,
     'label' => 'Zwroty i wymiana', 'bot_text' => 'Masz 30 dni na zwrot.', 'buttons' => '[{"label":"Formularz i szczegóły","target":"link:link_zwroty"},{"label":"Inne pytanie","target":"ai"}]', 'context_hint' => null, 'model_level' => null, 'ai_prompt' => null],
    ['id' => 3, 'node_key' => 'serwis', 'parent_id' => 1, 'level' => 2, 'sort_order' => 2,
     'label' => 'Serwis automatu', 'bot_text' => 'Serwisujemy automaty...', 'buttons' => '[{"label":"Pełny cennik","target":"link:link_serwis"},{"label":"Umów serwis","target":"link:link_kontakt"},{"label":"Inne pytanie","target":"ai"}]', 'context_hint' => null, 'model_level' => null, 'ai_prompt' => null],
    ['id' => 4, 'node_key' => 'wysylka', 'parent_id' => 1, 'level' => 2, 'sort_order' => 3,
     'label' => 'Dostępność i wysyłka', 'bot_text' => 'Do 15:00 wysyłamy tego samego dnia.', 'buttons' => '[{"label":"Koszty i metody dostawy","target":"ai"},{"label":"Inne pytanie","target":"ai"}]', 'context_hint' => null, 'model_level' => null, 'ai_prompt' => null],
    // Poziom 3 pod dobor — test rekurencji. Bez label (NULL dozwolone). ai_prompt USTAWIONY (088e).
    ['id' => 10, 'node_key' => 'dobor_skafander', 'parent_id' => 5, 'level' => 3, 'sort_order' => 1,
     'label' => null, 'bot_text' => null, 'buttons' => '[]', 'context_hint' => null, 'model_level' => 'primary',
     'ai_prompt' => 'Zapytaj o obwód klatki i wzrost, dopytaj o grubość pianki.'],
];

// Mapa link klucz→URL. link_kontakt CELOWO pominięty → test "brak klucza → url:null".
$linkMap = [
    'link_zwroty' => 'https://divezone.pl/zwroty-produktow',
    'link_serwis' => 'https://divezone.pl/serwis-automatow',
];

$tree = ChipTreeService::buildTree($rows, $linkMap);

// === Struktura korzeni ===
assertT('1 korzeń', count($tree) === 1, 'got ' . count($tree));
$root = $tree[0];
assertT('root.node_key=root', $root['node_key'] === 'root');
assertT('root ma 4 dzieci', count($root['children']) === 4, 'got ' . count($root['children']));

// === Kolejność dzieci po sort_order (zwroty,serwis,wysylka,dobor) ===
$order = array_map(static fn(array $c): string => $c['node_key'], $root['children']);
assertT('kolejność po sort_order', $order === ['zwroty', 'serwis', 'wysylka', 'dobor'], 'got ' . implode(',', $order));

// === label (088b) ===
assertT('root.label', $root['label'] === 'W czym mogę pomóc?');
$labels = array_map(static fn(array $c): ?string => $c['label'], $root['children']);
assertT('label dzieci', $labels === ['Zwroty i wymiana', 'Serwis automatu', 'Dostępność i wysyłka', 'Dobór sprzętu'], 'got ' . implode('|', array_map('strval', $labels)));

// === Węzeł hybrydowy: serwis ma bot_text ORAZ buttons ===
$serwis = $root['children'][1];
assertT('serwis: bot_text niepusty', is_string($serwis['bot_text']) && $serwis['bot_text'] !== '');
assertT('serwis: 3 przyciski (hybryda)', count($serwis['buttons']) === 3, 'got ' . count($serwis['buttons']));

// === Rozwijanie link: na URL (088b, decyzja 43a) ===
assertT('serwis btn0: target zmieniony na "link"', $serwis['buttons'][0]['target'] === 'link', 'got ' . $serwis['buttons'][0]['target']);
assertT('serwis btn0: url rozwinięty z configu', $serwis['buttons'][0]['url'] === 'https://divezone.pl/serwis-automatow');
assertT('serwis btn0: label zachowany', $serwis['buttons'][0]['label'] === 'Pełny cennik');
assertT('serwis btn1: brak klucza w mapie → url:null', $serwis['buttons'][1]['target'] === 'link' && $serwis['buttons'][1]['url'] === null);
assertT('serwis btn2: target ai → url:null', $serwis['buttons'][2]['target'] === 'ai' && $serwis['buttons'][2]['url'] === null);

$zwroty = $root['children'][0];
assertT('zwroty btn0: link rozwinięty', $zwroty['buttons'][0]['target'] === 'link' && $zwroty['buttons'][0]['url'] === 'https://divezone.pl/zwroty-produktow');
assertT('zwroty btn1: ai → url:null', $zwroty['buttons'][1]['target'] === 'ai' && $zwroty['buttons'][1]['url'] === null);

// === Bez linkMap (domyślny []) → link bez url ===
$noMap = ChipTreeService::buildTree($rows);
$serwisNoMap = $noMap[0]['children'][1];
assertT('bez linkMap: link → target "link", url:null', $serwisNoMap['buttons'][0]['target'] === 'link' && $serwisNoMap['buttons'][0]['url'] === null);

// === Rekurencja: dobor ma dziecko poziomu 3 ===
$dobor = $root['children'][3];
assertT('dobor ma 1 dziecko (poziom 3)', count($dobor['children']) === 1, 'got ' . count($dobor['children']));
assertT('dobor.dziecko.node_key=dobor_skafander', $dobor['children'][0]['node_key'] === 'dobor_skafander');
assertT('liść poziom 3: children pusty', $dobor['children'][0]['children'] === []);
assertT('liść poziom 3: bot_text null', $dobor['children'][0]['bot_text'] === null);
assertT('liść poziom 3: label null', $dobor['children'][0]['label'] === null);
assertT('liść poziom 3: model_level=primary', $dobor['children'][0]['model_level'] === 'primary');

// === ai_prompt (088e) ===
assertT('liść poziom 3: ai_prompt ustawiony', $dobor['children'][0]['ai_prompt'] === 'Zapytaj o obwód klatki i wzrost, dopytaj o grubość pianki.');
assertT('root: ai_prompt null', $root['ai_prompt'] === null);
assertT('dobor: ai_prompt null (brak instrukcji)', $dobor['ai_prompt'] === null);

// === Kontrakt: pola wewnętrzne NIE wychodzą; label + ai_prompt dołączone ===
$keys = array_keys($root);
sort($keys);
assertT('kontrakt: dokładnie 8 pól (z label + ai_prompt)', $keys === ['ai_prompt', 'bot_text', 'buttons', 'children', 'context_hint', 'label', 'model_level', 'node_key'], 'got ' . implode(',', $keys));
assertT('kontrakt: brak id', !array_key_exists('id', $root));
assertT('kontrakt: brak parent_id', !array_key_exists('parent_id', $root));
assertT('kontrakt: brak _sort', !array_key_exists('_sort', $root));
$btnKeys = array_keys($serwis['buttons'][0]);
sort($btnKeys);
assertT('kontrakt button: label+target+url', $btnKeys === ['label', 'target', 'url'], 'got ' . implode(',', $btnKeys));

// === Robustność decodeButtons: wpis bez target pomijany ===
$bad = ChipTreeService::buildTree([
    ['id' => 1, 'node_key' => 'x', 'parent_id' => null, 'level' => 1, 'sort_order' => 0,
     'label' => null, 'bot_text' => null, 'buttons' => '[{"label":"bez_target"},{"label":"ok","target":"ai"}]', 'context_hint' => null, 'model_level' => null],
]);
assertT('decodeButtons: pomija wpis bez target', count($bad[0]['buttons']) === 1);
assertT('decodeButtons: zachowuje poprawny wpis (z url:null)', $bad[0]['buttons'][0]['target'] === 'ai' && $bad[0]['buttons'][0]['url'] === null);

// === Brak kolumny label w wierszu (np. stary SELECT) → label null, bez błędu ===
$noLabelCol = ChipTreeService::buildTree([
    ['id' => 1, 'node_key' => 'x', 'parent_id' => null, 'level' => 1, 'sort_order' => 0,
     'bot_text' => null, 'buttons' => '[]', 'context_hint' => null, 'model_level' => null],
]);
assertT('brak kolumny label → label:null (robustność)', $noLabelCol[0]['label'] === null);
assertT('brak kolumny ai_prompt → ai_prompt:null (robustność)', $noLabelCol[0]['ai_prompt'] === null);

// === Pusty input → puste drzewo ===
assertT('pusty input → []', ChipTreeService::buildTree([]) === []);

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
