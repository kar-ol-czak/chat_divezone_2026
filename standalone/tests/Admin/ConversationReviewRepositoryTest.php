<?php

declare(strict_types=1);

/**
 * Test repo recenzji rozmow (CHAT-T-104, ADR-102).
 *
 * Real-path wg ADR-088: laczy sie z Railway przez PostgresConnection
 * (DATABASE_URL -> $_ENV -> PDO), NIE przez CLI/odczyt pliku. Tworzy wlasna
 * jednorazowa rozmowe w divechat_conversations, cwiczy repo, sprzata
 * (DELETE rozmowy -> ON DELETE CASCADE usuwa wiersz recenzji).
 *
 * Pokrycie: brak wiersza -> null; upsert tworzy (status default); upsert
 * aktualizuje TYLKO podane pola; updated_by zmienia sie; walidacja enumow
 * odrzuca smieci (InvalidReviewValueException -> 422); lista po statusie z
 * lekkim skrotem rozmowy; czyszczenie werdyktu.
 *
 * Uruchomienie: php standalone/tests/Admin/ConversationReviewRepositoryTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Admin\ConversationReviewRepository;
use DiveChat\Admin\InvalidReviewValueException;
use DiveChat\Database\PostgresConnection;
use DiveChat\Enum\ReviewStatus;
use DiveChat\Enum\ReviewVerdict;

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

function assertThrows(string $name, callable $fn): void
{
    try {
        $fn();
        assertT($name, false, 'nie rzucilo wyjatku');
    } catch (InvalidReviewValueException $e) {
        assertT($name, true);
    }
}

// === Pure: walidacja enumow (bez PG) ===
assertT('ReviewStatus valid', ReviewStatus::tryFromString('do_weryfikacji') === ReviewStatus::DO_WERYFIKACJI);
assertT('ReviewStatus garbage -> null', ReviewStatus::tryFromString('cos_innego') === null);
assertT('ReviewStatus DEFAULT = do_weryfikacji', ReviewStatus::DEFAULT->value === 'do_weryfikacji');
assertT('ReviewVerdict valid', ReviewVerdict::tryFromString('problem_rozwiazany') === ReviewVerdict::PROBLEM_ROZWIAZANY);
assertT('ReviewVerdict garbage -> null', ReviewVerdict::tryFromString('maybe') === null);

// === Real-path: DATABASE_URL z root .env (lokalny .env ma 1 zepsuty klucz z
// myslnikiem -> phpdotenv safeLoad wybucha; serwer ma osobny czysty .env).
// Wyciagamy WYLACZNIE DATABASE_URL, dalej normalny tor PostgresConnection. ===
$root = dirname(dirname(dirname(__DIR__))); // standalone/tests/Admin -> projekt root
if (!isset($_ENV['DATABASE_URL'])) {
    foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^DATABASE_URL=(.*)$/', $line, $m)) {
            $_ENV['DATABASE_URL'] = trim($m[1], " \t\"'");
            break;
        }
    }
}

$db = PostgresConnection::getInstance();
$pdo = $db->getPdo();
fwrite(STDERR, '[real-path] host=' . parse_url($_ENV['DATABASE_URL'], PHP_URL_HOST) . "\n");

$repo = new ConversationReviewRepository($db);

// upsert ze smieciami rzuca PRZED dotknieciem DB (validateFields first) — nie
// wymaga istniejacej rozmowy.
assertThrows('upsert status-smiec -> wyjatek', fn() => $repo->upsert(1, ['status' => 'XXX'], 1));
assertThrows('upsert verdict-smiec -> wyjatek', fn() => $repo->upsert(1, ['verdict' => 'YYY'], 1));
assertThrows('listByStatus smiec -> wyjatek', fn() => $repo->listByStatus('ZZZ', 10, 0));

// Tworzymy jednorazowa rozmowe (sprzatana na koncu, cascade usunie recenzje).
$sid = 'test-review-CHAT-T-104-' . uniqid();
$convId = (int) $pdo->query(
    "INSERT INTO divechat_conversations (session_id, messages)
     VALUES (" . $pdo->quote($sid) . ", '[{\"role\":\"user\",\"content\":\"testowe pytanie recenzji\"}]'::jsonb)
     RETURNING id"
)->fetchColumn();
fwrite(STDERR, "[setup] test conversation id={$convId} session={$sid}\n");

try {
    // === brak wiersza -> null (D3) ===
    assertT('getByConversation brak wiersza -> null', $repo->getByConversation($convId) === null);

    // === upsert tworzy z defaultem ===
    $r1 = $repo->upsert($convId, [], 101);
    assertT('upsert create: status default do_weryfikacji', $r1['status'] === 'do_weryfikacji', json_encode($r1));
    assertT('upsert create: verdict null', $r1['verdict'] === null);
    assertT('upsert create: note null', $r1['note'] === null);
    assertT('upsert create: updated_by=101', $r1['updated_by'] === 101);
    assertT('upsert create: conversation_id', $r1['conversation_id'] === $convId);

    // === upsert aktualizuje TYLKO podane pola + zmienia updated_by ===
    $r2 = $repo->upsert($convId, ['note' => '  pierwsza notatka  '], 202);
    assertT('upsert update: note ustawione (trim)', $r2['note'] === 'pierwsza notatka', json_encode($r2));
    assertT('upsert update: status NIE zmieniony', $r2['status'] === 'do_weryfikacji');
    assertT('upsert update: updated_by=202', $r2['updated_by'] === 202);
    assertT('upsert update: id ten sam (jeden wiersz)', $r2['id'] === $r1['id']);

    // === zmiana statusu nie rusza note ===
    $r3 = $repo->upsert($convId, ['status' => 'w_trakcie'], 202);
    assertT('upsert update: status w_trakcie', $r3['status'] === 'w_trakcie');
    assertT('upsert update: note NIE zmieniona', $r3['note'] === 'pierwsza notatka');

    // === domkniecie: status + verdict razem ===
    $r4 = $repo->upsert($convId, ['status' => 'zamkniety', 'verdict' => 'ok'], 202);
    assertT('upsert close: status zamkniety', $r4['status'] === 'zamkniety');
    assertT('upsert close: verdict ok', $r4['verdict'] === 'ok');

    // === getByConversation zwraca pelny wiersz ===
    $got = $repo->getByConversation($convId);
    assertT('getByConversation pelny wiersz', $got !== null && $got['status'] === 'zamkniety' && $got['verdict'] === 'ok');

    // === lista po statusie z lekkim skrotem rozmowy ===
    $list = $repo->listByStatus('zamkniety', 200, 0);
    $mine = null;
    foreach ($list['items'] as $it) {
        if ($it['conversation_id'] === $convId) { $mine = $it; break; }
    }
    assertT('listByStatus zawiera nasza rozmowe', $mine !== null);
    assertT('listByStatus: message_count=1', $mine !== null && $mine['message_count'] === 1, json_encode($mine));
    assertT('listByStatus: first_user_message', $mine !== null && $mine['first_user_message'] === 'testowe pytanie recenzji');
    assertT('listByStatus: paginacja total>=1', $list['total'] >= 1 && $list['limit'] === 200 && $list['offset'] === 0);

    // === czyszczenie werdyktu (jawny null) ===
    $r5 = $repo->upsert($convId, ['verdict' => null], 202);
    assertT('upsert clear verdict -> null', $r5['verdict'] === null);
    assertT('upsert clear: status nietkniety', $r5['status'] === 'zamkniety');
} finally {
    // Sprzatanie: usun rozmowe -> ON DELETE CASCADE usuwa wiersz recenzji.
    $pdo->prepare('DELETE FROM divechat_conversations WHERE id = ?')->execute([$convId]);
    fwrite(STDERR, "[teardown] usunieto test conversation id={$convId}\n");
}

// Po cascade brak wiersza recenzji.
assertT('po cascade: getByConversation -> null', $repo->getByConversation($convId) === null);

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
