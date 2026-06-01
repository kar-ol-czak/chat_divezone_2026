<?php

declare(strict_types=1);

/**
 * T-035 CZESC A KROK 2 — dry-run mapowania CAŁEGO CSV Subiekta dla tabeli
 * popularnosci (divechat_product_sales_popularity).
 *
 * W odroznieniu od T-031 (`reseed_curated_candidates.php`) NIE filtruje po
 * grupie/nazwie — tabela popularnosci ma byc OGOLNA (przyda sie pod przyszle
 * kategorie rekomendacji). Mapowanie SKU -> product_id przez ta sama regule:
 * pr_product.reference + supplier_reference + ean13 + fallback na
 * pr_product_attribute.{reference,supplier_reference,ean13}.
 *
 * Agregacja: per product_id (suma qty + suma net_value ze wszystkich SKU
 * mapowanych na ten sam produkt, granularnosc 39a). Robi UPSERT? NIE — tylko
 * raport. Czeka na bramke Karola.
 *
 * Uruchamianie na prod (MYSQL=localhost, dostep do pr_product*):
 *   /opt/cpanel/ea-php84/root/usr/bin/php scripts/popularity_seed_dryrun.php \
 *       [reports/sales_subiekt_12mcy.csv] [--top=20]
 */

use DiveChat\Config;
use DiveChat\Database\MysqlConnection;
use DiveChat\Database\PostgresConnection;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

foreach ([dirname($basePath), $basePath] as $path) {
    if (is_file($path . '/.env')) {
        Config::load($path);
        break;
    }
}

$csvPath = $basePath . '/reports/sales_subiekt_12mcy.csv';
$topN = 20;
$apply = false;
$exportSqlPath = null;
$periodLabel = '2025-06..2026-05';

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--top=(\d+)$/', $arg, $m)) {
        $topN = (int) $m[1];
    } elseif ($arg === '--apply') {
        $apply = true;
    } elseif (preg_match('/^--export-sql=(.+)$/', $arg, $m)) {
        $exportSqlPath = $m[1];
    } elseif (preg_match('/^--period=(.+)$/', $arg, $m)) {
        $periodLabel = $m[1];
    } elseif (substr($arg, 0, 2) !== '--') {
        $csvPath = $arg; // positional argument = sciezka CSV (override default)
    }
}

if (!is_file($csvPath)) {
    fwrite(STDERR, "Brak CSV: {$csvPath}\n");
    exit(1);
}

$reportDir = $basePath . '/reports';
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0775, true);
}
$reportFile = $reportDir . '/popularity_dryrun_' . date('Ymd_His') . '.txt';
$fh = fopen($reportFile, 'w');
$out = static function (string $line = '') use ($fh): void {
    echo $line . "\n";
    if ($fh !== false) {
        fwrite($fh, $line . "\n");
    }
};

$LANG_PL = 1;
$db = MysqlConnection::getInstance();

$out('========================================================================');
$out('T-035 CZESC A KROK 2 — DRY-RUN popularnosci (Subiekt 12mc, CAŁY CSV)');
$out('Zrodlo: ' . $csvPath);
$out('Wygenerowano: ' . date('Y-m-d H:i:s'));
$out('========================================================================');
$out();

// ============================================================================
// PARSE CSV — calosc, BEZ filtra grup. Konwersja "12 062,01" -> 12062.01
// ============================================================================
$normalizeNet = static function (string $raw): float {
    $clean = str_replace(['"', ' ', "\xc2\xa0"], '', $raw); // usun cudzyslowy + spacje + NBSP
    $clean = str_replace(',', '.', $clean);                  // przecinek dziesietny -> kropka
    return (float) $clean;
};

$f = fopen($csvPath, 'r');
$header = fgetcsv($f);
$colIdx = array_flip($header);

