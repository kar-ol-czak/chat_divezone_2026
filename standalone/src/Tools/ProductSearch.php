<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;

/**
 * Wyszukiwanie hybrydowe produktów: embedding similarity + filtry SQL.
 * Dane z divechat_product_embeddings (PostgreSQL/pgvector).
 */
final class ProductSearch implements ToolInterface
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly PostgresConnection $db,
    ) {}

    public function getName(): string
    {
        return 'search_products';
    }

    public function getDescription(): string
    {
        return 'Wyszukuje produkty w ofercie divezone.pl na podstawie opisu, kategorii, ceny i marki. '
             . 'Zwraca listę pasujących produktów z cenami i dostępnością. '
             . 'Używaj gdy klient szuka produktu, pyta o rekomendację lub porównanie.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Opis czego szuka klient, np. "maska do freedivingu" lub "automat oddechowy do zimnej wody"',
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Filtr kategorii, np. "Maski", "Automaty", "Komputery nurkowe"',
                ],
                'min_price' => [
                    'type' => 'number',
                    'description' => 'Minimalna cena w PLN',
                ],
                'max_price' => [
                    'type' => 'number',
                    'description' => 'Maksymalna cena w PLN',
                ],
                'brand' => [
                    'type' => 'string',
                    'description' => 'Filtr marki, np. "SCUBAPRO", "TECLINE"',
                ],
                'in_stock_only' => [
                    'type' => 'boolean',
                    'description' => 'Czy pokazywać tylko produkty dostępne od ręki (domyślnie true)',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Liczba wyników (domyślnie 5, max 10)',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $params): array
    {
        $query = $params['query'] ?? '';
        $category = $params['category'] ?? null;
        $minPrice = isset($params['min_price']) ? (float) $params['min_price'] : null;
        $maxPrice = isset($params['max_price']) ? (float) $params['max_price'] : null;
        $brand = $params['brand'] ?? null;
        $inStockOnly = $params['in_stock_only'] ?? true;
        $limit = min((int) ($params['limit'] ?? 5), 10);

        // Generuj embedding zapytania
        $embedding = $this->embeddingService->getEmbedding($query);
        $vectorStr = '[' . implode(',', $embedding) . ']';

        // Buduj zapytanie dynamicznie
        $conditions = ['is_active = true'];
        $sqlParams = [$vectorStr];

        if ($inStockOnly) {
            $conditions[] = 'in_stock = true';
        }

        if ($category !== null) {
            $sqlParams[] = $category;
            $conditions[] = 'category_name ILIKE \'%\' || ? || \'%\'';
        }

        if ($minPrice !== null) {
            $sqlParams[] = $minPrice;
            $conditions[] = 'price >= ?';
        }

        if ($maxPrice !== null) {
            $sqlParams[] = $maxPrice;
            $conditions[] = 'price <= ?';
        }

        if ($brand !== null) {
            $sqlParams[] = $brand;
            $conditions[] = 'brand_name ILIKE \'%\' || ? || \'%\'';
        }

        $where = implode(' AND ', $conditions);

        $sql = "SELECT ps_product_id, product_name, brand_name, category_name,
                       price, in_stock, product_url, image_url,
                       1 - (embedding <=> ?::vector) AS similarity
                FROM divechat_product_embeddings
                WHERE {$where}
                ORDER BY embedding <=> ?::vector
                LIMIT {$limit}";

        // Wektor potrzebny dwa razy (w SELECT i ORDER BY)
        $sqlParams[] = $vectorStr;

        $results = $this->db->fetchAll($sql, $sqlParams);

        if (empty($results)) {
            return ['products' => [], 'message' => 'Nie znaleziono produktów pasujących do zapytania.'];
        }

        return [
            'products' => array_map(fn(array $row) => [
                'id' => (int) $row['ps_product_id'],
                'name' => $row['product_name'],
                'brand' => $row['brand_name'],
                'category' => $row['category_name'],
                'price' => (float) $row['price'],
                'in_stock' => (bool) $row['in_stock'],
                'url' => $row['product_url'],
                'image_url' => $row['image_url'],
                'similarity' => round((float) $row['similarity'], 3),
            ], $results),
            'count' => count($results),
        ];
    }
}
