<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\MysqlConnection;
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
                            'description' => 'Filtruj tylko dostępne produkty. DOMYŚLNIE TRUE. '
                                . 'Ustaw na false TYLKO gdy klient pyta o konkretny model który może być niedostępny.',
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

        // Fuzja RRF (5 torów) + real-time MySQL enrichment
        $inStockOnly = !empty($normalized['in_stock_only']);
        $merged = $this->mergeRRF($semanticName, $semanticDesc, $semanticJargon, $fulltext, $trigram, $limit, $inStockOnly);

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
            'in_stock_only' => $filtersInput['in_stock_only'] ?? $params['in_stock_only'] ?? true,
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

        // in_stock_only filtrowane post-hoc z real-time MySQL (nie z pgvector)

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
        bool $inStockOnly = true,
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

        // Pobierz większy zbiór kandydatów (zapas na filtrowanie po MySQL)
        $candidateLimit = min(count($scores), $limit * 3);
        $candidateIds = array_slice(array_keys($scores), 0, $candidateLimit);

        // Pobierz nazwy produktów z pgvector (do debug logów)
        $namesPlaceholders = implode(',', array_fill(0, count($candidateIds), '?'));
        $nameRows = $this->db->fetchAll(
            "SELECT ps_product_id, product_name FROM divechat_product_embeddings WHERE ps_product_id IN ({$namesPlaceholders})",
            $candidateIds,
        );
        $namesById = [];
        foreach ($nameRows as $row) {
            $namesById[(int) $row['ps_product_id']] = $row['product_name'];
        }

        // Snapshot kandydatów PRZED MySQL enrichment (top 20)
        $candidatesBeforeMySQL = [];
        foreach (array_slice($candidateIds, 0, 20) as $id) {
            $info = $trackInfo[$id] ?? [];
            $candidatesBeforeMySQL[] = [
                'id' => $id,
                'name' => $namesById[$id] ?? 'unknown',
                'rrf_score' => round($scores[$id], 6),
                'tracks' => [
                    'name' => $info['name_rank'] ?? null,
                    'desc' => $info['desc_rank'] ?? null,
                    'jargon' => $info['jargon_rank'] ?? null,
                    'fts' => $info['fts_rank'] ?? null,
                    'trigram' => $info['trigram_rank'] ?? null,
                ],
            ];
        }

        // Real-time dane z MySQL (cena, stan, visibility, active)
        $mysqlData = $this->enrichWithMySQLData($candidateIds);

        // Log MySQL enrichment
        $mysqlLog = [
            'success' => !empty($mysqlData),
            'count' => count($mysqlData),
            'products' => [],
        ];
        foreach ($candidateIds as $id) {
            if (isset($mysqlData[$id])) {
                $mysqlLog['products'][$id] = $mysqlData[$id];
            }
        }

        // Boost dostępnych produktów z AKTUALNYCH danych MySQL
        // Tylko gdy in_stock_only=true (exploratory). Przy navigational (in_stock_only=false)
        // klient szuka konkretnego produktu — nie karzemy za brak stanu.
        if ($inStockOnly) {
            foreach ($scores as $id => &$score) {
                if (isset($mysqlData[$id]) && !$mysqlData[$id]['in_stock']) {
                    $score *= 0.3;
                }
            }
            unset($score);
            arsort($scores);
        }

        // Filtruj ukryte i nieaktywne produkty (MySQL real-time) + loguj odfiltrowane
        $filteredOut = [];
        $filteredIds = array_filter(array_keys($scores), function (int $id) use ($mysqlData, &$filteredOut, $namesById) {
            $data = $mysqlData[$id] ?? null;
            if ($data === null) {
                return true; // brak danych MySQL = zachowaj (fallback na pgvector)
            }
            $keep = $data['active'] && $data['visible'];
            if (!$keep) {
                $filteredOut[] = [
                    'id' => $id,
                    'name' => $namesById[$id] ?? 'unknown',
                    'reason' => !$data['active'] ? 'active=false' : 'visibility=none',
                ];
            }
            return $keep;
        });

        // Filtruj in_stock_only z aktualnych danych MySQL
        if ($inStockOnly) {
            $filteredIds = array_filter($filteredIds, function (int $id) use ($mysqlData, &$filteredOut, $namesById) {
                $inStock = $mysqlData[$id]['in_stock'] ?? false;
                if (!$inStock) {
                    $filteredOut[] = [
                        'id' => $id,
                        'name' => $namesById[$id] ?? 'unknown',
                        'reason' => 'in_stock_only=true, quantity=0',
                    ];
                }
                return $inStock;
            });
        }

        $topIds = array_slice(array_values($filteredIds), 0, $limit);

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

            $product = [
                'id' => (int) $row['ps_product_id'],
                'name' => $row['product_name'],
                'brand' => $row['brand_name'],
                'category' => $row['category_name'],
                // Real-time z MySQL (fallback na pgvector jeśli brak)
                'price' => $mysqlData[$id]['price'] ?? (float) $row['price'],
                'in_stock' => $mysqlData[$id]['in_stock'] ?? (bool) $row['in_stock'],
                'availability' => $mysqlData[$id]['availability'] ?? ((bool) $row['in_stock'] ? 'in_stock' : 'unavailable'),
                'url' => $row['product_url'],
                'image_url' => $row['image_url'],
                'similarity' => round($rrfScore, 4),
            ];

            // Cena przed rabatem — AI może powiedzieć "przeceniony z X na Y"
            if (isset($mysqlData[$id]['price_before_discount'])) {
                $product['price_before_discount'] = $mysqlData[$id]['price_before_discount'];
            }

            $products[] = $product;

            $debugItems[] = [
                'product_id' => $id,
                'rrf_score' => round($rrfScore, 6),
                'dominant_track' => $dominant,
                'name_rank' => $info['name_rank'] ?? null,
                'desc_rank' => $info['desc_rank'] ?? null,
                'jargon_rank' => $info['jargon_rank'] ?? null,
                'fts_rank' => $info['fts_rank'] ?? null,
                'trigram_rank' => $info['trigram_rank'] ?? null,
                'mysql_price' => $mysqlData[$id]['price'] ?? null,
                'mysql_price_before_discount' => $mysqlData[$id]['price_before_discount'] ?? null,
                'mysql_in_stock' => $mysqlData[$id]['in_stock'] ?? null,
                'mysql_availability' => $mysqlData[$id]['availability'] ?? null,
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
                'candidates_before_mysql' => array_slice($candidatesBeforeMySQL, 0, 10),
                'mysql_enrichment' => $mysqlLog,
                'filtered_out' => $filteredOut,
                'items' => $debugItems,
            ],
        ];
    }

    /**
     * Wzbogaca wyniki wyszukiwania o aktualne dane z MySQL PrestaShop.
     * Zastępuje zamrożone dane z pgvector real-time danymi (cena, stan, visibility).
     *
     * @param list<int> $productIds
     * @return array<int, array{price: float, in_stock: bool, quantity: int, active: bool, visible: bool}>
     */
    private function enrichWithMySQLData(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        try {
            $mysql = MysqlConnection::getInstance();
        } catch (\Throwable $e) {
            // MySQL niedostępny — fallback na dane z pgvector
            error_log("[DiveChat] MySQL enrichment failed: {$e->getMessage()}");
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        // Główne dane produktów (cena bazowa netto, stan, visibility)
        $rows = $mysql->fetchAll(
            "SELECT
                p.id_product,
                ps.price AS price_netto,
                COALESCE(t.rate, 23) AS tax_rate,
                COALESCE(sa.total_qty, 0) AS quantity,
                CASE
                    WHEN COALESCE(sa.total_qty, 0) > 0 THEN 'in_stock'
                    WHEN COALESCE(sa.allow_oos, 0) = 1 THEN 'available_to_order'
                    ELSE 'unavailable'
                END AS availability,
                ps.active,
                ps.visibility
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
            WHERE p.id_product IN ({$placeholders})",
            $productIds,
        );

        // Aktywne promocje z pr_specific_price (priorytetyzacja: bardziej specyficzny wygrywa)
        $specificPrices = $this->fetchSpecificPrices($mysql, $productIds);

        $dataById = [];
        foreach ($rows as $row) {
            $productId = (int) $row['id_product'];
            $priceNetto = (float) $row['price_netto'];
            $taxRate = (float) $row['tax_rate'];
            $availability = $row['availability'] ?? 'unavailable';

            // Oblicz cenę finalną netto z uwzględnieniem specific_price
            $finalNetto = $priceNetto;
            $hasPromo = false;

            if (isset($specificPrices[$productId])) {
                $sp = $specificPrices[$productId];
                $hasPromo = true;

                // Price override: sp.price > 0 zastępuje cenę bazową
                $base = ($sp['sp_price'] > 0) ? $sp['sp_price'] : $priceNetto;

                // Reduction
                if ($sp['reduction'] > 0) {
                    $finalNetto = match ($sp['reduction_type']) {
                        'percentage' => $base * (1 - $sp['reduction']),
                        'amount' => $base - $sp['reduction'],
                        default => $base,
                    };
                } else {
                    $finalNetto = $base;
                }
            }

            $priceBrutto = round($finalNetto * (1 + $taxRate / 100), 2);
            $baseBrutto = round($priceNetto * (1 + $taxRate / 100), 2);

            $entry = [
                'price' => $priceBrutto,
                'in_stock' => $availability !== 'unavailable',
                'availability' => $availability,
                'quantity' => (int) $row['quantity'],
                'active' => (bool) $row['active'],
                'visible' => $row['visibility'] !== 'none',
            ];

            // Dodaj cenę przed rabatem jeśli produkt ma aktywną promocję
            if ($hasPromo && $baseBrutto > $priceBrutto) {
                $entry['price_before_discount'] = $baseBrutto;
            }

            $dataById[$productId] = $entry;
        }

        return $dataById;
    }

    /**
     * Pobiera najlepszą aktywną specific_price per produkt.
     * Priorytet PS: id_shop > 0 wygrywa z id_shop = 0, id_group > 0 wygrywa z id_group = 0.
     *
     * @param list<int> $productIds
     * @return array<int, array{sp_price: float, reduction: float, reduction_type: string}>
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

        // Wybierz jedną (najwyższy priorytet) specific_price per produkt
        $bestByProduct = [];
        foreach ($rows as $row) {
            $productId = (int) $row['id_product'];
            if (isset($bestByProduct[$productId])) {
                continue; // Pierwszy wiersz ma najwyższy priorytet (ORDER BY DESC)
            }
            $bestByProduct[$productId] = [
                'sp_price' => (float) $row['sp_price'],
                'reduction' => (float) $row['reduction'],
                'reduction_type' => $row['reduction_type'],
            ];
        }

        return $bestByProduct;
    }
}
