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
 * Dedup: flaga /home/divezone/_diag/mail_sent_YYYYMMDD.flag, gdzie YYYYMMDD to doba
 * RAPORTOWANA (nie dzien wysylki) — dzieki temu sciezka in-process (07:00) i cron (07:05)
 * dalej deduplikuja sie wzajemnie. Brak logu raportowanej doby = NO-OP.
 */

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
     * @return array{n:int,rejected:int,fails:array<string,int>,errno110:int,alerts:int,
     *               alertsMailFailed:int,firstWaw:?string,lastWaw:?string,medianGap:?int,maxGap:?int,
     *               corr:array<string,int>,windows:array<int,array{0:string,1:string,2:int,3:int,4:bool,5:int}>}
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
        $firstWaw = null; $lastWaw = null;
        $prevUtc = null; $gaps = []; $maxGap = null;
        $corr = ['pg_select1_gh_ok' => 0, 'railway_tcp_gh_ok' => 0, 'any_pg_gh_ok' => 0];
        // okno = ciag kolejnych probek z pg_select1 FAIL (metryka wiodaca: connect + SELECT 1)
        $windows = [];
        $curStartWaw = null; $curEndWaw = null; $curStartUtc = 0; $curEndUtc = 0;
        $curCnt = 0; $curGhOk = true; $curMaxGap = 0; $curPrevUtc = null;

        $empty = ['n' => 0, 'rejected' => 0, 'fails' => $fails, 'errno110' => 0, 'alerts' => 0,
                  'alertsMailFailed' => 0, 'firstWaw' => null, 'lastWaw' => null, 'medianGap' => null,
                  'maxGap' => null, 'corr' => $corr, 'windows' => []];
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

            if ($firstWaw === null) { $firstWaw = $waws; }
            $lastWaw = $waws;
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
                    $curCnt = 0; $curGhOk = true; $curMaxGap = 0; $curPrevUtc = null;
                }
                $curEndWaw = $waws; $curEndUtc = ($utcTs !== false ? $utcTs : $curStartUtc); $curCnt++;
                // najwiekszy odstep MIEDZY probkami w oknie: pokazuje, ile czasu w oknie NIE bylo mierzone
                if ($curPrevUtc !== null && $utcTs !== false && $utcTs - $curPrevUtc > $curMaxGap) {
                    $curMaxGap = $utcTs - $curPrevUtc;
                }
                if ($utcTs !== false) { $curPrevUtc = $utcTs; }
                if (!$ghOk) { $curGhOk = false; }
            } elseif ($curStartWaw !== null) {
                $windows[] = [$curStartWaw, $curEndWaw, $curCnt, max(0, $curEndUtc - $curStartUtc), $curGhOk, $curMaxGap];
                $curStartWaw = null;
            }
        }
        fclose($fh);
        if ($curStartWaw !== null) {
            $windows[] = [$curStartWaw, $curEndWaw, $curCnt, max(0, $curEndUtc - $curStartUtc), $curGhOk, $curMaxGap];
        }

        $medianGap = null;
        if ($gaps) {
            sort($gaps);
            $c = count($gaps);
            $medianGap = (int)round(($c % 2 === 1) ? $gaps[intdiv($c, 2)] : ($gaps[$c / 2 - 1] + $gaps[$c / 2]) / 2);
        }

        return ['n' => $n, 'rejected' => $rejected, 'fails' => $fails, 'errno110' => $errno110,
                'alerts' => $alerts, 'alertsMailFailed' => $alertsMailFailed,
                'firstWaw' => $firstWaw, 'lastWaw' => $lastWaw, 'medianGap' => $medianGap,
                'maxGap' => $maxGap, 'corr' => $corr, 'windows' => $windows];
    }

    /**
     * Zrzuty incydentow CHAT-T-119 z danej doby: procent strat pingu do Railway i do kontroli
     * Leaseweb. Tylko odczyt, pliki incydentow nietkniete. Gdy plik ma dwa bloki DIAG
     * (epizod-start + epizod-koniec) — bierzemy STRATE NAJWIEKSZA (najgorszy moment epizodu).
     *
     * @return array<int,array{file:string,railway:?float,leaseweb:?float}>
     */
    function railway_summary_incidents(string $diagDir, string $date): array
    {
        $railwayIp = '66.33.22.230';
        $ctrlIp    = '5.79.108.33';
        $files = glob($diagDir . '/incident_' . $date . '_*.txt') ?: [];
        sort($files);
        $out = [];
        foreach ($files as $path) {
            $loss = [];
            $cur = null;
            $fh = @fopen($path, 'r');
            if ($fh === false) {
                $out[] = ['file' => basename($path), 'railway' => null, 'leaseweb' => null];
                continue;
            }
            while (($ln = fgets($fh)) !== false) {
                if (preg_match('/^--- (\d+\.\d+\.\d+\.\d+) ping statistics ---/', $ln, $m)) {
                    $cur = $m[1];
                    continue;
                }
                if ($cur !== null && preg_match('/([\d.]+)% packet loss/', $ln, $m)) {
                    $v = (float)$m[1];
                    if (!isset($loss[$cur]) || $v > $loss[$cur]) { $loss[$cur] = $v; }
                    $cur = null;
                }
            }
            fclose($fh);
            $out[] = [
                'file'     => basename($path),
                'railway'  => $loss[$railwayIp] ?? null,
                'leaseweb' => $loss[$ctrlIp] ?? null,
            ];
        }
        return $out;
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
        $b[] = sprintf('Ciaglosc pomiaru: najwieksza przerwa miedzy probkami %s%s.',
            $s['maxGap'] !== null ? railway_summary_fmt_dur($s['maxGap']) : 'nieustalona',
            $s['rejected'] > 0 ? sprintf(' | UWAGA: %d linii z numerem cyklu NIE sparsowano (zmiana formatu logu?)', $s['rejected']) : '');

        // Jednozdaniowa ocena doby — zeby nie trzeba bylo czytac calego maila, zeby wiedziec, czy cos bylo.
        $incidents = railway_summary_incidents($diagDir, $date);
        $winCnt = count($s['windows']);
        $maxWin = 0;
        foreach ($s['windows'] as $w) { if ($w[3] > $maxWin) { $maxWin = $w[3]; } }
        if ($s['alerts'] === 0 && count($incidents) === 0) {
            $b[] = $winCnt === 0
                ? "OCENA DOBY: CZYSTA — zero FAIL na metryce wiodacej pg_select1, zero alertow."
                : sprintf('OCENA DOBY: CZYSTA — zero alertow i zero epizodow; pojedyncze zdarzenia: %d prob pg_select1 FAIL w %d %s, najdluzsze %s.',
                    $s['fails']['pg_select1'], $winCnt, $winCnt === 1 ? 'oknie' : 'oknach',
                    railway_summary_fmt_dur($maxWin));
        } else {
            $b[] = sprintf('OCENA DOBY: EPIZODY — %d alertow, %d zrzutow incydentow, %d okien pg_select1 FAIL, najdluzsze %s.',
                $s['alerts'], count($incidents), $winCnt, railway_summary_fmt_dur($maxWin));
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
        $b[] = "";
        $b[] = "=== ZLE OKNA (ciagi kolejnych pg_select1 FAIL) ===";
        $windows = $s['windows'];
        if (!$windows) {
            $b[] = "Brak. Doba czysta — pg_select1 0 FAIL na {$N} prob.";
        } else {
            $b[] = "Liczba okien: " . count($windows);
            $show = $windows;
            $omitted = 0;
            $maxShow = 50;
            if (count($windows) > $maxShow) {
                // Nie ucinamy po cichu: bierzemy $maxShow NAJDLUZSZYCH, reszte jawnie zliczamy.
                usort($show, fn(array $x, array $y): int => $y[3] <=> $x[3]);
                $omitted = count($show) - $maxShow;
                $show = array_slice($show, 0, $maxShow);
                usort($show, fn(array $x, array $y): int => strcmp($x[0], $y[0]));
            }
            foreach ($show as $w) {
                // "max przerwa" = najdluzszy odstep miedzy probkami WEWNATRZ okna. Duza wartosc znaczy,
                // ze czas trwania okna zawiera odcinek NIEMIERZONY (dluzszy cykl przy timeoutach albo
                // restart monitora) — bez tego czytelnik wzialby dlugosc okna za pomiar ciagly.
                $b[] = sprintf('  %s - %s WAW | %d prob | %s | max przerwa %ds | github w tym oknie: %s',
                    $w[0], $w[1], $w[2], railway_summary_fmt_dur($w[3]), $w[5],
                    $w[4] ? 'OK (Railway winny)' : 'TEZ FAIL (szerszy problem)');
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
            $b[] = sprintf('  %s | Railway %s strat | Leaseweb %s strat',
                $inc['file'],
                $inc['railway'] !== null ? railway_summary_fmt_pct($inc['railway']) : 'n/d',
                $inc['leaseweb'] !== null ? railway_summary_fmt_pct($inc['leaseweb']) : 'n/d');
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
