<?php

declare(strict_types=1);

/**
 * Skrypt analityczny sprzedazy — top N produktow per kategoria rekomendacji.
 *
 * T-030 (ADR-065 uzup.1, decyzja 148a):
 *   Metryka = LICZBA ZAMOWIEN (distinct id_order) produktu z ostatnich N miesiecy
 *   (NIE sztuki, NIE przychod). Filtr zamowien zrealizowanych: pr_orders.valid=1
 *   (standard PrestaShop). Skrypt rownoczesnie pokazuje rozklad current_state
 *   wszystkich zamowien 12mc — do walidacji filtra przez zespol.
 *
 * Uruchamianie na prod (MySQL=localhost, dostep do pr_*):
 *   /opt/cpanel/ea-php84/root/usr/bin/php \
 *     /home/divezone/public_html/chat.divezone.pl/scripts/sales_report.php \
 *     [--months=12] [--top=15]
 *
 * Output:
 *   - STDOUT: czytelny raport tekstowy.
 *   - Plik: reports/sales_top_YYYYMMDD.txt (cwd na prod = chat.divezone.pl/).
 */

use DiveChat\Config;
use DiveChat\Database\MysqlConnection;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

// .env z root projektu (one level up od standalone/) lub lokalnie standalone/.env
foreach ([dirname($basePath), $basePath] as $path) {
    if (is_file($path . '/.env')) {
        Config::load($path);
        break;
    }
}

// === Parametry CLI ===
$months = 12;
$topN = 15;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--months=(\d+)$/', $arg, $m)) {
        $months = (int) $m[1];
    } elseif (preg_match('/^--top=(\d+)$/', $arg, $m)) {
        $topN = (int) $m[1];
    }
}

$LANG_PL = 1;
$db = MysqlConnection::getInstance();

// === Output sink: STDOUT + plik ===
$reportDir = $basePath . '/reports';
if (!is_dir($reportDir)) {
    @mkdir($reportDir, 0775, true);
}
$reportFile = $reportDir . '/sales_top_' . date('Ymd_His') . '.txt';
$fh = fopen($reportFile, 'w');
$out = static function (string $line = '') use ($fh): void {
    echo $line . "\n";
    if ($fh !== false) {
        fwrite($fh, $line . "\n");
    }
};

$out('========================================================================');
$out('DIVEZONE — RAPORT SPRZEDAZY (T-030, ADR-065 uzup.1)');
$out('Okres: ostatnich ' . $months . ' miesiecy | Top N: ' . $topN . ' produktow/kategoria');
$out('Metryka: liczba ZAMOWIEN (distinct id_order), filtr pr_orders.valid=1');
$out('Wygenerowano: ' . date('Y-m-d H:i:s'));
$out('========================================================================');
$out();

// ============================================================================
// SEKCJA 1: Rozklad current_state w zamowieniach z ostatnich N miesiecy
// (do walidacji filtra valid=1 z Karolem — pokazujemy WSZYSTKIE zamowienia +
//  ile z nich ma valid=1, zeby zobaczyc czy current_state odpowiada intuicji
//  "zrealizowane" wg PS)
// ============================================================================
$out('--- SEKCJA 1: ROZKLAD current_state (do walidacji filtra) ---');
$out();

$stateRows = $db->fetchAll(
    "SELECT
        o.current_state AS state_id,
        COALESCE(osl.name, '(brak nazwy)') AS state_name,
        COUNT(*) AS orders_all,
        SUM(CASE WHEN o.valid = 1 THEN 1 ELSE 0 END) AS orders_valid_1
     FROM pr_orders o
     LEFT JOIN pr_order_state_lang osl
         ON o.current_state = osl.id_order_state AND osl.id_lang = ?
     WHERE o.date_add >= DATE_SUB(NOW(), INTERVAL ? MONTH)
     GROUP BY o.current_state, osl.name
     ORDER BY orders_all DESC",
    [$LANG_PL, $months],
);

$out(sprintf('%-6s %-40s %-12s %-12s', 'state', 'name', 'orders_all', 'valid=1'));
$out(str_repeat('-', 76));
$totalAll = 0;
$totalValid = 0;
foreach ($stateRows as $r) {
    $name = mb_substr($r['state_name'] ?? '', 0, 40);
    $out(sprintf(
        '%-6s %-40s %-12s %-12s',
        $r['state_id'],
        $name,
        (string) $r['orders_all'],
        (string) $r['orders_valid_1'],
    ));
    $totalAll += (int) $r['orders_all'];
    $totalValid += (int) $r['orders_valid_1'];
}
$out(str_repeat('-', 76));
$out(sprintf('%-47s %-12d %-12d', 'RAZEM:', $totalAll, $totalValid));
$out();
$out('# WALIDACJA: czy lista current_state z valid=1 odpowiada zamowieniom');
$out('#            faktycznie zrealizowanym? Jesli np. anulowane maja valid=1');
$out('#            (custom PS) -> zmienic filtr na whitelist konkretnych state_id.');
$out();

