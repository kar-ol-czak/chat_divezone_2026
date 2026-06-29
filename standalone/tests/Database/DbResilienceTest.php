<?php

declare(strict_types=1);

/**
 * Test odpornosci na zrywanie Railway (CHAT-T-107, P36c/P37c).
 *
 * NIE zalezy od zywego Railway. Pokrycie:
 *  - FileCache: set/getFresh(TTL)/getAny/forget, null-wartosc vs brak.
 *  - classifyError: HARD (could not connect/refused/timeout) vs SOFT (server
 *    closed/57P01) vs null (skladnia) — sterownik 1-vs-3 prob.
 *  - executeWithRetry (reflection, liczenie wywolan): SOFT → 3 proby; HARD → 1
 *    proba; skladnia → 1 proba + rethrow PDOException; SOFT-then-ok → wasDelayed.
 *  - DbUnavailableException::HARD/SOFT flaga + breaker (fail-fast 2. zapytania).
 *  - SettingsStore/ChipTreeService degraduja (cache→default/[]) bez wyjatku.
 *  - ChatController: komunikat per flaga (HARD ma kontakt, SOFT "za moment").
 *
 * Uruchomienie: php standalone/tests/Database/DbResilienceTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Chat\SettingsStore;
use DiveChat\Chip\ChipTreeService;
use DiveChat\Controller\ChatController;
use DiveChat\Database\PostgresConnection;
use DiveChat\Exception\DbUnavailableException;
use DiveChat\Support\FileCache;

$passed = 0; $failed = 0;
function assertT(string $n, bool $c, string $d = ''): void {
    global $passed, $failed;
    if ($c) { echo "[OK] {$n}\n"; $passed++; }
    else { echo "[FAIL] {$n}" . ($d ? " — {$d}" : '') . "\n"; $failed++; }
}

$TMP = sys_get_temp_dir() . '/divechat_cachetest_' . uniqid();
@mkdir($TMP, 0o775, true);
$cache = new FileCache($TMP);

// === FileCache ===
$cache->set('k1', ['a' => 1]);
[$f, $v] = $cache->getFresh('k1', 300);
assertT('FileCache getFresh zwraca zapisane', $f === true && $v === ['a' => 1]);
[$f2] = $cache->getFresh('brak', 300);
assertT('FileCache getFresh brak → [false,...]', $f2 === false);
$cache->set('null_val', null);
[$fn, $vn] = $cache->getAny('null_val');
assertT('FileCache rozroznia zapisany null od braku', $fn === true && $vn === null);
file_put_contents($TMP . '/' . sha1('old') . '.json', json_encode(['ts' => time() - 1000, 'data' => 'x']));
[$fo] = $cache->getFresh('old', 300);
[$fa, $va] = $cache->getAny('old');
assertT('FileCache getFresh przeterminowany → nieswiezy', $fo === false);
assertT('FileCache getAny zwraca mimo wieku (lkg)', $fa === true && $va === 'x');
$cache->forget('k1');
[$ff] = $cache->getAny('k1');
assertT('FileCache forget usuwa', $ff === false);

// === classifyError: HARD vs SOFT vs null ===
$ce = new ReflectionMethod(PostgresConnection::class, 'classifyError');
$ce->setAccessible(true);
$cls = fn(string $msg, int $code = 0): ?string => $ce->invoke(null, new PDOException($msg, $code));
assertT('classify HARD: could not connect', $cls('SQLSTATE[08006] could not connect to server') === DbUnavailableException::HARD);
assertT('classify HARD: connection refused', $cls('connection to server at "127.0.0.1" failed: Connection refused') === DbUnavailableException::HARD);
assertT('classify HARD: timed out', $cls('could not connect ... Connection timed out') === DbUnavailableException::HARD);
assertT('classify SOFT: server closed', $cls('server closed the connection unexpectedly') === DbUnavailableException::SOFT);
assertT('classify SOFT: no connection to the server', $cls('no connection to the server') === DbUnavailableException::SOFT);
assertT('classify null: syntax error (nie retry)', $cls('SQLSTATE[42601]: syntax error at or near') === null);
assertT('classify null: unique violation', $cls('duplicate key value violates unique constraint') === null);

// === executeWithRetry: liczenie prob (reflection) ===
$ewr = new ReflectionMethod(PostgresConnection::class, 'executeWithRetry');
$ewr->setAccessible(true);
$freshConn = static function (): PostgresConnection {
    PostgresConnection::reset();
    $_ENV['DATABASE_URL'] = 'postgresql://u:p@198.51.100.1:5432/d?sslmode=disable';
    return PostgresConnection::getInstance();
};

// SOFT → 3 proby, kind SOFT
$conn = $freshConn(); $calls = 0; $kind = '-';
try { $ewr->invoke($conn, function () use (&$calls) { $calls++; throw new PDOException('server closed the connection unexpectedly'); }); }
catch (DbUnavailableException $e) { $kind = $e->kind(); }
assertT('SOFT (zerwanie) → 3 proby', $calls === 3, "calls={$calls}");
assertT('SOFT → DbUnavailableException::SOFT', $kind === DbUnavailableException::SOFT);

// HARD → 1 proba, kind HARD
$conn = $freshConn(); $calls = 0; $kind = '-';
try { $ewr->invoke($conn, function () use (&$calls) { $calls++; throw new PDOException('could not connect to server: Connection refused'); }); }
catch (DbUnavailableException $e) { $kind = $e->kind(); }
assertT('HARD (nieosiagalne) → TYLKO 1 proba (nie 3)', $calls === 1, "calls={$calls}");
assertT('HARD → DbUnavailableException::HARD', $kind === DbUnavailableException::HARD);

// Blad skladni → 1 proba, rethrow PDOException (NIE DbUnavailable)
$conn = $freshConn(); $calls = 0; $caught = '-';
try { $ewr->invoke($conn, function () use (&$calls) { $calls++; throw new PDOException('SQLSTATE[42601]: syntax error at or near "x"'); }); }
catch (DbUnavailableException $e) { $caught = 'DbUnavailable'; }
catch (PDOException $e) { $caught = 'PDO'; }
assertT('skladnia → 1 proba (bez retry)', $calls === 1, "calls={$calls}");
assertT('skladnia → rethrow PDOException (nie DbUnavailable)', $caught === 'PDO');

// SOFT raz potem sukces → wasDelayed true
$conn = $freshConn(); $calls = 0;
$res = $ewr->invoke($conn, function () use (&$calls) {
    $calls++;
    if ($calls === 1) { throw new PDOException('server closed the connection unexpectedly'); }
    return 'OK';
});
assertT('SOFT-then-ok → zwraca wynik po reconnect', $res === 'OK' && $calls === 2);
assertT('SOFT-then-ok → wasDelayed() === true (P37c)', $conn->wasDelayed() === true);

// === Symulacja niedostepnosci realnym query (zly host, refused = HARD szybko) ===
PostgresConnection::reset();
$_ENV['DATABASE_URL'] = 'postgresql://u:p@127.0.0.1:1/db?sslmode=disable';
$db = PostgresConnection::getInstance();
$t1 = microtime(true); $kind1 = '-';
try { $db->query('SELECT 1'); } catch (DbUnavailableException $e) { $kind1 = $e->kind(); }
$d1 = microtime(true) - $t1;
assertT('zly host refused → DbUnavailableException::HARD', $kind1 === DbUnavailableException::HARD);
assertT('HARD degraduje szybko (<3s, nie 15s)', $d1 < 3.0, sprintf('%.2fs', $d1));
// breaker: 2. zapytanie fail-fast
$t2 = microtime(true);
try { $db->query('SELECT 1'); } catch (DbUnavailableException $e) {}
$d2 = microtime(true) - $t2;
assertT('breaker: 2. zapytanie fail-fast', $d2 < 0.05 || $d2 < $d1 / 2, sprintf('d1=%.3f d2=%.3f', $d1, $d2));

// SettingsStore degraduje (default / last-known-good)
PostgresConnection::reset();
$ss = new SettingsStore(new FileCache($TMP));
$got = '(throw)';
try { $got = $ss->get('nieistnieje_xyz', 'DEFAULT'); } catch (\Throwable $e) { $got = '(throw)'; }
assertT('SettingsStore::get DB-down → default (nie wyjatek)', $got === 'DEFAULT');
$cache->set('settings.get.model_id', 'gpt-5.5');
PostgresConnection::reset();
$lkg = '(throw)';
try { $lkg = (new SettingsStore($cache))->get('model_id', 'FALLBACK'); } catch (\Throwable $e) { $lkg = '(throw)'; }
assertT('SettingsStore::get DB-down → last-known-good z cache', $lkg === 'gpt-5.5');

// ChipTreeService degraduje (puste + degraded; oraz lkg)
PostgresConnection::reset();
$cts = new ChipTreeService(PostgresConnection::getInstance(), new FileCache($TMP . '/chip'));
$tree = '(throw)';
try { $tree = $cts->getTree(); } catch (\Throwable $e) { $tree = '(throw)'; }
assertT('ChipTree DB-down + brak cache → [] (nie wyjatek)', $tree === []);
assertT('ChipTree wasDegraded() === true', $cts->wasDegraded() === true);
$fakeTree = [['node_key' => 'root', 'label' => 'X', 'children' => []]];
$chipCache = new FileCache($TMP . '/chip2');
$chipCache->set('chip_tree', $fakeTree);
PostgresConnection::reset();
$cts2 = new ChipTreeService(PostgresConnection::getInstance(), $chipCache);
$t = '(throw)';
try { $t = $cts2->getTree(); } catch (\Throwable $e) { $t = '(throw)'; }
assertT('ChipTree DB-down → last-known-good drzewo z cache', $t === $fakeTree);

// === ChatController: komunikat per flaga (P37c) ===
$rc = new ReflectionClass(ChatController::class);
$cc = $rc->newInstanceWithoutConstructor();
$dp = new ReflectionMethod(ChatController::class, 'dbDegradePayload');
$dp->setAccessible(true);
$hard = $dp->invoke($cc, new DbUnavailableException(DbUnavailableException::HARD), 'sid1');
$soft = $dp->invoke($cc, new DbUnavailableException(DbUnavailableException::SOFT), 'sid1');
assertT('HARD payload zawiera mail kontakt', str_contains($hard['response'], 'dive@divezone.pl'));
assertT('HARD payload zawiera telefon kontakt', str_contains($hard['response'], '56 307 03 03'));
assertT('HARD reason-key = db_unavailable_hard', ($hard['diagnostics']['db_unavailable_hard'] ?? false) === true);
assertT('SOFT payload bez kontaktu, "za moment"', !str_contains($soft['response'], 'dive@divezone.pl') && str_contains($soft['response'], 'za moment'));
assertT('SOFT reason-key = db_unavailable_soft', ($soft['diagnostics']['db_unavailable_soft'] ?? false) === true);
assertT('payload degradacji: success=false, response niepuste', $hard['success'] === false && $hard['response'] !== '');

// sprzatanie
$rmrf = static function (string $path) use (&$rmrf): void {
    foreach (glob($path . '/*') ?: [] as $item) { is_dir($item) ? $rmrf($item) : @unlink($item); }
    @unlink($path . '/.htaccess');
    @rmdir($path);
};
$rmrf($TMP);

echo "\n=== {$passed} passed, {$failed} failed ===\n";
exit($failed > 0 ? 1 : 0);
