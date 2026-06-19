<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;

/**
 * Deterministyczny dobór rozmiaru skafandra mokrego (Scubapro / Bare).
 * CHAT-T-100 / ADR-099 (+ ADDENDUM 099b). Port logiki z embeddings/size_matcher.py.
 * CHAT-T-103: źródło prawdy rozmiarów przeniesione na MySQL PrestaShop
 * (divezone_attr_*, ATTR-T-001) — wcześniej Railway/PG divechat_size_*. Logika doboru bez zmian.
 *
 * NIE embeddingi — relacyjny lookup w divezone_attr_size_* (SQL BETWEEN / wartości punktowe).
 *  - chart przedziałowy (dorośli, min≠max): klatka piersiowa wiodąca, reszta weryfikuje;
 *  - chart punktowy (dzieci Rebel, gender='DZIECI', height min==max): dobór po wzroście.
 *
 * Suche skafandry POZA zakresem (reguła SystemPrompt: konsultacja, narzędzie NIE wołane).
 */
final class SizeRecommender implements ToolInterface
{
    /** Wymiary weryfikujące (poza wiodącą klatką). Klient nie podaje `leg`. */
    private const VERIFY_DIMS = ['waist', 'hip', 'height', 'weight'];

    public function __construct(
        private readonly MysqlConnection $db,
    ) {}

    public function getName(): string
    {
        return 'recommend_wetsuit_size';
    }