// ============================================================================
// SEKCJA 2: Mapowanie kategorii rekomendacji -> id_category (PrestaShop)
// Tag = grupa kategorii ktore traktujemy jak jedna "kategoria rekomendacji".
// LIKE patterns wybrane wg nazw kategorii widzianych w SystemPrompt + struktury
// faktycznej pr_category_lang. Output pokazuje WSZYSTKIE dopasowane id_category
// per tag — do walidacji z Karolem.
// ============================================================================
$out('--- SEKCJA 2: MAPOWANIE KATEGORII REKOMENDACJI ---');
$out();

$categoryTags = [
    'automaty_oddechowe' => [
        'patterns' => ['Automaty Oddechowe', 'Automaty stage'],
        'note' => 'Automaty pierwszego/drugiego stopnia (rekreacja + tech) — sidemount stage osobno',
    ],
    'komputery_nurkowe' => [
        'patterns' => ['Komputer'],  // parent + branded ("Komputery SHEARWATER", "Komputery SUUNTO" itp.)
        'note' => 'Wszystkie podkategorie z prefiksem "Komputer" (parent 60 + brandowe podkategorie)',
    ],
    'maski' => [
        'patterns' => ['Maski jednoszybowe', 'Maski dwuszybowe', 'Maski panoramiczne',
                       'Maski korekcyjne', 'Maski Jedno i Dwuszybowe',
                       'Maski do nurkowania dla dzieci'],
        'note' => 'Wszystkie szczegolowe podkategorie masek (POMIJAM parent "Maski i Fajki")',
    ],
    'fajki' => [
        'patterns' => ['Fajki', 'Zestawy Maska+Fajka'],
        'note' => 'Solo fajki + zestawy maska+fajka',
    ],
    'pletwy' => [
        'patterns' => ['Płetwy Paskowe na Buta', 'Płetwy Gumowe JET', 'Płetwy Kaloszowe na Stopę'],
        'note' => 'Tylko nurkowe podkategorie (POMIJAM parent "Płetwy" i "Płetwy do pływania")',
    ],
    'pianki_mokre' => [
        'patterns' => ['Skafandry mokre', 'Skafandry Na ZIMNE wody', 'Skafandry Na CIEPŁE wody'],
        'note' => 'Pianki i komplety mokre (zimne + cieple wody)',
    ],
    'skafandry_suche' => [
        'patterns' => ['Skafandry suche'],
        'note' => 'Tylko suche (trylaminat, neopren), BEZ ocieplaczy',
    ],
    'ocieplacze' => [
        'patterns' => ['Ocieplacze do Suchych'],
        'note' => 'Ocieplacze pod suche skafandry',
    ],
    'jackety_bcd' => [
        'patterns' => ['Jackety (BCD)'],
        'note' => 'Klasyczne jackety BCD',
    ],
    'skrzydla' => [
        'patterns' => ['Skrzydła', 'Skrzydła z uprzężą do Poj. Butli', 'Skrzydła z uprzężą do Twina'],
        'note' => 'Wszystkie skrzydła (techniczne + rekreacyjne)',
    ],
    'latarki' => [
        'patterns' => ['Latarki nurkowe'],
        'note' => 'Parent + podkategorie (Male do reki, Duze z glowica, Video)',
    ],
];

$tagToCategoryIds = [];
$tagNames = [];

foreach ($categoryTags as $tag => $cfg) {
    $matchedRows = [];
    foreach ($cfg['patterns'] as $pattern) {
        // dokladne dopasowanie nazwy + descendants (parent->child) NIE tutaj —
        // bierzemy plaska liste id_category dopasowanych do LIKE pattern
        $r = $db->fetchAll(
            "SELECT id_category, name
             FROM pr_category_lang
             WHERE id_lang = ? AND name = ?",
            [$LANG_PL, $pattern],
        );
        foreach ($r as $row) {
            $matchedRows[(int) $row['id_category']] = $row['name'];
        }
    }
    // Dla 'komputery_nurkowe' dodatkowy LIKE na "Komputer%" (parent + brandowe)
    if ($tag === 'komputery_nurkowe') {
        $r = $db->fetchAll(
            "SELECT id_category, name
             FROM pr_category_lang
             WHERE id_lang = ? AND name LIKE ?",
            [$LANG_PL, 'Komputer%'],
        );
        foreach ($r as $row) {
            $matchedRows[(int) $row['id_category']] = $row['name'];
        }
    }

    $tagToCategoryIds[$tag] = array_keys($matchedRows);
    $tagNames[$tag] = $matchedRows;
    $out(sprintf('%-22s -> %d kategorii: [%s]',
        $tag,
        count($matchedRows),
        implode(', ', array_keys($matchedRows)),
    ));
    $out('  uwaga: ' . $cfg['note']);
    foreach ($matchedRows as $catId => $catName) {
        $out(sprintf('    %4d  %s', $catId, $catName));
    }
    $out();
}
$out('# WALIDACJA: czy mapowanie kategorii odpowiada intencji? Brakuje czegos?');
$out('#            (np. Twinsety/butle/oświetlenie video nie maja oddzielnego tagu)');
$out();

