<?php
declare(strict_types=1);
/**
 * Wspolny parser logu + wysylka podsumowania dobowego monitora Railway (READ-ONLY).
 * Uzywany trojako:
 *  - require'owany przez railway_monitor.php (wysylka in-process o 07:00, fallback=false)
 *  - uruchamiany bezposrednio przez cron 07:05 (fallback=true)
 *  - uruchamiany recznie z --dry-run YYYYMMDD [diagDir] (tylko stdout, bez maila i bez flagi)
 *
 * CHAT-T-182 (2026-09-03) — raport byl martwy od 2026-06-29, dwa niezalezne bledy:
 *  1) parser szukal pola `railway_pg`, ktore CHAT-T-108 rozbil na pg_select1/pg_settings/
 *     pg_chiptree/pg_upsert => 0 dopasowan => status noop:no-data kazdego dnia,
 *  2) raport czytal log BIEZACEJ doby, a chodzi o 07:05 WAW, wiec pokrywal 00:00-07:05 WAW
 *     i pomijal wieczor, w ktorym leza wszystkie awarie.
 * Teraz: okno = PELNA POPRZEDNIA doba WAW (log D-1), metryki per sonda, dlugosci okien
 * liczone z realnych znacznikow czasu (bez stalego mnoznika interwalu).
 *
 * CHAT-T-183 (2026-09-04) — trzy poprawki na wierzchu T-182:
 *  1) werdykt okna awarii bierze sie ze STRAT PINGU w zrzucie incydentu, a nie z `github`.
 *     Stara etykieta "TEZ FAIL (szerszy problem)" stawiala teze, ktorej te same zrzuty
 *     przecza — szczegoly i dowod w docblocku railway_summary_window_verdict(),
 *  2) bramka kompletnosci doby: bez pokrycia log nie ma prawa dostac oceny CZYSTA,
 *  3) (w railway_monitor.php) nazwa pliku logu bierze sie z tego samego znacznika,
 *     ktory idzie w pole WAW linii — cykl przez polnoc nie ucieka do pliku D+1.
 *
 * Dedup: flaga /home/divezone/_diag/mail_sent_YYYYMMDD.flag, gdzie YYYYMMDD to doba
 * RAPORTOWANA (nie dzien wysylki) — dzieki temu sciezka in-process (07:00) i cron (07:05)
 * dalej deduplikuja sie wzajemnie. Brak logu raportowanej doby = NO-OP.
 */

// === Progi oceny (CHAT-T-183). Nazwane stale, nie liczby wsrod kodu. ===
// Guard `defined`, bo plik bywa require'owany (monitor) i uruchamiany bezposrednio (cron).
if (!defined('RAILWAY_SUMMARY_COVERAGE_MIN_PCT')) {
    /** Ponizej tego pokrycia doby raport nie ma prawa napisac "CZYSTA". */
    define('RAILWAY_SUMMARY_COVERAGE_MIN_PCT', 95.0);
    /** Przerwa miedzy probkami powyzej tej wartosci = doba niepelna (15 min). */
    define('RAILWAY_SUMMARY_MAX_GAP_S', 900);
    /** Jak dlugo blok diagnostyczny realnie MIERZY siec od swojego znacznika `### czas`.
     *  Cztery ping -c 15 -W 2 pod `timeout 40` (railway_monitor.php:123-126) => do 160 s;
     *  traceroute juz strat nie mierzy, wiec go nie liczymy. Okno i blok musza sie
     *  w tym przedziale przecinac, zeby blok byl dowodem dla okna. */
    define('RAILWAY_SUMMARY_DIAG_PING_RUN_S', 160);
    /** Kadencja pomiaru: mediana odstepu powyzej tej wartosci znaczy, ze monitor mierzyl
     *  za rzadko, zeby cokolwiek orzekac (nominal to 5 s + czas sond, realnie ~6 s).
     *  Bez tego progu doba z jedna probka na 15 minut wyszlaby "kompletna" — pokrycie
     *  liczone wzgledem WLASNEJ mediany samo by sie znormalizowalo (recenzja codex 2026-09-04). */
    define('RAILWAY_SUMMARY_MAX_MEDIAN_GAP_S', 60);
    /** Straty kontroli (Leaseweb/1.1.1.1/8.8.8.8) od tego progu uznajemy za realne.
     *  15 pakietow w probie => 1 zgubiony to 6,7%; pojedynczy drop ICMP to szum,
     *  3 pakiety (20%) to juz sygnal. */
    define('RAILWAY_SUMMARY_CONTROL_LOSS_PCT', 20.0);
}

