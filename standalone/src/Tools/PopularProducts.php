<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;
use DiveChat\Shop\MysqlProductEnrichmentService;

/**
 * Dynamiczna popularnosc produktow (ADR-115, CHAT-T-132).
 *
 * Bot przy OGOLNYM pytaniu o polecenie w kategorii ("jakie pletwy polecacie",
 * "co sie najlepiej sprzedaje") dostaje REALNA sprzedaz z PrestaShop na zywo
 * (pr_orders + pr_order_detail, decyzja 19a/20a — bez materializacji, ~9 ms)
 * zamiast recznej listy, ktora cicho sie rozjezdza.
 *
 * Dwie ODDZIELNE sekcje (decyzja 22a):
 * - bestsellers: top wg sztuk sprzedanych w oknie (domyslnie 6 mcy),
 * - new_arrivals: produkty dodane < 90 dni (okno laski 21b) — nowosc z mala
 *   sprzedaza nie udaje bestsellera, ale bot moze ja wskazac jako nowosc.
 * Produkt bedacy jednoczesnie nowoscia i bestsellerem dostaje flage is_new
 * w bestsellers i NIE jest powtarzany w new_arrivals.
 *
 * Klucze kategorii semantyczne (decyzja 17b) — mapa category_key -> id_category
 * PrestaShop zaszyta w klasie (dolozenie kategorii = jeden wpis w mapie).
 * Bot wybiera klucz z enum, NIE zgaduje kategorii z nazwy (pomylka JET vs
 * paskowe, czaty 605/606).
 *
 * Cena/dostepnosc real-time przez MysqlProductEnrichmentService (TWARDA zasada
 * jak w CuratedRecommendations: brak danych / active=false / unavailable -> out).
 *
 * NIE do wyszukiwania konkretnego modelu (to search_products).
 * NIE do eksperckiego osadu zespolu (to get_curated_recommendations).
 */
final class PopularProducts implements ToolInterface
{
    /**
     * Mapa semantycznych kluczy kategorii -> PrestaShop id_category.
     * Rozszerzanie: dopisz wpis (klucz => [id, opis dla LLM]) — bez zmian logiki.
     */
    public const CATEGORIES = [
        'fins_recreational' => [
            'id' => 473,
            'label' => 'Pletwy paskowe na buta (rekreacyjne, regulowany pasek, zakladane na buty nurkowe)',
        ],
        'fins_jet' => [
            'id' => 415,
            'label' => 'Pletwy gumowe JET (ciezkie gumowe, tech/wraki/pradowe, np. do suchego skafandra)',
        ],
        'fins_snorkel' => [
            'id' => 472,
            'label' => 'Pletwy kaloszowe na stope (pelny kalosz na bosa stope, snorkeling/basen/cieple wody)',
        ],
    ];

    private const DEFAULT_MONTHS = 6;
    private const MIN_MONTHS = 1;
    private const MAX_MONTHS = 24;
    private const NEW_ARRIVAL_DAYS = 90;
    private const SECTION_LIMIT = 5;
    private const SQL_CANDIDATE_LIMIT = 15; // nadmiar — enrich/max_price odetnie czesc

    public function __construct(
        private readonly MysqlConnection $mysql,
        private readonly MysqlProductEnrichmentService $enrichmentService,
    ) {}

    public function getName(): string
    {
        return 'get_popular_products';
    }

    public function getDescription(): string
    {
        return 'Najczesciej kupowane produkty w kategorii wg REALNEJ sprzedazy sklepu (okno domyslnie 6 miesiecy) '
             . '+ nowosci w ofercie (< 90 dni). Uzywaj przy OGOLNYCH pytaniach o polecenie w kategorii: '
             . '"jakie pletwy polecacie", "co sie najlepiej sprzedaje", "najpopularniejsze". '
             . 'Zwraca dwie ODDZIELNE sekcje: bestsellers (top sprzedazy) i new_arrivals (nowosci) — '
             . 'cena i dostepnosc real-time. '
             . 'NIE uzywaj do wyszukiwania konkretnego modelu (to search_products), '
             . 'ani do pytan gdzie liczy sie ekspercki osad zespolu (to get_curated_recommendations). '
             . 'Wybierz kategorie z enum (parametr category) — jesli pytanie klienta nie pasuje '
             . 'do zadnej, NIE wywoluj tego narzedzia.';
    }

