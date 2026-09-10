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
 *     i cron-guard (prog swiezosci 60s) NIE uznal monitora za martwy podczas zrzutu
 *     (~100 s po dolozeniu mtr w CHAT-T-185, zmierzone na produkcji 2026-09-09 i 2026-09-10).
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

// === CHAT-T-185 ===
// 20 cykli, bo zmierzone 2026-09-09: mtr ICMP 25 s (tyle samo przy 100% strat), mtr TCP 11 s.
// Caly zrzut z mtr miesci sie ponizej progu ~4 min ze zlecenia (pomiar w raporcie).
define('MTR_CYCLES', 20);
// Tryb TCP dostaje 10 cykli, nie 20. Pomiar architekta 2026-09-09, 5 przebiegow:
// --report-cycles 20 -> Snt 10/12/15 (wynik UCIETY, niedeterministycznie), 15 -> Snt 10 (uciety),
// 10 -> Snt 10, czyli komplet. Przy 10 liczby w raporcie sa pelne i porownywalne miedzy zrzutami.
define('MTR_CYCLES_TCP', 10);
// IP proxy Railway. Zrzut CHAT-T-119 ma je wpisane na sztywno w komendach ping/traceroute;
// tu trzymamy je raz, zeby mtr epizodowy i mtr odniesienia nigdy sie nie rozjechaly.
define('MTR_TARGET_IP', '66.33.22.230');   // wartosc odniesienia; realny cel ustala $MTR_IP na starcie
$MTR_BASELINE_AT_UTC = '18:30';   // srodek okna ryzyka; baseline bez maila i bez alertu
// Powiadomienie smarthosta: BRAK zmiennej w .env = funkcja nieaktywna (stan domyslny po wdrozeniu).
$SMARTHOST_MAIL   = trim((string)($_ENV['SMARTHOST_TICKET_MAIL'] ?? ''));
$SMARTHOST_TICKET = trim((string)($_ENV['SMARTHOST_TICKET_ID'] ?? '167585'));
$SMARTHOST_MAX_PER_DAY = 3;
$SMARTHOST_WAIT_S = 180;    // ile czekamy po recovery, zeby zrzut w tle zdazyl dopisac oba mtr
$SMARTHOST_DEADLINE_S = 600; // twardy limit czekania: po tym wysylamy to, co jest

