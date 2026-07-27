<?php

declare(strict_types=1);

namespace DiveChat\Shop;

use DiveChat\Database\MysqlConnection;

/**
 * Enrichment danych produktow z MySQL PrestaShop: cena brutto z VAT,
 * dostepnosc (in_stock / available_to_order / unavailable wg PS out_of_stock 0/1/2),
 * status active + visibility, aktywne promocje z pr_specific_price.
 *
 * Wydzielone z ProductSearch::enrichWithMySQLData w T-029 (ADR-065 MVP) jako
 * wspolne zrodlo dla ProductSearch i CuratedRecommendations. Logika identyczna
 * do tej z ProductSearch przed refaktorem — szanuje 3 stany PS out_of_stock
 * (0=deny, 1=allow, 2=use PS_ORDER_OUT_OF_STOCK global) i hierarchie priorytetow
 * specific_price (id_shop > 0 wygrywa, id_group > 0 wygrywa).
 *
 * Falla MySQL (timeout / odmowa polaczenia) -> error_log + zwroc pusta tablice.
 * Wywolujacy decyduje co zrobic z brakiem danych (ProductSearch: fallback na
 * dane pgvector; CuratedRecommendations: pomijaj rekomendacje bez enrichment).
 */
final class MysqlProductEnrichmentService
{
    /**
     * Kurs PLN→EUR (id_currency=2) z pr_currency — czytany RAZ, cache per instancja
     * (CHAT-T-115, ADR-106). NIE zaszyty w kodzie. null = EUR niedostepne.
     */
    private ?float $eurRate = null;
    private bool $eurRateLoaded = false;

