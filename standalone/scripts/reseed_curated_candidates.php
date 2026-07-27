<?php

declare(strict_types=1);

/**
 * T-031 Faza A — ranking kandydatow do re-seedu curated_recommendations.
 *
 * Wczytuje reports/sales_subiekt_12mcy.csv (Subiekt, NIE PrestaShop — decyzja 172c),
 * filtruje grupy automatow + komputerow, odsiewa akcesoria (nazwa musi zaczynac
 * sie od "Automat " lub "Komputer "), mapuje Symbol Subiekta -> product_id przez
 * probowanie kolumn pr_product.reference / supplier_reference / ean13 + ten sam
 * zestaw na pr_product_attribute, enrich z MySQL przez MysqlProductEnrichmentService,
 * agreguje ilosc 12mc per product_id, raportuje tabele kandydatow + niezmapowane.
 *
 * NIE wykonuje INSERT-u — wybor produktow nalezy do Karola (Faza B).
 *
 * Uruchamianie na prod (MYSQL=localhost):
 *   /opt/cpanel/ea-php84/root/usr/bin/php scripts/reseed_curated_candidates.php \
 *       [reports/sales_subiekt_12mcy.csv]
 */

use DiveChat\Config;
use DiveChat\Database\MysqlConnection;
use DiveChat\Shop\MysqlProductEnrichmentService;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

foreach ([dirname($basePath), $basePath] as $path) {
    if (is_file($path . '/.env')) {
        Config::load($path);
        break;
    }
}

$csvPath = $argv[1] ?? ($basePath . '/reports/sales_subiekt_12mcy.csv');
if (!is_file($csvPath)) {
    fwrite(STDERR, "Brak CSV: {$csvPath}\n");
    exit(1);
}

$reportDir = $basePath . '/reports';
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0775, true);
}
$reportFile = $reportDir . '/reseed_candidates_' . date('Ymd_His') . '.txt';
$fh = fopen($reportFile, 'w');
$out = static function (string $line = '') use ($fh): void {
    echo $line . "\n";
    if ($fh !== false) {
        fwrite($fh, $line . "\n");
    }
};

$LANG_PL = 1;
$db = MysqlConnection::getInstance();
$enricher = new MysqlProductEnrichmentService();

$out('========================================================================');
$out('T-031 Faza A — RANKING KANDYDATOW (Subiekt, 12mc)');
$out('Zrodlo: ' . $csvPath);
$out('Wygenerowano: ' . date('Y-m-d H:i:s'));
$out('========================================================================');
$out();

// ============================================================================
// PARSE CSV — filtr: grupa + name prefix
// ============================================================================
$rowsAuto = [];
$rowsKomp = [];

$f = fopen($csvPath, 'r');
$header = fgetcsv($f);
$colIdx = array_flip($header);
while (($row = fgetcsv($f)) !== false) {
    $rec = [
        'nazwa' => $row[$colIdx['Nazwa']],
        'symbol' => trim((string) $row[$colIdx['Symbol']]),
        'grupa' => $row[$colIdx['Grupa']],
        'ilosc' => (int) $row[$colIdx['Ilość']],
    ];
    $nazwa = $rec['nazwa'];
    $grupa = $rec['grupa'];

    if ($grupa === 'Automaty Oddechowe' && preg_match('/^Automat[y]?\s/iu', $nazwa)) {
        $rowsAuto[] = $rec;
    } elseif (in_array($grupa, ['Komputery nurkowe', 'Komputerowe'], true)
              && preg_match('/^Komputer\s/iu', $nazwa)) {
        $rowsKomp[] = $rec;
    }
}
fclose($f);

$out(sprintf('Wierszy po filtrze: AUTOMATY=%d, KOMPUTERY=%d',
    count($rowsAuto), count($rowsKomp)));
$out();

