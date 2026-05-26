<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\MysqlConnection;
use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;

/**
 * Wyszukiwanie hybrydowe produktów: 5 torów (3× semantic + FTS + trigram) + RRF fusion.
 * Semantic: embedding_name (nawigacyjne), embedding_desc (eksploracyjne), embedding_jargon (slang).
 * Dane z divechat_product_embeddings (PostgreSQL/pgvector).
 */
final class ProductSearch implements ToolInterface
{
    private const RRF_K = 60;
    private const TRACK_LIMIT = 30;

    // T-015: gdy klient podaje budżet max bez min, odcinamy drobnicę poniżej tego ułamka.
    // 0.25 = przy budżecie 700 zł floor = 175 zł (ładowarki 60 zł odpadają).
    private const PRICE_FLOOR_RATIO = 0.25;

    /** @var list<string>|null cache blacklisty marek (lowercased) */
    private ?array $brandBlacklistCache = null;

    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly PostgresConnection $db,
        private readonly SynonymExpander $synonymExpander,
        private readonly ?EditorialPicksService $editorialPicks = null,
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

        $inStockOnly = !empty($normalized['in_stock_only']);

        // 5 torów + RRF + editorial boost + MySQL enrichment
        $merged = $this->runTracksAndMerge(
            $vectorStr, $expandedQuery, $trigramQuery, $filters,
            $limit, $inStockOnly, $normalized['category'] ?? null,
            $query, $exactKeywords,
        );

        // T-017: auto-fallback — gdy 0 wyników i była użyta kategoria, ponów bez kategorii.
        // T-020: rozszerzony trigger — także gdy navigational + exact_keywords zwróciło tylko
        // substytuty (żaden wynik nie zawiera wszystkich exact_keywords w nazwie). Crystal Vu
        // (panoramiczna) był maskowany przez 5 substytutów Scubapro w błędnej kategorii.
        // Deterministyczne — nie zależy od posłuszeństwa modelu wobec reguły "uprość query gdy 0".
        $usedFilters = $filters;
        $intent = $searchPlan['intent'] ?? '';
        $needFallback = empty($merged['products']);
        $navigationalMiss = false;
        if (
            !$needFallback
            && $intent === 'navigational'
            && !empty($exactKeywords)
            && !empty($normalized['category'])
            && !$this->hasExactKeywordMatch($merged['products'], $exactKeywords)
        ) {
            $needFallback = true;
            $navigationalMiss = true;
        }

        if ($needFallback && !empty($normalized['category'])) {
            $fallbackNormalized = $normalized;
            $fallbackNormalized['category'] = null;
            $fallbackFilters = $this->buildFilters($fallbackNormalized);

            $merged = $this->runTracksAndMerge(
                $vectorStr, $expandedQuery, $trigramQuery, $fallbackFilters,
                $limit, $inStockOnly, null,
                $query, $exactKeywords,
            );
            $merged['search_debug']['category_fallback'] = true;
            $merged['search_debug']['original_category'] = $normalized['category'];
            if ($navigationalMiss) {
                $merged['search_debug']['navigational_miss'] = true;
            }
            $usedFilters = $fallbackFilters;
        }

        // T-020: sygnał dla promptu (PATCH 11 T-018) — czy mimo wszystko brak dokładnego dopasowania.
        // Liczony na finalnym $merged (po ewentualnym fallbacku).
        if ($intent === 'navigational' && !empty($exactKeywords)) {
            $merged['search_debug']['exact_match_miss'] =
                !$this->hasExactKeywordMatch($merged['products'] ?? [], $exactKeywords);
        }

        // Dołącz search_plan do debug info
        if (!empty($searchPlan)) {
            $merged['search_debug']['search_plan'] = $searchPlan;
        }

