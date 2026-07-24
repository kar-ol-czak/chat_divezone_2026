<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;
use DiveChat\Shop\MysqlProductEnrichmentService;

/**
 * Wyszukiwanie pianek (skafandrów mokrych) po wymiarach ciała klienta, z przeliczeniem
 * rozmiaru osobno w charcie każdej marki (ADR-132, CHAT-T-163). Odwrotność
 * `recommend_wetsuit_size`: „mam takie wymiary → co mi pasuje i jest dostępne".
 *
 * Rozmiary marek NIE są porównywalne nazwowo (Scubapro „L" ≠ Bare „8T"). Dlatego
 * wymiary klienta przepuszczamy przez chart KAŻDEJ marki osobno — deterministyczne
 * przecięcie (ta sama logika co SizeRecommender, liczona w SQL: HAVING COUNT DISTINCT),
 * nie mapowanie etykieta→etykieta.
 *
 * Dostępność: `availability` z MysqlProductEnrichmentService (CHAT-T-062); `quantity`
 * na wariantach jest niewiarygodne (0/500 zaślepki) i CELOWO ignorowane (decyzja 18a).
 * Suche skafandry i marki bez chartu poza zakresem — te ostatnie zwracane w
 * `brands_without_chart` do konsultacji telefonicznej, NIE ukrywane.
 */
final class FindWetsuitsByMeasurements implements ToolInterface
{
    /** Wymiary równocenne (CHAT-T-161). Klient nie podaje `leg`. */
    private const MATCH_DIMS = ['chest', 'waist', 'hip', 'height', 'weight'];

    private const ID_LANG = 1;
    private const GROUP_SIZE = 27; // pr_attribute_group „Rozmiar" (jak w GetProductCombinations)
    private const CATEGORIES = [337, 367]; // kategorie skafandrów mokrych
    private const MAX_ALTERNATIVES = 8;

    private const F_THICKNESS = 'Grubość neoprenu';
    private const F_LENGTH = 'Długość pianki';
    private const F_GENDER = 'Damska / Męska';

    public function __construct(
        private readonly MysqlConnection $db,
        private readonly MysqlProductEnrichmentService $enrichment,
    ) {}

    public function getName(): string
    {
        return 'find_wetsuits_by_measurements';
    }