$missingCol = array_diff(['Nazwa', 'Symbol', 'Grupa', 'Ilość', 'Netto'], array_keys($colIdx));
if (!empty($missingCol)) {
    fwrite(STDERR, "Brakujace kolumny CSV: " . implode(', ', $missingCol) . "\n");
    exit(2);
}

$rows = [];
$totalCsv = 0;
$skippedBlank = 0;

while (($row = fgetcsv($f)) !== false) {
    $totalCsv++;
    $symbol = trim((string) ($row[$colIdx['Symbol']] ?? ''));
    if ($symbol === '') {
        $skippedBlank++;
        continue;
    }
    $rows[] = [
        'nazwa'  => (string) ($row[$colIdx['Nazwa']] ?? ''),
        'symbol' => $symbol,
        'grupa'  => (string) ($row[$colIdx['Grupa']] ?? ''),
        'ilosc'  => (int) ($row[$colIdx['Ilość']] ?? 0),
        'netto'  => $normalizeNet((string) ($row[$colIdx['Netto']] ?? '0')),
    ];
}
fclose($f);

$out(sprintf('CSV wierszy danych: %d (po pominieciu %d bez symbolu = %d kandydatow)',
    $totalCsv, $skippedBlank, count($rows)));
$out();

// ============================================================================
// MAPOWANIE SYMBOL -> product_id (regula T-031, 6 prob)
// ============================================================================
$findHit = static function (MysqlConnection $db, string $symbol): ?array {
    $tries = [
        ['source' => 'p.reference',
         'sql' => 'SELECT id_product, NULL AS id_product_attribute FROM pr_product WHERE reference = ? LIMIT 1'],
        ['source' => 'p.supplier_reference',
         'sql' => 'SELECT id_product, NULL AS id_product_attribute FROM pr_product WHERE supplier_reference = ? LIMIT 1'],
        ['source' => 'p.ean13',
         'sql' => 'SELECT id_product, NULL AS id_product_attribute FROM pr_product WHERE ean13 = ? LIMIT 1'],
        ['source' => 'pa.reference',
         'sql' => 'SELECT id_product, id_product_attribute FROM pr_product_attribute WHERE reference = ? LIMIT 1'],
        ['source' => 'pa.supplier_reference',
         'sql' => 'SELECT id_product, id_product_attribute FROM pr_product_attribute WHERE supplier_reference = ? LIMIT 1'],
        ['source' => 'pa.ean13',
         'sql' => 'SELECT id_product, id_product_attribute FROM pr_product_attribute WHERE ean13 = ? LIMIT 1'],
    ];
    foreach ($tries as $t) {
        $r = $db->fetchAll($t['sql'], [$symbol]);
        if (count($r) > 0) {
            return [
                'source' => $t['source'],
                'product_id' => (int) $r[0]['id_product'],
            ];
        }
    }
    return null;
};

// ============================================================================
// Agregacja per product_id + cache mapowania (Symbol moze powtarzac sie)
// ============================================================================
$byPid = [];
$unmapped = [];
$sourceCounts = [];
$symbolCache = [];

$startTs = microtime(true);
foreach ($rows as $r) {
    $sym = $r['symbol'];
    if (!array_key_exists($sym, $symbolCache)) {
        $symbolCache[$sym] = $findHit($db, $sym);
    }
    $hit = $symbolCache[$sym];
    if ($hit === null) {
        $unmapped[] = $r;
        continue;
    }
    $sourceCounts[$hit['source']] = ($sourceCounts[$hit['source']] ?? 0) + 1;
    $pid = $hit['product_id'];
    if (!isset($byPid[$pid])) {
        $byPid[$pid] = [
            'product_id'  => $pid,
            'qty_sum'     => 0,
            'net_sum'     => 0.0,
            'symbols'     => [],
            'first_name'  => null,
            'first_grupa' => null,
        ];
    }
    $byPid[$pid]['qty_sum'] += $r['ilosc'];
    $byPid[$pid]['net_sum'] += $r['netto'];
    if (!in_array($sym, $byPid[$pid]['symbols'], true)) {
        $byPid[$pid]['symbols'][] = $sym;
    }
    if ($byPid[$pid]['first_name'] === null) {
        $byPid[$pid]['first_name'] = $r['nazwa'];
        $byPid[$pid]['first_grupa'] = $r['grupa'];
    }
}
$elapsed = microtime(true) - $startTs;