        // T-015: dołącz meta z buildFilters (blacklist bypass, price floor).
        // Po fallbacku bierzemy meta z fallback filtrów (rzeczywiście zastosowane).
        foreach ($usedFilters['meta'] ?? [] as $key => $value) {
            $merged['search_debug'][$key] = $value;
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
     * Uruchamia 5 torów (3× semantic + FTS + trigram) i łączy je przez mergeRRF.
     * Wydzielone żeby T-017 fallback mógł powtórzyć cały pipeline ze zmienionym $filters
     * bez duplikacji 5 wywołań.
     */
    private function runTracksAndMerge(
        string $vectorStr,
        string $expandedQuery,
        string $trigramQuery,
        array $filters,
        int $limit,
        bool $inStockOnly,
        ?string $category,
        string $query,
        array $exactKeywords,
    ): array {
        $semanticName = $this->searchSemanticColumn('embedding_name', $vectorStr, $filters);
        $semanticDesc = $this->searchSemanticColumn('embedding_desc', $vectorStr, $filters);
        $semanticJargon = $this->searchSemanticColumn('embedding_jargon', $vectorStr, $filters);
        $fulltext = $expandedQuery !== '' ? $this->searchFullText($expandedQuery, $filters) : [];
        $trigram = $this->searchTrigram($trigramQuery, $filters);

        return $this->mergeRRF(
            $semanticName,
            $semanticDesc,
            $semanticJargon,
            $fulltext,
            $trigram,
            $limit,
            $inStockOnly,
            $category,
            $query,
            $exactKeywords,
        );
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
     * T-015: blacklist marek + price floor (drobnica wycinana gdy budżet max bez min).
     * @return array{where: string, params: list<mixed>, meta: array<string, mixed>}
     */
    private function buildFilters(array $params): array
    {
        $conditions = ['is_active = true'];
        $sqlParams = [];
        $meta = [];

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

        // T-015: price floor — przy budżecie max bez min odetnij produkty < ratio*max.
        // Cel: klient pyta "latarki do 700 zł", ładowarki 60 zł i uchwyty nie wpadają.
        if (!empty($params['max_price']) && empty($params['min_price'])) {
            $impliedFloor = (float) $params['max_price'] * self::PRICE_FLOOR_RATIO;
            if ($impliedFloor > 0) {
                $sqlParams[] = $impliedFloor;
                $conditions[] = 'price >= ?';
                $meta['price_floor_applied'] = round($impliedFloor, 2);
                $meta['price_floor_ratio'] = self::PRICE_FLOOR_RATIO;
            }
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

        // T-015: blacklist marek (divechat_brand_blacklist).
        // Normalizacja: lowercase + strip spaces/dashes — "Aqua Zone" / "AquaZone" / "aqua-zone"
        // wszystkie matchują wpis "Aquazone". Wyjątek: klient explicite szuka blacklistowanej
        // marki → bypass + flag dla SystemPrompt (T-016 doda ostrzeżenie "tej marki nie polecamy").
        $blacklist = $this->getBrandBlacklist();
        if (!empty($blacklist)) {
            $explicitBrand = !empty($params['brand']) ? $this->normalizeBrand((string) $params['brand']) : null;
            $bypass = $explicitBrand !== null && in_array($explicitBrand, $blacklist, true);

            if ($bypass) {
                $meta['brand_blacklisted'] = true;
                $meta['brand_blacklisted_query'] = $params['brand'];
            } else {
                $placeholders = implode(',', array_fill(0, count($blacklist), '?'));
                foreach ($blacklist as $b) {
                    $sqlParams[] = $b;
                }
                $conditions[] = "(brand_name IS NULL OR LOWER(REPLACE(REPLACE(brand_name, ' ', ''), '-', '')) NOT IN ({$placeholders}))";
            }
        }

        return [
            'where' => implode(' AND ', $conditions),
            'params' => $sqlParams,
            'meta' => $meta,
        ];
    }

    /**
     * Lazy-load blacklisty marek z divechat_brand_blacklist (lowercase do case-insensitive matchu).
     * Defensywnie: gdy tabela nie istnieje (migracja 014 nie zapplikowana) → pusta lista.
     * @return list<string>
     */
    private function getBrandBlacklist(): array
    {
        if ($this->brandBlacklistCache !== null) {
            return $this->brandBlacklistCache;
        }

        try {
            $rows = $this->db->fetchAll('SELECT brand_name FROM divechat_brand_blacklist', []);
        } catch (\PDOException $e) {
            // Tabela nie istnieje → skip (pre-migration safety)
            $this->brandBlacklistCache = [];
            return [];
        }

        $list = [];
        foreach ($rows as $row) {
            $name = $row['brand_name'] ?? null;
            if (is_string($name) && $name !== '') {
                $list[] = $this->normalizeBrand($name);
            }
        }
        $this->brandBlacklistCache = $list;
        return $list;
    }

    /**
     * Normalizuje nazwę marki: lowercase + strip spaces/dashes.
     * "Aqua Zone" / "AquaZone" / "aqua-zone" → "aquazone".
     */
    private function normalizeBrand(string $name): string
    {
        return str_replace([' ', '-', '_'], '', mb_strtolower($name));
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
     * T-015: po RRF + editorial boost stosuje boolean re-rank — produkty matchujące
     * więcej tokenów zapytania w product_name dostają boost (do +50% za pełny match).
     * @param list<string> $exactKeywords nazwy własne z search_plan (dodatkowe tokeny)
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
        ?string $category = null,
        string $query = '',
        array $exactKeywords = [],
    ): array {
        $k = self::RRF_K;
        $scores = [];
        $trackInfo = [];
        $editorialBoosts = [];

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

        // Editorial boost — manualny override rankingu (ADR-054).
        // final_score = base_rrf_score * boost_factor (1.0-2.5).
        // Aktywne picki tylko (active=TRUE AND expires_at IS NULL OR > NOW()).
        if ($this->editorialPicks !== null) {
            $editorialBoosts = $this->editorialPicks->getActiveBoosts(array_keys($scores), $category);
            if (!empty($editorialBoosts)) {
                foreach ($editorialBoosts as $pid => $boost) {
                    if (isset($scores[$pid])) {
                        $scores[$pid] *= $boost;
                    }
                }
                arsort($scores);
            }
        }

        // Pobierz nazwy WSZYSTKICH kandydatów z RRF (potrzebne do boolean re-rank + debug).
        // T-015: re-rank musi widzieć cały zbiór, nie tylko top — produkt z niskim RRF
        // ale matchem wszystkich tokenów może wskoczyć wyżej.
        $allScoredIds = array_keys($scores);
        $namesById = [];
        if (!empty($allScoredIds)) {
            $namesPlaceholders = implode(',', array_fill(0, count($allScoredIds), '?'));
            $nameRows = $this->db->fetchAll(
                "SELECT ps_product_id, product_name FROM divechat_product_embeddings WHERE ps_product_id IN ({$namesPlaceholders})",
                $allScoredIds,
            );
            foreach ($nameRows as $row) {
                $namesById[(int) $row['ps_product_id']] = $row['product_name'];
            }
        }

        // T-015: boolean re-rank — boost dla multi-attribute match (np. pink+child+mask)
        $booleanDebug = $this->applyMultiAttributeBoost($scores, $query, $exactKeywords, $namesById);
        if (!empty($booleanDebug['boost_applied'])) {
            arsort($scores);
        }

        // Pobierz większy zbiór kandydatów (zapas na filtrowanie po MySQL)
        $candidateLimit = min(count($scores), $limit * 3);
        $candidateIds = array_slice(array_keys($scores), 0, $candidateLimit);

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

        // Filtruj in_stock_only z aktualnych danych MySQL.
        // ADR-058: Editorial Picks bypassują in_stock_only filter (flagowe produkty
        // często available_to_order, warto je pokazać). price_max szanowany przez
        // buildFilters() na poziomie PG — pick poza budżetem klienta nie trafia tu w ogóle.
        if ($inStockOnly) {
            $filteredIds = array_filter($filteredIds, function (int $id) use ($mysqlData, &$filteredOut, $namesById, $editorialBoosts) {
                $inStock = $mysqlData[$id]['in_stock'] ?? false;
                if ($inStock) {
                    return true;
                }
                // Bypass dla Editorial Picks (ADR-058 hybryda).
                if (isset($editorialBoosts[$id])) {
                    return true;
                }
                $filteredOut[] = [
                    'id' => $id,
                    'name' => $namesById[$id] ?? 'unknown',
                    'reason' => 'in_stock_only=true, quantity=0',
                ];
                return false;
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
                'editorial_boost' => $editorialBoosts[$id] ?? null,
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
                'boolean_rerank' => $booleanDebug,
                'items' => $debugItems,
            ],
        ];
    }

    /**
     * T-015: boost dla produktów które matchują WIELE tokenów zapytania w product_name.
     * Cel: gdy klient pyta "pink mask for child", produkt z dopasowaniem wszystkich
     * 3 tokenów (różowa dziecięca maska) wskakuje wyżej niż produkty z 1 atrybutem
     * (różowa dla dorosłych albo dziecięca niebieska).
     *
     * Boost = 1 + 0.5 * (matched/total). Pełen match = +50%, połowa = +25%.
     * Aktywny tylko gdy zapytanie ma >= 2 znaczące tokeny — single-token nie niesie info.
     *
     * @param array<int, float> $scores referencja — modyfikujemy in place
     * @param list<string> $exactKeywords nazwy własne z search_plan
     * @param array<int, string> $namesById nazwy produktów z pgvector
     * @return array{tokens: list<string>, boost_applied: bool, top_matches: array<int, int>}
     */
    private function applyMultiAttributeBoost(array &$scores, string $query, array $exactKeywords, array $namesById): array
    {
        $tokens = $this->extractSignificantTokens($query);
        foreach ($exactKeywords as $kw) {
            if (!is_string($kw)) {
                continue;
            }
            foreach ($this->extractSignificantTokens($kw) as $t) {
                $tokens[] = $t;
            }
        }
        $tokens = array_values(array_unique($tokens));

        $debug = ['tokens' => $tokens, 'boost_applied' => false, 'top_matches' => []];

        if (count($tokens) < 2) {
            return $debug;
        }

        $totalTokens = count($tokens);
        $topMatchSample = [];

        foreach ($scores as $pid => &$score) {
            $name = $namesById[$pid] ?? '';
            if ($name === '') {
                continue;
            }
            $matched = $this->countTokenMatches($name, $tokens);
            if ($matched === 0) {
                continue;
            }
            $ratio = $matched / $totalTokens;
            $score *= 1.0 + 0.5 * $ratio;

            if ($matched === $totalTokens && count($topMatchSample) < 5) {
                $topMatchSample[$pid] = $matched;
            }
        }
        unset($score);

        $debug['boost_applied'] = true;
        $debug['top_matches'] = $topMatchSample;
        return $debug;
    }

    /**
     * Wyciąga znaczące tokeny z tekstu: lowercase + strip accents,
     * pomija stopwordy PL/EN i tokeny krótsze niż 3 znaki.
     * @return list<string>
     */
    private function extractSignificantTokens(string $text): array
    {
        static $stopwords = [
            // PL
            'do' => 1, 'na' => 1, 'po' => 1, 'ze' => 1, 'dla' => 1, 'pod' => 1, 'nad' => 1, 'bez' => 1,
            'jest' => 1, 'lub' => 1, 'oraz' => 1, 'czy' => 1, 'mi' => 1, 'mnie' => 1, 'sie' => 1,
            'mam' => 1, 'masz' => 1, 'chce' => 1, 'jaki' => 1, 'jaka' => 1, 'jakie' => 1,
            // EN
            'the' => 1, 'for' => 1, 'and' => 1, 'with' => 1, 'are' => 1, 'you' => 1, 'can' => 1,
            'need' => 1, 'want' => 1, 'have' => 1, 'has' => 1, 'this' => 1, 'that' => 1, 'any' => 1,
            'some' => 1, 'all' => 1,
        ];

        $normalized = mb_strtolower($this->stripDiacritics($text));
        $tokens = preg_split('/[^a-z0-9]+/', $normalized);
        if ($tokens === false) {
            return [];
        }

        $significant = [];
        foreach ($tokens as $t) {
            if ($t === '' || mb_strlen($t) < 3) {
                continue;
            }
            if (isset($stopwords[$t])) {
                continue;
            }
            $significant[$t] = true;
        }
        // T-020: numeric tokeny ("4000") muszą zostać string — PHP key auto-cast
        // zamieniłby "4000" na int, co potem wybucha w str_contains() PHP 8.4.
        return array_map('strval', array_keys($significant));
    }

    /**
     * Liczy ile z $tokens występuje jako substring w $text (po normalizacji).
     */
    private function countTokenMatches(string $text, array $tokens): int
    {
        $normalized = mb_strtolower($this->stripDiacritics($text));
        $matched = 0;
        foreach ($tokens as $t) {
            // T-020: defensywny cast (string) — gdyby tokens przyszły z innego źródła.
            if ($t !== '' && str_contains($normalized, (string) $t)) {
                $matched++;
            }
        }
        return $matched;
    }

    /**
     * Czy któryś produkt zawiera WSZYSTKIE exact_keywords w nazwie (case+accent-insensitive).
     * Wieloczłonowe keywords (np. "Crystal Vu") sprawdzane jako całość (substring).
     * Używane w T-020 do wykrycia "exact match miss" — gdy navigational query zwraca
     * tylko substytuty.
     */
    private function hasExactKeywordMatch(array $products, array $exactKeywords): bool
    {
        if (empty($exactKeywords)) {
            return true;
        }
        $normKeywords = [];
        foreach ($exactKeywords as $kw) {
            $n = mb_strtolower($this->stripDiacritics((string) $kw));
            if ($n !== '') {
                $normKeywords[] = $n;
            }
        }
        if (empty($normKeywords)) {
            return true;
        }
        foreach ($products as $p) {
            $name = mb_strtolower($this->stripDiacritics((string) ($p['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $allMatch = true;
            foreach ($normKeywords as $kw) {
                if (!str_contains($name, $kw)) {
                    $allMatch = false;
                    break;
                }
            }
            if ($allMatch) {
                return true;
            }
        }
        return false;
    }

    /**
     * Strip polskich znaków diakrytycznych (do case+accent-insensitive match).
     */
    private function stripDiacritics(string $text): string
    {
        return strtr($text, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n',
            'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
        ]);
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

        // Globalna konfiguracja: czy sklep pozwala zamawiać niedostępne produkty (PS_ORDER_OUT_OF_STOCK).
        // Używana gdy produkt ma out_of_stock=2 (use default behavior — PrestaShop konwencja).
        // Wartość pobierana per request — może się zmieniać w PS admin bez restartu.
        $globalAllowOos = (int) (
            $mysql->fetchOne(
                "SELECT value FROM pr_configuration WHERE name = 'PS_ORDER_OUT_OF_STOCK' LIMIT 1"
            )['value'] ?? 0
        );

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));

        // Główne dane produktów (cena bazowa netto, stan, visibility).
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
            array_merge([$globalAllowOos], $productIds),
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