    /**
     * @param list<int> $productIds
     * @return array<int, array{
     *     price: float,
     *     price_eur: float|null,
     *     in_stock: bool,
     *     availability: string,
     *     quantity: int,
     *     active: bool,
     *     visible: bool,
     *     available_for_order: bool,
     *     name: string|null,
     *     url: string|null,
     *     url_en: string|null,
     *     price_before_discount?: float,
     *     price_before_discount_eur?: float|null
     * }>
     */
    public function enrich(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        try {
            $mysql = MysqlConnection::getInstance();
        } catch (\Throwable $e) {
            error_log("[DiveChat] MySQL enrichment failed: {$e->getMessage()}");
            return [];
        }

        // Globalna konfiguracja: czy sklep pozwala zamawiac niedostepne produkty (PS_ORDER_OUT_OF_STOCK).
        // Uzywana gdy produkt ma out_of_stock=2 (use default behavior — PrestaShop konwencja).
        // Wartosc pobierana per request — moze sie zmieniac w PS admin bez restartu.
        $globalAllowOos = (int) (
            $mysql->fetchOne(
                "SELECT value FROM pr_configuration WHERE name = 'PS_ORDER_OUT_OF_STOCK' LIMIT 1"
            )['value'] ?? 0
        );

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        // Glowne dane produktow (cena bazowa netto, stan, visibility).
        // CASE availability respektuje 3 stany PrestaShop out_of_stock (0=deny, 1=allow, 2=use default).
        $rows = $mysql->fetchAll(
            "SELECT
                p.id_product,
                ps.price AS price_netto,
                COALESCE(t.rate, 23) AS tax_rate,
                COALESCE(sa.total_qty, 0) AS quantity,
                CASE
                    WHEN COALESCE(sa.total_qty, 0) > 0 THEN 'in_stock'
                    WHEN sa.allow_oos = 1 THEN 'available_to_order'
                    WHEN sa.allow_oos = 0 THEN 'unavailable'
                    WHEN (sa.allow_oos IS NULL OR sa.allow_oos = 2) AND ? = 1 THEN 'available_to_order'
                    ELSE 'unavailable'
                END AS availability,
                ps.active,
                ps.visibility,
                ps.available_for_order,
                plen.link_rewrite AS link_rewrite_en,
                plpl.name AS name_pl,
                plpl.link_rewrite AS link_rewrite_pl
            FROM pr_product p
            JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1
            LEFT JOIN (
                SELECT id_product,
                       MAX(quantity) as total_qty,
                       MAX(out_of_stock) as allow_oos
                FROM pr_stock_available
                GROUP BY id_product
            ) sa ON p.id_product = sa.id_product
            LEFT JOIN pr_tax_rule tr ON p.id_tax_rules_group = tr.id_tax_rules_group
                AND tr.id_country = 14
            LEFT JOIN pr_tax t ON tr.id_tax = t.id_tax
            LEFT JOIN pr_product_lang plen ON p.id_product = plen.id_product
                AND plen.id_lang = 3 AND plen.id_shop = 1
            LEFT JOIN pr_product_lang plpl ON p.id_product = plpl.id_product
                AND plpl.id_lang = 1 AND plpl.id_shop = 1
            WHERE p.id_product IN ({$placeholders})",
            array_merge([$globalAllowOos], $productIds),
        );

        $specificPrices = $this->fetchSpecificPrices($mysql, $productIds);
        $eurRate = $this->eurRate($mysql); // CHAT-T-115: jeden kurs dla calej puli (nie per produkt)

        $dataById = [];
        foreach ($rows as $row) {
            $productId = (int) $row['id_product'];
            $priceNetto = (float) $row['price_netto'];
            $taxRate = (float) $row['tax_rate'];
            $availability = $row['availability'] ?? 'unavailable';

            $priced = self::computeBruttoPrice(
                $priceNetto,
                $taxRate,
                $specificPrices[$productId] ?? null,
            );

            // CHAT-T-115: link EN z pr_product_lang id_lang=3. Brak slugu -> null
            // (NIE budujemy martwego /en/ z PL slugu — slug EN jest INNY niz PL).
            $slugEn = $row['link_rewrite_en'] ?? null;
            $urlEn = ($slugEn !== null && $slugEn !== '')
                ? 'https://divezone.pl/en/' . $slugEn . '.html'
                : null;

            // CHAT-T-139 (ADR-121): nazwa + URL PL z pr_product_lang id_lang=1.
            // Pusty/NULL slug -> url=null (guard jak w PopularProducts) — bot ma
            // nie dostac linku wcale, zamiast dostac gola domene lub /.html.
            $namePl = $row['name_pl'] ?? null;

            $entry = [
                'price' => $priced['price'],
                // CHAT-T-115: cena EUR = brutto PLN * kurs z bazy, half-up 2 miejsca (jak strona /en).
                'price_eur' => $eurRate !== null ? round($priced['price'] * $eurRate, 2) : null,
                'in_stock' => $availability !== 'unavailable',
                'availability' => $availability,
                'quantity' => (int) $row['quantity'],
                'active' => (bool) $row['active'],
                'visible' => $row['visibility'] !== 'none',
                // CHAT-T-143 (ADR-123): afo=0 = produkt wycofany ze sprzedazy (szary
                // przycisk koszyka). NIE mylic z quantity=0 (brak stanu, "zamowimy").
                'available_for_order' => (bool) $row['available_for_order'],
                'name' => ($namePl !== null && $namePl !== '') ? (string) $namePl : null,
                'url' => self::buildProductUrl($row['link_rewrite_pl'] ?? null),
                'url_en' => $urlEn,
            ];

            if ($priced['has_promo'] && $priced['base_brutto'] > $priced['price']) {
                $entry['price_before_discount'] = $priced['base_brutto'];
                $entry['price_before_discount_eur'] = $eurRate !== null
                    ? round($priced['base_brutto'] * $eurRate, 2)
                    : null;
            }

            $dataById[$productId] = $entry;
        }

        return $dataById;
    }

    /**
     * URL produktu PL z slugu (CHAT-T-139, ADR-121). Guard na pusty/NULL slug -> null:
     * NIGDY gola domena, NIGDY divezone.pl/.html — brak danych ma byc jawny (null),
     * bo link "prawie poprawny" bot dopelni zmysleniem. Public static jak
     * computeBruttoPrice — testowalna bez MySQL (tests/Shop/ProductUrlTest.php).
     */
    public static function buildProductUrl(?string $slug): ?string
    {
        return ($slug !== null && $slug !== '')
            ? "https://divezone.pl/{$slug}.html"
            : null;
    }

    /**
     * Kurs PLN→EUR z pr_currency (id_currency=2), czytany RAZ (cache per instancja,
     * CHAT-T-115/ADR-106). NIE zaszyty w kodzie — ma nadążać za zmianami kursu w PS.
     * Zwraca null gdy EUR niedostepne -> price_eur=null (NIE pokazujemy blednej ceny 0).
     */
    private function eurRate(MysqlConnection $mysql): ?float
    {
        if (!$this->eurRateLoaded) {
            $this->eurRateLoaded = true;
            try {
                $row = $mysql->fetchOne(
                    "SELECT conversion_rate FROM pr_currency WHERE id_currency = 2 LIMIT 1"
                );
                $this->eurRate = ($row !== null && $row['conversion_rate'] !== null)
                    ? (float) $row['conversion_rate']
                    : null;
            } catch (\Throwable $e) {
                error_log("[DiveChat] EUR rate read failed: {$e->getMessage()}");
                $this->eurRate = null;
            }
        }

        return $this->eurRate;
    }

