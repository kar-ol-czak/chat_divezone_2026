<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;
use DiveChat\Shop\MysqlProductEnrichmentService;

/**
 * Szczegóły produktu z MySQL PrestaShop (read-only).
 * Pobiera: nazwa, opis, cechy, cena, dostępność, zdjęcie, promocje.
 *
 * CHAT-T-062 (E5): cena LICZONA przez MysqlProductEnrichmentService — TA SAMA
 * sciezka co w ProductSearch (brutto z VAT, specific_price percentage/amount,
 * hierarchia priorytetow). Cel: ten sam product_id → ta sama cena niezaleznie
 * od narzedzia (E5 fix po ewaluacji 03.06.2026: 2940→2600 niespojnosc miedzy
 * turami przy tym samym produkcie).
 */
final class ProductDetails implements ToolInterface
{
    private const LANG_ID = 1; // polski
    private const SHOP_ID = 1;

    public function __construct(
        private readonly MysqlProductEnrichmentService $enrichment,
    ) {}

    public function getName(): string
    {
        return 'get_product_details';
    }

    public function getDescription(): string
    {
        return 'Pobiera pełną specyfikację konkretnego produktu: opis, cechy techniczne, cenę, dostępność. '
             . 'Nie zwraca wariantów (kolory, rozmiary) — do tego użyj get_product_combinations. '
             . 'Używaj gdy klient pyta o szczegóły konkretnego produktu lub potrzebujesz dokładnych danych.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'ID produktu z PrestaShop (ps_product_id z wyników search_products)',
                ],
            ],
            'required' => ['product_id'],
        ];
    }

    public function execute(array $params): array
    {
        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['error' => 'Nieprawidłowe product_id'];
        }

        $db = MysqlConnection::getInstance();

        // Podstawowe dane produktu
        $product = $db->fetchOne(
            'SELECT p.id_product, p.price, p.active, p.id_manufacturer,
                    pl.name, pl.description_short, pl.description, pl.link_rewrite,
                    pl.available_later,
                    m.name AS manufacturer_name
             FROM pr_product p
             JOIN pr_product_lang pl ON p.id_product = pl.id_product
                  AND pl.id_lang = ? AND pl.id_shop = ?
             LEFT JOIN pr_manufacturer m ON p.id_manufacturer = m.id_manufacturer
             WHERE p.id_product = ?',
            [self::LANG_ID, self::SHOP_ID, $productId],
        );

        if (!$product) {
            return ['error' => 'Produkt nie znaleziony'];
        }

        // Cechy produktu
        $features = $db->fetchAll(
            'SELECT fl.name AS feature_name, fvl.value AS feature_value
             FROM pr_feature_product fp
             JOIN pr_feature_lang fl ON fp.id_feature = fl.id_feature AND fl.id_lang = ?
             JOIN pr_feature_value_lang fvl ON fp.id_feature_value = fvl.id_feature_value AND fvl.id_lang = ?
             WHERE fp.id_product = ?',
            [self::LANG_ID, self::LANG_ID, $productId],
        );

        // Dostępność
        $stock = $db->fetchOne(
            'SELECT quantity, out_of_stock
             FROM pr_stock_available
             WHERE id_product = ? AND id_product_attribute = 0 AND id_shop = ?',
            [$productId, self::SHOP_ID],
        );

        // Zdjęcie główne
        $image = $db->fetchOne(
            'SELECT id_image FROM pr_image WHERE id_product = ? AND cover = 1',
            [$productId],
        );

        // CHAT-T-062 (E5): cena + availability + price_before_discount z TEGO SAMEGO
        // serwisu co ProductSearch. Lokalne $stock zostaje dla metadanych (quantity),
        // ale `price` w odpowiedzi ZAWSZE z enrichment — koniec dwoch sciezek liczenia ceny.
        // CHAT-T-130 (ADR-113): usuniete osobne zapytanie o pr_specific_price — nie
        // filtrowalo id_group/id_customer i wyciekalo rabaty grupowe (B2B id_group=9)
        // do zwyklych klientow (czaty 649/641). special_price liczone z enrichment.
        $enrichData = $this->enrichment->enrich([$productId]);
        $enrich = $enrichData[$productId] ?? null;

        $quantity = (int) ($stock['quantity'] ?? 0);

        // Availability: priorytet enrichment (3-stanowy spojny z ProductSearch:
        // in_stock / available_to_order / unavailable). Fallback na lokalne stock
        // tylko gdy enrichment padl (timeout MySQL z error_log).
        if ($enrich !== null) {
            $availability = $enrich['availability'];
        } else {
            $outOfStock = (int) ($stock['out_of_stock'] ?? 0);
            $availability = match (true) {
                $quantity > 0 => 'in_stock',
                $outOfStock === 1 => 'available_to_order',
                default => 'unavailable',
            };
        }

        $imageUrl = $image
            ? "https://divezone.pl/{$image['id_image']}-large_default/{$product['link_rewrite']}.jpg"
            : null;

        $productUrl = "https://divezone.pl/{$product['link_rewrite']}.html";

        $result = [
            'id' => $productId,
            'name' => $product['name'],
            'brand' => $product['manufacturer_name'],
            'description_short' => strip_tags($product['description_short'] ?? ''),
            'description' => strip_tags($product['description'] ?? ''),
            // CHAT-T-062 (E5): brutto z VAT + promocja zastosowana (enrichment).
            // Fallback na price_netto * 1.23 gdy enrichment padl — przyblizenie,
            // model lepiej dostanie cos niz NULL/netto.
            'price' => $enrich !== null
                ? $enrich['price']
                : round((float) $product['price'] * 1.23, 2),
            'price_eur' => $enrich['price_eur'] ?? null, // CHAT-T-115: dla rozmow EN
            'availability' => $availability,
            'quantity' => $quantity,
            'url' => $productUrl,
            'url_en' => $enrich['url_en'] ?? null, // CHAT-T-115: link /en/ (null gdy brak slugu EN)
            'image_url' => $imageUrl,
            'features' => array_map(fn(array $f) => [
                'name' => $f['feature_name'],
                'value' => $f['feature_value'],
            ], $features),
        ];

        // Cena przed rabatem — model moze powiedziec "przeceniony z X na Y".
        // special_price wyprowadzone z enrichment (jedno zrodlo prawdy o promocjach):
        // pojawia sie TYLKO gdy realna publiczna obnizka (id_group IN (0,1)) istnieje.
        if ($enrich !== null && isset($enrich['price_before_discount'])
            && $enrich['price_before_discount'] > 0
        ) {
            $result['price_before_discount'] = $enrich['price_before_discount'];
            $result['price_before_discount_eur'] = $enrich['price_before_discount_eur'] ?? null;
            $result['special_price'] = [
                'reduction' => round(1 - $enrich['price'] / $enrich['price_before_discount'], 4),
                'type' => 'percentage',
            ];
        }

        return $result;
    }
}
