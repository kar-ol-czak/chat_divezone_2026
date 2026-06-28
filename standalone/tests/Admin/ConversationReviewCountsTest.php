<?php

declare(strict_types=1);

/**
 * Test countsByStatus (CHAT-T-106, ADR-102) — liczniki per status dla panelu.
 *
 * Real-path wg ADR-088 (+ UZUPELNIENIE: nazwy kluczy .env bez myslnikow):
 * laczy sie z Railway przez PostgresConnection (Config::load -> $_ENV -> PDO),
 * NIE przez CLI/odczyt pliku. Po naprawie nazwy klucza DATAFORSEO_..._BASE64
 * (myslnik -> podkreslnik) Config::load() dziala — uzywamy go realnie.
 *
 * Podejscie delta (odporne na istniejace wiersze prod): snapshot counts ->
 * wstaw rozmowy o znanych statusach -> assert dokladne delty per status +
 * niezmiennik 4 kluczy + sum(counts)==COUNT(*) -> sprzatanie (cascade).
 *
 * Pokrycie: komplet 4 kluczy zawsze; brakujacy status -> 0; suma == liczba
 * wierszy; nieznany status nie przecieka (brak dodatkowych kluczy).
 *
 * Uruchomienie: php standalone/tests/Admin/ConversationReviewCountsTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Admin\ConversationReviewRepository;
use DiveChat\Config;
use DiveChat\Database\PostgresConnection;
use DiveChat\Enum\ReviewStatus;

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

// === Real-path: Config::load() jak w public/index.php (basePath = standalone).
// Po naprawie nazwy klucza .env nie rzuca juz wyjatku (ADR-088 UZUPELNIENIE). ===
$standalone = dirname(__DIR__, 2); // standalone/tests/Admin -> standalone
Config::load($standalone);
$db = PostgresConnection::getInstance();
$pdo = $db->getPdo();
fwrite(STDERR, '[real-path] Config::load OK, host=' . parse_url($_ENV['DATABASE_URL'], PHP_URL_HOST) . "\n");

$repo = new ConversationReviewRepository($db);

$EXPECTED_KEYS = array_map(static fn(ReviewStatus $s): string => $s->value, ReviewStatus::cases());
sort($EXPECTED_KEYS);

// Helper: COUNT(*) calej tabeli.
$tableCount = static fn(): int => (int) $pdo->query('SELECT COUNT(*) FROM divechat_conversation_review')->fetchColumn();

// === Niezmienniki na stanie BAZOWYM (przed wstawieniem czegokolwiek) ===
$before = $repo->countsByStatus();
$keys = array_keys($before);
sort($keys);
assertT('counts: dokladnie 4 klucze enuma (brak przeciekow)', $keys === $EXPECTED_KEYS, 'got ' . json_encode($keys));
assertT('counts: wszystkie wartosci int >= 0', count(array_filter($before, static fn($v) => is_int($v) && $v >= 0)) === 4);
assertT('counts: suma == COUNT(*) tabeli (baza)', array_sum($before) === $tableCount(), 'sum=' . array_sum($before) . ' count=' . $tableCount());

// === Wstaw 3 rozmowy o znanych, ROZNYCH statusach (delta) ===
$ids = [];
$mk = static function (string $status) use ($pdo, &$ids): int {
    $sid = 'test-counts-CHAT-T-106-' . uniqid('', true);
    $cid = (int) $pdo->query(
        "INSERT INTO divechat_conversations (session_id, messages)
         VALUES (" . $pdo->quote($sid) . ", '[]'::jsonb) RETURNING id"
    )->fetchColumn();
    $st = $pdo->prepare('INSERT INTO divechat_conversation_review (conversation_id, status, updated_by) VALUES (?, ?, ?)');
    $st->execute([$cid, $status, 999]);
    $ids[] = $cid;
    return $cid;
};

try {
    $mk('do_weryfikacji');
    $mk('w_trakcie');
    $mk('zamkniety');

    $after = $repo->countsByStatus();

    // Klucze nadal komplet.
    $keysAfter = array_keys($after);
    sort($keysAfter);
    assertT('counts po insert: nadal 4 klucze', $keysAfter === $EXPECTED_KEYS);

    // Dokladne delty per status (+1 dla trzech wstawionych, 0 dla nowy).
    assertT('delta do_weryfikacji = +1', $after['do_weryfikacji'] - $before['do_weryfikacji'] === 1);
    assertT('delta w_trakcie = +1',      $after['w_trakcie']      - $before['w_trakcie']      === 1);
    assertT('delta zamkniety = +1',      $after['zamkniety']      - $before['zamkniety']      === 1);
    assertT('delta nowy = 0 (nie wstawialismy)', $after['nowy'] - $before['nowy'] === 0);

    // Suma rosnie o 3 i nadal == COUNT(*).
    assertT('suma po insert = baza + 3', array_sum($after) === array_sum($before) + 3);
    assertT('counts: suma == COUNT(*) tabeli (po insert)', array_sum($after) === $tableCount());
} finally {
    // Sprzatanie: usun rozmowy -> ON DELETE CASCADE usuwa wiersze recenzji.
    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM divechat_conversations WHERE id IN ({$in})")->execute($ids);
        fwrite(STDERR, '[teardown] usunieto ' . count($ids) . " test conversations\n");
    }
}

// === Po cascade powrot do stanu bazowego ===
$restored = $repo->countsByStatus();
assertT('counts po cleanup == baza', $restored === $before, 'before=' . json_encode($before) . ' restored=' . json_encode($restored));

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
