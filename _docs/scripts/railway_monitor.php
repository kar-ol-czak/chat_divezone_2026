<?php
declare(strict_types=1);
/**
 * Monitor lacza serwer chat.divezone.pl -> Railway PG (TRASA PRODUKCYJNA, READ-ONLY na danych realnych).
 * CHAT-T-108: mierzy TO, CO REALNIE PADA pod obciazeniem, nie tylko SELECT 1. Dziala CIAGLE (dni).
 *
 * Metryki na cykl (kazda OK/FAIL + ms osobno):
 *   railway_tcp  — TCP connect do Railway proxy
 *   pg_select1   — SELECT 1 (baseline)
 *   pg_settings  — SELECT value FROM divechat_settings WHERE key='model_primary' (jak SettingsStore/ChatController)
 *   pg_chiptree  — SELECT count(*) FROM divechat_chip_nodes WHERE active (proxy budowy drzewa, ChipTreeService)
 *   pg_upsert    — INSERT ... ON CONFLICT na kluczu '__monitor_probe__' (sciezka ZAPISU, RateLimiter/NudgeEventStore)
 *   github       — TCP do api.github.com:443 (kontrola lacza wyjsciowego hostingu)
 *
 * Cechy:
 *   - interwal 5 s (gesto — awaria 28-06 dawala 5-17 bledow/min)
 *   - CIAGLE (bez okna stop); log rotuje sie dziennie (plik per YYYYMMDD)
 *   - heartbeat co 100 cykli ("# alive N")
 *   - ALERT mailowy przy >=3 FAIL z rzedu na DOWOLNEJ metryce PG (nie tcp/github), dedup per-epizod
 *     + cooldown 15 min, mail "recovery" gdy wroci OK
 *   - dzienny digest 07:00 (railway_summary_mail.php) zachowany jako dowod dlugoterminowy
 *   - probe ZAPISU tylko na kluczu '__monitor_probe__' — NIE rusza danych produkcyjnych.
 *
 * Log: /home/divezone/_diag/railway_monitor_YYYYMMDD.log (dopisywany).
 * Uruchomienie: pod nohup + cron-guard (railway_monitor_guard.sh) — patrz raport CHAT-T-108.
 *
 * CHAT-T-109 (anty-zawieszenie, incydent 29-06 cichy zgon o 23:33):
 *   - connect_timeout 8->5 (spojnie z CHAT-T-107 backend) — wiszacy CONNECT odpada po 5s.
 *   - SET statement_timeout=6000 na sesji — PG ubija zapytanie po 6s i zwraca blad,
 *     wiec klient sie ODBLOKOWUJE (zamiast wisiec na query() jak 28/29-06).
 *   - kooperatywny budzet ~7s w pgProbe: po przekroczeniu pozostale metryki = FAIL bez
 *     wykonania, zeby pojedynczy cykl domykal sie w ~6-7s niezaleznie od stanu Railway.
 *   - log ZAWSZE sie zapisuje (skok latencji widoczny jako FAIL/wysokie ms, nie cisza).
 *   - twardy backstop na wiszace polaczenie sieciowe (blackhole, gdy nawet statement_timeout
 *     nie dochodzi): cron-guard wykrywa stary log (>60s) i wskrzesza monitor (kill -9 + restart).
 *
 * CHAT-T-119 (diagnostyka sieciowa w chwili epizodu — dowod dla Smarthost #167585):
 *   - captureNetworkDiag(): ping x4 (Railway/Leaseweb-AMS/1.1.1.1/8.8.8.8) + traceroute Railway,
 *     kazde owiniete w `timeout`, uruchamiane W TLE (nohup ... &), zeby petla monitora szla dalej
 *     i cron-guard (prog swiezosci 60s) NIE uznal monitora za martwy podczas ~90s zrzutu.
 *   - wpiete w START epizodu (>=ALERT_STREAK FAIL) i przy RECOVERY (trasa na starcie i koncu).
 *   - zrzut -> ~/_diag/incident_YYYYMMDD_HHMMSS.txt (jeden plik per epizod, timestamp startu).
 *   - pomiar OKNA niedostepnosci: od 1. FAIL epizodu do powrotu OK (kluczowa liczba dla Smarthost).
 *   - FAZA 1: tylko plik + istniejacy mail do Karola. BEZ wysylki SMTP do Smarthost (faza 2 pozniej).
 */