// ============================================================================
// SEKCJA 3: Suunto Nautic — czy istnieje w bazie?
// ============================================================================
$out('--- SEKCJA 3: SUUNTO NAUTIC W BAZIE ---');
$out();

$nautic = $db->fetchAll(
    "SELECT p.id_product, pl.name, ps.active, ps.price,
            (SELECT id_category_default FROM pr_product WHERE id_product = p.id_product) AS cat_default
     FROM pr_product_lang pl
     JOIN pr_product p ON pl.id_product = p.id_product
     JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
     WHERE pl.id_lang = ? AND pl.name LIKE ?
     ORDER BY p.id_product",
    [$LANG_PL, '%Nautic%'],
);

if (empty($nautic)) {
    $out('# Suunto Nautic: BRAK w bazie pr_product (nie zaimportowany).');
} else {
    $out(sprintf('%-8s %-50s %-7s %-10s %-8s', 'id_prod', 'name', 'active', 'price', 'cat_def'));
    $out(str_repeat('-', 90));
    foreach ($nautic as $r) {
        $out(sprintf('%-8s %-50s %-7s %-10s %-8s',
            $r['id_product'],
            mb_substr($r['name'] ?? '', 0, 50),
            $r['active'],
            number_format((float) $r['price'], 2, '.', ''),
            $r['cat_default'] ?? '-',
        ));
    }
}
$out();

// ============================================================================
// SEKCJA 4: Top N produktow per tag kategorii (12mc, distinct id_order)
// Filtr: pr_orders.valid=1, date_add >= NOW() - INTERVAL N MONTH
// Przypisanie produkt->kategoria: pr_product.id_category_default
// (jednoznaczne; mozna przelaczyc na pr_category_product all-many w przyszlosci)
// ============================================================================
$out('--- SEKCJA 4: TOP ' . $topN . ' PRODUKTOW PER KATEGORIA (' . $months . ' mies, valid=1) ---');
$out('# Metryka: liczba ZAMOWIEN (distinct id_order), nie sztuki/przychod');
$out('# Przypisanie produkt->kategoria: pr_product.id_category_default (jednoznaczne)');
$out();

foreach ($tagToCategoryIds as $tag => $catIds) {
    $out('============================================================================');
    $out('KATEGORIA: ' . $tag);
    $out('id_category: [' . implode(', ', $catIds) . ']');
    $out('============================================================================');

    if (empty($catIds)) {
        $out('(brak zmapowanych kategorii dla tagu — pomijam)');
        $out();
        continue;
    }

    $placeholders = implode(',', array_fill(0, count($catIds), '?'));

    $rows = $db->fetchAll(
        "SELECT
            od.product_id,
            COALESCE(pl.name, MAX(od.product_name)) AS name,
            COUNT(DISTINCT od.id_order) AS orders_count,
            SUM(od.product_quantity) AS qty_sum,
            ps.active AS is_active,
            ps.price AS current_price_netto
         FROM pr_order_detail od
         JOIN pr_orders o
             ON od.id_order = o.id_order
            AND o.valid = 1
            AND o.date_add >= DATE_SUB(NOW(), INTERVAL ? MONTH)
         JOIN pr_product p
             ON od.product_id = p.id_product
            AND p.id_category_default IN ({$placeholders})
         LEFT JOIN pr_product_shop ps
             ON p.id_product = ps.id_product AND ps.id_shop = 1
         LEFT JOIN pr_product_lang pl
             ON p.id_product = pl.id_product AND pl.id_lang = ?
         WHERE od.product_id > 0
         GROUP BY od.product_id
         ORDER BY orders_count DESC, qty_sum DESC
         LIMIT ?",
        array_merge([$months], $catIds, [$LANG_PL, $topN]),
    );

    if (empty($rows)) {
        $out('(brak zamowien w okresie)');
        $out();
        continue;
    }

    $out(sprintf('%-8s %-55s %-7s %-7s %-7s %-10s',
        'id_prod', 'name', 'orders', 'qty', 'active', 'price_n'));
    $out(str_repeat('-', 102));
    foreach ($rows as $r) {
        $name = mb_substr($r['name'] ?? '', 0, 55);
        $out(sprintf(
            '%-8s %-55s %-7s %-7s %-7s %-10s',
            $r['product_id'],
            $name,
            $r['orders_count'],
            $r['qty_sum'],
            $r['is_active'] ?? '-',
            $r['current_price_netto'] !== null
                ? number_format((float) $r['current_price_netto'], 2, '.', '')
                : '-',
        ));
    }
    $out();
}

$out('========================================================================');
$out('KONIEC RAPORTU');
$out('Plik: ' . $reportFile);
$out('========================================================================');

if ($fh !== false) {
    fclose($fh);
}