$mappedRows = count($rows) - count($unmapped);
$hitRate = count($rows) > 0 ? round(100 * $mappedRows / count($rows), 1) : 0.0;

$out('--- STATYSTYKI MAPOWANIA (calosc CSV, regula T-031: 6 kolumn) ---');
$out(sprintf('Wierszy CSV (z symbolem): %d', count($rows)));
$out(sprintf('Zmapowanych do product_id: %d', $mappedRows));
$out(sprintf('Unikalnych product_id: %d', count($byPid)));
$out(sprintf('Niezmapowanych (pominiete w tabeli): %d', count($unmapped)));
$out(sprintf('Hit rate: %.1f%%', $hitRate));
$out(sprintf('Czas mapowania: %.1fs (unikalne symbole: %d)', $elapsed, count($symbolCache)));
$out();
$out('--- Rozklad zrodel trafien ---');
foreach ($sourceCounts as $src => $cnt) {
    $out(sprintf('  %-25s %5d (%.1f%%)', $src, $cnt, 100 * $cnt / max(1, $mappedRows)));
}
$out();

// ============================================================================
// TOP N wg qty (kontrola zdrowia + nazwa z PS dla weryfikacji)
// ============================================================================
$pids = array_keys($byPid);
$psNames = [];
if (!empty($pids)) {
    $placeholders = implode(',', array_fill(0, count($pids), '?'));
    $rowsName = $db->fetchAll(
        "SELECT id_product, name FROM pr_product_lang
         WHERE id_lang = ? AND id_product IN ({$placeholders})",
        array_merge([$LANG_PL], $pids),
    );
    foreach ($rowsName as $r) {
        $psNames[(int) $r['id_product']] = (string) $r['name'];
    }
}

// Filtr: ujemna qty_sum = zwroty przewyzszyly sprzedaz 12mc (Subiekt ksieguje
// zwroty jako ujemne pozycje). CHECK constraint qty_non_negative + biznesowo
// "popularnosc" nie ma sensu dla salda ujemnego — pomijamy.
$negatives = array_filter($byPid, static fn($r) => $r['qty_sum'] <= 0);
if (!empty($negatives)) {
    $out(sprintf('--- POMINIETO %d product_id z qty_sum <= 0 (zwroty >= sprzedaz w 12mc) ---', count($negatives)));
    if (!empty($psNames)) {
        $sorted = array_values($negatives);
        usort($sorted, static fn($a, $b) => $a['qty_sum'] <=> $b['qty_sum']);
        foreach (array_slice($sorted, 0, 10) as $r) {
            $out(sprintf('  pid=%d qty=%d net=%.2f name="%s"',
                $r['product_id'], $r['qty_sum'], $r['net_sum'],
                mb_substr($psNames[$r['product_id']] ?? '(brak nazwy)', 0, 50)));
        }
        if (count($negatives) > 10) {
            $out(sprintf('  ... i %d wiecej', count($negatives) - 10));
        }
    }
    $out();
}
$byPid = array_filter($byPid, static fn($r) => $r['qty_sum'] > 0);

$list = array_values($byPid);
usort($list, static fn($a, $b) => $b['qty_sum'] <=> $a['qty_sum']);

$out(sprintf('--- TOP %d product_id wg qty_12m (kontrola zdrowia) ---', $topN));
$out(sprintf('%-7s %-7s %-12s %-35s %-50s',
    'pid', 'qty', 'netto_zl', 'Grupa Subiekt', 'Nazwa PS (id_lang=1)'));