$BASE = '/home/divezone/public_html/chat.divezone.pl';
$DIAG = '/home/divezone/_diag';
require $BASE . '/vendor/autoload.php';
require $DIAG . '/railway_summary_mail.php'; // definiuje railway_summary_send()
use DiveChat\Config;

Config::load($BASE);
$DBURL = $_ENV['DATABASE_URL'] ?? '';
$p = parse_url($DBURL);
parse_str($p['query'] ?? '', $q);
$ssl   = $q['sslmode'] ?? 'disable';
$RHOST = $p['host'] ?? 'switchback.proxy.rlwy.net';
$RPORT = (int)($p['port'] ?? 14368);
// connect_timeout=5 (CHAT-T-109): wiszacy CONNECT do Railway odpada po 5s, nie zamraza petli.
$DSN = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s;user=%s;password=%s;connect_timeout=5',
    $RHOST, $RPORT, ltrim($p['path'] ?? '', '/'), $ssl, $p['user'] ?? '', $p['pass'] ?? '');

$WAW = new DateTimeZone('Europe/Warsaw');
$UTC = new DateTimeZone('UTC');
$MAIL_TO = 'k.susicki@divezone.pl, k.susicki@gmail.com';
$INTERVAL = 5;
$PROBE_KEY = '__monitor_probe__';
$ALERT_STREAK = 3;        // >=3 FAIL z rzedu => alert
$RECOVERY_OK = 3;         // tyle pelnych OK cykli => recovery
$COOLDOWN_S = 15 * 60;    // min odstep miedzy alertami
$HEARTBEAT_EVERY = 100;
$PG_METRICS = ['pg_select1', 'pg_settings', 'pg_chiptree', 'pg_upsert'];

function logPath(string $diag, DateTimeZone $waw): string {
    return $diag . '/railway_monitor_' . (new DateTime('now', $waw))->format('Ymd') . '.log';
}
function wlog(string $diag, DateTimeZone $waw, string $line): void {
    file_put_contents(logPath($diag, $waw), $line, FILE_APPEND);
}

function tcp(string $host, int $port, int $tmo): array {
    $t = microtime(true); $e = 0; $es = '';
    $s = @stream_socket_client("tcp://{$host}:{$port}", $e, $es, $tmo, STREAM_CLIENT_CONNECT);
    $ms = (microtime(true) - $t) * 1000;
    if ($s !== false) { fclose($s); return [true, $ms, 0]; }
    return [false, $ms, $e];
}

/**
 * CHAT-T-119 — diagnostyka sieciowa w chwili epizodu (dowod dla Smarthost #167585).
 * Odpala ping x4 + traceroute do celow diagnostycznych i DOPISUJE surowe wyniki do pliku incydentu.
 *
 * KRYTYCZNE: uruchamiane W TLE (nohup bash -c '...' &). Petla monitora wraca NATYCHMIAST i loguje
 * dalej, wiec cron-guard (CHAT-T-109, prog swiezosci 60s) nie uzna monitora za martwy podczas ~90s
 * zrzutu. Diag jako osobny proces przezyje nawet ubicie monitora przez guard.
 *
 * Kazde polecenie owiniete w `timeout` — wiszacy traceroute przy blackhole nie zablokuje nawet
 * procesu w tle. Brak inputu uzytkownika (stale IP) -> sanitizacja nie jest potrzebna.
 *
 * Cele (patrz KONTEKST DIAGNOSTYCZNY taska):
 *   66.33.22.230  switchback.proxy.rlwy.net — Railway EU West/Amsterdam (oczekiwane STRATY w epizodzie)
 *   5.79.108.33   Leaseweb Amsterdam — kontrola EU, INNA trasa niz twelve99 (oczekiwane CZYSTO)
 *   1.1.1.1 / 8.8.8.8 — kontrola globalna (oczekiwane CZYSTO)
 */