// ============================================================================
// MAPOWANIE SYMBOL -> product_id (probuje kolumn w kolejnosci)
// Zwraca: ['source','product_id','attribute_id','matches'] | null
// ============================================================================
$findHit = static function (MysqlConnection $db, string $symbol): ?array {
    $tries = [
        ['source' => 'p.reference',
         'sql' => 'SELECT id_product, NULL AS id_product_attribute FROM pr_product WHERE reference = ? LIMIT 5'],
        ['source' => 'p.supplier_reference',
         'sql' => 'SELECT id_product, NULL AS id_product_attribute FROM pr_product WHERE supplier_reference = ? LIMIT 5'],
        ['source' => 'p.ean13',
         'sql' => 'SELECT id_product, NULL AS id_product_attribute FROM pr_product WHERE ean13 = ? LIMIT 5'],
        ['source' => 'pa.reference',
         'sql' => 'SELECT id_product, id_product_attribute FROM pr_product_attribute WHERE reference = ? LIMIT 5'],
        ['source' => 'pa.supplier_reference',
         'sql' => 'SELECT id_product, id_product_attribute FROM pr_product_attribute WHERE supplier_reference = ? LIMIT 5'],
        ['source' => 'pa.ean13',
         'sql' => 'SELECT id_product, id_product_attribute FROM pr_product_attribute WHERE ean13 = ? LIMIT 5'],
    ];

    foreach ($tries as $t) {
        $r = $db->fetchAll($t['sql'], [$symbol]);
        if (count($r) > 0) {
            return [
                'source' => $t['source'],
                'product_id' => (int) $r[0]['id_product'],
                'attribute_id' => $r[0]['id_product_attribute'] !== null
                    ? (int) $r[0]['id_product_attribute']
                    : null,
                'matches' => count($r),
            ];
        }
    }
    return null;
};

// ============================================================================
// A1: probka 4+4
// ============================================================================
usort($rowsAuto, static fn($a, $b) => $b['ilosc'] <=> $a['ilosc']);
usort($rowsKomp, static fn($a, $b) => $b['ilosc'] <=> $a['ilosc']);

$out('--- A1: PROBKA MAPOWANIA SKU (top 4 automaty + top 4 komputery) ---');
$out(sprintf('%-22s %-10s %-45s %-23s %-9s %-7s',
    'symbol', 'kat', 'nazwa Subiekt', 'source', 'product_id', 'match'));
$out(str_repeat('-', 120));

$probka = array_merge(
    array_map(static fn($r) => $r + ['kat' => 'automaty'], array_slice($rowsAuto, 0, 4)),
    array_map(static fn($r) => $r + ['kat' => 'komputery'], array_slice($rowsKomp, 0, 4)),
);

foreach ($probka as $r) {
    $hit = $findHit($db, $r['symbol']);
    $out(sprintf('%-22s %-10s %-45s %-23s %-9s %-7s',
        mb_substr($r['symbol'], 0, 22),
        $r['kat'],
        mb_substr($r['nazwa'], 0, 45),
        $hit ? $hit['source'] : 'BRAK',
        $hit ? (string) $hit['product_id'] : '-',
        $hit ? (string) $hit['matches'] : '-',
    ));
}
$out();

// ============================================================================
// A2/A3: pelne mapowanie + agregacja per product_id
// ============================================================================
$aggregate = static function (MysqlConnection $db, array $rows, callable $findHit): array {
    $byPid = [];
    $unmapped = [];
    $sourceCounts = [];

    foreach ($rows as $r) {
        $hit = $findHit($db, $r['symbol']);
        if ($hit === null) {
            $unmapped[] = $r;
            continue;
        }
        $sourceCounts[$hit['source']] = ($sourceCounts[$hit['source']] ?? 0) + 1;
        $pid = $hit['product_id'];
        if (!isset($byPid[$pid])) {
            $byPid[$pid] = [
                'product_id' => $pid,
                'symbols' => [],
                'names_subiekt' => [],
                'qty_sum' => 0,
                'sources' => [],
            ];
        }
        $byPid[$pid]['symbols'][] = $r['symbol'];
        $byPid[$pid]['names_subiekt'][] = $r['nazwa'];
        $byPid[$pid]['qty_sum'] += $r['ilosc'];
        $byPid[$pid]['sources'][] = $hit['source'];
    }

    return [
        'by_pid' => $byPid,
        'unmapped' => $unmapped,
        'source_counts' => $sourceCounts,
        'total' => count($rows),
    ];
};

$aggAuto = $aggregate($db, $rowsAuto, $findHit);
$aggKomp = $aggregate($db, $rowsKomp, $findHit);

