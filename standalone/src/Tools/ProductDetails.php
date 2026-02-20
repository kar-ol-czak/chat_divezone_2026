<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;

/**
 * Szczegóły produktu z MySQL PrestaShop (read-only).
 * Pobiera: nazwa, opis, cechy, cena, dostępność, zdjęcie, promocje.
 */
final class ProductDetails implements ToolInterface
{
    private const LANG_ID = 1; // polski
    private const SHOP_ID = 1;

    public function getName(): string
    {
        return 'get_product_details';
    }

    public function getDescription(): string
    {
        return 'Pobiera pełną specyfikację konkretnego produktu: opis, cechy techniczne, cenę, dostępność, warianty. '
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

        // Promocje
        $specialPrice = $db->fetchOne(
            'SELECT reduction, reduction_type, from_quantity,
                    `from` AS date_from, `to` AS date_to
             FROM pr_specific_price
             WHERE id_product = ? AND id_shop IN (0, ?)
               AND (`from` = "0000-00-00 00:00:00" OR `from` <= NOW())
               AND (`to` = "0000-00-00 00:00:00" OR `to` >= NOW())
             ORDER BY from_quantity ASC
             LIMIT 1',
            [$productId, self::SHOP_ID],
        );

        // Buduj odpowiedź
        $quantity = (int) ($stock['quantity'] ?? 0);
        $outOfStock = (int) ($stock['out_of_stock'] ?? 0);

        $availability = match (true) {
            $quantity > 0 => 'Dostępny od ręki',
            $outOfStock === 0 => 'Niedostępny',
            default => $product['available_later'] ?: 'Na zamówienie',
        };

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
            'price' => (float) $product['price'],
            'availability' => $availability,
            'quantity' => $quantity,
            'url' => $productUrl,
            'image_url' => $imageUrl,
            'features' => array_map(fn(array $f) => [
                'name' => $f['feature_name'],
                'value' => $f['feature_value'],
            ], $features),
        ];

        if ($specialPrice) {
            $result['special_price'] = [
                'reduction' => (float) $specialPrice['reduction'],
                'type' => $specialPrice['reduction_type'],
            ];
        }

        return $result;
    }
}