    public function getDescription(): string
    {
        return 'Dobiera rozmiar skafandra MOKREGO (marki Scubapro / Bare) deterministycznie '
             . 'na podstawie wymiarów ciała. WYMAGA płci — ZAWSZE zapytaj klienta "dla kobiety '
             . 'czy mężczyzny?", nie zgaduj. Dla dorosłych wiodący jest obwód klatki piersiowej '
             . '(chest) — poproś o niego. Dla dzieci (pianki Rebel) wiodący jest wzrost (height). '
             . 'NIE używaj dla skafandrów SUCHYCH (dobór wymaga pełnej miary + konsultacji z dostawcą). '
             . 'Gdy klatka/wzrost wypada między rozmiary lub poza skalę — zwraca dwa najbliższe '
             . '+ flagę konsultacji; ZERO ekstrapolacji.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'id_product PrestaShop — chart wybierany przez mapowanie produkt→chart. '
                        . 'Gdy brak, podaj brand + gender.',
                ],
                'brand' => [
                    'type' => 'string',
                    'enum' => ['Scubapro', 'Bare'],
                    'description' => 'Marka — alternatywnie do product_id, gdy znana tylko marka.',
                ],
                'gender' => [
                    'type' => 'string',
                    'enum' => ['M', 'K', 'DZIECI'],
                    'description' => 'Płeć — ZAWSZE zapytaj klienta, nie zgaduj. '
                        . 'DZIECI = dziecięce pianki Rebel (dobór po wzroście).',
                ],
                'chest' => [
                    'type' => 'number',
                    'description' => 'Obwód klatki piersiowej [cm] — WIODĄCY dla dorosłych (wymagany dla M/K).',
                ],
                'waist' => [
                    'type' => 'number',
                    'description' => 'Obwód talii/pasa [cm] — weryfikujący.',
                ],
                'hip' => [
                    'type' => 'number',
                    'description' => 'Obwód bioder [cm] — weryfikujący.',
                ],
                'height' => [
                    'type' => 'number',
                    'description' => 'Wzrost [cm] — weryfikujący u dorosłych, WIODĄCY dla dzieci (wymagany dla DZIECI).',
                ],
                'weight' => [
                    'type' => 'number',
                    'description' => 'Waga [kg] — weryfikujący/rozróżniający.',
                ],
            ],
            // chest/height walidowane w execute() zależnie od typu charta (dorosły vs dziecięcy).
            'required' => ['gender'],
        ];
    }

    public function execute(array $params): array
    {
        $gender = isset($params['gender']) ? strtoupper(trim((string) $params['gender'])) : null;
        $brand = isset($params['brand']) ? trim((string) $params['brand']) : null;
        $productId = isset($params['product_id']) ? (int) $params['product_id'] : null;
        $chest = isset($params['chest']) ? (float) $params['chest'] : null;
        $verify = [
            'waist' => isset($params['waist']) ? (float) $params['waist'] : null,
            'hip' => isset($params['hip']) ? (float) $params['hip'] : null,
            'height' => isset($params['height']) ? (float) $params['height'] : null,
            'weight' => isset($params['weight']) ? (float) $params['weight'] : null,
        ];

        if ($gender === null || $gender === '') {
            return ['error' => 'Brak płci. Zapytaj klienta: dla kobiety czy mężczyzny? (twarda reguła — nie zgaduj).'];
        }
        if (!in_array($gender, ['M', 'K', 'DZIECI'], true)) {
            return ['error' => "Nieobsługiwana płeć: {$gender}. Dozwolone: M, K, DZIECI."];
        }

        $chart = $this->resolveChart($productId, $brand, $gender);
        if ($chart === null) {
            return [
                'error' => 'Nie znaleziono tabeli rozmiarów dla podanych danych. '
                    . 'Podaj product_id zmapowanego skafandra mokrego LUB brand (Scubapro/Bare) + gender. '
                    . 'Dostępne charty: Scubapro (M/K/DZIECI), Bare (M/K).',
            ];
        }

        $rows = $this->loadChartRows($chart['id']);
        if ($rows === []) {
            return ['error' => 'Tabela rozmiarów jest pusta dla wybranego charta.'];
        }
        $sizes = $this->buildSizes($rows);

        // Chart dziecięcy / punktowy → dobór po wzroście (NIE klatce).
        $pointwise = $chart['gender'] === 'DZIECI' || $this->isPointwise($sizes, 'height');

        if ($pointwise) {
            $height = $verify['height'];
            if ($height === null) {
                return ['error' => 'Chart dziecięcy/punktowy — podaj wzrost dziecka (height, cm). '
                    . 'To wymiar wiodący dla pianek dziecięcych.'];
            }
            $result = $this->matchPointwise($sizes, $height, 'height');
        } else {
            if ($chest === null) {
                return ['error' => 'Brak obwodu klatki piersiowej (chest) — wymiar wiodący dla dorosłych. '
                    . 'Poproś klienta o obwód klatki.'];
            }
            $result = $this->matchSize($sizes, $chest, $verify);
        }

        // Pełne nazwy rozmiarów (Scubapro "L - 52"; Bare = label) + metadane charta.
        $result['size_full'] = array_map(fn(string $l): string => $this->fullFor($sizes, $l), $result['sizes']);
        $result['brand'] = $chart['brand'];
        $result['gender'] = $chart['gender'];

        // Warstwa aliasów (CHAT-T-099b 45c): wariant PrestaShop -> etykieta charta.
        // Pozwala botowi rozpoznać niestandardowe etykiety w sklepie (np. "M tall" == "MT").
        $aliases = $this->loadAliases($chart['id']);
        if ($aliases !== []) {
            $result['aliases'] = $aliases;
        }

        return $result;
    }

    /**
     * Wybór charta: 1) product_id przez mapowanie (bi-gender → po płci klienta);
     * 2) fallback brand + gender. Zwraca ['id','brand','gender'] albo null.
     *
     * @return array{id: int|string, brand: string, gender: string}|null
     */
    private function resolveChart(?int $productId, ?string $brand, string $gender): ?array
    {
        if ($productId !== null) {
            $charts = $this->db->fetchAll(
                'SELECT c.id_chart AS id, c.brand, c.gender
                 FROM divezone_attr_product_chart pc
                 JOIN divezone_attr_size_charts c ON pc.id_chart = c.id_chart
                 WHERE pc.id_product = ?
                 ORDER BY c.gender',
                [$productId],
            );
            if (count($charts) === 1) {
                // Pojedyncze mapowanie (jednopłciowy lub dziecięcy) — bierzemy wprost.
                return $charts[0];
            }
            if (count($charts) > 1) {
                // Produkt bi-gender (M + K) — płeć klienta wybiera właściwy chart.
                foreach ($charts as $c) {
                    if (strtoupper((string) $c['gender']) === $gender) {
                        return $c;
                    }
                }
                // Płeć klienta nie pasuje do mapowań — spróbuj brand+gender niżej.
            }
            // Brak mapowania dla product_id — fallback do brand+gender.
        }

        if ($brand !== null && $brand !== '') {
            return $this->db->fetchOne(
                'SELECT id_chart AS id, brand, gender FROM divezone_attr_size_charts WHERE brand = ? AND gender = ?',
                [$brand, $gender],
            );
        }

        return null;
    }

    /** @return list<array<string, mixed>> */
    private function loadChartRows(int|string $chartId): array
    {
        return $this->db->fetchAll(
            'SELECT r.size_label, r.size_full, r.dimension, r.min_val, r.max_val, r.sort_order
             FROM divezone_attr_size_chart_rows r
             WHERE r.id_chart = ?
             ORDER BY r.sort_order',
            [$chartId],
        );
    }

    /**
     * Z wierszy (size_label, dimension, min_val, max_val, sort_order) buduje listę rozmiarów
     * [{label, full, sort, dims:{dim:[min,max]}}], posortowaną po sort_order. (build_sizes z PY).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{label: string, full: string, sort: int, dims: array<string, array{0: float, 1: float}>}>
     */
    private function buildSizes(array $rows): array
    {
        $byLabel = [];
        foreach ($rows as $r) {
            $lbl = (string) $r['size_label'];
            if (!isset($byLabel[$lbl])) {
                $byLabel[$lbl] = [
                    'label' => $lbl,
                    'full' => ($r['size_full'] ?? null) !== null && $r['size_full'] !== ''
                        ? (string) $r['size_full']
                        : $lbl,
                    'sort' => (int) ($r['sort_order'] ?? 0),
                    'dims' => [],
                ];
            }
            $byLabel[$lbl]['dims'][(string) $r['dimension']] = [(float) $r['min_val'], (float) $r['max_val']];
        }
        $sizes = array_values($byLabel);
        usort($sizes, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $sizes;
    }

    /** Chart punktowy na wymiarze $dim (min==max we wszystkich wierszach). (is_pointwise z PY). */
    private function isPointwise(array $sizes, string $dim): bool
    {
        $found = false;
        foreach ($sizes as $s) {
            if (!isset($s['dims'][$dim])) {
                continue;
            }
            $found = true;
            if ($s['dims'][$dim][0] !== $s['dims'][$dim][1]) {
                return false;
            }
        }

        return $found;
    }

    /**
     * Algorytm przedziałowy (dorośli) — wierny port match_size() z size_matcher.py.
     * Klatka piersiowa wiodąca, reszta weryfikuje. ZERO ekstrapolacji poza skalę.
     *
     * @param array<string, float|null> $verify
     */
    private function matchSize(array $sizes, float $chest, array $verify): array
    {
        $given = array_filter(
            $verify,
            static fn(?float $v): bool => $v !== null,
        );

        // (ile wymiarów weryfikujących pasuje, ile sprawdzono).
        $verifyScore = function (array $s) use ($given): array {
            $ok = 0;
            $checked = 0;
            foreach ($given as $dim => $val) {
                if (isset($s['dims'][$dim])) {
                    $checked++;
                    if ($this->inRange($val, $s['dims'][$dim])) {
                        $ok++;
                    }
                }
            }
            return [$ok, $checked];
        };

        // 1. Rozmiary, w których chest klienta ∈ [min,max].
        $chestHits = array_values(array_filter(
            $sizes,
            fn(array $s): bool => isset($s['dims']['chest']) && $this->inRange($chest, $s['dims']['chest']),
        ));

        if (count($chestHits) === 1) {
            $s = $chestHits[0];
            [$ok, $checked] = $verifyScore($s);
            if ($checked === 0 || $ok * 2 >= $checked) {
                return [
                    'decision' => 'match',
                    'sizes' => [$s['label']],
                    'consult' => false,
                    'reason' => 'chest trafia w jeden rozmiar, wymiary weryfikujące zgodne',
                ];
            }
            return [
                'decision' => 'ambiguous',
                'sizes' => [$s['label']],
                'consult' => true,
                'reason' => 'chest trafia w rozmiar, ale wymiary weryfikujące się nie zgadzają',
            ];
        }

        if (count($chestHits) >= 2) {
            // 3. chest trafia w ≥2 rozmiary → rozróżnij wzrostem/wagą (najwięcej zgodnych wygrywa).
            $scored = [];
            $bestOk = 0;
            foreach ($chestHits as $s) {
                [$ok, $checked] = $verifyScore($s);
                $scored[] = ['ok' => $ok, 's' => $s];
                if ($ok > $bestOk) {
                    $bestOk = $ok;
                }
            }
            $winners = array_values(array_filter($scored, static fn(array $x): bool => $x['ok'] === $bestOk));
            if (count($winners) === 1 && $bestOk > 0) {
                return [
                    'decision' => 'match',
                    'sizes' => [$winners[0]['s']['label']],
                    'consult' => false,
                    'reason' => 'chest w kilku rozmiarach, rozróżnienie po wzroście/wadze',
                ];
            }
            $labels = array_map(static fn(array $s): string => $s['label'], $chestHits);
            return [
                'decision' => 'ambiguous',
                'sizes' => $labels,
                'consult' => true,
                'reason' => 'chest pasuje do kilku rozmiarów, brak jednoznacznego rozróżnienia',
            ];
        }

        // 4. chest NIE trafia w żaden → dwa najbliższe po odległości chest + konsultacja.
        $byDist = $sizes;
        usort($byDist, fn(array $a, array $b): int =>
            $this->distToRange($chest, $a['dims']['chest'] ?? [0.0, 0.0])
            <=> $this->distToRange($chest, $b['dims']['chest'] ?? [0.0, 0.0]));
        $nearest = array_map(static fn(array $s): string => $s['label'], array_slice($byDist, 0, 2));

        return [
            'decision' => 'out_of_scale',
            'sizes' => $nearest,
            'consult' => true,
            'reason' => 'klatka piersiowa między rozmiarami lub poza skalą — bez ekstrapolacji',
        ];
    }

    /**
     * Dobór po wymiarze PUNKTOWYM (dzieci Rebel) — wierny port match_pointwise() z size_matcher.py.
     * Trafienie dokładne → match; między dwoma → boundary (graniczny); poza → out_of_scale.
     */
    private function matchPointwise(array $sizes, float $value, string $dim = 'height'): array
    {
        $pts = [];
        foreach ($sizes as $s) {
            if (isset($s['dims'][$dim])) {
                $pts[] = [$s['dims'][$dim][0], $s];
            }
        }
        usort($pts, static fn(array $a, array $b): int => $a[0] <=> $b[0]);

        if ($pts === []) {
            return [
                'decision' => 'out_of_scale',
                'sizes' => [],
                'consult' => true,
                'graniczny' => false,
                'reason' => "chart nie ma wymiaru {$dim}",
            ];
        }

        $exact = array_values(array_filter($pts, static fn(array $p): bool => $p[0] == $value));
        if ($exact !== []) {
            return [
                'decision' => 'match',
                'sizes' => [$exact[0][1]['label']],
                'consult' => false,
                'graniczny' => false,
                'reason' => "{$dim} trafia dokładnie w rozmiar",
            ];
        }

        $lo = $pts[0][0];
        $hi = $pts[count($pts) - 1][0];
        if ($value < $lo || $value > $hi) {
            $nearest = $pts;
            usort($nearest, static fn(array $a, array $b): int => abs($a[0] - $value) <=> abs($b[0] - $value));
            $labels = array_map(static fn(array $p): string => $p[1]['label'], array_slice($nearest, 0, 2));
            return [
                'decision' => 'out_of_scale',
                'sizes' => $labels,
                'consult' => true,
                'graniczny' => false,
                'reason' => "{$dim} poza skalą dziecięcego charta — bez ekstrapolacji",
            ];
        }

        // value między dwoma kolejnymi rozmiarami → graniczny.
        for ($i = 0; $i < count($pts) - 1; $i++) {
            if ($pts[$i][0] < $value && $value < $pts[$i + 1][0]) {
                $pair = [$pts[$i][1]['label'], $pts[$i + 1][1]['label']];
                return [
                    'decision' => 'boundary',
                    'sizes' => $pair,
                    'consult' => true,
                    'graniczny' => true,
                    'reason' => 'wzrost między dwoma rozmiarami — dwa najbliższe, większy to świadomy '
                        . 'kompromis (pianka musi przylegać, decyzja rodzica)',
                ];
            }
        }

        // Nie powinno się zdarzyć (wartości unikalne), defensywnie:
        return [
            'decision' => 'out_of_scale',
            'sizes' => [$pts[0][1]['label']],
            'consult' => true,
            'graniczny' => false,
            'reason' => 'nierozstrzygnięte',
        ];
    }

    /** Pełna nazwa rozmiaru (size_full) dla etykiety; fallback do samej etykiety. */
    private function fullFor(array $sizes, string $label): string
    {
        foreach ($sizes as $s) {
            if ($s['label'] === $label) {
                return $s['full'];
            }
        }
        return $label;
    }

    /**
     * Mapa alias_label → canonical_label dla charta (CHAT-T-099b 45c). {} gdy brak.
     * (load_aliases z size_matcher.py / map_size_products.py).
     *
     * @return array<string, string>
     */
    private function loadAliases(int|string $chartId): array
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
     * Normalizacja etykiety "plus" Bare: "6 Plus" → "6+" (norm_label z map_size_products.py).
     * Etykieta wariantu PrestaShop → etykieta charta (warstwa prezentacji / matchu do oferty).
     */
    public static function normalizeLabel(string $label): string
    {
        return preg_replace('/^(\d+)\s*Plus$/', '$1+', trim($label)) ?? trim($label);
    }

    /**
     * Rozwiązanie etykiety wariantu PrestaShop do etykiety charta przez warstwę aliasów + regex.
     * (resolve_label z size_matcher.py). Używane przy matchu rozmiaru do oferty/prezentacji.
     *
     * @param array<string, string> $aliases
     */
    public static function resolveLabel(string $label, array $aliases): string
    {
        $normalized = self::normalizeLabel($label);
        return $aliases[$normalized] ?? ($aliases[$label] ?? $normalized);
    }

    private function inRange(float $val, array $rng): bool
    {
        return $rng[0] <= $val && $val <= $rng[1];
    }

    private function distToRange(float $val, array $rng): float
    {
        if ($val < $rng[0]) {
            return $rng[0] - $val;
        }
        if ($val > $rng[1]) {
            return $val - $rng[1];
        }
        return 0.0;
    }
}
