<?php

declare(strict_types=1);

/**
 * Test ChipAiLabelProvider (CHAT-T-122, ADR-110 korekta 18a).
 *
 * Dwie warstwy:
 *  - toPgTextArray(): czysta serializacja do literalu PG text[] (bez PG).
 *  - fetchLabels(): real-path wg ADR-088 — laczy sie z Railway przez
 *    PostgresConnection i czyta AKTUALNE etykiety target:ai z divechat_chip_nodes.
 *    Dowodzi ze zrodlo jest dynamiczne (drzewo), nie stala lista w kodzie.
 *
 * Uruchomienie: php standalone/tests/Chip/ChipAiLabelProviderTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Chip\ChipAiLabelProvider;
use DiveChat\Database\PostgresConnection;

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

// --- toPgTextArray: czysta serializacja ---
assertT(
    'prosta lista -> {"a","b"}',
    ChipAiLabelProvider::toPgTextArray(['a', 'b']) === '{"a","b"}',
    ChipAiLabelProvider::toPgTextArray(['a', 'b']),
);
assertT(
    'pusta lista -> {}',
    ChipAiLabelProvider::toPgTextArray([]) === '{}',
);
assertT(
    'polskie znaki bez zmian',
    ChipAiLabelProvider::toPgTextArray(['Napisz czego szukasz']) === '{"Napisz czego szukasz"}',
);
assertT(
    'przecinek w etykiecie nie rozbija tablicy (cudzyslow)',
    ChipAiLabelProvider::toPgTextArray(['Koszty, dostawa']) === '{"Koszty, dostawa"}',
);
assertT(
    'cudzyslow eskejpowany',
    ChipAiLabelProvider::toPgTextArray(['a"b']) === '{"a\\"b"}',
    ChipAiLabelProvider::toPgTextArray(['a"b']),
);
assertT(
    'backslash eskejpowany',
    ChipAiLabelProvider::toPgTextArray(['a\\b']) === '{"a\\\\b"}',
    ChipAiLabelProvider::toPgTextArray(['a\\b']),
);

// --- fetchLabels: real Railway ---
// Real-path wg ADR-088: DATABASE_URL z root .env (lokalny .env ma zepsuty klucz z
// myslnikiem -> phpdotenv safeLoad wybucha; wyciagamy WYLACZNIE DATABASE_URL).
$root = dirname(dirname(dirname(__DIR__))); // standalone/tests/Chip -> projekt root
if (!isset($_ENV['DATABASE_URL'])) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^DATABASE_URL=(.*)$/', $line, $m)) {
            $_ENV['DATABASE_URL'] = trim($m[1], " \t\"'");
            break;
        }
    }
}

try {
    $db = PostgresConnection::getInstance();
    $labels = ChipAiLabelProvider::fetchLabels($db);

    assertT('fetchLabels zwraca list<string>', is_array($labels) && array_is_list($labels));
    assertT(
        'wszystkie elementy to niepuste stringi',
        array_reduce($labels, static fn (bool $ok, $l): bool => $ok && is_string($l) && $l !== '', true),
    );
    assertT('DISTINCT — brak duplikatow', count($labels) === count(array_unique($labels)));

    // Weryfikacja ze to naprawde odczyt z drzewa: policz recznie surowe labele
    // target:ai i porownaj zbior z fetchLabels (DISTINCT).
    $raw = $db->fetchAll(
        "SELECT btn->>'label' AS label
           FROM (SELECT buttons FROM divechat_chip_nodes
                  WHERE jsonb_typeof(buttons) = 'array') n,
                jsonb_array_elements(n.buttons) btn
          WHERE btn->>'target' = 'ai' AND btn->>'label' IS NOT NULL",
    );
    $expected = array_values(array_unique(array_map(static fn (array $r): string => (string) $r['label'], $raw)));
    sort($expected);
    $got = $labels;
    sort($got);
    assertT(
        'fetchLabels == DISTINCT surowych labeli target:ai z drzewa',
        $got === $expected,
        'got=' . json_encode($got, JSON_UNESCAPED_UNICODE) . ' expected=' . json_encode($expected, JSON_UNESCAPED_UNICODE),
    );

    echo "[info] aktualne labele target:ai (" . count($labels) . "): "
        . json_encode($labels, JSON_UNESCAPED_UNICODE) . "\n";
} catch (\Throwable $e) {
    assertT('fetchLabels real-path (Railway dostepne)', false, $e->getMessage());
}

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
