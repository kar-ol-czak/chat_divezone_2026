<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\PostgresConnection;
use DiveChat\Shop\MysqlProductEnrichmentService;

/**
 * Kuratorowane rekomendacje produktowe (ADR-065 MVP, T-029).
 *
 * Warstwa miedzy zywa baza a wiedza zespolu. Bot przy pytaniu o DOBOR ("jaki
 * komputer na start", "automat na Malediwy", "pianka w nietypowym rozmiarze")
 * pobiera 1-3 RECZNIE wybrane produkty per kategoria + rationale; cena/dostepnosc
 * z zywego MySQL (TWARDY staleness: produkt active=false -> pomijamy).
 *
 * Lista kategorii (category_key + opis) zaszyta w enum schema, generowana per
 * request z divechat_curated_recommendations — gdy zespol doda kategorie w SQL,
 * LLM od razu moze ja wybrac bez deploy.
 *
 * NIE do wyszukiwania konkretnego modelu (to search_products).
 * NIE do wiedzy ogolnej o sprzecie (to get_expert_knowledge).
 */
final class CuratedRecommendations implements ToolInterface
{
    public function __construct(
        private readonly PostgresConnection $db,
        private readonly MysqlProductEnrichmentService $enrichmentService,
    ) {}

    public function getName(): string
    {
        return 'get_curated_recommendations';
    }

    public function getDescription(): string
    {
        return 'Reczne rekomendacje zespolu divezone dla pytan o DOBOR produktu, gdzie liczy sie osad ekspercki '
             . '("jaki komputer na start", "automat na egzotyke", "pianka w nietypowym rozmiarze"). '
             . 'Zwraca 1-3 produkty z uzasadnieniem dla wybranej kategorii. Cena i dostepnosc real-time z MySQL. '
             . 'NIE uzywaj do wyszukiwania konkretnego modelu (to search_products), '
             . 'ani do wiedzy ogolnej o sprzecie (to get_expert_knowledge). '
             . 'Wybierz kategorie z listy enum (parametr category) — jesli zadne pytanie klienta NIE pasuje '
             . 'do zadnej z kategorii, NIE wywoluj tego narzedzia.';
    }

    public function getParametersSchema(): array
    {
        $categories = $this->fetchAvailableCategories();

        // Brak skuratorowanych kategorii (tabela pusta lub wszystkie active=false).
        // Zwracamy schema z enum=[] zeby LLM widzial ze narzedzie istnieje, ale nie ma
        // czego wybrac — w praktyce model i tak nie wywola toola bez pasujacej kategorii.
        if (empty($categories)) {
            return [
                'type' => 'object',
                'properties' => [
                    'category' => [
                        'type' => 'string',
                        'description' => 'Aktualnie brak skuratorowanych kategorii — uzyj search_products lub get_expert_knowledge.',
                        'enum' => [],
                    ],
                ],
                'required' => ['category'],
            ];
        }

        $enum = array_map(static fn(array $r): string => $r['category_key'], $categories);
        $descriptionLines = array_map(
            static fn(array $r): string => "- {$r['category_key']}: {$r['category_label_pl']}",
            $categories,
        );
        $description = "Klucz kuratorowanej kategorii doboru. Wybierz pasujaca do intencji klienta:\n"
                     . implode("\n", $descriptionLines);

        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'enum' => $enum,
                    'description' => $description,
                ],
            ],
            'required' => ['category'],
        ];
    }

    public function execute(array $params): array
    {
        $category = (string) ($params['category'] ?? '');
        if ($category === '') {
            return [
                'category' => null,
                'status' => 'error',
                'message' => 'Parametr category jest wymagany.',
                'products' => [],
            ];
        }

        $rows = $this->db->fetchAll(
            "SELECT id, category_key, category_label_pl, product_id, priority, rationale_pl,
                    verified_at, recheck_interval_days
             FROM divechat_curated_recommendations
             WHERE category_key = ? AND active = TRUE
             ORDER BY priority ASC, id ASC",
            [$category],
        );

        if (empty($rows)) {
            return [
                'category' => $category,
                'category_label' => null,
                'status' => 'unknown_category',
                'message' => 'Brak skuratorowanych rekomendacji dla tej kategorii. Uzyj search_products lub zaproponuj kontakt.',
                'curated_count' => 0,
                'available_count' => 0,
                'products' => [],
            ];
        }

        $categoryLabel = $rows[0]['category_label_pl'];
        $productIds = array_map(static fn(array $r): int => (int) $r['product_id'], $rows);
        $enriched = $this->enrichmentService->enrich($productIds);

        $available = [];
        $skipped = [];
        foreach ($rows as $row) {
            $productId = (int) $row['product_id'];
            $data = $enriched[$productId] ?? null;

            // TWARDY staleness (ADR-065): brak danych MySQL lub produkt nieaktywny/niewidoczny
            // -> pomijamy + logujemy (kandydat do alertu, MVP bez alertu).
            if ($data === null || !$data['active'] || !$data['visible']) {
                $reason = $data === null ? 'mysql_missing' : (!$data['active'] ? 'active=false' : 'visibility=none');
                error_log("[DiveChat curated] product_id={$productId} category={$category} skipped: {$reason}");
                $skipped[] = ['product_id' => $productId, 'reason' => $reason];
                continue;
            }

            // Dla MVP: zwracamy wszystkie aktywne (in_stock + available_to_order),
            // pomijamy tylko 'unavailable'. Bot widzi availability i informuje klienta.
            if ($data['availability'] === 'unavailable') {
                $skipped[] = ['product_id' => $productId, 'reason' => 'unavailable'];
                continue;
            }

            $available[] = [
                'product_id' => $productId,
                'priority' => (int) $row['priority'],
                'rationale_pl' => $row['rationale_pl'],
                'price' => $data['price'],
                'price_before_discount' => $data['price_before_discount'] ?? null,
                'availability' => $data['availability'],
                'verified_at' => $row['verified_at'],
            ];
        }

        // ZERO dostepnych -> sygnal dla bota by zrobil fallback (redirect na kontakt).
        if (empty($available)) {
            return [
                'category' => $category,
                'category_label' => $categoryLabel,
                'status' => 'no_available',
                'message' => 'Skuratorowane rekomendacje sa, ale aktualnie wszystkie sa niedostepne — zaproponuj klientowi kontakt: dive@divezone.pl lub 56 307 03 03.',
                'curated_count' => count($rows),
                'available_count' => 0,
                'skipped' => $skipped,
                'products' => [],
            ];
        }

        return [
            'category' => $category,
            'category_label' => $categoryLabel,
            'status' => 'ok',
            'curated_count' => count($rows),
            'available_count' => count($available),
            'skipped' => $skipped,
            'products' => $available,
        ];
    }

    /**
     * @return list<array{category_key: string, category_label_pl: string}>
     */
    private function fetchAvailableCategories(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT category_key, category_label_pl
             FROM divechat_curated_recommendations
             WHERE active = TRUE
             ORDER BY category_key ASC",
        );

        return array_map(
            static fn(array $r): array => [
                'category_key' => $r['category_key'],
                'category_label_pl' => $r['category_label_pl'],
            ],
            $rows,
        );
    }
}
