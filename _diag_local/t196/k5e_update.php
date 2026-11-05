<?php
// CHAT-T-196 KROK 5e: zamkniecie rozmowy testowej T-195 (decyzja Karola 6b: verdict NULL).
declare(strict_types=1);
use DiveChat\Config;
use DiveChat\Database\PostgresConnection;
$basePath = '/home/divezone/public_html/chat.divezone.pl';
require $basePath . '/vendor/autoload.php';
Config::load($basePath);
$pdo = PostgresConnection::getInstance()->getPdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->beginTransaction();
$st = $pdo->prepare(
    "UPDATE divechat_conversation_review
        SET status = 'zamkniety',
            verdict = NULL,
            updated_by = NULL,
            note = coalesce(note, '') || ?,
            updated_at = now()
      WHERE id = ? AND conversation_id = ? AND status = 'do_weryfikacji'
  RETURNING id, conversation_id, status, verdict, note, updated_by, updated_at"
);
$st->execute([' [zamkniete przez Claude, CHAT-T-196, 2026-11-05: rozmowa testowa T-195]', 535, 1709]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
if (count($rows) !== 1 || $st->rowCount() !== 1) {
    $pdo->rollBack();
    echo "ROLLBACK: wierszy " . count($rows) . " (rowCount " . $st->rowCount() . ")\n";
    exit(1);
}
$pdo->commit();
echo "COMMIT, wierszy 1\nRETURNING: " . json_encode($rows[0], JSON_UNESCAPED_UNICODE) . "\n";
$chk = $pdo->prepare('SELECT id, status, verdict, note, updated_by, updated_at FROM divechat_conversation_review WHERE id = ?');
$chk->execute([535]);
echo "ODCZYT PO COMMIT: " . json_encode($chk->fetch(PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE) . "\n";
