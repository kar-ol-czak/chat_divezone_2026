<?php
declare(strict_types=1);
/**
 * Wspolny parser logu + wysylka podsumowania nocnego monitora Railway (READ-ONLY).
 * Uzywany dwojako:
 *  - require'owany przez railway_monitor.php (wysylka in-process o 07:00, fallback=false)
 *  - uruchamiany bezposrednio przez cron 07:05 (fallback=true)
 * Dedup: wspolna flaga /home/divezone/_diag/mail_sent_YYYYMMDD.flag — kto pierwszy
 * wysle, ten tworzy flage; drugi widzi flage i pomija. Brak logu dnia = NO-OP.
 */

if (!function_exists('railway_summary_send')) {
    /**
     * @return string status: sent | skip:flag-exists | noop:no-log | mail-failed | noop:no-data
     */
    function railway_summary_send(string $diagDir, DateTimeZone $waw, string $to, bool $fallback): string
    {
        $date = (new DateTime('now', $waw))->format('Ymd');
        $log  = $diagDir . '/railway_monitor_' . $date . '.log';
        $flag = $diagDir . '/mail_sent_' . $date . '.flag';

        if (file_exists($flag)) {
            return 'skip:flag-exists';
        }
        if (!file_exists($log)) {
            return 'noop:no-log'; // brak logu danego dnia => nic nie robimy, NIE blad
        }

        $lines = file($log, FILE_IGNORE_NEW_LINES) ?: [];
        $N = 0; $rtF = 0; $rpF = 0; $ghF = 0; $rpFail_ghOk = 0; $rtFail_ghOk = 0;
        $windows = []; $curStart = null; $curEnd = null; $curCnt = 0; $curGhOk = true;
        $firstTs = null; $lastTs = null;
        foreach ($lines as $ln) {
            if (!preg_match('/^(\S+ \S+) UTC \| (\S+) WAW \| railway_tcp (\w+).*?railway_pg (\w+).*?github (\w+)/', $ln, $m)) {
                continue;
            }
            $N++;
            $waws = $m[2]; $rt = $m[3]; $rp = $m[4]; $gh = $m[5];
            if ($firstTs === null) { $firstTs = $waws; }
            $lastTs = $waws;
            if ($rt === 'FAIL') { $rtF++; if ($gh === 'OK') { $rtFail_ghOk++; } }
            if ($rp === 'FAIL') { $rpF++; if ($gh === 'OK') { $rpFail_ghOk++; } }
            if ($gh === 'FAIL') { $ghF++; }
            if ($rp === 'FAIL') {
                if ($curStart === null) { $curStart = $waws; $curCnt = 0; $curGhOk = true; }
                $curEnd = $waws; $curCnt++;
                if ($gh !== 'OK') { $curGhOk = false; }
            } else {
                if ($curStart !== null) { $windows[] = [$curStart, $curEnd, $curCnt, $curGhOk]; $curStart = null; }
            }
        }
        if ($curStart !== null) { $windows[] = [$curStart, $curEnd, $curCnt, $curGhOk]; }

        if ($N === 0) {
            return 'noop:no-data'; // log istnieje ale bez wierszy pomiarow
        }

        $pct = fn(int $x) => sprintf('%.1f%%', 100 * $x / $N);
        $b = [];
        $b[] = ($fallback ? '[FALLBACK / cron — monitor nie dozyl 07:00 lub nie wyslal]' : '[monitor in-process]');
        $b[] = "Nocny monitor lacza serwer chat.divezone.pl -> Railway PG (switchback.proxy.rlwy.net:14368).";
        $b[] = "Okno: {$firstTs} - {$lastTs} WAW. Prob: {$N} (interwal 25s).";
        $b[] = "";
        $b[] = "=== WSKAZNIKI FAIL ===";
        $b[] = "Railway TCP  FAIL: {$rtF}/{$N} (" . $pct($rtF) . ")";
        $b[] = "Railway PG   FAIL: {$rpF}/{$N} (" . $pct($rpF) . ")";
        $b[] = "github       FAIL: {$ghF}/{$N} (" . $pct($ghF) . ")  <- kontrola wyjscia hostingu";
        $b[] = "";
        $b[] = "=== DOWOD KORELACYJNY (do eskalacji) ===";
        $b[] = "Railway PG FAIL przy github OK:  {$rpFail_ghOk}/{$N}";
        $b[] = "Railway TCP FAIL przy github OK: {$rtFail_ghOk}/{$N}";
        $b[] = "(Wysokie te liczby + niskie github FAIL = problem specyficzny dla Railway, nie wyjscia hostingu.)";
        $b[] = "";
        $b[] = "=== ZLE OKNA (ciagi kolejnych railway_pg FAIL) ===";
        if (!$windows) {
            $b[] = "Brak. Lacze stabilne (railway_pg 0 FAIL).";
        } else {
            $b[] = "Liczba okien: " . count($windows);
            foreach ($windows as $w) {
                $dur = $w[2] * 25;
                $b[] = sprintf("  %s - %s WAW | %d cykli (~%ds) | github w tym oknie: %s",
                    $w[0], $w[1], $w[2], $dur, $w[3] ? 'OK (Railway winny)' : 'TEZ FAIL (szerszy problem)');
            }
        }
        $b[] = "";
        $b[] = "Log: {$log}";
        $body = implode("\n", $b) . "\n";

        $subject = '[divezone] Nocny monitor Railway — podsumowanie' . ($fallback ? ' (fallback)' : '');
        $headers = "From: noreply@divezone.pl\r\nContent-Type: text/plain; charset=UTF-8\r\n";
        $ok = @mail($to, $subject, $body, $headers);
        if ($ok) {
            @file_put_contents($flag, (new DateTime('now', $waw))->format('c') . ' sent by ' . ($fallback ? 'cron-fallback' : 'monitor') . "\n");
            return 'sent';
        }
        return 'mail-failed';
    }
}

// === Wejscie CLI (cron uruchamia ten plik bezposrednio) ===
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $waw = new DateTimeZone('Europe/Warsaw');
    $to = 'k.susicki@divezone.pl, k.susicki@gmail.com';
    $status = railway_summary_send('/home/divezone/_diag', $waw, $to, true);
    fwrite(STDOUT, '[railway_summary_mail] ' . (new DateTime('now', $waw))->format('Y-m-d H:i:s') . " status=" . $status . "\n");
}
