<?php

declare(strict_types=1);

/**
 * Test jednostkowy GetShopLinks (ADR-095, KROK C).
 * Czysta logika filtra + shapingu (bez PostgreSQL — filterRows/buildResult statyczne).
 *
 * Uruchomienie: php standalone/tests/Tools/GetShopLinksTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Tools\GetShopLinks;

// Dummy DSN — test nie łączy się z bazą (PDO lazy), potrzebny tylko do getInstance().
$_ENV['DATABASE_URL'] ??= 'postgresql://u:p@localhost:5432/test';

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

// Surowe wiersze jak z divechat_shop_config po migracji 028 (z placeholderami TODO).
$rows = [
    ['key' => 'bank_account_pln', 'value' => '27 1600 1462 1829 3115 4000 0003'],
    ['key' => 'bank_account_eur', 'value' => 'PL54 1600 1462 1829 3115 4000 0002'],
    ['key' => 'bank_swift', 'value' => 'PPABPLPK'],
    ['key' => 'link_kontakt', 'value' => 'https://divezone.pl/kontakt-z-nami'],
    ['key' => 'link_regulamin', 'value' => 'TODO'],          // niepotwierdzony → pomijany
    ['key' => 'link_polityka_prywatnosci', 'value' => '  '],  // pusty → pomijany
    ['key' => 'link_encyklopedia', 'value' => 'https://divezone.pl/encyklopedia'],
    ['key' => 'link_platnosci', 'value' => 'https://divezone.pl/formy-platnosci'],
    ['key' => 'link_serwis', 'value' => 'https://divezone.pl/serwis'],
    ['key' => 'link_o_nas', 'value' => 'https://divezone.pl/o-nas'],
    ['key' => 'link_b2b', 'value' => 'https://divezone.pl/b2b'],
    ['key' => 'link_filmy', 'value' => 'https://divezone.pl/filmy'],
];

// === filterRows: whitelist + skip TODO/pustych ===
$config = GetShopLinks::filterRows($rows);
assertT('filterRows: bank_account_pln zachowany', ($config['bank_account_pln'] ?? null) === '27 1600 1462 1829 3115 4000 0003');
assertT('filterRows: link_regulamin (TODO) pominięty', !isset($config['link_regulamin']));
assertT('filterRows: link_polityka (pusty) pominięty', !isset($config['link_polityka_prywatnosci']));
assertT('filterRows: link_encyklopedia zachowany', ($config['link_encyklopedia'] ?? null) === 'https://divezone.pl/encyklopedia');

// === filterRows: nowe klucze (ADR-095 028, +5 linków) ===
assertT('filterRows: link_platnosci zachowany', ($config['link_platnosci'] ?? null) === 'https://divezone.pl/formy-platnosci');
assertT('filterRows: link_b2b zachowany', ($config['link_b2b'] ?? null) === 'https://divezone.pl/b2b');

// === buildResult: komplet (topic=null) — 11 linków + konta ===
$full = GetShopLinks::buildResult($config, null);
assertT('full: accounts.pln = numer z configu', $full['accounts']['pln'] === '27 1600 1462 1829 3115 4000 0003');
assertT('full: accounts.swift = PPABPLPK', $full['accounts']['swift'] === 'PPABPLPK');
assertT('full: links.kontakt obecny', $full['links']['kontakt'] === 'https://divezone.pl/kontakt-z-nami');
assertT('full: brak klucza → null (graceful, nie błąd)', $full['links']['regulamin'] === null);
assertT('full: links ma 11 kluczy', count($full['links']) === 11, 'got ' . count($full['links']));
assertT('full: links.platnosci obecny', $full['links']['platnosci'] === 'https://divezone.pl/formy-platnosci');
assertT('full: links.filmy obecny', $full['links']['filmy'] === 'https://divezone.pl/filmy');
assertT('full: zawiera note', isset($full['note']));

// === buildResult: topic=payment → konta + kontakt + platnosci ===
$payment = GetShopLinks::buildResult($config, 'payment');
assertT('payment: zwraca accounts', isset($payment['accounts']['pln']));
assertT('payment: links = kontakt + platnosci', array_keys($payment['links']) === ['kontakt', 'platnosci']);
assertT('payment: platnosci ma URL', $payment['links']['platnosci'] === 'https://divezone.pl/formy-platnosci');

// === buildResult: topic=content → blog + encyklopedia ===
$content = GetShopLinks::buildResult($config, 'content');
assertT('content: encyklopedia obecna', $content['links']['encyklopedia'] === 'https://divezone.pl/encyklopedia');
assertT('content: brak accounts w content', !isset($content['accounts']));

// === buildResult: topic=service → serwis + kontakt ===
$service = GetShopLinks::buildResult($config, 'service');
assertT('service: links = serwis + kontakt', array_keys($service['links']) === ['serwis', 'kontakt']);
assertT('service: serwis ma URL', $service['links']['serwis'] === 'https://divezone.pl/serwis');
assertT('service: brak accounts', !isset($service['accounts']));

// === buildResult: topic=about → o_nas + b2b + filmy ===
$about = GetShopLinks::buildResult($config, 'about');
assertT('about: links = o_nas + b2b + filmy', array_keys($about['links']) === ['o_nas', 'b2b', 'filmy']);
assertT('about: o_nas ma URL', $about['links']['o_nas'] === 'https://divezone.pl/o-nas');
assertT('about: brak accounts', !isset($about['accounts']));

// === pusty config (np. przed migracją 028) → graceful, same nulle ===
$empty = GetShopLinks::buildResult([], null);
assertT('empty: accounts.pln = null (graceful)', $empty['accounts']['pln'] === null);
assertT('empty: links.kontakt = null (graceful)', $empty['links']['kontakt'] === null);

// === schema toola ===
$tool = new GetShopLinks(\DiveChat\Database\PostgresConnection::getInstance());
assertT('getName() = get_shop_links', $tool->getName() === 'get_shop_links');
$schema = $tool->getParametersSchema();
assertT('schema: topic w properties', isset($schema['properties']['topic']));
assertT('schema: topic nie jest required', !in_array('topic', $schema['required'] ?? [], true));
assertT('description wspomina numer konta/przelew', str_contains($tool->getDescription(), 'przelew'));

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