// CHAT-T-183: $atW = znacznik WAW, ktory JUZ trafil w tresc linii. Bez niego nazwa pliku
// brala sie z chwili ZAPISU (po sondach), wiec cykl zaczety przed polnoca ladowal w logu doby
// D+1 z godzina 23:59:xx z doby D — raport doby D gubil probke, D+1 dostawala obca.
// Domyslne null = zachowanie jak dotad (naglowki startowe poza petla).
function logPath(string $diag, DateTimeZone $waw, ?DateTime $atW = null): string {
    $t = $atW ?? new DateTime('now', $waw);
    return $diag . '/railway_monitor_' . $t->format('Ymd') . '.log';
}
function wlog(string $diag, DateTimeZone $waw, string $line, ?DateTime $atW = null): void {
    file_put_contents(logPath($diag, $waw, $atW), $line, FILE_APPEND);
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
 * dalej, wiec cron-guard (CHAT-T-109, prog swiezosci 60s) nie uzna monitora za martwy podczas ~100s
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
/**
 * CHAT-T-185/186 — polecenia mtr do zrzutu.
 *
 * KAZDE wywolanie ma USTAWIONY PATH, a nie tylko pelna sciezke do mtr. Powod (CHAT-T-186,
 * zmierzony 2026-09-10, bo wersja z samej pelnej sciezki byla MARTWA na produkcji od wdrozenia):
 * `mtr` uruchamia proces pomocniczy `mtr-packet` PO SAMEJ NAZWIE, przez PATH. Pelna sciezka do
 * `mtr` nic tu nie daje, bo gubi sie nie `mtr`, tylko jego dziecko. Monitor jest wskrzeszany
 * przez guard z CRONA i dziedziczy minimalny PATH:
 *     /proc/<pid>/environ zywego monitora  ->  PATH=/usr/bin:/bin      (2026-09-10)
 * a `mtr-packet` lezy w /usr/sbin (w /usr/bin go NIE MA). Efekt na produkcji:
 *     ~/_diag/mtr_baseline_20260909.txt  ->  "Failure to start mtr-packet: Invalid argument"
 *                                            (oba przebiegi, caly blok 0 s)
 * Odtworzone para na parze, ta sama komenda, ten sam host:
 *     env -i PATH=/usr/bin:/bin           ...  -> Failure to start mtr-packet
 *     env -i PATH=/usr/sbin:/usr/bin:/bin ...  -> pelny raport do hopa 11, 0.0% strat
 * Kontrola potwierdzajaca mechanizm: env -i PATH=/usr/bin:/bin MTR_PACKET=/usr/sbin/mtr-packet
 * tez daje pelny raport — czyli decyduje wylacznie odnalezienie procesu pomocniczego.
 *
 * KOREKTA FAKTU (CHAT-T-119 zapisal "mtr BRAK — NIE uzywac"): mtr JEST i dziala z konta
 * divezone bez roota, bo /usr/sbin/mtr-packet ma cap_net_raw=ep. Dochodzi do hopa 11, czyli do
 * samego 66.33.22.230, ktorego traceroute nigdy nie pokazywal (Railway filtruje ICMP
 * TTL-exceeded, ale odpowiada na echo i na TCP).
 *
 * Czasy zmierzone 2026-09-09: ICMP 20 cykli = 25 s (tyle samo przy 100% strat, do blackhole
 * 26 s), TCP 10 cykli = 11 s.
 */
function mtrCommands(string $target, int $port, int $cycles): string {
    $mtr = '/usr/sbin/mtr';
    // PATH przed KAZDYM wywolaniem — patrz docblock. Pelna sciezka do mtr zostaje: nic nie kosztuje.
    $env = 'PATH=/usr/sbin:/usr/bin:/bin ';
    return 'echo "=== mtr ICMP ' . $target . ' (strata per hop) ==="; '
         . $env . 'timeout 90 ' . $mtr . ' --report --report-wide --report-cycles ' . $cycles . ' -n ' . $target . '; echo; '
         . 'echo "=== mtr TCP ' . $target . ':' . $port . ' (ta sama sciezka na porcie, ktory realnie pada) ==="; '
         // MTR_CYCLES_TCP=10, bo powyzej tego mtr 0.92 ucina prob: przy 20 cyklach Snt wychodzilo
         // 10/12/15, przy 15 tez 10 (pomiar architekta, 5 przebiegow, 2026-09-09). Przy 10 cyklach
         // wynik jest powtarzalny: hopy posrednie Snt=10, hop koncowy 9-10 (moj pomiar, 5 przebiegow).
         // Komunikat "Unexpected mtr-packet error" to znany ogon mtr 0.92 w trybie TCP na tym hoscie
         // — NIE filtrujemy go, idzie surowy do zrzutu i do maila.
         // Osobno zaobserwowane 2026-09-09 15:30-15:33 UTC: seria natychmiastowych bledow
         // "Address in use" (Snt=1, sciezka urwana na 5 hopie), niepowtarzalna w 6 pozniejszych
         // przebiegach. Przyczyny nie ustalono; sekcja TCP moze wiec sporadycznie wyjsc szczatkowa,
         // co widac po samym komunikacie w zrzucie. Sekcja ICMP jest tym nietknieta.
         . $env . 'timeout 90 ' . $mtr . ' --report --report-wide --report-cycles ' . MTR_CYCLES_TCP . ' -n -T -P ' . $port . ' ' . $target . '; echo; ';
}

/**
 * CHAT-T-185 — dobowy mtr ODNIESIENIA (stan zdrowy), bez maila i bez alertu.
 * Epizody sa rzadkie, wiec mtr z awarii nie ma z czym porownac. Leci w tle, pod wlasnym
 * flockiem, dopisuje do ~/_diag/mtr_baseline_YYYYMMDD.txt.
 */
function captureMtrBaseline(string $diag, DateTimeZone $utc, string $target, int $port, int $cycles): string {
    $file = $diag . '/mtr_baseline_' . (new DateTime('now', $utc))->format('Ymd') . '.txt';
    $hdr = "\n############################################################\n"
         . "### MTR BASELINE (stan odniesienia, brak epizodu)\n"
         . "### czas: " . (new DateTime('now', $utc))->format('Y-m-d H:i:s') . " UTC\n"
         . "### host: " . gethostname() . "\n"
         . "############################################################\n";
    $payload = 'printf %s ' . escapeshellarg($hdr) . '; '
        . mtrCommands($target, $port, $cycles)
        . 'echo "=== BASELINE koniec ($(date -u)) ===";';
    $group = '( flock -x 9; { ' . $payload . ' } >> ' . escapeshellarg($file) . ' 2>&1 ) 9>'
           . escapeshellarg($file . '.lock');
    shell_exec('nohup bash -c ' . escapeshellarg($group) . ' >/dev/null 2>&1 &');
    return $file;
}

function captureNetworkDiag(string $incidentFile, string $reason, DateTimeZone $waw, DateTimeZone $utc,
                            string $preface = '', string $mtrIp = MTR_TARGET_IP, int $mtrPort = 14368): void {
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
        // CHAT-T-185: oba mtr pod TYM SAMYM flockiem co reszta zrzutu (smarthost prosil o mtr w chwili strat)
        . mtrCommands($mtrIp, $mtrPort, MTR_CYCLES)
        . 'echo "=== DIAG koniec ($(date -u)) ===";';
    // Grupujemy w { ...; } i przekierowujemy CALOSC do pliku incydentu, potem calosc w tle (nohup &).
    // flock: gdy epizod-start (~100s w tle) jeszcze pisze, a nastapi recovery, epizod-koniec CZEKA na
    // zwolnienie blokady zamiast przeplatac wyjscie w tym samym pliku (proces w tle => blokowanie OK).
    // CHAT-T-185: flock z limitem czekania. Rozne epizody maja ROZNE pliki (i rozne blokady),
    // wiec czekanie dotyczy tylko pary epizod-start / epizod-koniec tego samego epizodu.
    // Po dolozeniu mtr zrzut trwa ~100 s, wiec 600 s zapasu starcza z duzym marginesem,
    // a zablokowany na stale holder nie zostawia wiszacego procesu w tle na zawsze.
    $group = '( flock -x -w 600 9 || { echo "### DIAG POMINIETY: blokada zrzutu zajeta >600 s"; exit 0; }; { '
           . $payload . ' } >> ' . escapeshellarg($incidentFile) . ' 2>&1 ) 9>'
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

/**
 * CHAT-T-185 — REZERWACJA slotu na mail do smarthosta. Jedna operacja pod JEDNA blokada:
 * sprawdzenie limitu dobowego, sprawdzenie czy ten epizod juz nie poszedl, i zapis obu.
 *
 * Dlaczego rezerwacja, a nie "policz, wyslij, dolicz" (recenzja codex 2026-09-09): miedzy
 * odczytem a dopisaniem miescila sie cala wysylka, wiec dwie instancje monitora (guard potrafi
 * wskrzesic druga zanim pierwsza zniknie) mogly przeczytac ten sam stan i wyslac po mailu ponad limit.
 * Klucz epizodu (nazwa pliku incydentu) daje dodatkowo idempotencje: ten sam epizod nie pojdzie dwa razy.
 *
 * FAIL CLOSED: czego nie umiemy policzyc, tego nie wysylamy. Do obcej skrzynki lepiej nie wyslac
 * nic, niz wyslac bez kontroli limitu.
 *
 * @return array{ok:bool,reason:string,count:int}
 */
function smarthostReserve(string $diag, DateTimeZone $waw, string $episodeKey, int $max): array {
    $day   = (new DateTime('now', $waw))->format('Ymd');
    $cntF  = $diag . '/smarthost_mail_' . $day . '.count';     // nazwa wprost ze zlecenia
    $sentF = $diag . '/smarthost_sent_' . $day . '.list';      // klucze epizodow, ktore juz poszly
    $fh = @fopen($cntF, 'c+');
    if ($fh === false) {
        return ['ok' => false, 'reason' => 'nie moge otworzyc licznika (' . $cntF . ')', 'count' => -1];
    }
    try {
        if (!flock($fh, LOCK_EX)) {
            return ['ok' => false, 'reason' => 'nie moge zablokowac licznika', 'count' => -1];
        }
        $raw  = trim((string)stream_get_contents($fh));
        $sent = file_exists($sentF) ? array_filter(array_map('trim', (array)@file($sentF))) : [];
        // Uszkodzony/pusty licznik NIE otwiera furtki na nielimitowana wysylke: wtedy liczba
        // faktycznie wyslanych maili bierze sie z listy kluczy epizodow, ktora jest zapisem
        // tego, co naprawde poszlo (recenzja codex 2026-09-09 — fail open bylo bledem).
        $cur  = ctype_digit($raw) ? max((int)$raw, count($sent)) : count($sent);
        $note = ($raw !== '' && !ctype_digit($raw)) ? ' (licznik uszkodzony: ' . substr($raw, 0, 20) . ', licze z listy)' : '';
        if (in_array($episodeKey, $sent, true)) {
            return ['ok' => false, 'reason' => 'mail dla tego epizodu juz poszedl' . $note, 'count' => $cur];
        }
        if ($cur >= $max) {
            return ['ok' => false, 'reason' => "limit dobowy {$cur}/{$max}" . $note, 'count' => $cur];
        }
        $cur++;
        ftruncate($fh, 0); rewind($fh);
        if (fwrite($fh, (string)$cur) === false) {
            return ['ok' => false, 'reason' => 'nie moge zapisac licznika', 'count' => -1];
        }
        fflush($fh);
        @file_put_contents($sentF, $episodeKey . "\n", FILE_APPEND);
        return ['ok' => true, 'reason' => 'slot ' . $cur . '/' . $max . $note, 'count' => $cur];
    } finally {
        @flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Zwrot rezerwacji, gdy mail() zawiodl — inaczej nieudana proba zjadalaby limit dobowy. */
function smarthostRelease(string $diag, DateTimeZone $waw, string $episodeKey, ?string $day = null): void {
    // Dzien rezerwacji podajemy jawnie: rezerwacja przed polnoca + nieudany mail po polnocy
    // zdejmowalyby licznik z NIEWLASCIWEJ doby (recenzja codex 2026-09-10).
    $day   = $day ?? (new DateTime('now', $waw))->format('Ymd');
    $cntF  = $diag . '/smarthost_mail_' . $day . '.count';
    $sentF = $diag . '/smarthost_sent_' . $day . '.list';
    $fh = @fopen($cntF, 'c+');
    if ($fh === false) { return; }
    try {
        if (!flock($fh, LOCK_EX)) { return; }
        $raw = trim((string)stream_get_contents($fh));
        $cur = ctype_digit($raw) ? (int)$raw : 0;
        $cur = max(0, $cur - 1);
        ftruncate($fh, 0); rewind($fh); fwrite($fh, (string)$cur); fflush($fh);
        if (file_exists($sentF)) {
            $keep = array_values(array_filter(array_map('trim', (array)@file($sentF)),
                fn($k) => $k !== '' && $k !== $episodeKey));
            @file_put_contents($sentF, $keep ? implode("\n", $keep) . "\n" : '');
        }
    } finally {
        @flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/**
 * CHAT-T-185 — trwala kolejka zaleglych maili. Monitor restartuje sie kilka razy na dobe
 * (fatale max_execution_time + guard), a mail czeka ~180 s na domkniecie zrzutu — bez zapisu
 * na dysk restart w tym oknie kasowalby powiadomienie bez sladu.
 */
function smarthostPendingFile(string $diag): string { return $diag . '/smarthost_pending.json'; }

function smarthostSavePending(string $diag, array $pending): void {
    @file_put_contents(smarthostPendingFile($diag), json_encode(array_values($pending)), LOCK_EX);
}

function smarthostLoadPending(string $diag): array {
    $f = smarthostPendingFile($diag);
    if (!file_exists($f)) { return []; }
    $d = json_decode((string)@file_get_contents($f), true);
    if (!is_array($d)) { return []; }
    $out = [];
    foreach ($d as $row) {
        if (!is_array($row) || !isset($row['file'], $row['windowUtc'], $row['windowWaw'])) { continue; }
        // Po restarcie nie czekamy drugi raz pelnego okna — zrzut i tak dawno leci.
        $row['notBefore'] = microtime(true) + 30;
        // TWARDY termin jest ABSOLUTNY i przezywa restarty (recenzja codex 2026-09-10): bez tego
        // seria restartow przesuwalaby porzucenie w nieskonczonosc i ostrzezenie nigdy by nie poszlo.
        $row['deadlineAbs'] = $row['deadlineAbs'] ?? (time() + 600);
        $row['deadline']    = microtime(true) + 300;
        $out[] = $row;
    }
    return $out;
}

/**
 * CHAT-T-185 — czyta zrzut incydentu BLOKAMI (`### DIAG:` + `### czas:`), a nie calym plikiem.
 *
 * Dlaczego blokami (recenzja codex 2026-09-09): plik zawiera zwykle DWA zrzuty — z chwili awarii
 * (`epizod-start`) i z chwili powrotu (`epizod-koniec`), rozdzielone minutami. Branie maksimum
 * strat z calego pliku mieszaloby pomiar Railway z jednej chwili z pomiarem kontroli z innej,
 * czyli budowaloby tez dla obcej skrzynki wniosek z danych, ktore nigdy nie wystapily razem.
 *
 * @return array{blocks:array<int,array{label:string,ts:string,loss:array<string,?float>,mtr:string}>,
 *               window:string,doneMarkers:int}
 */
function parseIncidentForTicket(string $incidentFile): array {
    $ips = ['66.33.22.230', '5.79.108.33', '1.1.1.1', '8.8.8.8'];
    $blocks = []; $window = ''; $done = 0;
    $cur = null; $curIp = null; $inMtr = false;
    $fh = @fopen($incidentFile, 'r');
    if ($fh === false) { return ['blocks' => [], 'window' => '', 'doneMarkers' => 0]; }
    $push = function () use (&$cur, &$blocks) { if ($cur !== null) { $cur['mtr'] = rtrim($cur['mtr']); $blocks[] = $cur; $cur = null; } };
    while (($ln = fgets($fh)) !== false) {
        if (strpos($ln, '### DIAG: ') === 0) {
            $push();
            $cur = ['label' => trim(substr($ln, 10)), 'ts' => '', 'loss' => array_fill_keys($ips, null), 'mtr' => ''];
            $inMtr = false; $curIp = null;
            continue;
        }
        if (strpos($ln, '### czas: ') === 0 && $cur !== null) { $cur['ts'] = trim(substr($ln, 10)); continue; }
        if (strpos($ln, '### OKNO NIEDOSTEPNOSCI') === 0) { $window = trim($ln); continue; }
        if (strpos($ln, '=== DIAG koniec') === 0) { $done++; $inMtr = false; continue; }
        if (strpos($ln, '=== mtr ') === 0) { $inMtr = true; if ($cur !== null) { $cur['mtr'] .= $ln; } continue; }
        if ($inMtr) {
            if (strpos($ln, '===') === 0 || strpos($ln, '###') === 0) { $inMtr = false; }
            else { if ($cur !== null) { $cur['mtr'] .= $ln; } continue; }
        }
        if (preg_match('/^--- (\d+\.\d+\.\d+\.\d+) ping statistics ---/', $ln, $m)) { $curIp = $m[1]; continue; }
        if ($curIp !== null && $cur !== null && preg_match('/([\d.]+)% packet loss/', $ln, $m)) {
            if (array_key_exists($curIp, $cur['loss'])) { $cur['loss'][$curIp] = (float)$m[1]; }
            $curIp = null;
        }
    }
    $push();
    fclose($fh);
    return ['blocks' => $blocks, 'window' => $window, 'doneMarkers' => $done];
}

/**
 * CHAT-T-188 — czy SEKCJE MTR zawieraja realny pomiar, a nie sam komunikat bledu.
 *
 * Kryterium wyprowadzone z REALNYCH plikow (2026-09-10), nie z pamieci:
 *   KOMPLETNY   (zrzut z T-186, sciezka produkcyjna):
 *      === mtr ICMP 66.33.22.230 (strata per hop) ===
 *      HOST: divezonededyk.smarthost.pl Loss%   Snt   Last   Avg  Best  Wrst StDev
 *        1.|-- 193.93.88.254               0.0%    20    0.1 ...
 *   PUSTY       (~/_diag/mtr_baseline_20260909.txt, linie 7-11 — to przeszlo STARA bramke):
 *      === mtr ICMP 66.33.22.230 (strata per hop) ===
 *      /usr/sbin/mtr: Failure to start mtr-packet: Invalid argument
 *
 * Roznica jest jednoznaczna: wiersz naglowkowy `HOST:` i przynajmniej jeden wiersz hopa.
 * Marker `=== DIAG koniec` NIE odroznia tych dwoch przypadkow, bo pisze sie bezwarunkowo.
 *
 * ROZSTRZYGA WIERSZ `HOST:`, A NIE LICZBA HOPOW — i to jest swiadoma decyzja po pomiarze
 * z 2026-09-10, ktory pokazal falszywy negatyw pierwotnego kryterium:
 *     mtr --report do celu CALKOWICIE nieosiagalnego (198.51.100.1) drukuje
 *         Start: 2026-09-10T09:53:19+0200
 *         HOST: divezonededyk.smarthost.pl Loss%   Snt   Last   Avg ...
 *     i NIC WIECEJ — zero wierszy hopow.
 * Taki zrzut powstalby przy najciezszej awarii (nic nie odpowiada) i wymaganie hopa
 * BLOKOWALOBY dowod dokladnie wtedy, kiedy jest najbardziej potrzebny. Falszywy negatyw
 * cicho gubi material do zgloszenia i jest grozniejszy niz falszywy pozytyw.
 * Wiersz `HOST:` pojawia sie wylacznie wtedy, gdy mtr RUSZYL i wypisal raport — a tego
 * wlasnie brakuje w zrzucie z 09.09, gdzie jest sam komunikat bledu. To wystarcza do rozroznienia.
 *
 * Nie wymagamy tez kompletu hopow ani pelnej liczby sond: zrzut szczatkowy z realnego
 * incydentu (T-185, "Address in use": HOST + 5 hopow, ostatni "???") NIESIE dowod i ma przejsc.
 *
 * WYSTARCZY JEDNA sekcja z pomiarem, nie obie (recenzja codex 2026-09-10, dwa realne powody):
 *  - tryb TCP potrafi paść sam z siebie ("Address in use", zaobserwowane w T-185), a wtedy
 *    sekcja ICMP z pelna sciezka do hopa 11 dalej jest mocnym dowodem — blokowanie jej to
 *    czysta strata materialu,
 *  - plik baseline jest DOPISYWANY (`>>`), wiec po nieudanej probie i udanym ponowieniu
 *    zawiera sekcje zepsute I dobre; wymaganie "wszystkie sekcje dobre" kazaloby uznac
 *    udane ponowienie za nieudane i to jest blad krytyczny tej konstrukcji.
 *
 * @return array{ok:bool,reason:string}
 */
function mtrSectionsEvidence(string $text, int $expectedSections = 1): array {
    $parts = preg_split('/^=== mtr /m', $text);
    array_shift($parts);                     // to, co przed pierwsza sekcja
    if (!$parts) {
        return ['ok' => false, 'reason' => 'brak sekcji mtr w zrzucie'];
    }
    $withHost = 0; $withHops = 0;
    foreach ($parts as $sec) {
        if (preg_match('/^HOST:/m', $sec)) {
            $withHost++;
            if (preg_match('/^\s*\d+\.\|--/m', $sec)) { $withHops++; }
        }
    }
    if ($withHost < $expectedSections) {
        return ['ok' => false, 'reason' => 'sekcji mtr z wierszem HOST: ' . $withHost . ' z ' . count($parts)
                                           . ' (zadne mtr nie ruszylo)'];
    }
    return ['ok' => true, 'reason' => 'sekcje mtr: ' . count($parts) . ', z pomiarem: ' . $withHost
                                      . ', w tym z hopami: ' . $withHops];
}

/**
 * CHAT-T-188 — BRAMKA WYSYLKI. Sprawdza TRESC bloku dowodowego, nie marker konca.
 *
 * Dziala na TYM SAMYM bloku, z ktorego sendSmarthostTicket() buduje maila — inaczej
 * sprawdzalibysmy co innego, niz wysylamy (blok epizod-start pusty, blok epizod-koniec pelny
 * przeszedlby bramke, a w mailu tabela strat bylaby pusta).
 *
 * Wymagamy: strat pingu do Railway ORAZ do co najmniej jednej kontroli (to jest cala teza maila:
 * "tracimy do Railway, kontrole czyste" — bez obu liczb nie ma czego twierdzic), plus obu sekcji
 * mtr z danymi.
 *
 * @return array{ok:bool,reason:string}
 */
function ticketEvidenceGate(?array $block): array {
    if ($block === null) {
        return ['ok' => false, 'reason' => 'brak bloku diagnostycznego w zrzucie'];
    }
    if (($block['loss']['66.33.22.230'] ?? null) === null) {
        return ['ok' => false, 'reason' => 'brak pomiaru strat pingu do Railway'];
    }
    $ctrl = 0;
    foreach (['5.79.108.33', '1.1.1.1', '8.8.8.8'] as $ip) {
        if (($block['loss'][$ip] ?? null) !== null) { $ctrl++; }
    }
    if ($ctrl === 0) {
        return ['ok' => false, 'reason' => 'brak pomiaru zadnej kontroli pingowej'];
    }
    $m = mtrSectionsEvidence((string)($block['mtr'] ?? ''), 1);
    if (!$m['ok']) {
        return ['ok' => false, 'reason' => $m['reason']];
    }
    return ['ok' => true, 'reason' => 'ping Railway + ' . $ctrl . ' kontrol(e), ' . $m['reason']];
}

/**
 * CHAT-T-188 — czy plik baseline zawiera realny pomiar. Ten SAM test tresci co bramka maila,
 * tylko bez czesci pingowej (baseline z zalozenia ma same sekcje mtr).
 * Zastepuje file_exists(): 09.09 plik ISTNIAL i mial 555 bajtow samych komunikatow bledu,
 * wiec nadrabianie po restarcie uznawalo dobe za obsluzona.
 */
function mtrBaselineHasData(string $file): bool {
    if (!file_exists($file)) { return false; }
    $txt = (string)@file_get_contents($file);
    // Plik dobowy jest DOPISYWANY, wiec moze zawierac nieudana probe i udane ponowienie.
    // Wystarczy jedna sekcja z realnym pomiarem, zeby uznac dobe za obsluzona.
    return mtrSectionsEvidence($txt, 1)['ok'];
}

function sendAlertMail(string $to, string $subject, string $body): bool {
    $headers = "From: noreply@divezone.pl\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, $subject, $body, $headers);
}

/**
 * CHAT-T-185 — JEDEN mail do smarthosta na epizod, wysylany przy RECOVERY (nie przy starcie:
 * 02.09 poszloby 21 maili w poltorej godziny, co u helpdesku konczy sie filtrem).
 * Kopia zawsze do Karola. Zwraca linie do wpisania w log glowny.
 *
 * ZASADA TRESCI (po recenzji codex 2026-09-09): mail idzie do OBCEJ firmy pod nasza nazwa,
 * wiec kazde zdanie musi wynikac z jednego, konkretnego pomiaru:
 *   - straty pingu bierzemy z JEDNEGO bloku diagnostycznego (tego z chwili awarii), nie z maksimum
 *     po calym pliku — inaczej zestawialibysmy Railway z jednej minuty z kontrolami z innej,
 *   - "kontrole czyste" piszemy tylko wtedy, gdy WSZYSTKIE trzy kontrole sa zmierzone i maja 0%,
 *   - temat mowi o utracie pakietow tylko wtedy, gdy pomiar te utrate pokazuje,
 *   - brakujace dowody nazywamy po imieniu zamiast je przemilczec,
 *   - CHAT-T-188: bez realnych danych w zrzucie mail w ogole NIE WYCHODZI (bramka po tresci).
 *
 * @return array{0:string,1:string} status: sent|mail-failed|gate-failed|refused, plus linia do logu
 */
function sendSmarthostTicket(array $pending, string $to, string $cc, string $ticket,
                             DateTimeZone $waw, DateTimeZone $utc, string $diag, int $maxPerDay,
                             int $intervalS): array {
    $tsU = (new DateTime('now', $utc))->format('H:i:s');
    $tsW = (new DateTime('now', $waw))->format('H:i:s');
    $key = basename($pending['file']);

    // CHAT-T-188: NAJPIERW sprawdzamy tresc, POTEM rezerwujemy slot — inaczej niekompletny
    // zrzut zjadalby jeden z trzech dobowych maili, nic nie wysylajac.
    $d = parseIncidentForTicket($pending['file']);
    $names = ['66.33.22.230' => 'Railway (66.33.22.230, cel)',
              '5.79.108.33'  => 'Leaseweb Amsterdam (5.79.108.33, kontrola)',
              '1.1.1.1'      => 'Cloudflare (1.1.1.1, kontrola)',
              '8.8.8.8'      => 'Google (8.8.8.8, kontrola)'];

    // Blok dowodowy = ten z chwili AWARII (epizod-start). Blok z powrotu pokazujemy osobno.
    $fail = null; $rec = null;
    foreach ($d['blocks'] as $b) {
        if ($fail === null && stripos($b['label'], 'epizod-start') !== false) { $fail = $b; }
        elseif (stripos($b['label'], 'epizod-koniec') !== false) { $rec = $b; }
    }
    if ($fail === null && $d['blocks']) { $fail = $d['blocks'][0]; }

    // CHAT-T-188 — BRAMKA PO TRESCI, na TYM SAMYM bloku, z ktorego powstanie mail.
    // 09.09 zrzut mial sam komunikat bledu, a marker konca i tak byl — bez tej bramki
    // do dostawcy poszlaby wiadomosc z pustymi sekcjami i zdaniem, ze zalaczamy pomiar.
    //
    // Gdy blok z chwili awarii jest niekompletny, a blok z powrotu ma dane — bierzemy ten drugi
    // (recenzja codex 2026-09-10). Lepiej wyslac slabszy dowod NAZWANY PO IMIENIU niz nie wyslac nic.
    $gate = ticketEvidenceGate($fail);
    if (!$gate['ok'] && $rec !== null && $rec !== $fail) {
        $gateRec = ticketEvidenceGate($rec);
        if ($gateRec['ok']) { $fail = $rec; $rec = null; $gate = $gateRec; }
    }
    // Etykieta musi mowic, z czego naprawde jest tabela: blok powrotu NIE jest "chwila awarii".
    $failIsStart = ($fail !== null) && (stripos($fail['label'], 'epizod-start') !== false);
    if (!$gate['ok']) {
        return ['gate-failed', "### MAIL-SMARTHOST pominiety | powod=niekompletny zrzut ({$gate['reason']})"
                               . " | plik=" . basename($pending['file']) . "\n"];
    }

    $resDay = (new DateTime('now', $waw))->format('Ymd');   // doba, z ktorej pochodzi slot
    $res = smarthostReserve($diag, $waw, $key, $maxPerDay);
    if (!$res['ok']) {
        return ['refused', "### MAIL-SMARTHOST {$tsU} UTC / {$tsW} WAW | okno {$pending['windowUtc']} | NIE wyslano: {$res['reason']}\n"];
    }

    $rows = ''; $rwLoss = null; $ctrlVals = [];
    if ($fail !== null) {
        foreach ($names as $ip => $label) {
            $v = $fail['loss'][$ip] ?? null;
            $rows .= sprintf("  %-46s %s\n", $label,
                $v === null ? 'brak pomiaru w tym zrzucie' : rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . '% strat');
            if ($ip === '66.33.22.230') { $rwLoss = $v; } elseif ($v !== null) { $ctrlVals[] = $v; }
        }
    }
    $ctrlAllClean = (count($ctrlVals) === 3) && (max($ctrlVals) == 0.0);
    $ctrlAnyLoss  = $ctrlVals && max($ctrlVals) > 0.0;

    if ($fail === null) {
        $sense = 'Zrzut diagnostyczny nie zawiera bloku z chwili awarii, wiec zalaczamy samo okno niedostepnosci.';
    } elseif ($rwLoss === null) {
        $sense = 'W tym zrzucie brakuje pomiaru strat do adresu Railway — zostaje samo okno niedostepnosci i mtr ponizej.';
    } elseif ($rwLoss > 0.0 && $ctrlAllClean) {
        $sense = 'W chwili awarii pakiety ginely na trasie do adresu Railway, a wszystkie trzy cele kontrolne mierzone w tej samej chwili byly czyste (0% strat).';
    } elseif ($rwLoss > 0.0 && $ctrlAnyLoss) {
        $sense = 'W chwili awarii tracil pakiety zarowno adres Railway, jak i cel kontrolny — problem nie ogranicza sie wiec do jednej trasy.';
    } elseif ($rwLoss > 0.0) {
        $sense = 'W chwili awarii pakiety ginely na trasie do adresu Railway; czesc pomiarow kontrolnych jest niepelna, wiec nie przesadzamy o reszcie sieci.';
    } else {
        $sense = 'Ping w chwili awarii nie pokazal strat — epizod dotyczyl polaczenia z usluga bazy danych, nie odpowiedzi ICMP.';
    }

    $mtrTxt = '';
    foreach ([[$fail, $failIsStart ? 'Z CHWILI AWARII' : 'Z BLOKU ' . ($fail['label'] ?? '?')],
              [$rec, 'Z CHWILI POWROTU (dla porownania)']] as [$b, $tag]) {
        if ($b !== null && $b['mtr'] !== '') {
            $mtrTxt .= "\nMTR {$tag} — {$b['label']}, {$b['ts']}\n" . $b['mtr'] . "\n";
        }
    }
    if ($mtrTxt === '') {
        $mtrTxt = "\nMTR: zrzut nie zawieral jeszcze wynikow mtr w chwili wysylki (diagnostyka w tle nie zdazyla sie domknac).\n";
    }
    $kompl = $d['doneMarkers'] >= 2
        ? ''
        : "\nUwaga: zrzut w chwili wysylki mial " . $d['doneMarkers'] . " z 2 domknietych blokow diagnostycznych — czesc pomiarow moze byc niepelna.\n";

    $body = "Zgloszenie #{$ticket} — automatyczne powiadomienie z monitora divezone.pl.\n\n"
          . "Monitor probkuje trase serwer -> Railway co ok. " . max(1, $intervalS + 1) . " s (interwal {$intervalS} s plus czas sond);\n"
          . "alert powstaje po 3 kolejnych nieudanych probach na ktorejkolwiek metryce bazy.\n"
          . "Ponizej dane z epizodu, ktory wlasnie sie zakonczyl.\n\n"
          . "OKNO NIEDOSTEPNOSCI\n"
          . "  {$pending['windowUtc']} UTC  ({$pending['windowWaw']} czasu warszawskiego)\n"
          . ($d['window'] !== '' ? '  ' . $d['window'] . "\n" : '')
          . ($fail !== null
                ? "\nSTRATY PAKIETOW " . ($failIsStart ? 'Z CHWILI AWARII' : 'Z BLOKU: ' . $fail['label'])
                  . " (ping -c 15, jeden pomiar, {$fail['ts']})\n" . $rows
                : "\n")
          . "\n{$sense}\n"
          . $kompl
          . $mtrTxt
          . "\nPelny zrzut pozostaje na naszym serwerze: " . $pending['file'] . "\n"
          . "Dobowy mtr odniesienia (18:30 UTC, poza epizodem) mozemy dolaczyc na zyczenie.\n";

    $subject = ($rwLoss !== null && $rwLoss > 0.0)
        ? "[DIVEZONE #{$ticket}] Utrata pakietow serwer->Railway, okno {$pending['windowUtc']} UTC"
        : "[DIVEZONE #{$ticket}] Epizod niedostepnosci bazy (ping bez strat), okno {$pending['windowUtc']} UTC";
    $headers = "From: noreply@divezone.pl\r\nCc: {$cc}\r\nContent-Type: text/plain; charset=UTF-8\r\n";
    $ok = @mail($to, $subject, $body, $headers);
    if (!$ok) { smarthostRelease($diag, $waw, $key, $resDay); }   // nieudana proba nie zjada limitu
    // Temat w logu: po nim widac, KTORY blok dowodowy zdecydowal o tresci (i co poszlo do obcej firmy).
    return [($ok ? 'sent' : 'mail-failed'),
            "### MAIL-SMARTHOST {$tsU} UTC / {$tsW} WAW | okno {$pending['windowUtc']} | do {$to} | {$res['reason']}"
            . " | dowod: {$gate['reason']} | temat: {$subject} | mail=" . ($ok ? 'sent' : 'FAILED') . "\n"];
}

$now = new DateTime('now', $WAW);
// dzienny digest 07:00 (zachowany) — nadawany raz/dzien, monitor dalej dziala
$mailAt = (clone $now)->setTime(7, 0, 0);
if ($mailAt <= $now) { $mailAt->modify('+1 day'); }

// CHAT-T-185 — dobowy mtr odniesienia o 18:30 UTC (bez maila, bez alertu)
[$mbH, $mbM] = array_map('intval', explode(':', $MTR_BASELINE_AT_UTC));
$mtrAt = (new DateTime('now', $UTC))->setTime($mbH, $mbM, 0);
// Cel mtr bierzemy z REALNEGO hosta z DATABASE_URL, nie ze sztywnego IP: gdyby Railway zmienil
// adres proxy, zrzut opisywalby nieistniejaca juz infrastrukture (recenzja codex 2026-09-09).
$MTR_IP = gethostbyname($RHOST);
if (!filter_var($MTR_IP, FILTER_VALIDATE_IP)) { $MTR_IP = MTR_TARGET_IP; }
$mtrCatchUp = false;
// CHAT-T-188 — jedno ponowienie baseline'u na dobe (bez petli): po zleceniu sprawdzamy tresc
// po $MTR_RECHECK_S i, jesli pusto, powtarzamy DOKLADNIE raz.
$MTR_RECHECK_S = 300;
$mtrCheckAt = 0.0;       // 0 = nie ma czego sprawdzac
$mtrCheckFile = '';      // KTORY plik sprawdzamy — zapamietany, nie odtwarzany z dzisiejszej daty
                         // (proba zlecona tuz przed polnoca UTC sprawdzalaby plik nastepnej doby)
// Znacznik "ponowienie tej doby juz bylo" NA DYSKU: monitor restartuje sie kilka razy dziennie,
// wiec zmienna w pamieci nie jest zadnym limitem (recenzja codex 2026-09-10).
$mtrRetryFlag = fn(DateTimeZone $u): string => $DIAG . '/mtr_retry_' . (new DateTime('now', $u))->format('Ymd') . '.done';
if ($mtrAt <= new DateTime('now', $UTC)) {
    // Monitor restartuje sie kilka razy na dobe. Jezeli okno 18:30 minelo, a pliku baseline
    // na dzis NIE MA, to znaczy, ze restart zjadl termin — nadrabiamy raz, po starcie.
    // CHAT-T-188: nie "czy plik jest", tylko "czy ma dane" — pusty plik z bledem tez trzeba nadrobic.
    $mtrCatchUp = !mtrBaselineHasData($DIAG . '/mtr_baseline_' . (new DateTime('now', $UTC))->format('Ymd') . '.txt');
    $mtrAt->modify('+1 day');
}

cleanupProbe($DSN, $PROBE_KEY); // czysty start klucza probe

wlog($DIAG, $WAW, sprintf("# START %s WAW | CIAGLY (bez stop) | interval %ds | host %s:%d | digest@ %s | TRASA: serwer->Railway\n",
    $now->format('Y-m-d H:i:s'), $INTERVAL, $RHOST, $RPORT, $mailAt->format('H:i')));
wlog($DIAG, $WAW, "# metryki: railway_tcp, " . implode(', ', $PG_METRICS) . ", github | alert >= {$ALERT_STREAK} FAIL/rzad na metryce PG\n");
if ($MTR_IP !== MTR_TARGET_IP) {
    wlog($DIAG, $WAW, "# UWAGA CHAT-T-185: {$RHOST} wskazuje dzis na {$MTR_IP}, a stala MTR_TARGET_IP to " . MTR_TARGET_IP
        . " — mtr mierzy ADRES BIEZACY, zaktualizuj stala i dokumenty\n");
}
wlog($DIAG, $WAW, sprintf("# CHAT-T-185: mtr %d cykli do %s:%d | baseline mtr @ %s UTC | mail do smarthosta: %s\n",
    MTR_CYCLES, $MTR_IP, $RPORT, $MTR_BASELINE_AT_UTC,
    $SMARTHOST_MAIL !== '' ? 'wlaczony (' . $SMARTHOST_MAIL . '), max ' . $SMARTHOST_MAX_PER_DAY . '/dobe' : 'WYLACZONY (brak SMARTHOST_TICKET_MAIL w .env)'));

// --- alert state ---
$streak = array_fill_keys($PG_METRICS, 0);
$okStreak = 0; $alertActive = false; $lastAlertTs = 0.0; $episodeInfo = '';
// CHAT-T-119 — pomiar okna + plik incydentu per epizod
$firstFailW = null;      // DateTime 1. FAIL biezacej serii (reset przy pelnym OK) — start okna niedostepnosci
$episodeStartW = null;   // zamrozony start okna epizodu (kopia $firstFailW w chwili alertu)
$incidentFile = '';      // ~/_diag/incident_YYYYMMDD_HHMMSS.txt biezacego epizodu
// CHAT-T-185 — maile do smarthosta czekaja, az zrzut w tle dopisze oba mtr (wysylka opozniona).
// KOLEJKA, nie pojedynczy slot: dwa epizody pod rzad nie moga sie nadpisac i zgubic maila.
$pendingTickets = smarthostLoadPending($DIAG);   // przetrwaly restart monitora
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

    // CHAT-T-185 — dobowy mtr odniesienia (w tle, bez maila, bez alertu)
    if ($mtrCatchUp) {
        $bf = captureMtrBaseline($DIAG, $UTC, $MTR_IP, $RPORT, MTR_CYCLES);
        wlog($DIAG, $WAW, "# MTR-BASELINE nadrobiony po restarcie (termin " . $MTR_BASELINE_AT_UTC . " UTC juz minal): " . basename($bf) . "\n", $nowW);
        $mtrCatchUp = false;
        $mtrCheckAt = microtime(true) + $MTR_RECHECK_S; $mtrCheckFile = $bf;
    }
    if (new DateTime('now', $UTC) >= $mtrAt) {
        $bf = captureMtrBaseline($DIAG, $UTC, $MTR_IP, $RPORT, MTR_CYCLES);
        wlog($DIAG, $WAW, "# MTR-BASELINE zlecony: " . basename($bf) . "\n", $nowW);
        $mtrAt->modify('+1 day');
        $mtrCheckAt = microtime(true) + $MTR_RECHECK_S; $mtrCheckFile = $bf;
    }
    // CHAT-T-188 — kontrola tresci baseline'u po zleceniu i JEDNO ponowienie, gdy pusty.
    if ($mtrCheckAt > 0.0 && microtime(true) >= $mtrCheckAt) {
        $bfile = $mtrCheckFile !== '' ? $mtrCheckFile
               : $DIAG . '/mtr_baseline_' . (new DateTime('now', $UTC))->format('Ymd') . '.txt';
        $mtrCheckAt = 0.0;
        $retryFlag = $mtrRetryFlag($UTC);
        if (mtrBaselineHasData($bfile)) {
            wlog($DIAG, $WAW, "# MTR-BASELINE ma dane: " . basename($bfile) . "\n", $nowW);
        } elseif (!file_exists($retryFlag)) {
            @file_put_contents($retryFlag, (new DateTime('now', $UTC))->format('c') . "\n");
            $bf = captureMtrBaseline($DIAG, $UTC, $MTR_IP, $RPORT, MTR_CYCLES);
            $mtrCheckAt = microtime(true) + $MTR_RECHECK_S; $mtrCheckFile = $bf;
            wlog($DIAG, $WAW, "# MTR-BASELINE bez pomiaru — PONOWIENIE, jedyne w tej dobie: " . basename($bf) . "\n", $nowW);
        } else {
            wlog($DIAG, $WAW, "# MTR-BASELINE nadal bez pomiaru po ponowieniu — rezygnuje na dzis: " . basename($bfile) . "\n", $nowW);
        }
    }

    // CHAT-T-185 — zaległy mail do smarthosta: wysylamy dopiero, gdy zrzut w tle przestal rosnac
    // (inaczej mail poszedlby bez sekcji mtr, o ktore smarthost prosil) albo minal twardy termin.
    foreach ($pendingTickets as $k => $pt) {
        if (microtime(true) < $pt['notBefore']) { continue; }
        // Domkniecie zrzutu poznajemy po MARKERACH `=== DIAG koniec` (epizod-start + epizod-koniec),
        // a nie po samym mtime: sekwencja ping/traceroute/mtr potrafi milczec dluzej niz 20 s
        // i wygladac na skonczona, choc trwa (recenzja codex 2026-09-09).
        clearstatcache(true, $pt['file']);
        $txt = @file_get_contents($pt['file']);
        $done = ($txt === false) ? 0 : substr_count($txt, '=== DIAG koniec');
        $mtime = @filemtime($pt['file']);
        $quiet = ($mtime !== false) && (time() - $mtime >= 60);
        if ($done < 2 && !$quiet && microtime(true) < $pt['deadline']) { continue; }
        // Log PRZED wysylka: mail() nie ma timeoutu, a guard patrzy na swiezosc logu (60 s).
        // Slowo "probuje", nie "wysylam": w tym momencie bramka jeszcze nie orzekla (codex #8).
        wlog($DIAG, $WAW, "### MAIL-SMARTHOST probuje | okno {$pt['windowUtc']} | blokow domknietych {$done}/2\n", $nowW);
        [$status, $line] = sendSmarthostTicket($pt, $SMARTHOST_MAIL, $MAIL_TO, $SMARTHOST_TICKET,
                                               $WAW, $UTC, $DIAG, $SMARTHOST_MAX_PER_DAY, $INTERVAL);

        // CHAT-T-188 — zrzut bez danych: NIE wysylamy, ale tez nie gubimy epizodu.
        // Zostaje w kolejce do twardego terminu (moze zrzut sie jeszcze domknie), a linia
        // do logu idzie RAZ, zeby nie zasypac logu co cykl.
        if ($status === 'gate-failed') {
            if (empty($pendingTickets[$k]['gateLogged'])) {
                wlog($DIAG, $WAW, $line, $nowW);
                $pendingTickets[$k]['gateLogged'] = true;
                smarthostSavePending($DIAG, $pendingTickets);
            }
            if (time() >= ($pt['deadlineAbs'] ?? 0) || microtime(true) >= $pt['deadline']) {
                // Koniec czekania. Cicha degradacja jest zakazana: Karol ma wiedziec DZIS,
                // ze epizod zostal bez materialu dowodowego. Kanal: istniejacy mail alertowy
                // monitora (ten sam nadawca i adresaci co alerty epizodowe), zeby nie mnozyc kanalow.
                $subjW = "[DIVECHAT MONITOR] Zrzut nie przeszedl bramki — mail do smarthosta wstrzymany";
                $bodyW = "Epizod zakonczony, ale zrzut diagnostyczny nie przeszedl bramki dowodowej,\n"
                       . "wiec mail do dostawcy NIE zostal wyslany (lepiej nic niz pusty dowod).\n\n"
                       . "Okno epizodu: {$pt['windowUtc']} UTC ({$pt['windowWaw']} WAW)\n"
                       . "Plik zrzutu:  {$pt['file']}\n"
                       . "Werdykt bramki: " . trim(str_replace('### MAIL-SMARTHOST pominiety | ', '', $line)) . "\n\n"
                       . "Bramka wymaga: strat pingu do Railway i do co najmniej jednej kontroli ORAZ\n"
                       . "co najmniej jednej sekcji mtr z wierszem HOST: (czyli takiej, w ktorej mtr ruszyl).\n"
                       . "Powodem moze byc padniete narzedzie (tak bylo 2026-09-09, mtr bez PATH do\n"
                       . "mtr-packet, CHAT-T-186), ale takze urwany albo niekompletny zrzut — plik wyzej\n"
                       . "pokazuje, ktore z tych rzeczy zabraklo.\n";
                $okW = sendAlertMail($MAIL_TO, $subjW, $bodyW);
                wlog($DIAG, $WAW, "### MAIL-SMARTHOST porzucony | okno {$pt['windowUtc']} | zrzut bez danych do terminu"
                    . " | ostrzezenie do Karola mail=" . ($okW ? 'sent' : 'FAILED') . "\n", $nowW);
                unset($pendingTickets[$k]);
                smarthostSavePending($DIAG, $pendingTickets);
            }
            break;
        }

        wlog($DIAG, $WAW, $line, $nowW);
        // CHAT-T-188 (codex #5): nieudany mail albo przejsciowy problem z licznikiem NIE moze
        // kasowac epizodu z kolejki — slot zostal juz zwrocony, wiec probujemy dalej do terminu.
        if ($status === 'mail-failed' && time() < ($pt['deadlineAbs'] ?? 0)) {
            break;
        }
        unset($pendingTickets[$k]);
        smarthostSavePending($DIAG, $pendingTickets);
        break;   // jeden mail na cykl petli — zeby wysylka nie zatrzymala pomiaru
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
    // CHAT-T-183: plik wybierany po $nowW — tym samym znaczniku, ktory jest w polu WAW linii.
    wlog($DIAG, $WAW, $line, $nowW);

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
                  . "Host: {$RHOST}:{$RPORT}\nLog: " . logPath($DIAG, $WAW, $nowW) . "\n";
            $ok = sendAlertMail($MAIL_TO, $subj, $body);
            wlog($DIAG, $WAW, "### ALERT {$ts} | {$episodeInfo} | mail=" . ($ok ? 'sent' : 'FAILED') . "\n", $nowW);
            $alertActive = true; $lastAlertTs = $nowTs;

            // CHAT-T-119 — zamroz start okna, zaloz plik incydentu, odpal diag w tle (start epizodu)
            $episodeStartW = ($firstFailW instanceof DateTime) ? clone $firstFailW : clone $nowW;
            $incidentFile = $DIAG . '/incident_' . $episodeStartW->format('Ymd_His') . '.txt';
            file_put_contents($incidentFile, sprintf(
                "### EPIZOD START (1. FAIL): %s WAW | metryka %s FAIL x%d | prog alertu osiagniety %s\n",
                $episodeStartW->format('Y-m-d H:i:s'), $worst, $streak[$worst], $nowW->format('H:i:s')), FILE_APPEND);
            captureNetworkDiag($incidentFile, "epizod-start {$worst}", $WAW, $UTC, '', $MTR_IP, $RPORT);
            wlog($DIAG, $WAW, "### DIAG zapisano: " . basename($incidentFile) . " (epizod-start {$worst})\n", $nowW);
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
        wlog($DIAG, $WAW, "### RECOVERY {$ts} | po [{$episodeInfo}] | mail=" . ($ok ? 'sent' : 'FAILED') . "\n", $nowW);
        if ($windowStr !== '') { wlog($DIAG, $WAW, $windowStr, $nowW); }

        // CHAT-T-119 — druga diag (trasa na koncu epizodu) do TEGO SAMEGO pliku incydentu.
        // Linia OKNA idzie jako $preface -> wypisana pod flock RAZEM z naglowkiem epizod-koniec,
        // wiec czeka az diag epizod-start zwolni blokade (brak przeplotu przy krotkim epizodzie).
        if ($incidentFile !== '') {
            captureNetworkDiag($incidentFile, 'epizod-koniec', $WAW, $UTC, ($windowStr !== '' ? "\n" . $windowStr : ''), $MTR_IP, $RPORT);
            wlog($DIAG, $WAW, "### DIAG zapisano: " . basename($incidentFile) . " (epizod-koniec)\n", $nowW);

            // CHAT-T-185 — JEDEN mail do smarthosta na epizod, przy recovery. Nie wysylamy od razu:
            // zrzut epizod-koniec dopiero wystartowal w tle, a mail ma zawierac oba mtr.
            $winU = ($episodeStartW instanceof DateTime)
                ? (clone $episodeStartW)->setTimezone($UTC)->format('H:i') . '-' . (new DateTime('now', $UTC))->format('H:i')
                : (new DateTime('now', $UTC))->format('H:i');
            $winW = ($episodeStartW instanceof DateTime)
                ? $episodeStartW->format('H:i') . '-' . $nowW->format('H:i')
                : $nowW->format('H:i');
            if ($SMARTHOST_MAIL !== '') {
                $pendingTickets[] = ['file' => $incidentFile, 'windowUtc' => $winU, 'windowWaw' => $winW,
                                     'notBefore'   => microtime(true) + $SMARTHOST_WAIT_S,
                                     'deadline'    => microtime(true) + $SMARTHOST_DEADLINE_S,
                                     'deadlineAbs' => time() + $SMARTHOST_DEADLINE_S];
                smarthostSavePending($DIAG, $pendingTickets);   // przetrwa restart monitora
                wlog($DIAG, $WAW, "### MAIL-SMARTHOST zakolejkowany | okno {$winU} UTC | wysylka za {$SMARTHOST_WAIT_S}s (czekam na mtr w zrzucie)\n", $nowW);
            } else {
                wlog($DIAG, $WAW, "### MAIL-SMARTHOST pominiety | okno {$winU} UTC | brak SMARTHOST_TICKET_MAIL w .env (funkcja wylaczona)\n", $nowW);
            }
        }

        $alertActive = false;
        $episodeStartW = null; $incidentFile = '';
    }

    if ($i % $HEARTBEAT_EVERY === 0) {
        wlog($DIAG, $WAW, sprintf("# alive %05d %s UTC | alert_active=%s ok_streak=%d\n",
            $i, (new DateTime('now', $UTC))->format('H:i:s'), $alertActive ? '1' : '0', $okStreak), $nowW);
        cleanupProbe($DSN, $PROBE_KEY); // okresowe czyszczenie klucza probe
    }

    sleep($INTERVAL);
}
