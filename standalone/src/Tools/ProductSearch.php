<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;

/**
 * Wyszukiwanie hybrydowe produktów: 5 torów (3× semantic + FTS + trigram) + RRF fusion.
 * Semantic: embedding_name (nawigacyjne), embedding_desc (eksploracyjne), embedding_jargon (slang).
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
        return 'Wyszukaj produkty w sklepie divezone.pl. ZAWSZE wypełnij search_plan przed wyszukaniem. '
             . 'Parametr query musi zawierać nazewnictwo sklepu, NIE słowa klienta. '
             . 'Patrz sekcja JAK SZUKAĆ PRODUKTÓW w system prompcie.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Tekst zapytania w terminologii sklepu (NIE słowa klienta).',
                ],
                'search_plan' => [
                    'type' => 'object',
                    'description' => 'Plan wyszukiwania — ZAWSZE wypełnij przed szukaniem.',
                    'properties' => [
                        'intent' => [
                            'type' => 'string',
                            'enum' => ['navigational', 'exploratory'],
                            'description' => 'navigational: klient zna produkt/markę/model. exploratory: szuka porady, nie wie czego dokładnie szuka.',
                        ],
                        'reasoning' => [
                            'type' => 'string',
                            'description' => '1-2 zdania: co klient potrzebuje, dlaczego taki query, jaka kategoria.',
                        ],
                        'exact_keywords' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Nazwy własne, modele, marki do literalnego dopasowania. Np: ["Shearwater", "Teric"]',
                        ],
                    ],
                    'required' => ['intent', 'reasoning'],
                ],
                'category' => [
                    'type' => 'string',
                    'description' => 'Nazwa kategorii ze sklepu. WYMAGANE przy intent=exploratory.',
                ],
                'filters' => [
                    'type' => 'object',
                    'properties' => [
                        'price_min' => ['type' => 'number', 'description' => 'Minimalna cena PLN'],
                        'price_max' => ['type' => 'number', 'description' => 'Maksymalna cena PLN'],
                        'brand' => ['type' => 'string', 'description' => 'Filtr marki'],
                        'in_stock_only' => [
                            'type' => 'boolean',
                            'description' => 'Tylko produkty dostępne od ręki. Domyślnie false.',
                        ],
                        'exclude_categories' => [
                            'type' => 'array',
                            'items' => ['type' => 'string'],
                            'description' => 'Kategorie do WYKLUCZENIA. Używaj gdy klient mówi "nie szukam X".',
                        ],
                    ],
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'Liczba wyników (1-10, domyślnie 5).',
                ],
            ],
            'required' => ['query', 'search_plan'],
        ];
    }

    public function execute(array $params): array
    {
        $query = $params['query'] ?? '';
        $limit = min((int) ($params['limit'] ?? 5), 10);
        $searchPlan = $params['search_plan'] ?? [];
        $filtersInput = $params['filters'] ?? [];

        // Migracja starych parametrów (backward compat)
        $normalized = $this->normalizeParams($params, $filtersInput);

        // Wspólne filtry SQL
        $filters = $this->buildFilters($normalized);

        // Embedding
        $embedding = $this->embeddingService->getEmbedding($query);
        $vectorStr = '[' . implode(',', $embedding) . ']';

        // Ekspansja synonimów dla FTS
        $expandedQuery = $this->synonymExpander->expandForFts($query);

        // exact_keywords boost: doklejenie do trigram query
        $exactKeywords = $searchPlan['exact_keywords'] ?? [];
        $trigramQuery = !empty($exactKeywords) ? implode(' ', $exactKeywords) : $query;

        // 5 torów wyszukiwania: 3× semantic (name, desc, jargon) + FTS + trigram
        $semanticName = $this->searchSemanticColumn('embedding_name', $vectorStr, $filters);
        $semanticDesc = $this->searchSemanticColumn('embedding_desc', $vectorStr, $filters);
        $semanticJargon = $this->searchSemanticColumn('embedding_jargon', $vectorStr, $filters);
        $fulltext = $expandedQuery !== '' ? $this->searchFullText($expandedQuery, $filters) : [];
        $trigram = $this->searchTrigram($trigramQuery, $filters);

        // Fuzja RRF (5 torów)
        $merged = $this->mergeRRF($semanticName, $semanticDesc, $semanticJargon, $fulltext, $trigram, $limit);

        // Dołącz search_plan do debug info
        if (!empty($searchPlan)) {
            $merged['search_debug']['search_plan'] = $searchPlan;
        }

        if (empty($merged['products'])) {
            return [
                'products' => [],
                'message' => 'Nie znaleziono produktów pasujących do zapytania.',
                'search_debug' => $merged['search_debug'] ?? [],
            ];
        }

        return $merged;
    }

    /**
     * Normalizuje parametry: obsługuje nowy format (filters object) i stary (flat params).
     */
    private function normalizeParams(array $params, array $filtersInput): array
    {
        return [
            'category' => $params['category'] ?? null,
            'min_price' => $filtersInput['price_min'] ?? $params['min_price'] ?? null,
            'max_price' => $filtersInput['price_max'] ?? $params['max_price'] ?? null,
            'brand' => $filtersInput['brand'] ?? $params['brand'] ?? null,
            'in_stock_only' => $filtersInput['in_stock_only'] ?? $params['in_stock_only'] ?? false,
            'exclude_categories' => $filtersInput['exclude_categories'] ?? [],
        ];
    }

    /**
     * Buduje wspólne warunki WHERE + parametry dla 3 torów.
     * Obsługuje parent_category_name (ADR-027) i exclude_categories.
     * @return array{where: string, params: list<mixed>}
     */
    private function buildFilters(array $params): array
    {
        $conditions = ['is_active = true'];
        $sqlParams = [];

        if (!empty($params['in_stock_only'])) {
            $conditions[] = 'in_stock = true';
        }

        // ADR-027: filtr category działa na parent i child
        if (!empty($params['category'])) {
            $sqlParams[] = $params['category'];
            $sqlParams[] = $params['category'];
            $conditions[] = "(category_name ILIKE '%' || ? || '%' OR parent_category_name ILIKE '%' || ? || '%')";
        }

        if (!empty($params['min_price'])) {
            $sqlParams[] = (float) $params['min_price'];
            $conditions[] = 'price >= ?';
        }

        if (!empty($params['max_price'])) {
            $sqlParams[] = (float) $params['max_price'];
            $conditions[] = 'price <= ?';
        }

        if (!empty($params['brand'])) {
            $sqlParams[] = $params['brand'];
            $conditions[] = "brand_name ILIKE '%' || ? || '%'";
        }

        // exclude_categories: wykluczenie po category_name i parent_category_name
        $excludeCats = $params['exclude_categories'] ?? [];
        if (!empty($excludeCats)) {
            $placeholders = implode(',', array_fill(0, count($excludeCats), '?'));
            foreach ($excludeCats as $cat) {
                $sqlParams[] = $cat;
            }
            $conditions[] = "category_name NOT IN ({$placeholders})";

            // Wyklucz też po parent
            $placeholders2 = implode(',', array_fill(0, count($excludeCats), '?'));
            foreach ($excludeCats as $cat) {
                $sqlParams[] = $cat;
            }
            $conditions[] = "(parent_category_name IS NULL OR parent_category_name NOT IN ({$placeholders2}))";
        }

        return [
            'where' => implode(' AND ', $conditions),
            'params' => $sqlParams,
        ];
    }

    /**
     * Tor semantyczny na wskazanej kolumnie wektorowej (pgvector cosine).
     * @param string $column Nazwa kolumny: embedding_name, embedding_desc, embedding_jargon
     * @return list<array{ps_product_id: int, rank: int, similarity: float}>
     */
    private function searchSemanticColumn(string $column, string $vectorStr, array $filters): array
    {
        $params = [$vectorStr, ...$filters['params'], $vectorStr];
        $trackLimit = self::TRACK_LIMIT;

        $sql = "SELECT ps_product_id,
                       1 - ({$column} <=> ?::vector) AS similarity
                FROM divechat_product_embeddings
                WHERE {$filters['where']}
                  AND {$column} IS NOT NULL
                ORDER BY {$column} <=> ?::vector
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
     * Reciprocal Rank Fusion: łączy wyniki 5 torów.
     * RRF score = sum(1 / (K + rank_i)) dla każdego toru.
     * @return array{products: list<array>, count: int, search_debug: array}
     */
    private function mergeRRF(
        array $semanticName,
        array $semanticDesc,
        array $semanticJargon,
        array $fulltext,
        array $trigram,
        int $limit,
    ): array {
        $k = self::RRF_K;
        $scores = [];
        $trackInfo = [];

        $tracks = [
            'name'    => ['data' => $semanticName,   'score_key' => 'similarity'],
            'desc'    => ['data' => $semanticDesc,    'score_key' => 'similarity'],
            'jargon'  => ['data' => $semanticJargon,  'score_key' => 'similarity'],
            'fts'     => ['data' => $fulltext,        'score_key' => 'ts_rank'],
            'trigram'  => ['data' => $trigram,          'score_key' => 'trgm_score'],
        ];

        foreach ($tracks as $trackName => $track) {
            foreach ($track['data'] as $item) {
                $id = $item['ps_product_id'];
                $scores[$id] = ($scores[$id] ?? 0.0) + 1.0 / ($k + $item['rank']);
                $trackInfo[$id]["{$trackName}_rank"] = $item['rank'];
                $trackInfo[$id]["{$trackName}_score"] = $item[$track['score_key']];
            }
        }

        if (empty($scores)) {
            return ['products' => [], 'count' => 0, 'search_debug' => []];
        }

        arsort($scores);
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

        $rowsById = [];
        foreach ($rows as $row) {
            $rowsById[(int) $row['ps_product_id']] = $row;
        }

        // Buduj wyniki w kolejności RRF
        $products = [];
        $debugItems = [];
        $trackNames = array_keys($tracks);

        foreach ($topIds as $id) {
            if (!isset($rowsById[$id])) {
                continue;
            }
            $row = $rowsById[$id];
            $rrfScore = $scores[$id];
            $info = $trackInfo[$id] ?? [];

            // Dominujący tor
            $dominant = 'name';
            $bestContrib = 0;
            foreach ($trackNames as $tn) {
                if (isset($info["{$tn}_rank"])) {
                    $contrib = 1.0 / ($k + $info["{$tn}_rank"]);
                    if ($contrib > $bestContrib) {
                        $bestContrib = $contrib;
                        $dominant = $tn;
                    }
                }
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
                'name_rank' => $info['name_rank'] ?? null,
                'desc_rank' => $info['desc_rank'] ?? null,
                'jargon_rank' => $info['jargon_rank'] ?? null,
                'fts_rank' => $info['fts_rank'] ?? null,
                'trigram_rank' => $info['trigram_rank'] ?? null,
            ];
        }

        return [
            'products' => $products,
            'count' => count($products),
            'search_debug' => [
                'tracks' => [
                    'name_count' => count($semanticName),
                    'desc_count' => count($semanticDesc),
                    'jargon_count' => count($semanticJargon),
                    'fts_count' => count($fulltext),
                    'trigram_count' => count($trigram),
                ],
                'rrf_k' => $k,
                'items' => $debugItems,
            ],
        ];
    }
}