if (!function_exists('railway_summary_send')) {

    /** Sekundy -> "Xm Ys". */
    function railway_summary_fmt_dur(int $sec): string
    {
        if ($sec < 0) { $sec = 0; }
        return sprintf('%dm %ds', intdiv($sec, 60), $sec % 60);
    }

    /** Procent strat pingu -> "0%" / "53.3%" / "100%" (bez zbednych zer). */
    function railway_summary_fmt_pct(float $v): string
    {
        return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . '%';
    }

    /** Polska odmiana rzeczownika po liczbie: 1 okno, 2-4 okna, 5+ okien (12-14 = okien). */
    function railway_summary_plural(int $n, string $one, string $few, string $many): string
    {
        if ($n === 1) { return $one; }
        $last = $n % 10; $teen = ($n % 100) >= 12 && ($n % 100) <= 14;
        return (!$teen && $last >= 2 && $last <= 4) ? $few : $many;
    }

    /** "HH:MM:SS" -> sekundy od polnocy (do zestawiania okien ze zrzutami incydentow). */
    function railway_summary_waw_to_sec(?string $hms): ?int
    {
        if ($hms === null || !preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $hms, $m)) { return null; }
        return ((int)$m[1]) * 3600 + ((int)$m[2]) * 60 + (int)$m[3];
    }

    /**
     * Parser logu monitora. Dopasowuje WYLACZNIE linie pomiarowe; naglowki `# metryki:`,
     * `# START`, `# alive`, `### ALERT`, `### RECOVERY`, `### DIAG` sa liczone osobno.
     * Numer cyklu `#NNNNN` resetuje sie przy kazdym restarcie monitora przez cron-guard,
     * wiec NIE sluzy do liczenia niczego — sekwencja stoi wylacznie na znacznikach czasu.
     *
     * DLUGOSC OKNA liczymy ze znacznika UTC (nie WAW): znacznik WAW nie niesie daty ani offsetu,
     * wiec przy zmianie czasu (powtorzona godzina w pazdzierniku) roznica WAW klamie. Wyswietlamy
     * WAW (czytelnosc), liczymy w UTC (poprawnosc). Roznica miedzy oboma znacznikami tej samej
     * linii to czas trwania sond w cyklu (monitor bierze WAW przed sondami, UTC po nich).
     *
     * OKNO (CHAT-T-183) niesie LICZBE `github FAIL` w oknie, nie flage "github OK/nie-OK".
     * Werdykt, kto jest winny, powstaje osobno — z pingow w zrzucie incydentu
     * (railway_summary_window_verdict()), bo `github` w epizodzie klamie.
     *
     * @return array{n:int,rejected:int,fails:array<string,int>,errno110:int,alerts:int,
     *               alertsMailFailed:int,firstWaw:?string,lastWaw:?string,firstUtc:?int,lastUtc:?int,
     *               medianGap:?int,maxGap:?int,corr:array<string,int>,
     *               windows:array<int,array{start:string,end:string,start_utc:int,end_utc:int,
     *               n:int,dur:int,gh_fail:int,max_gap:int}>}
     */
    function railway_summary_parse_log(string $logPath): array
    {
        $metrics = ['railway_tcp', 'pg_select1', 'pg_settings', 'pg_chiptree', 'pg_upsert', 'github'];
        $re = '/^#\d+\s+(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC \| (\d{2}:\d{2}:\d{2}) WAW \| '
            . 'railway_tcp\s+(OK|FAIL)\s+[\d.]+ms \| '
            . 'pg_select1\s+(OK|FAIL)\s+[\d.]+ms \| '
            . 'pg_settings\s+(OK|FAIL)\s+[\d.]+ms \| '
            . 'pg_chiptree\s+(OK|FAIL)\s+[\d.]+ms \| '
            . 'pg_upsert\s+(OK|FAIL)\s+[\d.]+ms \| '
            . 'github\s+(OK|FAIL)\s+[\d.]+ms \| errno=(-?\d+)/';

        $n = 0;
        $rejected = 0;      // linie wygladajace na pomiarowe (#NNNNN ...), ktorych regex NIE objal
        $fails = array_fill_keys($metrics, 0);
        $errno110 = 0;
        $alerts = 0; $alertsMailFailed = 0;
        $firstWaw = null; $lastWaw = null; $firstUtc = null; $lastUtc = null;
        $prevUtc = null; $gaps = []; $maxGap = null;
        $corr = ['pg_select1_gh_ok' => 0, 'railway_tcp_gh_ok' => 0, 'any_pg_gh_ok' => 0];
        // okno = ciag kolejnych probek z pg_select1 FAIL (metryka wiodaca: connect + SELECT 1)
        $windows = [];
        $curStartWaw = null; $curEndWaw = null; $curStartUtc = 0; $curEndUtc = 0;
        $curCnt = 0; $curGhFail = 0; $curMaxGap = 0; $curPrevUtc = null;

        $empty = ['n' => 0, 'rejected' => 0, 'fails' => $fails, 'errno110' => 0, 'alerts' => 0,
                  'alertsMailFailed' => 0, 'firstWaw' => null, 'lastWaw' => null, 'firstUtc' => null,
                  'lastUtc' => null, 'medianGap' => null, 'maxGap' => null, 'corr' => $corr,
                  'windows' => []];
        $fh = @fopen($logPath, 'r');
        if ($fh === false) {
            return $empty;
        }
        while (($ln = fgets($fh)) !== false) {
            if (str_starts_with($ln, '### ALERT')) {
                $alerts++;
                if (str_contains($ln, 'mail=FAILED')) { $alertsMailFailed++; }
                continue;
            }
            if (!preg_match($re, $ln, $m)) {
                // Cicha degradacja jest zakazana: linia z numerem cyklu, ktorej nie umiemy sparsowac,
                // musi byc policzona i pokazana w raporcie (np. zmiana formatu logu przez monitor).
                if (preg_match('/^#\d+\s/', $ln)) { $rejected++; }
                continue;
            }

            $n++;
            $utcTs = strtotime($m[1] . ' UTC');
            $waws  = $m[2];
            $st = [
                'railway_tcp' => $m[3],
                'pg_select1'  => $m[4],
                'pg_settings' => $m[5],
                'pg_chiptree' => $m[6],
                'pg_upsert'   => $m[7],
                'github'      => $m[8],
            ];
            $errno = (int)$m[9];

            if ($firstWaw === null) { $firstWaw = $waws; $firstUtc = ($utcTs !== false ? $utcTs : null); }
            $lastWaw = $waws;
            if ($utcTs !== false) { $lastUtc = $utcTs; }
            if ($prevUtc !== null && $utcTs !== false && $utcTs >= $prevUtc) {
                $g = $utcTs - $prevUtc;
                $gaps[] = $g;
                if ($maxGap === null || $g > $maxGap) { $maxGap = $g; }
            }
            if ($utcTs !== false) { $prevUtc = $utcTs; }

            foreach ($metrics as $mt) {
                if ($st[$mt] === 'FAIL') { $fails[$mt]++; }
            }
            if ($errno === 110) { $errno110++; }

            $ghOk = ($st['github'] === 'OK');
            if ($ghOk && $st['pg_select1'] === 'FAIL')  { $corr['pg_select1_gh_ok']++; }
            if ($ghOk && $st['railway_tcp'] === 'FAIL') { $corr['railway_tcp_gh_ok']++; }
            $anyPgFail = ($st['pg_select1'] === 'FAIL' || $st['pg_settings'] === 'FAIL'
                || $st['pg_chiptree'] === 'FAIL' || $st['pg_upsert'] === 'FAIL');
            if ($ghOk && $anyPgFail) { $corr['any_pg_gh_ok']++; }

            if ($st['pg_select1'] === 'FAIL') {
                if ($curStartWaw === null) {
                    $curStartWaw = $waws; $curStartUtc = ($utcTs !== false ? $utcTs : 0);
                    $curCnt = 0; $curGhFail = 0; $curMaxGap = 0; $curPrevUtc = null;
                }
                $curEndWaw = $waws; $curEndUtc = ($utcTs !== false ? $utcTs : $curStartUtc); $curCnt++;
                // najwiekszy odstep MIEDZY probkami w oknie: pokazuje, ile czasu w oknie NIE bylo mierzone
                if ($curPrevUtc !== null && $utcTs !== false && $utcTs - $curPrevUtc > $curMaxGap) {
                    $curMaxGap = $utcTs - $curPrevUtc;
                }
                if ($utcTs !== false) { $curPrevUtc = $utcTs; }
                if (!$ghOk) { $curGhFail++; }
            } elseif ($curStartWaw !== null) {
                $windows[] = ['start' => $curStartWaw, 'end' => $curEndWaw,
                              'start_utc' => $curStartUtc, 'end_utc' => $curEndUtc, 'n' => $curCnt,
                              'dur' => max(0, $curEndUtc - $curStartUtc), 'gh_fail' => $curGhFail,
                              'max_gap' => $curMaxGap];
                $curStartWaw = null;
            }
        }
        fclose($fh);
        if ($curStartWaw !== null) {
            $windows[] = ['start' => $curStartWaw, 'end' => $curEndWaw,
                          'start_utc' => $curStartUtc, 'end_utc' => $curEndUtc, 'n' => $curCnt,
                          'dur' => max(0, $curEndUtc - $curStartUtc), 'gh_fail' => $curGhFail,
                          'max_gap' => $curMaxGap];
        }

        $medianGap = null;
        if ($gaps) {
            sort($gaps);
            $c = count($gaps);
            $medianGap = (int)round(($c % 2 === 1) ? $gaps[intdiv($c, 2)] : ($gaps[$c / 2 - 1] + $gaps[$c / 2]) / 2);
        }

        return ['n' => $n, 'rejected' => $rejected, 'fails' => $fails, 'errno110' => $errno110,
                'alerts' => $alerts, 'alertsMailFailed' => $alertsMailFailed,
                'firstWaw' => $firstWaw, 'lastWaw' => $lastWaw, 'firstUtc' => $firstUtc,
                'lastUtc' => $lastUtc, 'medianGap' => $medianGap,
                'maxGap' => $maxGap, 'corr' => $corr, 'windows' => $windows];
    }

    /**
     * Zrzuty incydentow CHAT-T-119 z danej doby, czytane BLOKAMI DIAG (CHAT-T-183).
     *
     * Jeden plik incydentu moze zawierac DWA bloki: `epizod-start` i — czasem godziny pozniej —
     * `epizod-koniec` (railway_monitor.php:299 i :331). Branie maksimum strat z calego pliku
     * mieszaloby pomiary z roznych chwil, wiec kazdy blok trzymamy osobno, z jego WLASNYM
     * znacznikiem `### czas: ... UTC` (railway_monitor.php:109). Znacznik z nazwy pliku
     * (1. FAIL epizodu) sluzy tylko jako zapasowy, gdy naglowka bloku nie da sie odczytac —
     * realny ping startuje pozniej, dopiero po osiagnieciu progu alertu.
     *
     * Straty: Railway 66.33.22.230 + trzy kontrole (Leaseweb AMS 5.79.108.33, Cloudflare 1.1.1.1,
     * Google 8.8.8.8). Tylko odczyt, pliki incydentow nietkniete.
     *
     * @return array<int,array{file:string,railway:?float,leaseweb:?float,cloudflare:?float,
     *                         google:?float,blocks:array<int,array{tsUtc:?int,railway:?float,
     *                         controls:array<string,?float>}>}>
     */
    function railway_summary_incidents(string $diagDir, string $date): array
    {
        $railwayIp = '66.33.22.230';
        $controls  = ['leaseweb' => '5.79.108.33', 'cloudflare' => '1.1.1.1', 'google' => '8.8.8.8'];
        $files = glob($diagDir . '/incident_' . $date . '_*.txt') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $path) {
            $base = basename($path);
            // Zapasowy znacznik z nazwy pliku: data z nazwy + godzina WAW 1. FAIL epizodu.
            $fallbackUtc = null;
            if (preg_match('/^incident_(\d{8})_(\d{2})(\d{2})(\d{2})\.txt$/', $base, $mm)) {
                $d = DateTime::createFromFormat('Ymd H:i:s', $mm[1] . ' ' . $mm[2] . ':' . $mm[3] . ':' . $mm[4],
                    new DateTimeZone('Europe/Warsaw'));
                if ($d instanceof DateTime) { $fallbackUtc = $d->getTimestamp(); }
            }

            $blocks = [];
            $curBlock = null;
            $curIp = null;
            $agg = [];   // maksima w calym pliku — tylko do listy w mailu, NIE do werdyktu
            $fh = @fopen($path, 'r');
            if ($fh !== false) {
                while (($ln = fgets($fh)) !== false) {
                    if (preg_match('/^### czas: (\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}) UTC/', $ln, $m)) {
                        if ($curBlock !== null) { $blocks[] = $curBlock; }
                        $ts = strtotime($m[1] . ' UTC');
                        $curBlock = ['tsUtc' => ($ts !== false ? $ts : null),
                                     'railway' => null,
                                     'controls' => array_fill_keys(array_keys($controls), null)];
                        $curIp = null;
                        continue;
                    }
                    if (preg_match('/^--- (\d+\.\d+\.\d+\.\d+) ping statistics ---/', $ln, $m)) {
                        $curIp = $m[1];
                        continue;
                    }
                    if ($curIp !== null && preg_match('/([\d.]+)% packet loss/', $ln, $m)) {
                        $v = (float)$m[1];
                        if (!isset($agg[$curIp]) || $v > $agg[$curIp]) { $agg[$curIp] = $v; }
                        if ($curBlock === null) {
                            // Straty przed pierwszym naglowkiem `### czas` (obciety/zepsuty zrzut):
                            // blok bez znacznika — do werdyktu sie nie nada, ale nie ginie po cichu.
                            $curBlock = ['tsUtc' => null, 'railway' => null,
                                         'controls' => array_fill_keys(array_keys($controls), null)];
                        }
                        if ($curIp === $railwayIp) {
                            $curBlock['railway'] = max($curBlock['railway'] ?? $v, $v);
                        } else {
                            foreach ($controls as $key => $ip) {
                                if ($curIp === $ip) {
                                    $curBlock['controls'][$key] = max($curBlock['controls'][$key] ?? $v, $v);
                                }
                            }
                        }
                        $curIp = null;
                    }
                }
                fclose($fh);
            }
            if ($curBlock !== null) { $blocks[] = $curBlock; }
            // Blok bez wlasnego znacznika dostaje zapasowy z nazwy pliku (lepszy niz zaden).
            foreach ($blocks as $i => $blk) {
                if ($blk['tsUtc'] === null) { $blocks[$i]['tsUtc'] = $fallbackUtc; }
            }

            $row = ['file' => $base, 'railway' => $agg[$railwayIp] ?? null, 'blocks' => $blocks];
            foreach ($controls as $key => $ip) {
                $row[$key] = $agg[$ip] ?? null;
            }
            $out[] = $row;
        }
        return $out;
    }

    /**
     * WERDYKT OKNA AWARII — kto tracil pakiety w czasie okna (CHAT-T-183).
     *
     * DLACZEGO NIE `github`. Do 2026-09-04 kazde okno dostawalo etykiete z pola `github`:
     * "OK (Railway winny)" albo "TEZ FAIL (szerszy problem)". W raporcie za dobe 2026-09-02
     * druga etykiete dostawalo 10 z 13 okien — a te same sekundy w zrzutach incydentow
     * pokazuja "Railway 100% strat | Leaseweb 0% strat". Mail przeczyl sam sobie i jako
     * material do eskalacji do hostingu byl bezwartosciowy.
     *
     * PODSTAWA (doba 2026-09-02, zmierzone 2026-09-04): wszystkie 40 `github FAIL` z okna
     * epizodu wypadaja 0-190 s PO starcie ktoregos ze zrzutow diagnostycznych, a kazdy zrzut
     * odpala w tle 4 x `ping -c 15` plus `traceroute` (railway_monitor.php:119-128, ~90 s).
     * Poza epizodem `github FAIL` byl 2 razy (13:17:33, 14:07:39 UTC) — pojedynczo, bez zrzutu.
     * Jedyny wyjatek to 15:04:55 UTC, 15 s po starcie epizodu, przed pierwszym zrzutem.
     * To KORELACJA, nie dowiedziona przyczynowosc — ale wystarczy, zeby `github` nie mogl
     * pelnic roli rozstrzygajacej. Alternatywe "epizod Railway psuje cale lacze serwera"
     * odrzucaja kontrole pingowe: Leaseweb AMS, 1.1.1.1 i 8.8.8.8 maja 0% strat w KAZDYM
     * z 21 zrzutow tej doby.
     *
     * ZASADY DOWODOWE (poprawki po recenzji krzyzowej codex 2026-09-04):
     *  - liczy sie BLOK DIAG, nie plik: plik moze miec blok `epizod-start` i `epizod-koniec`
     *    z roznych godzin, a mieszanie ich dawaloby werdykt z pomiarow, ktore nigdy nie
     *    wystapily razem,
     *  - Railway i kontrole musza pochodzic z TEGO SAMEGO bloku,
     *  - blok bez znacznika czasu, bez Railway albo bez zadnej kontroli NIE jest dowodem,
     *  - dopasowanie po czasie UTC: blok mierzy siec przez RAILWAY_SUMMARY_DIAG_PING_RUN_S
     *    od swojego znacznika; okno i blok musza sie w czasie PRZECINAC,
     *  - straty kontroli ponizej progu nie sa nazywane "czyste" tylko "ponizej progu".
     *
     * @param array{start_utc:int,end_utc:int} $window
     * @param array<int,array{file:string,blocks:array<int,array{tsUtc:?int,railway:?float,controls:array<string,?float>}>}> $incidents
     * @return array{label:string,detail:string}
     */
    function railway_summary_window_verdict(array $window, array $incidents): array
    {
        $ws = $window['start_utc']; $we = $window['end_utc'];
        if (!$ws || !$we) {
            return ['label' => 'BRAK ZRZUTU — bez werdyktu', 'detail' => 'znacznik okna nieczytelny'];
        }

        $usable = [];      // bloki z kompletem dowodow, pokrywajace okno
        $unusable = 0;     // bloki pokrywajace okno, ale bez kompletu danych
        foreach ($incidents as $inc) {
            foreach ($inc['blocks'] as $blk) {
                if ($blk['tsUtc'] === null) { $unusable++; continue; }
                $bs = $blk['tsUtc']; $be = $bs + RAILWAY_SUMMARY_DIAG_PING_RUN_S;
                if ($be < $ws || $bs > $we) { continue; }   // brak przeciecia w czasie
                $ctrl = array_filter($blk['controls'], fn($v) => $v !== null);
                if ($blk['railway'] === null || !$ctrl) { $unusable++; continue; }
                $worstName = ''; $worstVal = -1.0;
                foreach (['leaseweb' => 'Leaseweb', 'cloudflare' => '1.1.1.1', 'google' => '8.8.8.8'] as $k => $name) {
                    if (isset($ctrl[$k]) && $ctrl[$k] > $worstVal) { $worstVal = $ctrl[$k]; $worstName = $name; }
                }
                $usable[] = ['file' => $inc['file'], 'railway' => $blk['railway'],
                             'ctrlVal' => $worstVal, 'ctrlName' => $worstName];
            }
        }

        if (!$usable) {
            return $unusable > 0
                ? ['label' => 'ZRZUT NIEPELNY — bez werdyktu',
                   'detail' => "{$unusable} blok(ow) diagnostycznych bez kompletu danych (brak znacznika czasu albo brak pomiaru)"]
                : ['label' => 'BRAK ZRZUTU — bez werdyktu',
                   'detail' => 'zaden blok diagnostyczny nie pokrywa tego okna w czasie'];
        }

        $c = count($usable);
        $z = $c . ' ' . railway_summary_plural($c, 'blok diagnostyczny', 'bloki diagnostyczne', 'blokow diagnostycznych');

        // 1) Czy KTORYKOLWIEK blok pokazuje straty na kontroli? Wtedy problem jest szerszy.
        //    Railway i kontrola raportowane z TEGO SAMEGO bloku — bez skladania maksimow.
        $worstCtrl = null;
        foreach ($usable as $u) {
            if ($u['ctrlVal'] >= RAILWAY_SUMMARY_CONTROL_LOSS_PCT
                && ($worstCtrl === null || $u['ctrlVal'] > $worstCtrl['ctrlVal'])) {
                $worstCtrl = $u;
            }
        }
        if ($worstCtrl !== null) {
            return ['label' => 'SZERSZY PROBLEM LACZA (kontrole tez traca)',
                    'detail' => sprintf('%s; %s: Railway %s strat, kontrola %s %s', $z, $worstCtrl['file'],
                        railway_summary_fmt_pct($worstCtrl['railway']), $worstCtrl['ctrlName'],
                        railway_summary_fmt_pct($worstCtrl['ctrlVal']))];
        }

        // 2) Brak strat na kontrolach: czy Railway traci?
        $worstRw = null;
        foreach ($usable as $u) {
            if ($u['railway'] > 0.0 && ($worstRw === null || $u['railway'] > $worstRw['railway'])) {
                $worstRw = $u;
            }
        }
        if ($worstRw !== null) {
            $ctrlTxt = $worstRw['ctrlVal'] > 0.0
                ? sprintf('kontrole ponizej progu (%s %s)', $worstRw['ctrlName'], railway_summary_fmt_pct($worstRw['ctrlVal']))
                : 'kontrole 0% strat';
            return ['label' => 'TRASA DO RAILWAY (kontrole czyste)',
                    'detail' => sprintf('%s; %s: Railway %s strat, %s', $z, $worstRw['file'],
                        railway_summary_fmt_pct($worstRw['railway']), $ctrlTxt)];
        }

        return ['label' => 'ZRZUT BEZ STRAT PINGU (ICMP czysty)',
                'detail' => sprintf('%s, wszedzie 0%% strat — awarii nie widac po ICMP', $z)];
    }

    /**
     * Buduje tresc raportu dla wskazanej doby WAW (YYYYMMDD).
     * @return array{status:string,body:?string,log:string}
     *         status: ok | noop:no-log | noop:no-data
     */
    function railway_summary_build(string $diagDir, DateTimeZone $waw, string $date, bool $fallback): array
    {
        $log = $diagDir . '/railway_monitor_' . $date . '.log';
        if (!file_exists($log)) {
            return ['status' => 'noop:no-log', 'body' => null, 'log' => $log];
        }

        $s = railway_summary_parse_log($log);
        $N = $s['n'];
        if ($N === 0) {
            // Zero sparsowanych linii, ale log MA linie z numerem cyklu => format sie zmienil.
            // To dokladnie awaria z CHAT-T-182, ktora milczala 67 dni. Tu ma byc GLOSNO:
            // raport alarmowy zamiast noop. (noop:no-data zostaje dla logu bez linii pomiarowych.)
            if ($s['rejected'] > 0) {
                $dayA = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
                $body = "ALARM: parser raportu dobowego nie rozpoznal ANI JEDNEJ linii pomiarowej.\n\n"
                    . "Doba raportowana: {$dayA} (WAW).\n"
                    . "Linii z numerem cyklu, ktorych NIE dalo sie sparsowac: {$s['rejected']}.\n"
                    . "Linii sparsowanych: 0.\n\n"
                    . "To znaczy, ze format logu monitora zmienil sie i parser trzeba poprawic.\n"
                    . "Tak samo wygladala awaria CHAT-T-182 (pole railway_pg rozbite przez CHAT-T-108),\n"
                    . "ktora ukryla raport na dwa miesiace — dlatego ten mail wychodzi zamiast ciszy.\n\n"
                    . "Log: {$log}\n";
                return ['status' => 'ok', 'body' => $body, 'log' => $log];
            }
            return ['status' => 'noop:no-data', 'body' => null, 'log' => $log];
        }

        $day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        $pct = fn(int $x): string => sprintf('%.1f%%', 100 * $x / $N);
        $gap = $s['medianGap'] !== null ? "mediana odstepu probek {$s['medianGap']}s" : 'odstep probek nieustalony';

        $b = [];
        $b[] = ($fallback ? '[FALLBACK / cron — monitor nie dozyl 07:00 lub nie wyslal]' : '[monitor in-process]');
        $b[] = "Nocny monitor lacza serwer chat.divezone.pl -> Railway PG (switchback.proxy.rlwy.net:14368).";
        // Dlugosc doby liczona z kalendarza WAW — przy zmianie czasu doba ma 23h albo 25h,
        // wpisanie na sztywno "24h" byloby zmysleniem.
        $dayStart = new DateTime($day . ' 00:00:00', $waw);
        $dayEnd   = (clone $dayStart)->modify('+1 day');
        $dayLenH  = ($dayEnd->getTimestamp() - $dayStart->getTimestamp()) / 3600;
        $b[] = sprintf('Doba raportowana: %s (WAW), dlugosc doby %sh.', $day, rtrim(rtrim(number_format($dayLenH, 1, '.', ''), '0'), '.'));
        $b[] = "Okno logu: {$s['firstWaw']} - {$s['lastWaw']} WAW. Prob: {$N} ({$gap}).";

        // === BRAMKA KOMPLETNOSCI DOBY (CHAT-T-183) ===
        // Bez pokrycia zadna ocena jakosci lacza nie ma sensu: log siedmiogodzinny albo taki,
        // w ktorym monitor stal kilka godzin, potrafil dotad dostac etykiete "CZYSTA".
        // Oczekiwana liczba probek = dlugosc doby / mediana odstepu (obie liczby zmierzone,
        // nie zalozone). Mediana jest calkowita (log ma rozdzielczosc 1 s), wiec przy odstepie
        // ~5,7 s pokrycie potrafi wyjsc nieco ponad 100% — to artefakt zaokraglenia, nieszkodliwy
        // dla bramki, ktora patrzy na DOL.
        $dayLenSec = $dayEnd->getTimestamp() - $dayStart->getTimestamp();
        $expected  = ($s['medianGap'] !== null && $s['medianGap'] > 0)
            ? (int)round($dayLenSec / $s['medianGap'])
            : null;
        $sampleRatio = ($expected !== null && $expected > 0) ? 100.0 * $N / $expected : null;
        // Pokrycie CZASU doby liczymy na znacznikach UTC (nie na godzinach WAW bez daty) —
        // inaczej doba ze zmiana czasu dawalaby wynik z powtorzonej/przeskoczonej godziny.
        // Ta liczba nie przekracza 100% i to ona idzie do naglowka; stosunek liczby probek
        // stoi obok, bo przy medianie zaokraglonej do sekundy potrafi wyjsc ponad 100%.
        $timeCoverage = null;
        if ($s['firstUtc'] !== null && $s['lastUtc'] !== null && $s['lastUtc'] >= $s['firstUtc'] && $dayLenSec > 0) {
            $observed = ($s['lastUtc'] - $s['firstUtc']) + ($s['medianGap'] ?? 0);
            $timeCoverage = min(100.0, 100.0 * $observed / $dayLenSec);
        }
        $gapTooBig = ($s['maxGap'] !== null && $s['maxGap'] > RAILWAY_SUMMARY_MAX_GAP_S);
        // Fail closed: pokrycia NIE DA sie policzyc => doba niepelna (nie "w porzadku").
        $lowCover  = ($sampleRatio === null || $sampleRatio < RAILWAY_SUMMARY_COVERAGE_MIN_PCT)
            || ($timeCoverage === null || $timeCoverage < RAILWAY_SUMMARY_COVERAGE_MIN_PCT);
        // Kadencja: pokrycie liczone wzgledem WLASNEJ mediany samo by sie znormalizowalo,
        // gdyby monitor mierzyl raz na kwadrans. Mediana ma niezalezny sufit.
        $badCadence = ($s['medianGap'] === null || $s['medianGap'] > RAILWAY_SUMMARY_MAX_MEDIAN_GAP_S);
        $incomplete = $lowCover || $gapTooBig || $badCadence || $s['rejected'] > 0;
        $coverTxt = $timeCoverage !== null ? sprintf('%.1f%%', $timeCoverage) : 'nieustalone';

        $b[] = sprintf('Ciaglosc pomiaru: pokrycie doby %s (probki: %d wobec %s oczekiwanych = %s, mediana odstepu %ss), najwieksza przerwa %s.',
            $coverTxt,
            $N,
            $expected !== null ? (string)$expected : 'n/d',
            $sampleRatio !== null ? sprintf('%.1f%%', $sampleRatio) : 'n/d',
            $s['medianGap'] !== null ? (string)$s['medianGap'] : '?',
            $s['maxGap'] !== null ? railway_summary_fmt_dur($s['maxGap']) : 'nieustalona');
        if ($badCadence) {
            $b[] = sprintf('UWAGA: mediana odstepu probek %s przekracza dopuszczalne %ds — monitor mierzyl ZA RZADKO, '
                . 'zeby orzekac o jakosci lacza.',
                $s['medianGap'] !== null ? $s['medianGap'] . 's' : 'nieustalona', RAILWAY_SUMMARY_MAX_MEDIAN_GAP_S);
        }
        if ($s['rejected'] > 0) {
            $b[] = sprintf('UWAGA: %d linii z numerem cyklu NIE sparsowano — mozliwa ZMIANA FORMATU LOGU. '
                . 'Dokladnie taka awaria ukrywala raport przez dwa miesiace (CHAT-T-182). Sprawdz parser.',
                $s['rejected']);
        }

        // Jednozdaniowa ocena doby — zeby nie trzeba bylo czytac calego maila, zeby wiedziec, czy cos bylo.
        $incidents = railway_summary_incidents($diagDir, $date);
        // Do DOPASOWANIA okien dokladamy zrzuty z doby poprzedniej: epizod zaczety przed polnoca
        // ma plik z data D-1, a jego bloki moga pokrywac okno z pierwszych minut doby D.
        // Do LISTY w mailu ida wylacznie zrzuty raportowanej doby (jak dotad).
        $prevDate = (clone $dayStart)->modify('-1 day')->format('Ymd');
        $matchPool = array_merge(railway_summary_incidents($diagDir, $prevDate), $incidents);
        $winCnt = count($s['windows']);
        $maxWin = 0;
        foreach ($s['windows'] as $w) { if ($w['dur'] > $maxWin) { $maxWin = $w['dur']; } }
        if ($incomplete) {
            // Doba niepelna NIGDY nie dostaje slowa CZYSTA — nie wiadomo, co sie dzialo w luce.
            $b[] = sprintf('OCENA DOBY: NIEPELNA (pokrycie %s, najwieksza przerwa %s, linii nieparsowalnych %d) — '
                . 'brak podstaw do oceny jakosci lacza za te dobe.',
                $coverTxt,
                $s['maxGap'] !== null ? railway_summary_fmt_dur($s['maxGap']) : 'nieustalona',
                $s['rejected']);
            $b[] = sprintf('W widocznej czesci doby: %d %s, %d %s incydentow, %d %s pg_select1 FAIL, najdluzsze %s.',
                $s['alerts'], railway_summary_plural($s['alerts'], 'alert', 'alerty', 'alertow'),
                count($incidents), railway_summary_plural(count($incidents), 'zrzut', 'zrzuty', 'zrzutow'),
                $winCnt, railway_summary_plural($winCnt, 'okno', 'okna', 'okien'),
                railway_summary_fmt_dur($maxWin));
        } elseif ($s['alerts'] === 0 && count($incidents) === 0) {
            $b[] = $winCnt === 0
                ? "OCENA DOBY: CZYSTA — zero FAIL na metryce wiodacej pg_select1, zero alertow."
                : sprintf('OCENA DOBY: CZYSTA — zero alertow i zero epizodow; pojedyncze zdarzenia: %d prob pg_select1 FAIL w %d %s, najdluzsze %s.',
                    $s['fails']['pg_select1'], $winCnt,
                    railway_summary_plural($winCnt, 'oknie', 'oknach', 'oknach'),
                    railway_summary_fmt_dur($maxWin));
        } else {
            $b[] = sprintf('OCENA DOBY: EPIZODY — %d %s, %d %s incydentow, %d %s pg_select1 FAIL, najdluzsze %s.',
                $s['alerts'], railway_summary_plural($s['alerts'], 'alert', 'alerty', 'alertow'),
                count($incidents), railway_summary_plural(count($incidents), 'zrzut', 'zrzuty', 'zrzutow'),
                $winCnt, railway_summary_plural($winCnt, 'okno', 'okna', 'okien'),
                railway_summary_fmt_dur($maxWin));
        }
        $b[] = "";
        $b[] = "=== WSKAZNIKI FAIL (per sonda) ===";
        foreach (['railway_tcp' => 'railway_tcp (connect TCP)', 'pg_select1' => 'pg_select1  (connect+SELECT 1)',
                  'pg_settings' => 'pg_settings (odczyt ustawien)', 'pg_chiptree' => 'pg_chiptree (odczyt chip_nodes)',
                  'pg_upsert'   => 'pg_upsert   (zapis probe)'] as $k => $label) {
            $b[] = sprintf('%-32s FAIL: %6d/%d (%s)', $label, $s['fails'][$k], $N, $pct($s['fails'][$k]));
        }
        $b[] = sprintf('%-32s FAIL: %6d/%d (%s)  <- kontrola wyjscia hostingu',
            'github      (api.github.com:443)', $s['fails']['github'], $N, $pct($s['fails']['github']));
        $b[] = sprintf('%-32s     : %6d/%d (%s)  <- timeout connectu (ETIMEDOUT)',
            'errno=110', $s['errno110'], $N, $pct($s['errno110']));
        $b[] = "";
        $b[] = "=== DOWOD KORELACYJNY (do eskalacji) ===";
        $b[] = "pg_select1 FAIL przy github OK:      {$s['corr']['pg_select1_gh_ok']}/{$N}";
        $b[] = "railway_tcp FAIL przy github OK:     {$s['corr']['railway_tcp_gh_ok']}/{$N}";
        $b[] = "dowolna metryka PG FAIL przy gh OK:  {$s['corr']['any_pg_gh_ok']}/{$N}";
        $b[] = "(Wysokie te liczby + niskie github FAIL = problem specyficzny dla Railway, nie wyjscia hostingu.)";
        $b[] = "(github FAIL w epizodzie koreluje z wlasnymi zrzutami diagnostycznymi — patrz werdykty okien.";
        $b[] = " Ta korelacja liczby powyzej tylko ZANIZA, wiec jako argument sa bezpieczne.)";
        $b[] = "";
        $b[] = "=== ZLE OKNA (ciagi kolejnych pg_select1 FAIL) ===";
        $windows = $s['windows'];
        if (!$windows) {
            $b[] = $incomplete
                ? "Brak okien w widocznej czesci doby (pg_select1 0 FAIL na {$N} sparsowanych prob). "
                  . "Doba NIEPELNA — to nie znaczy, ze bylo czysto."
                : "Brak. Doba czysta — pg_select1 0 FAIL na {$N} prob.";
        } else {
            $b[] = "Liczba okien: " . count($windows);
            $show = $windows;
            $omitted = 0;
            $maxShow = 50;
            if (count($windows) > $maxShow) {
                // Nie ucinamy po cichu: bierzemy $maxShow NAJDLUZSZYCH, reszte jawnie zliczamy.
                usort($show, fn(array $x, array $y): int => $y['dur'] <=> $x['dur']);
                $omitted = count($show) - $maxShow;
                $show = array_slice($show, 0, $maxShow);
                usort($show, fn(array $x, array $y): int => strcmp($x['start'], $y['start']));
            }
            foreach ($show as $w) {
                // "max przerwa" = najdluzszy odstep miedzy probkami WEWNATRZ okna. Duza wartosc znaczy,
                // ze czas trwania okna zawiera odcinek NIEMIERZONY (dluzszy cykl przy timeoutach albo
                // restart monitora) — bez tego czytelnik wzialby dlugosc okna za pomiar ciagly.
                $b[] = sprintf('  %s - %s WAW | %d prob | %s | max przerwa %ds',
                    $w['start'], $w['end'], $w['n'], railway_summary_fmt_dur($w['dur']), $w['max_gap']);
                // Werdykt z pingow w zrzucie, NIE z github (patrz railway_summary_window_verdict).
                $v = railway_summary_window_verdict($w, $matchPool);
                $b[] = sprintf('      werdykt: %s | %s', $v['label'], $v['detail']);
                $b[] = sprintf('      github w oknie: %d FAIL%s', $w['gh_fail'],
                    $w['gh_fail'] > 0
                        ? ' (przeslanka, nie dowod: koreluje z wlasnymi zrzutami diagnostycznymi)'
                        : '');
            }
            if ($omitted > 0) {
                $b[] = "  ... pominieto {$omitted} krotszych okien (pokazano {$maxShow} najdluzszych, pelna lista w logu).";
            }
        }
        $b[] = "";
        $b[] = "=== EPIZODY (alerty i zrzuty diagnostyczne CHAT-T-119) ===";
        $b[] = sprintf('Alerty (linie ### ALERT w logu): %d, w tym mail=FAILED: %d',
            $s['alerts'], $s['alertsMailFailed']);
        $b[] = "Pliki incydentow: " . count($incidents);
        foreach ($incidents as $inc) {
            // Kontrole 1.1.1.1 i 8.8.8.8 dopisujemy TYLKO gdy tez traca — cisza znaczy "czysto".
            $noisy = [];
            foreach (['cloudflare' => '1.1.1.1', 'google' => '8.8.8.8'] as $k => $name) {
                if ($inc[$k] !== null && $inc[$k] >= RAILWAY_SUMMARY_CONTROL_LOSS_PCT) {
                    $noisy[] = $name . ' ' . railway_summary_fmt_pct($inc[$k]);
                }
            }
            $b[] = sprintf('  %s | Railway %s strat | Leaseweb %s strat%s',
                $inc['file'],
                $inc['railway'] !== null ? railway_summary_fmt_pct($inc['railway']) : 'n/d',
                $inc['leaseweb'] !== null ? railway_summary_fmt_pct($inc['leaseweb']) : 'n/d',
                $noisy ? ' | UWAGA kontrole tez traca: ' . implode(', ', $noisy) : '');
        }
        $b[] = "";
        $b[] = "Log: {$log}";

        return ['status' => 'ok', 'body' => implode("\n", $b) . "\n", 'log' => $log];
    }

    /**
     * @return string status: sent | skip:flag-exists | noop:no-log | mail-failed | noop:no-data
     */
    function railway_summary_send(string $diagDir, DateTimeZone $waw, string $to, bool $fallback): string
    {
        // Raportujemy PELNA POPRZEDNIA dobe WAW (uruchomienie jest o 07:00/07:05 dnia nastepnego).
        $date = (new DateTime('now', $waw))->modify('-1 day')->format('Ymd');
        $flag = $diagDir . '/mail_sent_' . $date . '.flag';   // klucz = doba raportowana

        if (file_exists($flag)) {
            return 'skip:flag-exists';
        }

        $r = railway_summary_build($diagDir, $waw, $date, $fallback);
        if ($r['status'] !== 'ok') {
            return $r['status'];   // noop:no-log | noop:no-data
        }

        $day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        $subject = '[divezone] Nocny monitor Railway — podsumowanie ' . $day . ($fallback ? ' (fallback)' : '');
        $headers = "From: noreply@divezone.pl\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $ok = @mail($to, $subject, (string)$r['body'], $headers);
        if ($ok) {
            // Nieudany zapis flagi = dedup przestaje dzialac (cron o 07:05 wysle drugi raz).
            // Statusu nie zmieniamy (kontrakt), ale awaria ma byc glosna w error_logu.
            $w = @file_put_contents($flag, (new DateTime('now', $waw))->format('c') . ' sent by ' . ($fallback ? 'cron-fallback' : 'monitor') . "\n");
            if ($w === false) {
                error_log('[railway_summary_mail] mail wyslany, ale NIE udalo sie zapisac flagi ' . $flag);
            }
            return 'sent';
        }
        return 'mail-failed';
    }
}