function captureNetworkDiag(string $incidentFile, string $reason, DateTimeZone $waw, DateTimeZone $utc, string $preface = ''): void {
    $hdr = "\n############################################################\n"
         . "### DIAG: {$reason}\n"
         . "### czas: " . (new DateTime('now', $utc))->format('Y-m-d H:i:s') . " UTC / "
         . (new DateTime('now', $waw))->format('H:i:s') . " WAW\n"
         . "### host: " . gethostname() . "\n"
         . "### Cel: switchback.proxy.rlwy.net (66.33.22.230), baza Railway region EU West/Amsterdam. Trasa przez twelve99/Arelion.\n"
         . "############################################################\n";
    // Naglowek (i opcjonalny $preface, np. linia OKNA) wypisujemy WEWNATRZ zablokowanego (flock) bloku w tle
    // — nie z procesu glownego. Inaczej synchroniczny zapis PHP wpadlby w srodek strumienia pingow diagu,
    //   ktory jeszcze pisze (przeplot przy krotkim epizodzie). Teraz KOMPLET (naglowek+dane) idzie pod jedna blokada.
    $textBlock = $preface . $hdr;

    // Payload w tle: naglowek (printf) + sekwencja pingow + traceroute, kazde z twardym `timeout`.
    // ping -c 15 -W 2: worst-case ~30s przy 100% strat; timeout 40 daje margines. traceroute: timeout 50.
    $payload =
          'printf %s ' . escapeshellarg($textBlock) . '; '
        . 'echo "=== ping Railway 66.33.22.230 (oczekiwane STRATY w epizodzie) ==="; timeout 40 ping -c 15 -W 2 66.33.22.230; echo; '
        . 'echo "=== ping Leaseweb AMS 5.79.108.33 (kontrola EU, inna trasa niz twelve99) ==="; timeout 40 ping -c 15 -W 2 5.79.108.33; echo; '
        . 'echo "=== ping Cloudflare 1.1.1.1 (kontrola globalna) ==="; timeout 40 ping -c 15 -W 2 1.1.1.1; echo; '
        . 'echo "=== ping Google 8.8.8.8 (kontrola globalna) ==="; timeout 40 ping -c 15 -W 2 8.8.8.8; echo; '
        . 'echo "=== traceroute Railway 66.33.22.230 (hop gdzie gina pakiety) ==="; timeout 50 traceroute -w 2 -m 20 66.33.22.230; echo; '
        . 'echo "=== DIAG koniec ($(date -u)) ===";';
    // Grupujemy w { ...; } i przekierowujemy CALOSC do pliku incydentu, potem calosc w tle (nohup &).
    // flock: gdy epizod-start (~90s w tle) jeszcze pisze, a nastapi recovery, epizod-koniec CZEKA na
    // zwolnienie blokady zamiast przeplatac wyjscie w tym samym pliku (proces w tle => blokowanie OK).
    $group = '( flock -x 9; { ' . $payload . ' } >> ' . escapeshellarg($incidentFile) . ' 2>&1 ) 9>'
           . escapeshellarg($incidentFile . '.lock');
    $cmd = 'nohup bash -c ' . escapeshellarg($group) . ' >/dev/null 2>&1 &';
    shell_exec($cmd);
}

/**
 * Jeden connect na cykl + 4 realne zapytania mierzone osobno.
 * Connect padl -> wszystkie PG metryki FAIL. Zwraca [metryka => [ok, ms]].
 *
 * CHAT-T-109 — anty-zawieszenie:
 *  - connect_timeout=5 (w DSN) ogranicza wiszacy connect.
 *  - SET statement_timeout=6000 -> PG ubija dlugie zapytanie i odblokowuje klienta.
 *  - $budgetMs (~7s): po przekroczeniu lacznego czasu pozostale metryki = FAIL bez wykonania,
 *    zeby cykl domykal sie w ~6-7s niezaleznie od stanu Railway.
 * (Wiszace polaczenie sieciowe / blackhole, gdy nawet statement_timeout nie dochodzi -> lapie cron-guard
 *  po swiezosci logu; tu chronimy przed typowa degradacja, gdy serwer PG odpowiada wolno albo bledem.)
 */