// % trafien na pelnej probie
$out('--- A1 PODSUMOWANIE (regula mapowania) ---');
foreach (['automaty' => $aggAuto, 'komputery' => $aggKomp] as $kat => $agg) {
    $matched = count($agg['by_pid']);
    $unmappedRows = count($agg['unmapped']);
    $totalRows = $agg['total'];
    $hitRate = $totalRows > 0
        ? round(100 * ($totalRows - $unmappedRows) / $totalRows, 1)
        : 0.0;
    $out(sprintf('%-10s wierszy=%-4d zmapowanych=%-4d unikalnych_pid=%-4d hit_rate=%.1f%%',
        $kat, $totalRows, $totalRows - $unmappedRows, $matched, $hitRate));
    $out('  rozklad zrodel: ' . json_encode($agg['source_counts'], JSON_UNESCAPED_UNICODE));
}
$out();

// ============================================================================
// ENRICH z MySQL (cena, dostepnosc, active)
// ============================================================================
$pids = array_values(array_unique(array_map('intval',
    array_merge(array_keys($aggAuto['by_pid']), array_keys($aggKomp['by_pid']))
)));
$enrich = $enricher->enrich($pids);

$psName = [];
if (!empty($pids)) {
    $rows = $db->fetchAll(
        "SELECT id_product, name FROM pr_product_lang
         WHERE id_lang = ? AND id_product IN ("
            . implode(',', array_fill(0, count($pids), '?')) . ")",
        array_merge([$LANG_PL], $pids),
    );
    foreach ($rows as $r) {
        $psName[(int) $r['id_product']] = $r['name'];
    }
}

// ============================================================================
// A4: tabele kandydatow (sort po qty_sum)
// ============================================================================
$printTable = static function (array $agg, string $title, array $enrich, array $psName, callable $out): void {
    $out('===========================================================================================');
    $out($title);
    $out('===========================================================================================');
    $list = array_values($agg['by_pid']);
    usort($list, static fn($a, $b) => $b['qty_sum'] <=> $a['qty_sum']);

    $out(sprintf('%-7s %-22s %-37s %-6s %-42s %-9s %-13s %-3s',
        'pid', 'Symbol(s)', 'Nazwa Subiekt (pierwsza)', 'ilosc',
        'Nazwa PS', 'Cena', 'Dostepnosc', 'act'));
    $out(str_repeat('-', 145));
    foreach ($list as $r) {
        $pid = $r['product_id'];
        $e = $enrich[$pid] ?? null;
        $out(sprintf('%-7d %-22s %-37s %-6d %-42s %-9s %-13s %-3s',
            $pid,
            mb_substr(implode(';', array_values(array_unique($r['symbols']))), 0, 22),
            mb_substr($r['names_subiekt'][0], 0, 37),
            $r['qty_sum'],
            mb_substr($psName[$pid] ?? '(brak nazwy)', 0, 42),
            $e ? number_format($e['price'], 2, '.', '') : '-',
            $e ? $e['availability'] : '-',
            $e ? ($e['active'] ? 'T' : 'N') : '-',
        ));
    }
    $out();

    if (count($agg['unmapped']) > 0) {
        $out('--- NIEZMAPOWANE (Symbol bez trafienia w pr_product) ---');
        $out(sprintf('%-22s %-55s %-6s', 'symbol', 'nazwa Subiekt', 'ilosc'));
        $out(str_repeat('-', 90));
        $unmapped = $agg['unmapped'];
        usort($unmapped, static fn($a, $b) => $b['ilosc'] <=> $a['ilosc']);
        foreach ($unmapped as $r) {
            $out(sprintf('%-22s %-55s %-6d',
                mb_substr($r['symbol'], 0, 22),
                mb_substr($r['nazwa'], 0, 55),
                $r['ilosc']));
        }
        $out();
    }
};

$printTable($aggAuto, 'AUTOMATY (12mc, mapowane wg reguly)', $enrich, $psName, $out);
$printTable($aggKomp, 'KOMPUTERY (12mc, mapowane wg reguly)', $enrich, $psName, $out);

$out('========================================================================');
$out('Plik raportu: ' . $reportFile);
$out('========================================================================');

if ($fh !== false) {
    fclose($fh);
}