// === Wejscie CLI (cron uruchamia ten plik bezposrednio) ===
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $waw = new DateTimeZone('Europe/Warsaw');

    // --dry-run YYYYMMDD [diagDir] — tylko stdout: bez maila, bez flagi, bez sprawdzania flagi.
    if (($argv[1] ?? '') === '--dry-run') {
        $date = $argv[2] ?? '';
        if (!preg_match('/^\d{8}$/', $date)) {
            fwrite(STDERR, "uzycie: railway_summary_mail.php --dry-run YYYYMMDD [katalog_diag]\n");
            exit(2);
        }
        $diagDir = $argv[3] ?? '/home/divezone/_diag';
        $r = railway_summary_build($diagDir, $waw, $date, true);
        $day = substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2);
        fwrite(STDOUT, "[DRY-RUN] doba {$day} | katalog {$diagDir} | status=" . ($r['status'] === 'ok' ? 'ok (mail NIE wyslany, flaga NIE tworzona)' : $r['status']) . "\n");
        if ($r['status'] === 'ok') {
            // Ten sam temat co w sciezce cronowej (fallback=true), zeby dry-run pokazywal to, co pojdzie mailem.
            fwrite(STDOUT, "Subject: [divezone] Nocny monitor Railway — podsumowanie {$day} (fallback)\n\n" . (string)$r['body']);
            exit(0);
        }
        exit(1);
    }

    $to = 'k.susicki@divezone.pl, k.susicki@gmail.com';
    $status = railway_summary_send('/home/divezone/_diag', $waw, $to, true);
    fwrite(STDOUT, '[railway_summary_mail] ' . (new DateTime('now', $waw))->format('Y-m-d H:i:s') . " status=" . $status . "\n");
}