$out(str_repeat('-', 120));
foreach (array_slice($list, 0, $topN) as $r) {
    $out(sprintf('%-7d %-7d %-12s %-35s %-50s',
        $r['product_id'],
        $r['qty_sum'],
        number_format($r['net_sum'], 2, '.', ' '),
        mb_substr($r['first_grupa'] ?? '', 0, 35),
        mb_substr($psNames[$r['product_id']] ?? '(brak nazwy PS)', 0, 50),
    ));
}
$out();

// ============================================================================
// TOP 20 niezmapowanych (do oceny czy istotne)
// ============================================================================
if (!empty($unmapped)) {
    usort($unmapped, static fn($a, $b) => $b['ilosc'] <=> $a['ilosc']);
    $out('--- TOP 20 NIEZMAPOWANYCH wg qty (Symbol bez trafienia, pominiete) ---');
    $out(sprintf('%-22s %-50s %-7s %-12s', 'Symbol', 'Nazwa Subiekt', 'qty', 'netto'));
    $out(str_repeat('-', 100));
    foreach (array_slice($unmapped, 0, 20) as $r) {
        $out(sprintf('%-22s %-50s %-7d %-12s',
            mb_substr($r['symbol'], 0, 22),
            mb_substr($r['nazwa'], 0, 50),
            $r['ilosc'],
            number_format($r['netto'], 2, '.', ' '),
        ));
    }
    $unmappedQty = array_sum(array_column($unmapped, 'ilosc'));
    $unmappedNet = array_sum(array_column($unmapped, 'netto'));
    $out();
    $out(sprintf('Razem niezmapowane: %d wierszy, qty=%d sztuk, netto=%s zl',
        count($unmapped), $unmappedQty, number_format($unmappedNet, 2, '.', ' ')));
}

$out();

// ============================================================================
// APPLY (UPSERT do divechat_product_sales_popularity) — tylko gdy --apply
// ============================================================================
if ($apply) {
    $out('--- APPLY: UPSERT do divechat_product_sales_popularity ---');
    $pg = PostgresConnection::getInstance();
    $pdo = $pg->getPdo();
    $stmt = $pdo->prepare(
        'INSERT INTO divechat_product_sales_popularity
            (product_id, qty_12m, net_value_12m, period_label, source, updated_at)
         VALUES (?, ?, ?, ?, ?, NOW())
         ON CONFLICT (product_id) DO UPDATE
         SET qty_12m       = EXCLUDED.qty_12m,
             net_value_12m = EXCLUDED.net_value_12m,
             period_label  = EXCLUDED.period_label,
             source        = EXCLUDED.source,
             updated_at    = NOW()'
    );

    $pdo->beginTransaction();
    $applyStart = microtime(true);
    $n = 0;
    foreach ($byPid as $pid => $r) {
        $stmt->execute([
            $pid,
            $r['qty_sum'],
            round($r['net_sum'], 2),
            $periodLabel,
            'subiekt_csv',
        ]);
        $n++;
    }
    $pdo->commit();
    $applyElapsed = microtime(true) - $applyStart;

    $out(sprintf('  UPSERT-owano: %d wierszy', $n));
    $out(sprintf('  Czas: %.2fs', $applyElapsed));
    $out(sprintf('  period_label: %s', $periodLabel));

    $count = (int) $pg->fetchOne('SELECT COUNT(*) AS c FROM divechat_product_sales_popularity')['c'];
    $out(sprintf('  Tabela po seedzie: %d wierszy', $count));
    $out();

    $top5 = $pg->fetchAll(
        'SELECT product_id, qty_12m, net_value_12m, period_label, source, updated_at
         FROM divechat_product_sales_popularity
         ORDER BY qty_12m DESC, product_id ASC
         LIMIT 5'
    );
    $out('--- TOP 5 po seedzie (verify) ---');
    $out(sprintf('%-7s %-7s %-13s %-25s %-13s',
        'pid', 'qty', 'netto_zl', 'period_label', 'source'));
    $out(str_repeat('-', 70));
    foreach ($top5 as $r) {
        $out(sprintf('%-7s %-7s %-13s %-25s %-13s',
            $r['product_id'],
            $r['qty_12m'],
            number_format((float) $r['net_value_12m'], 2, '.', ' '),
            $r['period_label'],
            $r['source'],
        ));
    }
    $out();
}

