<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;

/**
 * Wyszukiwanie hybrydowe produktów: 3 tory (semantic + FTS + trigram) + RRF fusion.
 * Dane z divechat_product_embeddings (PostgreSQL/pgvector).
 */
final class ProductSearch implements ToolInterface
{
    private const RRF_K = 60;
    private const TRACK_LIMIT = 30;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly PostgresConnection $db,
        private readonly SynonymExpander $synonymExpander,
    ) {}

    public function getName(): string
    {
        return 'search_products';
    }

    public function getDescription(): string
    {
        return 'Wyszukuje produkty w ofercie divezone.pl metodą semantyczną (embedding similarity). '
             . 'WAŻNE: Parametr query musi zawierać nazewnictwo sklepu divezone.pl, NIE słowa klienta ani podręcznikową terminologię. '
             . 'Przed wyszukaniem przetłumacz potrzebę klienta na nazwy kategorii i produktów sklepu (patrz NAZEWNICTWO SKLEPU w system prompcie). '
             . 'Uwzględniaj w query typ, przeznaczenie i kontekst z rozmowy.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Zapytanie w terminologii produktowej sklepu (NIE słowa klienta). '
                        . 'Przetłumacz potrzebę klienta na parametry produktu: typ, grubość, przeznaczenie. '
                        . 'Np. "skafander mokry 7mm damski" lub "automat oddechowy zimna woda DIN"',
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
                    'description' => 'Czy pokazywać tylko produkty dostępne od ręki. Domyślnie false (pokazuj też produkty na zamówienie). Ustaw true tylko gdy klient wyraźnie potrzebuje natychmiastowej dostawy.',
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
        $limit = min((int) ($params['limit'] ?? 5), 10);

        // Wspólne filtry
        $filters = $this->buildFilters($params);

        // Embedding
        $embedding = $this->embeddingService->getEmbedding($query);
        $vectorStr = '[' . implode(',', $embedding) . ']';

        // Ekspansja synonimów dla FTS
        $expandedQuery = $this->synonymExpander->expandForFts($query);

        // 3 tory wyszukiwania
        $semantic = $this->searchSemantic($vectorStr, $filters);
        $fulltext = $expandedQuery !== '' ? $this->searchFullText($expandedQuery, $filters) : [];
        $trigram = $this->searchTrigram($query, $filters);

        // Fuzja RRF
        $merged = $this->mergeRRF($semantic, $fulltext, $trigram, $limit);

        if (empty($merged['products'])) {
            return ['products' => [], 'message' => 'Nie znaleziono produktów pasujących do zapytania.'];
        }

        return $merged;
    }

    /**
     * Buduje wspólne warunki WHERE + parametry dla 3 torów.
     * @return array{where: string, params: list<mixed>}
     */
    private function buildFilters(array $params): array
    {
        $conditions = ['is_active = true'];
        $sqlParams = [];

        if (!empty($params['in_stock_only'])) {
            $conditions[] = 'in_stock = true';
        }

        if (isset($params['category'])) {
            $sqlParams[] = $params['category'];
            $conditions[] = "category_name ILIKE '%' || ? || '%'";
        }

        if (isset($params['min_price'])) {
            $sqlParams[] = (float) $params['min_price'];
            $conditions[] = 'price >= ?';
        }

        if (isset($params['max_price'])) {
            $sqlParams[] = (float) $params['max_price'];
            $conditions[] = 'price <= ?';
        }

        if (isset($params['brand'])) {
            $sqlParams[] = $params['brand'];
            $conditions[] = "brand_name ILIKE '%' || ? || '%'";
        }

        return [
            'where' => implode(' AND ', $conditions),
            'params' => $sqlParams,
        ];
    }

    /**
     * Tor 1: Embedding cosine similarity (pgvector).
     * @return list<array{ps_product_id: int, rank: int, similarity: float}>
     */
    private function searchSemantic(string $vectorStr, array $filters): array
    {
        $params = [$vectorStr, ...$filters['params'], $vectorStr];
        $trackLimit = self::TRACK_LIMIT;

        $sql = "SELECT ps_product_id,
                       1 - (embedding <=> ?::vector) AS similarity
                FROM divechat_product_embeddings
                WHERE {$filters['where']}
                ORDER BY embedding <=> ?::vector
                LIMIT {$trackLimit}";

        $rows = $this->db->fetchAll($sql, $params);

        $results = [];
        foreach ($rows as $rank => $row) {
            $results[] = [
                'ps_product_id' => (int) $row['ps_product_id'],
                'rank' => $rank + 1,
                'similarity' => (float) $row['similarity'],
            ];
        }

        return $results;
    }

    /**
     * Tor 2: Full-text search z unaccent (diving_simple config).
     * @return list<array{ps_product_id: int, rank: int, ts_rank: float}>
     */
    private function searchFullText(string $expandedQuery, array $filters): array
    {
        $trackLimit = self::TRACK_LIMIT;

        // Próbuj to_tsquery z ręcznie zbudowanym tsquery string
        $params = [$expandedQuery, ...$filters['params'], $expandedQuery];
        $sql = "SELECT ps_product_id,
                       ts_rank(fts_vector, to_tsquery('diving_simple', ?)) AS ts_rank
                FROM divechat_product_embeddings
                WHERE {$filters['where']}
                  AND fts_vector @@ to_tsquery('diving_simple', ?)
                ORDER BY ts_rank DESC
                LIMIT {$trackLimit}";

        try {
            $rows = $this->db->fetchAll($sql, $params);
        } catch (\PDOException) {
            // Fallback na plainto_tsquery przy błędzie składni
            $params = [$expandedQuery, ...$filters['params'], $expandedQuery];
            $sql = "SELECT ps_product_id,
                           ts_rank(fts_vector, plainto_tsquery('diving_simple', ?)) AS ts_rank
                    FROM divechat_product_embeddings
                    WHERE {$filters['where']}
                      AND fts_vector @@ plainto_tsquery('diving_simple', ?)
                    ORDER BY ts_rank DESC
                    LIMIT {$trackLimit}";
            $rows = $this->db->fetchAll($sql, $params);
        }

        $results = [];
        foreach ($rows as $rank => $row) {
            $results[] = [
                'ps_product_id' => (int) $row['ps_product_id'],
                'rank' => $rank + 1,
                'ts_rank' => (float) $row['ts_rank'],
            ];
        }

        return $results;
    }

    /**
     * Tor 3: Trigram fuzzy matching na product_name i brand_name.
     * @return list<array{ps_product_id: int, rank: int, trgm_score: float}>
     */
    private function searchTrigram(string $query, array $filters): array
    {
        $trackLimit = self::TRACK_LIMIT;

        $params = [$query, $query, ...$filters['params'], $query, $query];
        $sql = "SELECT ps_product_id,
                       GREATEST(
                           similarity(product_name, ?),
                           similarity(brand_name, ?)
                       ) AS trgm_score
                FROM divechat_product_embeddings
                WHERE {$filters['where']}
                  AND (similarity(product_name, ?) > 0.15 OR similarity(brand_name, ?) > 0.25)
                ORDER BY trgm_score DESC
                LIMIT {$trackLimit}";

        $rows = $this->db->fetchAll($sql, $params);

        $results = [];
        foreach ($rows as $rank => $row) {
            $results[] = [
                'ps_product_id' => (int) $row['ps_product_id'],
                'rank' => $rank + 1,
                'trgm_score' => (float) $row['trgm_score'],
            ];
        }

        return $results;
    }

    /**
     * Reciprocal Rank Fusion: łączy wyniki 3 torów.
     * RRF score = sum(1 / (K + rank_i)) dla każdego toru.
     * @return array{products: list<array>, count: int, search_debug: array}
     */
    private function mergeRRF(array $semantic, array $fulltext, array $trigram, int $limit): array
    {
        $k = self::RRF_K;
        $scores = [];
        $trackInfo = [];

        // Semantic scores
        foreach ($semantic as $item) {
            $id = $item['ps_product_id'];
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + $item['rank']);
            $trackInfo[$id]['semantic_rank'] = $item['rank'];
            $trackInfo[$id]['semantic_sim'] = $item['similarity'];
        }

        // Fulltext scores
        foreach ($fulltext as $item) {
            $id = $item['ps_product_id'];
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + $item['rank']);
            $trackInfo[$id]['fulltext_rank'] = $item['rank'];
            $trackInfo[$id]['fulltext_ts_rank'] = $item['ts_rank'];
        }

        // Trigram scores
        foreach ($trigram as $item) {
            $id = $item['ps_product_id'];
            $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + $item['rank']);
            $trackInfo[$id]['trigram_rank'] = $item['rank'];
            $trackInfo[$id]['trigram_score'] = $item['trgm_score'];
        }

        if (empty($scores)) {
            return ['products' => [], 'count' => 0, 'search_debug' => []];
        }

        // Sortuj po RRF score malejąco
        arsort($scores);

        // Top N product IDs
        $topIds = array_slice(array_keys($scores), 0, $limit);

        if (empty($topIds)) {
            return ['products' => [], 'count' => 0, 'search_debug' => []];
        }

        // Pobierz pełne dane produktów
        $placeholders = implode(',', array_fill(0, count($topIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT ps_product_id, product_name, brand_name, category_name,
                    price, in_stock, product_url, image_url
             FROM divechat_product_embeddings
             WHERE ps_product_id IN ({$placeholders})",
            $topIds
        );

        // Indeksuj po ID
        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row['ps_product_id']] = $row;
        }

        // Buduj wyniki w kolejności RRF
        $products = [];
        $debugItems = [];
        foreach ($topIds as $id) {
            if (!isset($rowsById[$id])) {
                continue;
            }
            $row = $rowsById[$id];
            $rrfScore = $scores[$id];
            $info = $trackInfo[$id] ?? [];

            // Dominujący tor
            $dominant = 'semantic';
            $bestContrib = 0;
            if (isset($info['semantic_rank'])) {
                $contrib = 1.0 / ($k + $info['semantic_rank']);
                if ($contrib > $bestContrib) { $bestContrib = $contrib; $dominant = 'semantic'; }
            }
            if (isset($info['fulltext_rank'])) {
                $contrib = 1.0 / ($k + $info['fulltext_rank']);
                if ($contrib > $bestContrib) { $bestContrib = $contrib; $dominant = 'fulltext'; }
            }
            if (isset($info['trigram_rank'])) {
                $contrib = 1.0 / ($k + $info['trigram_rank']);
                if ($contrib > $bestContrib) { $bestContrib = $contrib; $dominant = 'trigram'; }
            }

            $products[] = [
                'id' => (int) $row['ps_product_id'],
                'name' => $row['product_name'],
                'brand' => $row['brand_name'],
                'category' => $row['category_name'],
                'price' => (float) $row['price'],
                'in_stock' => (bool) $row['in_stock'],
                'url' => $row['product_url'],
                'image_url' => $row['image_url'],
                'similarity' => round($rrfScore, 4),
            ];

            $debugItems[] = [
                'product_id' => $id,
                'rrf_score' => round($rrfScore, 6),
                'dominant_track' => $dominant,
                'semantic_rank' => $info['semantic_rank'] ?? null,
                'fulltext_rank' => $info['fulltext_rank'] ?? null,
                'trigram_rank' => $info['trigram_rank'] ?? null,
            ];
        }

        return [
            'products' => $products,
            'count' => count($products),
            'search_debug' => [
                'tracks' => [
                    'semantic_count' => count($semantic),
                    'fulltext_count' => count($fulltext),
                    'trigram_count' => count($trigram),
                ],
                'rrf_k' => $k,
                'items' => $debugItems,
            ],
        ];
    }
}