    public function getParametersSchema(): array
    {
        $descriptionLines = [];
        foreach (self::CATEGORIES as $key => $def) {
            $descriptionLines[] = "- {$key}: {$def['label']}";
        }

        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'enum' => array_keys(self::CATEGORIES),
                    'description' => "Semantyczny klucz kategorii. Wybierz pasujacy do intencji klienta (NIE zgaduj typu pletw z nazwy — dopytaj klienta o zastosowanie jesli niejasne):\n"
                        . implode("\n", $descriptionLines),
                ],
                'max_price' => [
                    'type' => 'number',
                    'description' => 'Opcjonalnie: gorna granica budzetu klienta w PLN brutto — produkty drozsze zostana odciete.',
                ],
                'months' => [
                    'type' => 'integer',
                    'description' => 'Opcjonalnie: okno sprzedazy w miesiacach (domyslnie 6).',
                ],
            ],
            'required' => ['category'],
        ];
    }

    /**
     * Mapowanie klucza kategorii na PrestaShop id_category (null = nieznany klucz).
     * Public static — testowalne bez MySQL.
     */
    public static function resolveCategoryId(string $categoryKey): ?int
    {
        return self::CATEGORIES[$categoryKey]['id'] ?? null;
    }

    /**
     * Normalizacja okna sprzedazy: default 6, clamp 1-24. Testowalna bez MySQL.
     */
    public static function normalizeMonths(mixed $months): int
    {
        if (!is_numeric($months)) {
            return self::DEFAULT_MONTHS;
        }

        return max(self::MIN_MONTHS, min(self::MAX_MONTHS, (int) $months));
    }

    public function execute(array $params): array
    {
        $categoryKey = (string) ($params['category'] ?? '');
        $categoryId = self::resolveCategoryId($categoryKey);

        if ($categoryId === null) {
            return [
                'category' => $categoryKey !== '' ? $categoryKey : null,
                'status' => 'unknown_category',
                'message' => 'Nieznany klucz kategorii. Uzyj search_products lub get_expert_knowledge.',
                'bestsellers' => [],
                'new_arrivals' => [],
            ];
        }

        $months = self::normalizeMonths($params['months'] ?? null);
        $maxPrice = isset($params['max_price']) && is_numeric($params['max_price'])
            ? (float) $params['max_price']
            : null;

        try {
            $bestsellerRows = $this->fetchBestsellers($categoryId, $months);
            $newArrivalRows = $this->fetchNewArrivals($categoryId);
        } catch (\Throwable $e) {
            error_log("[DiveChat popular] MySQL query failed (category={$categoryKey}): {$e->getMessage()}");
            return [
                'category' => $categoryKey,
                'status' => 'error',
                'message' => 'Dane sprzedazy chwilowo niedostepne — uzyj search_products albo zaproponuj kontakt: dive@divezone.pl / 56 307 03 03.',
                'bestsellers' => [],
                'new_arrivals' => [],
            ];
        }

        // Jeden enrich dla obu sekcji (cena brutto z promocjami + dostepnosc na zywo).
        $allIds = array_values(array_unique(array_merge(
            array_map(static fn(array $r): int => (int) $r['product_id'], $bestsellerRows),
            array_map(static fn(array $r): int => (int) $r['product_id'], $newArrivalRows),
        )));
        $enriched = $this->enrichmentService->enrich($allIds);

        $skipped = [];
        $newArrivalIds = array_map(static fn(array $r): int => (int) $r['product_id'], $newArrivalRows);

        $bestsellers = [];
        foreach ($bestsellerRows as $row) {
            $entry = $this->buildEntry($row, $enriched, $maxPrice, $categoryKey, $skipped);
            if ($entry === null) {
                continue;
            }
            $entry['sold_qty'] = (int) $row['sold_qty'];
            // Okno laski 21b: bestseller bedacy nowoscia dostaje flage zamiast duplikatu w new_arrivals.
            if (in_array((int) $row['product_id'], $newArrivalIds, true)) {
                $entry['is_new'] = true;
            }
            $bestsellers[] = $entry;
            if (count($bestsellers) >= self::SECTION_LIMIT) {
                break;
            }
        }

        $bestsellerIds = array_map(static fn(array $e): int => $e['product_id'], $bestsellers);
        $newArrivals = [];
        foreach ($newArrivalRows as $row) {
            if (in_array((int) $row['product_id'], $bestsellerIds, true)) {
                continue; // juz pokazany jako bestseller z flaga is_new
            }
            $entry = $this->buildEntry($row, $enriched, $maxPrice, $categoryKey, $skipped);
            if ($entry === null) {
                continue;
            }
            $entry['added_date'] = substr((string) $row['date_add'], 0, 10);
            $newArrivals[] = $entry;
            if (count($newArrivals) >= self::SECTION_LIMIT) {
                break;
            }
        }

        if (empty($bestsellers) && empty($newArrivals)) {
            return [
                'category' => $categoryKey,
                'category_label' => self::CATEGORIES[$categoryKey]['label'],
                'status' => 'no_available',
                'message' => 'Brak dostepnych produktow spelniajacych kryteria (sprzedaz/nowosci/budzet) — uzyj search_products lub zaproponuj kontakt: dive@divezone.pl / 56 307 03 03.',
                'months_window' => $months,
                'skipped' => $skipped,
                'bestsellers' => [],
                'new_arrivals' => [],
            ];
        }

        return [
            'category' => $categoryKey,
            'category_label' => self::CATEGORIES[$categoryKey]['label'],
            'status' => 'ok',
            'months_window' => $months,
            'skipped' => $skipped,
            'bestsellers' => $bestsellers,
            'new_arrivals' => $newArrivals,
        ];
    }

    /**
     * Wspolna budowa pozycji wyniku: twardy filtr enrichment (jak w
     * CuratedRecommendations) + odciecie po budzecie. null = pozycja odpada.
     *
     * @param array<int, array<string, mixed>> $enriched
     * @param list<array{product_id: int, reason: string}> $skipped
     */
    private function buildEntry(
        array $row,
        array $enriched,
        ?float $maxPrice,
        string $categoryKey,
        array &$skipped,
    ): ?array {
        $productId = (int) $row['product_id'];
        $data = $enriched[$productId] ?? null;

        // TWARDY staleness (wzorzec ADR-065): brak enrichment lub produkt
        // nieaktywny/niewidoczny/niedostepny -> pomijamy + log.
        if ($data === null || !$data['active'] || !$data['visible']) {
            $reason = $data === null ? 'mysql_missing' : (!$data['active'] ? 'active=false' : 'visibility=none');
            error_log("[DiveChat popular] product_id={$productId} category={$categoryKey} skipped: {$reason}");
            $skipped[] = ['product_id' => $productId, 'reason' => $reason];
            return null;
        }

        // CHAT-T-143 (ADR-123): narzedzie poleca NIEPYTANY -> produkt wycofany
        // ze sprzedazy (afo=0) wypada ZAWSZE, bez parametru.
        // Brak klucza (dryf wersji enrich) = traktuj jak dostepny — jak w ProductSearch.
        if (($data['available_for_order'] ?? true) === false) {
            $skipped[] = ['product_id' => $productId, 'reason' => 'discontinued'];
            return null;
        }

        if ($data['availability'] === 'unavailable') {
            $skipped[] = ['product_id' => $productId, 'reason' => 'unavailable'];
            return null;
        }

        if ($maxPrice !== null && $data['price'] > $maxPrice) {
            $skipped[] = ['product_id' => $productId, 'reason' => 'over_budget'];
            return null;
        }

        $slug = (string) ($row['link_rewrite'] ?? '');

        return [
            'product_id' => $productId,
            'name' => (string) $row['name'],
            'brand' => $row['brand'] !== null ? (string) $row['brand'] : null,
            'price' => $data['price'],
            'price_eur' => $data['price_eur'] ?? null,
            'price_before_discount' => $data['price_before_discount'] ?? null,
            'availability' => $data['availability'],
            // CHAT-T-160 (decyzja 197a): URL KANONICZNY z enrichmentu (prefiks kategorii),
            // fallback na lokalna forme bez prefiksu gdy enrich go nie ma.
            'url' => $data['url'] ?? ($slug !== '' ? "https://divezone.pl/{$slug}.html" : null),
            'url_en' => $data['url_en'] ?? null,
        ];
    }

    /**
     * Top sprzedazy w kategorii w oknie (pr_orders + pr_order_detail na zywo,
     * decyzja 19a/20a — zmierzone ~9 ms na PROD, bez materializacji).
     *
     * @return list<array<string, mixed>>
     */
    private function fetchBestsellers(int $categoryId, int $months): array
    {
        return $this->mysql->fetchAll(
            "SELECT od.product_id,
                    SUM(od.product_quantity) AS sold_qty,
                    pl.name,
                    pl.link_rewrite,
                    m.name AS brand
             FROM pr_order_detail od
             JOIN pr_orders o ON o.id_order = od.id_order
                 AND o.valid = 1
                 AND o.date_add >= DATE_SUB(NOW(), INTERVAL ? MONTH)
             JOIN pr_category_product cp ON cp.id_product = od.product_id
                 AND cp.id_category = ?
             JOIN pr_product_shop ps ON ps.id_product = od.product_id
                 AND ps.id_shop = 1 AND ps.active = 1
             JOIN pr_product p ON p.id_product = od.product_id
             JOIN pr_product_lang pl ON pl.id_product = od.product_id
                 AND pl.id_lang = 1 AND pl.id_shop = 1
             LEFT JOIN pr_manufacturer m ON m.id_manufacturer = p.id_manufacturer
             GROUP BY od.product_id, pl.name, pl.link_rewrite, m.name
             ORDER BY sold_qty DESC
             LIMIT " . self::SQL_CANDIDATE_LIMIT,
            [$months, $categoryId],
        );
    }

    /**
     * Nowosci: dodane < 90 dni (okno laski 21b), aktywne, w kategorii —
     * niezaleznie od sprzedazy. Zalozenie ADR-115: pr_product.date_add wiarygodne.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchNewArrivals(int $categoryId): array
    {
        return $this->mysql->fetchAll(
            "SELECT p.id_product AS product_id,
                    p.date_add,
                    pl.name,
                    pl.link_rewrite,
                    m.name AS brand
             FROM pr_product p
             JOIN pr_product_shop ps ON ps.id_product = p.id_product
                 AND ps.id_shop = 1 AND ps.active = 1
             JOIN pr_category_product cp ON cp.id_product = p.id_product
                 AND cp.id_category = ?
             JOIN pr_product_lang pl ON pl.id_product = p.id_product
                 AND pl.id_lang = 1 AND pl.id_shop = 1
             LEFT JOIN pr_manufacturer m ON m.id_manufacturer = p.id_manufacturer
             WHERE p.date_add >= DATE_SUB(NOW(), INTERVAL " . self::NEW_ARRIVAL_DAYS . " DAY)
             ORDER BY p.date_add DESC
             LIMIT " . self::SQL_CANDIDATE_LIMIT,
            [$categoryId],
        );
    }
}