// ============================================================================
// EXPORT-SQL (snapshot jednorazowy do gita) — tylko gdy --export-sql=PATH
// ============================================================================
if ($exportSqlPath !== null) {
    $sqlFh = fopen($exportSqlPath, 'w');
    if ($sqlFh === false) {
        fwrite(STDERR, "Nie moge otworzyc do zapisu: {$exportSqlPath}\n");
        exit(3);
    }
    $period = str_replace("'", "''", $periodLabel);
    fwrite($sqlFh, "-- ============================================\n");
    fwrite($sqlFh, "-- DIVEZONE CHAT AI - Seed 019 (popularnosc Subiekt 12mc)\n");
    fwrite($sqlFh, "-- T-035 CZESC A KROK 3 — jednorazowy snapshot, NIE skrypt reuzywalny.\n");
    fwrite($sqlFh, "-- Data wygenerowania: " . date('Y-m-d H:i:s') . "\n");
    fwrite($sqlFh, "-- Zrodlo: reports/sales_subiekt_12mcy.csv (Subiekt, gitignored)\n");
    fwrite($sqlFh, "-- Liczba product_id: " . count($byPid) . "\n");
    fwrite($sqlFh, "-- period_label: {$period}\n");
    fwrite($sqlFh, "-- Docelowe zrodlo: pomost Subiekt -> czat (decyzja 173, odlozony).\n");
    fwrite($sqlFh, "-- Idempotentny: ON CONFLICT (product_id) DO UPDATE refresh wszystkie pola.\n");
    fwrite($sqlFh, "-- ============================================\n\n");
    fwrite($sqlFh, "INSERT INTO divechat_product_sales_popularity\n");
    fwrite($sqlFh, "    (product_id, qty_12m, net_value_12m, period_label, source)\n");
    fwrite($sqlFh, "VALUES\n");

    $valuesList = [];
    $sortedPids = array_keys($byPid);
    sort($sortedPids); // stable order dla diff-friendly snapshotu
    foreach ($sortedPids as $pid) {
        $r = $byPid[$pid];
        $valuesList[] = sprintf(
            '    (%d, %d, %s, %s, %s)',
            $pid,
            $r['qty_sum'],
            number_format($r['net_sum'], 2, '.', ''),
            "'{$period}'",
            "'subiekt_csv'"
        );
    }
    fwrite($sqlFh, implode(",\n", $valuesList));
    fwrite($sqlFh, "\nON CONFLICT (product_id) DO UPDATE\n");
    fwrite($sqlFh, "    SET qty_12m       = EXCLUDED.qty_12m,\n");
    fwrite($sqlFh, "        net_value_12m = EXCLUDED.net_value_12m,\n");
    fwrite($sqlFh, "        period_label  = EXCLUDED.period_label,\n");
    fwrite($sqlFh, "        source        = EXCLUDED.source,\n");
    fwrite($sqlFh, "        updated_at    = NOW();\n");
    fclose($sqlFh);

    $out(sprintf('--- EXPORT-SQL: zapisano %d UPSERT-ow do %s (%.1f KB) ---',
        count($byPid), $exportSqlPath, filesize($exportSqlPath) / 1024.0));
    $out();
}

$out('========================================================================');
$out($apply ? 'APPLIED — UPSERT do tabeli wykonany' : 'DRY-RUN — ZADNE dane NIE zostaly zapisane do tabeli');
$out('Plik raportu: ' . $reportFile);
$out('========================================================================');

if ($fh !== false) {
    fclose($fh);
}