function pgProbe(string $dsn, string $probeKey, array $metrics, int $budgetMs = 7000): array {
    $res = [];
    foreach ($metrics as $m) { $res[$m] = [false, 0.0]; }
    $pdo = null;
    $t0 = microtime(true);
    $budgetLeft = static fn() => ($budgetMs - (microtime(true) - $t0) * 1000) > 0;
    try {
        $tc = microtime(true);
        $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        // Twardy limit zapytania po stronie PG: serwer przerywa query po 6s i zwraca blad,
        // dzieki czemu klient sie odblokowuje (zamiast wisiec w libpq jak podczas incydentu 29-06).
        $pdo->exec('SET statement_timeout = 6000');
        $connMs = (microtime(true) - $tc) * 1000;

        $timed = function (string $name, callable $fn) use (&$res, $budgetLeft) {
            if (!$budgetLeft()) { $res[$name] = [false, 0.0]; return; } // budzet wyczerpany -> FAIL bez wykonania
            $t = microtime(true);
            try { $fn(); $res[$name][0] = true; }
            catch (\Throwable $e) { $res[$name][0] = false; }
            finally { $res[$name][1] = (microtime(true) - $t) * 1000; }
        };

        // pg_select1 — dolicz koszt connectu (to realny pierwszy odczyt w cyklu)
        $t = microtime(true);
        try { $pdo->query('SELECT 1')->fetch(); $res['pg_select1'][0] = true; }
        catch (\Throwable $e) { $res['pg_select1'][0] = false; }
        $res['pg_select1'][1] = $connMs + (microtime(true) - $t) * 1000;

        $timed('pg_settings', function () use ($pdo) {
            $st = $pdo->prepare("SELECT value FROM divechat_settings WHERE key = ?");
            $st->execute(['model_primary']); $st->fetch();
        });
        $timed('pg_chiptree', function () use ($pdo) {
            $pdo->query('SELECT count(*) FROM divechat_chip_nodes WHERE active')->fetch();
        });
        // Probe ZAPISU — tylko klucz __monitor_probe__, dane produkcyjne nietkniete.
        $timed('pg_upsert', function () use ($pdo, $probeKey) {
            $st = $pdo->prepare(
                "INSERT INTO divechat_rate_limit (key, window_start, count) VALUES (?, NOW(), 1) "
                . "ON CONFLICT (key) DO UPDATE SET count = divechat_rate_limit.count + 1, window_start = NOW() "
                . "RETURNING count");
            $st->execute([$probeKey]); $st->fetch();
        });
    } catch (\Throwable $e) {
        // connect padl -> wszystkie PG FAIL, ms = czas do bledu na select1
        $res['pg_select1'][1] = isset($connMs) ? $connMs : 0.0;
    } finally {
        $pdo = null;
    }
    return $res;
}

function cleanupProbe(string $dsn, string $probeKey): void {
    try {
        $pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
        $pdo->exec('SET statement_timeout = 6000');
        $st = $pdo->prepare("DELETE FROM divechat_rate_limit WHERE key = ?");
        $st->execute([$probeKey]);
        $pdo = null;
    } catch (\Throwable $e) { /* best-effort */ }
}