    public function getDescription(): string
    {
        return 'Wyszukuje skafandry MOKRE pasujące do wymiarów ciała klienta i dostępne w sklepie. '
             . 'Odwrotność recommend_wetsuit_size: klient podaje wymiary, narzędzie zwraca modele + rozmiar. '
             . 'Rozmiar liczony OSOBNO z tabeli każdej marki (Scubapro „L" nie znaczy tego samego co Bare „8T") — '
             . 'gdy podajesz rozmiar innej marki, ZAWSZE zaznacz, że to inne oznaczenie. '
             . 'WYMAGA płci. Filtry grubości/długości opcjonalne. Gdy klient wyszedł od konkretnego modelu, '
             . 'podaj reference_product_id — dostaniesz ten sam model na zamówienie PRZED alternatywami. '
             . 'Marki bez tabeli rozmiarów zwracane w brands_without_chart (rozmiar do potwierdzenia telefonicznie).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'gender' => [
                    'type' => 'string',
                    'enum' => ['M', 'K', 'DZIECI'],
                    'description' => 'Płeć — ZAWSZE zapytaj klienta, nie zgaduj (twarda reguła).',
                ],
                'chest' => ['type' => 'number', 'description' => 'Obwód klatki piersiowej [cm].'],
                'waist' => ['type' => 'number', 'description' => 'Obwód talii/pasa [cm].'],
                'hip' => ['type' => 'number', 'description' => 'Obwód bioder [cm].'],
                'height' => ['type' => 'number', 'description' => 'Wzrost [cm].'],
                'weight' => ['type' => 'number', 'description' => 'Waga [kg].'],
                'thickness' => [
                    'type' => 'string',
                    'description' => 'Grubość neoprenu — dokładna wartość cechy, np. "5 mm", "2+3mm". Filtr opcjonalny.',
                ],
                'length' => [
                    'type' => 'string',
                    'enum' => ['Długa', 'Shorty'],
                    'description' => 'Długość pianki. Filtr opcjonalny.',
                ],
                'reference_product_id' => [
                    'type' => 'integer',
                    'description' => 'id_product modelu, od którego wyszedł klient (gdy brak jego rozmiaru na stanie).',
                ],
            ],
            // Wymagane: gender + co najmniej jeden wymiar (walidowane w execute()).
            'required' => ['gender'],
        ];
    }

    public function execute(array $params): array
    {
        $gender = isset($params['gender']) ? strtoupper(trim((string) $params['gender'])) : null;
        if ($gender === null || $gender === '') {
            return ['error' => 'Brak płci. Zapytaj klienta: dla kobiety czy mężczyzny? (twarda reguła — nie zgaduj).'];
        }
        if (!in_array($gender, ['M', 'K', 'DZIECI'], true)) {
            return ['error' => "Nieobsługiwana płeć: {$gender}. Dozwolone: M, K, DZIECI."];
        }

        // Wymiary równocenne. Niepodane pomijamy. GUARD: pusty zbiór w przecięciu zwróciłby
        // wszystkie rozmiary (pułapka z _docs/44) — bez wymiaru odmawiamy.
        $dims = [];
        foreach (self::MATCH_DIMS as $d) {
            if (isset($params[$d]) && is_numeric($params[$d])) {
                $dims[$d] = (float) $params[$d];
            }
        }
        if ($dims === []) {
            return ['error' => 'Podaj co najmniej jeden wymiar ciała (np. obwód klatki, talii, '
                . 'bioder, wzrost lub wagę) — bez wymiaru nie da się dobrać pianki.'];
        }

        $thickness = isset($params['thickness']) && trim((string) $params['thickness']) !== ''
            ? trim((string) $params['thickness']) : null;
        $length = isset($params['length']) && trim((string) $params['length']) !== ''
            ? trim((string) $params['length']) : null;
        $refId = isset($params['reference_product_id']) ? (int) $params['reference_product_id'] : null;
        if ($refId !== null && $refId <= 0) {
            $refId = null;
        }

        // Marka produktu referencyjnego (do wykluczenia z „inne marki").
        $refBrand = null;
        if ($refId !== null) {
            $rc = $this->db->fetchOne(
                "SELECT c.brand
                 FROM divezone_attr_product_chart pc
                 JOIN divezone_attr_size_charts c ON c.id_chart = pc.id_chart
                 WHERE pc.id_product = ? AND c.category_hint = 'skafander' AND c.chart_type = 'progowy'
                 LIMIT 1",
                [$refId],
            );
            $refBrand = $rc['brand'] ?? null;
        }

        // Charty skafandrowe progowe dla płci (+ UNISEX, np. Tecline Proterm).
        $charts = $this->db->fetchAll(
            "SELECT id_chart, brand, gender
             FROM divezone_attr_size_charts
             WHERE chart_type = 'progowy' AND category_hint = 'skafander' AND gender IN (?, 'UNISEX')",
            [$gender],
        );

        // Dla każdej marki: przelicz rozmiar (KROK 1) i znajdź produkty oferujące go jako wariant (KROK 2).
        $candidates = []; // id_product => ['brand','sizes'=>set,'thickness'?,'length'?]
        foreach ($charts as $ch) {
            $chartId = (int) $ch['id_chart'];
            $sizes = $this->computeSizes($chartId, $dims);
            if ($sizes === []) {
                continue; // klient poza tabelą tej marki — nie zgadujemy (ADR-099)
            }
            $sizeSet = array_flip($sizes);
            $aliases = $this->loadAliases($chartId);
            $variants = $this->loadChartProductVariants($chartId);
            foreach ($variants as $pid => $labels) {
                $offered = [];
                foreach ($labels as $lbl) {
                    // resolveLabel = wspólna warstwa etykiet z SizeRecommender (public static, bez zmian zachowania).
                    $canon = SizeRecommender::resolveLabel($lbl, $aliases);
                    if (isset($sizeSet[$canon])) {
                        $offered[$canon] = true;
                    }
                }
                if ($offered === []) {
                    continue;
                }
                if (!isset($candidates[$pid])) {
                    $candidates[$pid] = ['brand' => (string) $ch['brand'], 'sizes' => []];
                }
                foreach (array_keys($offered) as $c) {
                    $candidates[$pid]['sizes'][$c] = true;
                }
            }
        }

        // KROK 3 — filtry cech (decyzja 15b). Produkt referencyjny NIE jest filtrowany
        // (to świadomy wybór klienta) — trafia do same_model niezależnie od grubości/długości.
        $pids = array_keys($candidates);
        $features = $this->loadFeatures($pids);
        foreach ($candidates as $pid => $c) {
            $f = $features[$pid] ?? [];
            if ($pid !== $refId) {
                if ($thickness !== null && ($f['thickness'] ?? null) !== $thickness) {
                    unset($candidates[$pid]);
                    continue;
                }
                if ($length !== null && ($f['length'] ?? null) !== $length) {
                    unset($candidates[$pid]);
                    continue;
                }
                if ($this->genderContradicts($gender, $f['gender'] ?? null)) {
                    unset($candidates[$pid]);
                    continue;
                }
            }
            $candidates[$pid]['thickness'] = $f['thickness'] ?? null;
            $candidates[$pid]['length'] = $f['length'] ?? null;
        }

        // KROK 4 — dostępność z enrichmentu; quantity IGNOROWANE (decyzja 18a). unavailable pomijamy.
        $pids = array_keys($candidates);
        $enrich = $pids !== [] ? $this->enrichment->enrich($pids) : [];

        $sameModel = [];
        $alternatives = [];
        foreach ($candidates as $pid => $c) {
            $e = $enrich[$pid] ?? null;
            $availability = $e['availability'] ?? 'unavailable';
            if ($availability === 'unavailable') {
                continue; // decyzja 18a + kryterium 6
            }
            $sizeLabel = implode(', ', array_keys($c['sizes']));

            if ($refId !== null && $pid === $refId) {
                $sameModel[] = [
                    'id' => $pid,
                    'name' => $e['name'] ?? null,
                    'size_label' => $sizeLabel,
                    'availability' => $availability,
                    'price' => $e['price'] ?? null,
                    'url' => $e['url'] ?? null,
                ];
                continue;
            }
            if ($refBrand !== null && $c['brand'] === $refBrand) {
                continue; // „inne marki" — pomijamy markę referencyjną (jej model jest w same_model)
            }
            $alternatives[] = [
                'id' => $pid,
                'name' => $e['name'] ?? null,
                'brand' => $c['brand'],
                'size_label' => $sizeLabel,
                'availability' => $availability,
                'price' => $e['price'] ?? null,
                'url' => $e['url'] ?? null,
                'thickness' => $c['thickness'],
                'length' => $c['length'],
            ];
        }

        // KROK 5 — kolejność: in_stock przed available_to_order (decyzja 16a), ale z
        // różnorodnością marek (round-robin po marce w obrębie progu dostępności). Bez tego
        // jedna marka z wieloma modelami wypełnia limit i wypiera alternatywy innych marek
        // (kryterium 1: Bare 8T i Mares 5 muszą się pojawić mimo wielu in_stock Scubapro).
        $rank = static fn(string $a): int => $a === 'in_stock' ? 0 : 1;
        usort($sameModel, static fn(array $x, array $y): int => $rank($x['availability']) <=> $rank($y['availability']));
        $alternatives = $this->diversifyByBrand($alternatives, self::MAX_ALTERNATIVES);

        return [
            'same_model' => $sameModel,
            'alternatives' => $alternatives,
            'measurements_used' => $dims,
            'brands_without_chart' => $this->brandsWithoutChart(),
        ];
    }

    /**
     * Układa alternatywy: najpierw próg in_stock, potem available_to_order (decyzja 16a);
     * WEWNĄTRZ każdego progu round-robin po marce (kolejność pierwszego wystąpienia), żeby
     * jedna marka nie zdominowała limitu i alternatywy pozostałych marek były widoczne.
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function diversifyByBrand(array $items, int $limit): array
    {
        $brandOrder = [];
        $tiers = ['in_stock' => [], 'available_to_order' => []];
        foreach ($items as $it) {
            $brand = (string) $it['brand'];
            if (!in_array($brand, $brandOrder, true)) {
                $brandOrder[] = $brand;
            }
            $tier = $it['availability'] === 'in_stock' ? 'in_stock' : 'available_to_order';
            $tiers[$tier][$brand][] = $it;
        }

        $out = [];
        foreach (['in_stock', 'available_to_order'] as $tier) {
            $buckets = $tiers[$tier];
            $picked = true;
            while ($picked) {
                $picked = false;
                foreach ($brandOrder as $brand) {
                    if (!empty($buckets[$brand])) {
                        $out[] = array_shift($buckets[$brand]);
                        $picked = true;
                        if (count($out) >= $limit) {
                            return $out;
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * KROK 1 — przecięcie wymiarów w charcie, liczone w SQL (HAVING COUNT DISTINCT =
     * liczba podanych). Ta sama logika co SizeRecommender, ale w bazie — NIE zestawiamy
     * trafień w PHP (pułapka z _docs/44). @return list<string> etykiety rozmiarów (canonical).
     */
    private function computeSizes(int $chartId, array $dims): array
    {
        $conds = [];
        $params = [$chartId];
        foreach ($dims as $dim => $val) {
            $conds[] = '(r.dimension = ? AND ? BETWEEN r.min_val AND r.max_val)';
            $params[] = $dim;
            $params[] = $val;
        }
        $params[] = count($dims);

        $rows = $this->db->fetchAll(
            'SELECT r.size_label
             FROM divezone_attr_size_chart_rows r
             WHERE r.id_chart = ? AND (' . implode(' OR ', $conds) . ')
             GROUP BY r.size_label
             HAVING COUNT(DISTINCT r.dimension) = ?',
            $params,
        );

        return array_map(static fn(array $r): string => (string) $r['size_label'], $rows);
    }

    /**
     * KROK 2 — etykiety wariantów rozmiaru (pr_attribute_group=27) dla produktów tej marki.
     * @return array<int, list<string>> id_product => surowe etykiety wariantów (przed resolveLabel).
     */
    private function loadChartProductVariants(int $chartId): array
    {
        $sql = sprintf(
            'SELECT pc.id_product, al.name AS variant_label
             FROM divezone_attr_product_chart pc
             JOIN pr_product p ON p.id_product = pc.id_product AND p.active = 1
             JOIN pr_product_attribute pa ON pa.id_product = pc.id_product
             JOIN pr_product_attribute_combination pac ON pac.id_product_attribute = pa.id_product_attribute
             JOIN pr_attribute a ON a.id_attribute = pac.id_attribute AND a.id_attribute_group = %1$d
             JOIN pr_attribute_lang al ON al.id_attribute = a.id_attribute AND al.id_lang = %2$d
             WHERE pc.id_chart = ?',
            self::GROUP_SIZE,
            self::ID_LANG,
        );
        $rows = $this->db->fetchAll($sql, [$chartId]);

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['id_product']][] = (string) $r['variant_label'];
        }

        return $out;
    }

    /** Mapa alias_label → canonical_label dla charta (jak SizeRecommender::loadAliases). @return array<string,string> */
    private function loadAliases(int $chartId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT alias_label, canonical_label FROM divezone_attr_size_label_alias WHERE id_chart = ?',
            [$chartId],
        );
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['alias_label']] = (string) $r['canonical_label'];
        }

        return $map;
    }

    /**
     * Cechy strukturalne (grubość, długość, płeć) — TEKSTEM, bez parsowania na liczby
     * (`2+3mm`, `2,5 mm`, `lycra` rozsypią parser).
     * @param list<int> $pids
     * @return array<int, array{thickness?: string, length?: string, gender?: string}>
     */
    private function loadFeatures(array $pids): array
    {
        if ($pids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($pids), '?'));
        $sql = sprintf(
            'SELECT fp.id_product, fl.name AS fname, fvl.value AS fval
             FROM pr_feature_product fp
             JOIN pr_feature_lang fl ON fl.id_feature = fp.id_feature AND fl.id_lang = %1$d
             JOIN pr_feature_value_lang fvl ON fvl.id_feature_value = fp.id_feature_value AND fvl.id_lang = %1$d
             WHERE fp.id_product IN (%2$s)',
            self::ID_LANG,
            $placeholders,
        );
        $rows = $this->db->fetchAll($sql, $pids);

        $out = [];
        foreach ($rows as $r) {
            $pid = (int) $r['id_product'];
            $name = (string) $r['fname'];
            $val = (string) $r['fval'];
            if ($name === self::F_THICKNESS) {
                $out[$pid]['thickness'] = $val;
            } elseif ($name === self::F_LENGTH) {
                $out[$pid]['length'] = $val;
            } elseif ($name === self::F_GENDER) {
                $out[$pid]['gender'] = $val;
            }
        }

        return $out;
    }

    /**
     * Sprzeczność płci z cechą „Damska / Męska". Brak cechy albo wartość unisex
     * (zawiera oba) → NIE wyklucza. Wyklucza tylko jednoznaczną sprzeczność.
     */
    private function genderContradicts(string $gender, ?string $featureGender): bool
    {
        if ($featureGender === null || $featureGender === '') {
            return false;
        }
        $fg = mb_strtolower($featureGender);
        $hasD = str_contains($fg, 'damsk');
        $hasM = str_contains($fg, 'męsk') || str_contains($fg, 'mesk');
        if ($gender === 'M') {
            return $hasD && !$hasM;
        }
        if ($gender === 'K') {
            return $hasM && !$hasD;
        }

        return false; // DZIECI/UNISEX — nie wykluczamy po tej cesze
    }

    /**
     * Marki mające w kategoriach skafandrowych modele BEZ chartu (39 produktów) — do
     * konsultacji telefonicznej, NIE ukrywamy ich istnienia. @return list<string>
     */
    private function brandsWithoutChart(): array
    {
        $sql = sprintf(
            "SELECT DISTINCT m.name
             FROM pr_product p
             JOIN pr_category_product cp ON cp.id_product = p.id_product AND cp.id_category IN (%1\$d, %2\$d)
             JOIN pr_manufacturer m ON m.id_manufacturer = p.id_manufacturer
             LEFT JOIN divezone_attr_product_chart pc ON pc.id_product = p.id_product
             WHERE p.active = 1 AND pc.id_product IS NULL AND m.name <> ''
             ORDER BY m.name",
            self::CATEGORIES[0],
            self::CATEGORIES[1],
        );
        $rows = $this->db->fetchAll($sql);

        return array_map(static fn(array $r): string => (string) $r['name'], $rows);
    }
}
