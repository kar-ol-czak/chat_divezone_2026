<?php
// CHAT-T-196 KROK 2: odczyt read-only (transakcja READ ONLY), bez wartosci z .env.
declare(strict_types=1);
use DiveChat\Config;
use DiveChat\Database\PostgresConnection;
$basePath = '/home/divezone/public_html/chat.divezone.pl';
require $basePath . '/vendor/autoload.php';
Config::load($basePath);
$db = PostgresConnection::getInstance();
$pdo = $db->getPdo();
$pdo->exec('BEGIN READ ONLY');
echo "host: " . $pdo->query("select coalesce(inet_server_addr()::text,'?') || ' db=' || current_database()")->fetchColumn() . "\n";
foreach ($db->fetchAll("select conname, pg_get_constraintdef(oid) def from pg_constraint where conrelid='divechat_conversation_review'::regclass and contype='c' order by conname") as $r) {
    echo "CHECK {$r['conname']}: {$r['def']}\n";
}
foreach ($db->fetchAll("select column_name, data_type, is_nullable, column_default from information_schema.columns where table_name='divechat_conversation_review' order by ordinal_position") as $r) {
    echo "COL {$r['column_name']} {$r['data_type']} null={$r['is_nullable']} def=" . ($r['column_default'] ?? 'NULL') . "\n";
}
foreach ($db->fetchAll("select status, coalesce(verdict,'NULL') v, count(*) n from divechat_conversation_review group by 1,2 order by 1,2") as $r) {
    echo "DIST status={$r['status']} verdict={$r['v']} n={$r['n']}\n";
}
$row = $db->fetchOne("select * from divechat_conversation_review where id=?", [535]);
echo "ROW535: " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";
$c = $db->fetchOne("select id, started_at from divechat_conversations where id=?", [1709]);
echo "CONV1709: " . json_encode($c) . "\n";
echo "NOTES z markerem 'zamkniete przez Claude' (wzor): \n";
foreach ($db->fetchAll("select id, status, verdict, right(note, 160) tail from divechat_conversation_review where note like '%zamkniete przez Claude%' order by updated_at desc limit 3") as $r) {
    echo "  {$r['id']} {$r['status']} {$r['verdict']} ...{$r['tail']}\n";
}
$pdo->exec('ROLLBACK');