function sendAlertMail(string $to, string $subject, string $body): bool {
    $headers = "From: noreply@divezone.pl\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

$now = new DateTime('now', $WAW);
// dzienny digest 07:00 (zachowany) — nadawany raz/dzien, monitor dalej dziala
$mailAt = (clone $now)->setTime(7, 0, 0);
if ($mailAt <= $now) { $mailAt->modify('+1 day'); }

cleanupProbe($DSN, $PROBE_KEY); // czysty start klucza probe

wlog($DIAG, $WAW, sprintf("# START %s WAW | CIAGLY (bez stop) | interval %ds | host %s:%d | digest@ %s | TRASA: serwer->Railway\n",
    $now->format('Y-m-d H:i:s'), $INTERVAL, $RHOST, $RPORT, $mailAt->format('H:i')));
wlog($DIAG, $WAW, "# metryki: railway_tcp, " . implode(', ', $PG_METRICS) . ", github | alert >= {$ALERT_STREAK} FAIL/rzad na metryce PG\n");

// --- alert state ---
$streak = array_fill_keys($PG_METRICS, 0);
$okStreak = 0; $alertActive = false; $lastAlertTs = 0.0; $episodeInfo = '';
// CHAT-T-119 — pomiar okna + plik incydentu per epizod
$firstFailW = null;      // DateTime 1. FAIL biezacej serii (reset przy pelnym OK) — start okna niedostepnosci
$episodeStartW = null;   // zamrozony start okna epizodu (kopia $firstFailW w chwili alertu)
$incidentFile = '';      // ~/_diag/incident_YYYYMMDD_HHMMSS.txt biezacego epizodu
$i = 0;

while (true) {
    $i++;
    $nowW = new DateTime('now', $WAW);

    // dzienny digest 07:00 — po wyslaniu mailAt przeskakuje na jutro (brak refire tego samego dnia)
    if ($nowW >= $mailAt) {
        try {
            $st = railway_summary_send($DIAG, $WAW, $MAIL_TO, false);
            error_log('[railway_monitor] digest mail status=' . $st);
        } catch (\Throwable $e) {
            error_log('[railway_monitor] digest failed: ' . $e->getMessage());
        }
        $mailAt->modify('+1 day');
    }

    [$rtok, $rtms, $errno] = tcp($RHOST, $RPORT, 5);   // connect TCP — limit 5s (CHAT-T-109)
    $pg = pgProbe($DSN, $PROBE_KEY, $PG_METRICS);
    [$ghok, $ghms, $ge] = tcp('api.github.com', 443, 5);

    $cell = function (string $name) use ($pg) {
        return sprintf("%s %-4s %6.0fms", $name, $pg[$name][0] ? 'OK' : 'FAIL', $pg[$name][1]);
    };
    $line = sprintf("#%05d %s UTC | %s WAW | railway_tcp %-4s %6.0fms | %s | %s | %s | %s | github %-4s %6.0fms | errno=%d\n",
        $i, (new DateTime('now', $UTC))->format('Y-m-d H:i:s'), $nowW->format('H:i:s'),
        $rtok ? 'OK' : 'FAIL', $rtms,
        $cell('pg_select1'), $cell('pg_settings'), $cell('pg_chiptree'), $cell('pg_upsert'),
        $ghok ? 'OK' : 'FAIL', $ghms, $errno);
    wlog($DIAG, $WAW, $line);

    // --- alert / recovery ---
    $allPgOk = true; $worst = null;
    foreach ($PG_METRICS as $m) {
        if ($pg[$m][0]) { $streak[$m] = 0; }
        else {
            $streak[$m]++; $allPgOk = false;
            if ($worst === null || $streak[$m] > $streak[$worst]) { $worst = $m; }
        }
    }
    // CHAT-T-119 — start okna niedostepnosci = 1. FAIL serii (przed osiagnieciem progu alertu).
    if ($allPgOk) { $firstFailW = null; }
    elseif ($firstFailW === null) { $firstFailW = clone $nowW; }
    $okStreak = $allPgOk ? $okStreak + 1 : 0;
    $nowTs = microtime(true);

    if ($worst !== null && $streak[$worst] >= $ALERT_STREAK) {
        if (!$alertActive && ($nowTs - $lastAlertTs) >= $COOLDOWN_S) {
            $ts = (new DateTime('now', $UTC))->format('H:i:s') . ' UTC / ' . $nowW->format('H:i:s') . ' WAW';
            $episodeInfo = "{$worst} FAIL x{$streak[$worst]} od ~{$ts}";
            $subj = "[DIVECHAT MONITOR] Railway degradacja: {$worst} FAIL x{$streak[$worst]} od {$ts}";
            $body = "TRASA PRODUKCYJNA serwer->Railway.\n"
                  . "Metryka {$worst}: {$streak[$worst]} FAIL z rzedu, od {$ts}.\n"
                  . "Host: {$RHOST}:{$RPORT}\nLog: " . logPath($DIAG, $WAW) . "\n";
            $ok = sendAlertMail($MAIL_TO, $subj, $body);
            wlog($DIAG, $WAW, "### ALERT {$ts} | {$episodeInfo} | mail=" . ($ok ? 'sent' : 'FAILED') . "\n");
            $alertActive = true; $lastAlertTs = $nowTs;

            // CHAT-T-119 — zamroz start okna, zaloz plik incydentu, odpal diag w tle (start epizodu)
            $episodeStartW = ($firstFailW instanceof DateTime) ? clone $firstFailW : clone $nowW;
            $incidentFile = $DIAG . '/incident_' . $episodeStartW->format('Ymd_His') . '.txt';
            file_put_contents($incidentFile, sprintf(
                "### EPIZOD START (1. FAIL): %s WAW | metryka %s FAIL x%d | prog alertu osiagniety %s\n",
                $episodeStartW->format('Y-m-d H:i:s'), $worst, $streak[$worst], $nowW->format('H:i:s')), FILE_APPEND);
            captureNetworkDiag($incidentFile, "epizod-start {$worst}", $WAW, $UTC);
            wlog($DIAG, $WAW, "### DIAG zapisano: " . basename($incidentFile) . " (epizod-start {$worst})\n");
        }
    }
    if ($alertActive && $okStreak >= $RECOVERY_OK) {
        $ts = (new DateTime('now', $UTC))->format('H:i:s') . ' UTC / ' . $nowW->format('H:i:s') . ' WAW';

        // CHAT-T-119 — pomiar OKNA niedostepnosci (od 1. FAIL do powrotu OK). Kluczowa liczba dla Smarthost.
        $windowStr = '';
        if ($episodeStartW instanceof DateTime) {
            $durSec = max(0, $nowW->getTimestamp() - $episodeStartW->getTimestamp());
            $windowStr = sprintf("### OKNO NIEDOSTEPNOSCI: start %s, koniec %s, czas trwania %dm %ds\n",
                $episodeStartW->format('H:i:s'), $nowW->format('H:i:s'), intdiv($durSec, 60), $durSec % 60);
        }

        $subj = "[DIVECHAT MONITOR] Railway recovery: PG znow OK ({$ts})";
        $body = "TRASA PRODUKCYJNA serwer->Railway.\n"
              . "Po epizodzie [{$episodeInfo}] wszystkie metryki PG OK przez {$okStreak} cykli.\nPowrot: {$ts}\n"
              . ($windowStr !== '' ? $windowStr : '');
        $ok = sendAlertMail($MAIL_TO, $subj, $body);
        wlog($DIAG, $WAW, "### RECOVERY {$ts} | po [{$episodeInfo}] | mail=" . ($ok ? 'sent' : 'FAILED') . "\n");
        if ($windowStr !== '') { wlog($DIAG, $WAW, $windowStr); }

        // CHAT-T-119 — druga diag (trasa na koncu epizodu) do TEGO SAMEGO pliku incydentu.
        // Linia OKNA idzie jako $preface -> wypisana pod flock RAZEM z naglowkiem epizod-koniec,
        // wiec czeka az diag epizod-start zwolni blokade (brak przeplotu przy krotkim epizodzie).
        if ($incidentFile !== '') {
            captureNetworkDiag($incidentFile, 'epizod-koniec', $WAW, $UTC, ($windowStr !== '' ? "\n" . $windowStr : ''));
            wlog($DIAG, $WAW, "### DIAG zapisano: " . basename($incidentFile) . " (epizod-koniec)\n");
        }

        $alertActive = false;
        $episodeStartW = null; $incidentFile = '';
    }

    if ($i % $HEARTBEAT_EVERY === 0) {
        wlog($DIAG, $WAW, sprintf("# alive %05d %s UTC | alert_active=%s ok_streak=%d\n",
            $i, (new DateTime('now', $UTC))->format('H:i:s'), $alertActive ? '1' : '0', $okStreak));
        cleanupProbe($DSN, $PROBE_KEY); // okresowe czyszczenie klucza probe
    }

    sleep($INTERVAL);
}