    /**
     * Czysta kalkulacja ceny brutto z uwzględnieniem promocji (ADR-093: reduction_tax).
     * Wydzielona z enrich() by była deterministycznie testowalna bez MySQL.
     *
     * Reguły:
     * - `amount` + `reduction_tax=1` (rabat BRUTTO): odejmij kwotę od ceny brutto (baseBrutto − reduction).
     *   NIE odejmuj od netto i NIE mnóż rabatu przez VAT — to zaniżało cenę o reduction×rate (bug 98 produktów).
     * - `amount` + `reduction_tax=0` (rabat NETTO): odejmij od netto, potem dolicz VAT (zachowanie dotychczasowe).
     * - `percentage`: działa identycznie na netto i brutto (bez zmian).
     * - sp_price > 0 nadpisuje cenę katalogową jako bazę promocji.
     *
     * @param array{sp_price: float, reduction: float, reduction_type: string, reduction_tax: int}|null $sp
     * @return array{price: float, base_brutto: float, has_promo: bool}
     */
    public static function computeBruttoPrice(float $priceNetto, float $taxRate, ?array $sp): array
    {
        // Cena bazowa brutto z katalogu (do price_before_discount).
        $baseBrutto = round($priceNetto * (1 + $taxRate / 100), 2);

        $priceBrutto = $baseBrutto;
        $hasPromo = false;

        if ($sp !== null) {
            $hasPromo = true;

            // $base = netto bazowe promocji (sp_price nadpisuje cenę katalogową, jeśli > 0).
            $base = ($sp['sp_price'] > 0) ? $sp['sp_price'] : $priceNetto;
            $baseBruttoPromo = round($base * (1 + $taxRate / 100), 2);

            if ($sp['reduction'] > 0) {
                if ($sp['reduction_type'] === 'amount' && $sp['reduction_tax'] === 1) {
                    // Rabat kwotowy BRUTTO — odejmij kwotę od ceny brutto.
                    $priceBrutto = round($baseBruttoPromo - $sp['reduction'], 2);
                } else {
                    // percentage ORAZ amount+reduction_tax=0 (rabat NETTO): licz na netto, potem VAT.
                    $finalNetto = match ($sp['reduction_type']) {
                        'percentage' => $base * (1 - $sp['reduction']),
                        'amount' => $base - $sp['reduction'],
                        default => $base,
                    };
                    $priceBrutto = round($finalNetto * (1 + $taxRate / 100), 2);
                }
            } else {
                // Brak rabatu, ale sp_price mógł nadpisać cenę bazową.
                $priceBrutto = $baseBruttoPromo;
            }
        }

        return [
            'price' => $priceBrutto,
            'base_brutto' => $baseBrutto,
            'has_promo' => $hasPromo,
        ];
    }

    /**
     * Pobiera najlepsza aktywna specific_price per produkt.
     * Priorytet PS: id_shop > 0 wygrywa z id_shop = 0, id_group > 0 wygrywa z id_group = 0.
     *
     * @param list<int> $productIds
     * @return array<int, array{sp_price: float, reduction: float, reduction_type: string, reduction_tax: int}>
     */
    private function fetchSpecificPrices(MysqlConnection $mysql, array $productIds): array
    {
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        $rows = $mysql->fetchAll(
            "SELECT
                sp.id_product,
                sp.price AS sp_price,
                sp.reduction,
                sp.reduction_type,
                sp.reduction_tax,
                sp.id_shop,
                sp.id_group
            FROM pr_specific_price sp
            WHERE sp.id_product IN ({$placeholders})
              AND sp.id_shop IN (0, 1)
              AND sp.id_customer = 0
              AND sp.id_group IN (0, 1)
              AND sp.from_quantity <= 1
              AND sp.id_product_attribute = 0
              AND (sp.`from` = '0000-00-00 00:00:00' OR sp.`from` <= NOW())
              AND (sp.`to` = '0000-00-00 00:00:00' OR sp.`to` >= NOW())
            ORDER BY sp.id_shop DESC, sp.id_group DESC",
            $productIds,
        );

        $bestByProduct = [];
        foreach ($rows as $row) {
            $productId = (int) $row['id_product'];
            if (isset($bestByProduct[$productId])) {
                continue;
            }
            $bestByProduct[$productId] = [
                'sp_price' => (float) $row['sp_price'],
                'reduction' => (float) $row['reduction'],
                'reduction_type' => $row['reduction_type'],
                'reduction_tax' => (int) $row['reduction_tax'],
            ];
        }

        return $bestByProduct;
    }
}
