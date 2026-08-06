<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;

/**
 * Deterministyczny dobór rozmiaru z tabel divezone_attr_* (wszystkie marki).
 * CHAT-T-100 / ADR-099 (+ ADDENDUM 099b). Port logiki z embeddings/size_matcher.py.
 * CHAT-T-103: źródło prawdy rozmiarów przeniesione na MySQL PrestaShop
 * (divezone_attr_*, ATTR-T-001) — wcześniej Railway/PG divechat_size_*. Logika doboru bez zmian.
 * CHAT-T-165 / ADR-133: zakres rozszerzony poza skafandry na rękawice, kaptury, buty i pierścienie.
 *
 * NIE embeddingi — relacyjny lookup w divezone_attr_size_* (SQL BETWEEN / wartości punktowe).
 *  - chart `progowy` (dorośli, min≠max): przecięcie wymiarów — wszystkie podane wymiary
 *    równocenne, wynik to rozmiary mieszczące każdy z nich; im więcej podanych, tym węziej;
 *    wymiary czytane DYNAMICZNIE z charta (§3.1) — skafander chest/height/hip/waist/weight,
 *    rękawica hand_circ/palm_length, kaptur head_circ/neck, but foot_length/shoe_eu;
 *  - chart punktowy (dzieci Rebel, gender='DZIECI', height min==max): dobór po wzroście;
 *  - chart `tresciowy` (buty suche, buty Scubapro, pierścienie VDS): brak wierszy wymiarowych —
 *    zwraca surową tabelę HTML (decision=content_table), model ją cytuje bez interpolacji (ADR-133);
 *  - chart progowy BEZ osi doboru (ATTR-T-076 / ADR-039): wszystkie wymiary to wymiary produktu
 *    `g_*` spoza schematu narzędzia (przecięcie ze SCHEMA_DIMS puste, 9 chartów odzieży Santi/Mola
 *    Mola) — zwraca listę rozmiarów z size_full (decision=size_list_only), NIE prosi o wymiary,
 *    odsyła do tabeli na karcie produktu.
 *
 * Suche skafandry (drysuit) POZA zakresem (reguła SystemPrompt: konsultacja, narzędzie NIE wołane).
 */
final class SizeRecommender implements ToolInterface
{
    /** Wymiary wyłączone z doboru mimo obecności w charcie (klient ich nie podaje). `leg` — ADR-032 aneks 1. */
    private const EXCLUDED_DIMS = ['leg'];

    /**
     * Wymiary przyjmowane przez schemat narzędzia (getParametersSchema properties minus klucze
     * sterujące product_id/brand/category/gender), w kolejności ze schematu. Źródło prawdy dla
     * strażnika ADR-039: chart, którego wymiary NIE przecinają się z tym zbiorem, nie jest chartem
     * doboru. Rozjazd tej stałej ze schematem wychodzi w teście K7, nie u klienta (ADR-039 D5).
     */
    // ATTR-T-082 (Z1): public, aby ProductDetails czytał listę ze źródła, nie z kopii.
    // Kopia byłaby trzecim miejscem z tą samą listą (po stałej i po zapytaniu walidacyjnym V2).
    public const SCHEMA_DIMS = [
        'chest', 'waist', 'hip', 'height', 'weight',
        'foot_length', 'shoe_eu', 'hand_circ', 'palm_length', 'head_circ', 'neck',
    ];

    public function __construct(
        private readonly MysqlConnection $db,
    ) {}

    public function getName(): string
    {
        return 'recommend_wetsuit_size';
    }

    public function getDescription(): string
    {
        return 'Dobiera rozmiar deterministycznie na podstawie wymiarów ciała — obsługuje skafandry MOKRE, '
             . 'rękawice, kaptury, buty i pierścienie suchego skafandra. '
             . 'WYMAGA płci — ZAWSZE zapytaj klienta "dla kobiety czy mężczyzny?", nie zgaduj. '
             . 'Pytaj o wymiary właściwe dla kategorii: skafander — obwód klatki/talii/bioder, wzrost, waga; '
             . 'rękawica — obwód dłoni (hand_circ), długość dłoni (palm_length); '
             . 'kaptur — obwód głowy (head_circ), obwód szyi (neck); '
             . 'but — rozmiar buta EU (shoe_eu, numerówka, np. 42) i/lub długość stopy (foot_length). '
             . 'Każdy podany wymiar zawęża wynik. '
             . 'Dla dzieci (pianki Rebel) wiodący jest wzrost (height). '
             . 'Dla butów suchych, butów Scubapro i pierścieni narzędzie zwraca gotową tabelę przeliczeń '
             . '(decision=content_table, pole content_html) — NIE wymaga wtedy wymiarów; przedstaw ją, '
             . 'cytując wyłącznie wiersze z tabeli, bez interpolacji. '
             . 'Wybór tabeli: podaj product_id; przy podaniu samej marki (brand) MUSISZ dodać category '
             . '(marka ma tabele w wielu kategoriach) — nie zgaduj. '
             . 'NIE używaj dla skafandrów SUCHYCH (drysuit — dobór wymaga pełnej miary + konsultacji z dostawcą). '
             . 'Gdy wymiary wypadają między rozmiary lub poza skalę — zwraca najbliższe '
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
                    'description' => 'Marka — alternatywnie do product_id, gdy produkt nieznany. '
                        . 'Przy podaniu marki bez product_id MUSISZ dodać category (marka ma tabele w wielu kategoriach).',
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => ['skafander', 'rekawica', 'kaptur', 'but', 'but_suchy', 'pierscienie'],
                    'description' => 'Kategoria produktu — WYMAGANA przy fallbacku po marce (brand bez product_id). '
                        . 'Gdy podano product_id, kategoria jest wyprowadzana z produktu (tego pola nie podawaj).',
                ],
                'gender' => [
                    'type' => 'string',
                    'enum' => ['M', 'K', 'DZIECI'],
                    'description' => 'Płeć — ZAWSZE zapytaj klienta, nie zgaduj. '
                        . 'DZIECI = dziecięce pianki Rebel (dobór po wzroście).',
                ],
                'chest' => [
                    'type' => 'number',
                    'description' => 'Obwód klatki piersiowej [cm] — wymiar ciała (równocenny z pozostałymi).',
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
                    'description' => 'Wzrost [cm] — wymiar ciała (równocenny u dorosłych); wiodący dla dzieci (wymagany dla DZIECI).',
                ],
                'weight' => [
                    'type' => 'number',
                    'description' => 'Waga [kg] — weryfikujący/rozróżniający.',
                ],
                'foot_length' => [
                    'type' => 'number',
                    'description' => 'Długość stopy [cm] — wymiar dla butów.',
                ],
                'shoe_eu' => [
                    'type' => 'number',
                    'description' => 'Rozmiar buta EU (numerówka), np. 42 — wymiar dla butów. '
                        . 'To NUMERACJA, nie centymetry: nie przeliczaj na długość stopy.',
                ],
                'hand_circ' => [
                    'type' => 'number',
                    'description' => 'Obwód dłoni [cm] — wymiar dla rękawic.',
                ],
                'palm_length' => [
                    'type' => 'number',
                    'description' => 'Długość dłoni [cm] — wymiar dla rękawic.',
                ],
                'head_circ' => [
                    'type' => 'number',
                    'description' => 'Obwód głowy [cm] — wymiar dla kapturów.',
                ],
                'neck' => [
                    'type' => 'number',
                    'description' => 'Obwód szyi [cm] — wymiar dla kapturów.',
                ],
            ],
            // chest/height/inne walidowane w execute() zależnie od typu charta (dorosły vs dziecięcy vs tresciowy).
            'required' => ['gender'],
        ];
    }

    public function execute(array $params): array
    {
        $gender = isset($params['gender']) ? strtoupper(trim((string) $params['gender'])) : null;
        $brand = isset($params['brand']) ? trim((string) $params['brand']) : null;
        $category = isset($params['category']) ? strtolower(trim((string) $params['category'])) : null;
        $productId = isset($params['product_id']) ? (int) $params['product_id'] : null;

        if ($gender === null || $gender === '') {
            return ['error' => 'Brak płci. Zapytaj klienta: dla kobiety czy mężczyzny? (twarda reguła — nie zgaduj).'];
        }
        if (!in_array($gender, ['M', 'K', 'DZIECI'], true)) {
            return ['error' => "Nieobsługiwana płeć: {$gender}. Dozwolone: M, K, DZIECI."];
        }

        $chart = $this->resolveChart($productId, $brand, $category, $gender);
        if ($chart === null) {
            return [
                'error' => 'Nie znaleziono tabeli rozmiarów dla podanych danych. '
                    . 'Podaj product_id zmapowanego produktu LUB brand + category '
                    . '(skafander/rękawica/kaptur/but/but suchy/pierścienie) + gender.',
            ];
        }
        if (isset($chart['error'])) {
            // Fallback po marce: brak kategorii albo kilka tabel — prośba o doprecyzowanie (nigdy pierwszy z brzegu).
            return $chart;
        }

        // CHAT-T-165 / ADR-133: charty `tresciowy` (buty suche, buty Scubapro, pierścienie VDS) NIE mają
        // wierszy wymiarowych — treść leży w divezone_attr_size_chart_content. Zwracamy surowy content_html
        // (model cytuje bez interpolacji), BEZ wymagania wymiarów od klienta.
        if (($chart['chart_type'] ?? 'progowy') === 'tresciowy') {
            $content = $this->loadChartContent($chart['id']);
            if ($content === null || trim((string) ($content['content_html'] ?? '')) === '') {
                return ['error' => 'Tabela rozmiarów jest pusta dla wybranego charta.'];
            }
            return [
                'decision' => 'content_table',
                'content_html' => (string) $content['content_html'],
                'note' => ($content['note'] ?? null) !== null && $content['note'] !== '' ? (string) $content['note'] : null,
                'brand' => $chart['brand'],
                'category' => $chart['category_hint'] ?? null,
                'gender' => $chart['gender'],
            ];
        }

        $rows = $this->loadChartRows($chart['id']);
        if ($rows === []) {
            return ['error' => 'Tabela rozmiarów jest pusta dla wybranego charta.'];
        }
        $sizes = $this->buildSizes($rows);

        // §3.1: wymiary dopasowujące czytane DYNAMICZNIE z charta (nie ze stałej listy) — nowy wymiar
        // w danych działa bez zmiany kodu. `leg` wyłączony z doboru (ADR-032 aneks 1, EXCLUDED_DIMS).
        $allowedDims = $this->matchableDimensions($sizes);

        // ADR-039: chart progowy, którego wymiary NIE przecinają się ze schematem narzędzia,
        // nie jest chartem doboru — wszystkie jego osie to wymiary produktu (g_*), których schemat
        // nie przyjmuje, więc $dims wyszłoby puste i narzędzie prosiłoby o wymiary, których samo nie
        // umie przyjąć. Zwracamy listę rozmiarów z size_full i odsyłamy do tabeli na karcie produktu.
        // Kryterium to WYŁĄCZNIE przecięcie ze SCHEMA_DIMS, NIGDY test punktowości Z6 z modułu:
        // ch6 Mares i ch74 Mola Mola mają foot_length w wierszach punktowych, a schemat go przyjmuje,
        // więc przeniesienie Z6 tutaj skasowałoby dobór po foot_length i cofnęło ATTR-T-074b.
        $schemaDims = array_intersect($allowedDims, self::SCHEMA_DIMS);
        if ($schemaDims === []) {
            $payload = [
                'decision' => 'size_list_only',
                'sizes' => array_map(static fn(array $s): string => $s['label'], $sizes),
                'size_full' => array_map(static fn(array $s): string => $s['full'], $sizes),
                'reason' => 'tabela producenta dla tego produktu podaje wymiary odzieży (na płasko), '
                    . 'nie wymiary ciała, więc dobór po wymiarach klienta nie jest możliwy; '
                    . 'pełna tabela jest na karcie produktu',
                'brand' => $chart['brand'],
                'category' => $chart['category_hint'] ?? null,
                'gender' => $chart['gender'],
            ];
            $aliases = $this->loadAliases($chart['id']);
            if ($aliases !== []) {
                $payload['aliases'] = $aliases;
            }
            return $payload;
        }

        // Wszystkie podane wymiary są równocenne (przecięcie). Niepodane pomijamy — nie liczą się jako niezgodność.
        $dims = [];
        foreach ($allowedDims as $d) {
            if (isset($params[$d]) && is_numeric($params[$d])) {
                $dims[$d] = (float) $params[$d];
            }
        }

        // Chart dziecięcy / punktowy → dobór po wzroście (NIE klatce).
        $pointwise = $chart['gender'] === 'DZIECI' || $this->isPointwise($sizes, 'height');

        // ADR-034 (D2): półotwartość warunkowa liczona RAZ, przed pierwszym dopasowaniem.
        // Charty punktowe pomijamy jak moduł (matchPointwise nie czyta granic zakresu).
        if (!$pointwise) {
            $this->normalizeSizes($sizes);
        }

        if ($pointwise) {
            $height = $dims['height'] ?? null;
            if ($height === null) {
                return ['error' => 'Chart dziecięcy/punktowy — podaj wzrost dziecka (height, cm). '
                    . 'To wymiar wiodący dla pianek dziecięcych.'];
            }
            $result = $this->matchPointwise($sizes, $height, 'height');
        } else {
            if ($dims === []) {
                return ['error' => 'Podaj co najmniej jeden wymiar do doboru rozmiaru '
                    . '(' . implode(', ', $allowedDims) . ') — każdy podany wymiar zawęża wynik.'];
            }
            $result = $this->matchSize($sizes, $dims);
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
     * 2) fallback brand + category + gender (LIKE, chart_type='progowy', category_hint=parametr;
     *    krok 1 = dokładna płeć, krok 2 = UNISEX; >1 wiersz → błąd, nigdy pierwszy z brzegu).
     * §3.3 (CHAT-T-165): przy fallbacku po marce kategoria WYMAGANA — marka ma tabele w wielu
     * kategoriach (Scubapro: skafander/but/but suchy/kaptur/rękawica), nie zgadujemy.
     * Zwraca ['id','brand','gender','chart_type','category_hint'], ['error'=>...] albo null.
     *
     * @return array{id: int|string, brand: string, gender: string, chart_type: string, category_hint: ?string}|array{error: string}|null
     */
    private function resolveChart(?int $productId, ?string $brand, ?string $category, string $gender): ?array
    {
        if ($productId !== null) {
            $charts = $this->db->fetchAll(
                'SELECT c.id_chart AS id, c.brand, c.gender, c.chart_type, c.category_hint
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
                // Płeć klienta nie pasuje do mapowań — spróbuj brand+category+gender niżej.
            }
            // Brak mapowania dla product_id — fallback do brand+category+gender.
        }

        if ($brand !== null && $brand !== '') {
            // §3.3: kategoria OBOWIĄZKOWA — bez niej marka trafia w kilka kategorii naraz. Nie zgadujemy.
            if ($category === null || $category === '') {
                return ['error' => 'Marka "' . $brand . '" ma tabele rozmiarów w kilku kategoriach '
                    . '(skafander, rękawica, kaptur, but, but suchy, pierścienie). '
                    . 'Zapytaj klienta, o którą kategorię chodzi, albo podaj product_id — nie zgaduję.'];
            }
            // Filtry chart_type='progowy' + category_hint (parametr) + gender OBOWIĄZKOWE (CHAT-T-161 §2.4).
            // Krok 1: dokładna płeć; krok 2 (tylko przy 0): UNISEX.
            foreach ([$gender, 'UNISEX'] as $g) {
                $rows = $this->db->fetchAll(
                    "SELECT id_chart AS id, brand, gender, chart_type, category_hint
                     FROM divezone_attr_size_charts
                     WHERE brand LIKE CONCAT('%', ?, '%')
                       AND chart_type = 'progowy'
                       AND category_hint = ?
                       AND gender = ?",
                    [$brand, $category, $g],
                );
                if (count($rows) === 1) {
                    return $rows[0];
                }
                if (count($rows) > 1) {
                    // Zabezpieczenie (ADR-099): cicho wybrany zły chart gorszy niż odmowa.
                    return ['error' => 'Po marce "' . $brand . '" i kategorii "' . $category . '" pasuje kilka tabel rozmiarów. '
                        . 'Doprecyzuj — podaj product_id konkretnego produktu.'];
                }
                // 0 wierszy w kroku 1 → spróbuj UNISEX; 0 w kroku 2 → null niżej.
            }

            return null;
        }

        return null;
    }

    /**
     * ATTR-T-079B (ADR-042): wymiar tłumaczony na nazwę kanoniczną przy ODCZYCIE przez
     * LEFT JOIN divezone_attr_dimension_alias (`hips`→`hip`, `bust`→`chest`). Nazwa źródłowa
     * zostaje wierna w tabeli (ATTR-T-013); podmiana żyje tylko w wyniku tego zapytania, więc
     * cała logika niżej (buildSizes, matchableDimensions, matchSize, strażnik size_list_only)
     * widzi już nazwy kanoniczne bez zmiany. COALESCE: chart bez aliasu → NULL → r.dimension
     * bez zmiany. UNIQUE(alias_dimension) gwarantuje ≤1 dopasowanie (brak fan-outu wierszy).
     *
     * @return list<array<string, mixed>>
     */
    private function loadChartRows(int|string $chartId): array
    {
        return $this->db->fetchAll(
            'SELECT r.size_label, r.size_full,
                    COALESCE(a.canonical_dimension, r.dimension) AS dimension,
                    r.min_val, r.max_val, r.sort_order
             FROM divezone_attr_size_chart_rows r
             LEFT JOIN divezone_attr_dimension_alias a ON a.alias_dimension = r.dimension
             WHERE r.id_chart = ?
             ORDER BY r.sort_order',
            [$chartId],
        );
    }

    /**
     * Treść charta `tresciowy` (surowa tabela HTML + nota) z divezone_attr_size_chart_content.
     * ADR-133: NIE parsujemy HTML — model cytuje tabelę bez interpolacji. null gdy brak wiersza.
     *
     * @return array{content_html: string, note: ?string}|null
     */
    private function loadChartContent(int|string $chartId): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT content_html, note FROM divezone_attr_size_chart_content WHERE id_chart = ? LIMIT 1',
            [$chartId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Wymiary dopasowujące dla charta — czytane DYNAMICZNIE z jego wierszy (§3.1, ADR-133):
     * unia `dimension` po wszystkich rozmiarach, minus wymiary wyłączone (EXCLUDED_DIMS, m.in. `leg`).
     * Źródło prawdy w danych — nowy wymiar (foot_length, hand_circ, ...) działa bez zmiany kodu.
     *
     * @param list<array{label: string, full: string, sort: int, dims: array<string, array{0: ?float, 1: ?float, 2?: bool}>}> $sizes
     * @return list<string>
     */
    private function matchableDimensions(array $sizes): array
    {
        $dims = [];
        foreach ($sizes as $s) {
            foreach (array_keys($s['dims']) as $dim) {
                $dims[$dim] = true;
            }
        }
        foreach (self::EXCLUDED_DIMS as $excluded) {
            unset($dims[$excluded]);
        }

        return array_keys($dims);
    }

    /**
     * Z wierszy (size_label, dimension, min_val, max_val, sort_order) buduje listę rozmiarów
     * [{label, full, sort, dims:{dim:[min,max]}}], posortowaną po sort_order. (build_sizes z PY).
     *
     * ATTR-T-073 (D1, ADR-036/037): NULL z bazy ZOSTAJE NULL-em — otwarty kraniec, NIE 0.0.
     * `null` na pozycji 0 = „otwarty w dół", na pozycji 1 = „otwarty w górę". Trzeci element
     * (flaga wyłączania górnej granicy) dokłada normalizeSizes() PRZED pierwszym dopasowaniem.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array{label: string, full: string, sort: int, dims: array<string, array{0: ?float, 1: ?float, 2?: bool}>}>
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
            $byLabel[$lbl]['dims'][(string) $r['dimension']] = [
                $r['min_val'] !== null ? (float) $r['min_val'] : null,
                $r['max_val'] !== null ? (float) $r['max_val'] : null,
            ];
        }
        $sizes = array_values($byLabel);
        usort($sizes, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);

        return $sizes;
    }

    /**
     * Półotwartość WARUNKOWA (ADR-034) — wierny port `normalizeChart` przebieg 2 z
     * front-sizechart.js. Dla każdego wymiaru charta zbiera `min` (początki) wszystkich jego
     * wierszy, a następnie oznacza górną granicę wiersza jako WYŁĄCZAJĄCĄ (v < max) wtedy
     * i tylko wtedy, gdy równa się któremuś z tych początków:
     *   - zakresy stykające się (91–96 | 96–101) → 96 należy do WIĘKSZEGO rozmiaru,
     *   - zakresy z luką (92–96 | 97–101, chart 19 Tecline) → pasmo domknięte, 96 zostaje w mniejszym.
     * Porównanie DOKŁADNE, bez tolerancji (in_array strict): wartości pochodzą z decimal(6,2),
     * nie liczy się na nich. Zbiór początków budowany PER WYMIAR W TYM SAMYM CHARCIE (R1) —
     * policzenie go szerzej otworzyłoby granice, których producent nie otwierał.
     *
     * ADR-034 ANEKS 1 (ATTR-T-074b): wiersz PUNKTOWY (min === max) NIE podlega półotwartości.
     * Jest zawsze własnym początkiem, więc bez tego wyjątku flaga zapalałaby się na nim zawsze,
     * a `v < max` przy min === max nie dałoby się spełnić — wiersz znikałby z doboru (np. ch6
     * Mares foot_length, ch4 waist 4T, ch40 neck LG). Punkt dopasowuje się przez równość; człon
     * `$min !== $max` w warunku flagi zamyka okno rozjazdu bota z panelem (moduł: ten sam człon
     * `r[0] !== r[1]` w normalizeChart od etapu A).
     *
     * Wykonać RAZ, przed pierwszym dopasowaniem. Charty punktowe (DZIECI) i tak pomijamy jak moduł
     * (`chart.pointwise` guard) — matchPointwise nie czyta granic zakresu.
     *
     * @param list<array{label: string, full: string, sort: int, dims: array<string, array{0: ?float, 1: ?float, 2?: bool}>}> $sizes
     */
    private function normalizeSizes(array &$sizes): void
    {
        // Unia wymiarów charta (nie ze stałej listy — jak matchableDimensions, §3.1).
        $dims = [];
        foreach ($sizes as $s) {
            foreach (array_keys($s['dims']) as $d) {
                $dims[$d] = true;
            }
        }
        foreach (array_keys($dims) as $d) {
            $starts = [];
            foreach ($sizes as $s) {
                if (isset($s['dims'][$d]) && $s['dims'][$d][0] !== null) {
                    $starts[] = $s['dims'][$d][0];
                }
            }
            foreach ($sizes as $i => $s) {
                if (!isset($s['dims'][$d])) {
                    continue;
                }
                $min = $s['dims'][$d][0];
                $max = $s['dims'][$d][1];
                // ADR-034 aneks 1: wiersz PUNKTOWY (min === max) NIE podlega półotwartości —
                // dopasowuje się przez równość, flaga wyłączająca zapala się tylko dla pasm.
                $sizes[$i]['dims'][$d][2] = ($max !== null && $min !== $max && in_array($max, $starts, true));
            }
        }
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
     * Algorytm przecięcia wymiarów (dorośli). Wszystkie podane wymiary są równocenne:
     * wynik to rozmiary, w których KAŻDY podany wymiar mieści się w swoim zakresie.
     * Im więcej podanych wymiarów, tym węższy wynik. Wymiar niepodany nie liczy się jako
     * niezgodność (nie ma go w $dims). ZERO ekstrapolacji poza skalę.
     *
     * @param list<array{label: string, full: string, sort: int, dims: array<string, array{0: ?float, 1: ?float, 2?: bool}>}> $sizes
     * @param array<string, float> $dims  podane wymiary (bez null), co najmniej jeden
     */
    private function matchSize(array $sizes, array $dims): array
    {
        // Guard defense-in-depth (CHAT-T-166): przy pustym $dims pętla array_filter NIGDY nie zwraca
        // false → callback przepuściłby WSZYSTKIE rozmiary charta (na charcie jednorozmiarowym maskuje
        // się jako poprawny `match`). execute() ma własny guard PRZED tym wywołaniem — ta redundancja
        // chroni przyszłych wołających matchSize() (nie duplikat do usunięcia).
        if ($dims === []) {
            $available = $this->matchableDimensions($sizes);
            return ['error' => 'Podaj co najmniej jeden wymiar do doboru rozmiaru '
                . '(' . implode(', ', $available) . ') — każdy podany wymiar zawęża wynik.'];
        }

        // Przecięcie: rozmiary, w których KAŻDY podany wymiar mieści się w [min,max].
        $hits = array_values(array_filter($sizes, function (array $s) use ($dims): bool {
            foreach ($dims as $dim => $val) {
                if (!isset($s['dims'][$dim]) || !$this->inRange($val, $s['dims'][$dim])) {
                    return false;
                }
            }
            return true;
        }));

        // 3. Dokładnie jeden rozmiar → sugestia.
        if (count($hits) === 1) {
            return [
                'decision' => 'match',
                'sizes' => [$hits[0]['label']],
                'consult' => false,
                'reason' => 'wszystkie podane wymiary trafiają w jeden rozmiar',
            ];
        }

        // 4. Kilka rozmiarów → wszystkie równorzędne, bez wskazywania jednego.
        if (count($hits) >= 2) {
            return [
                'decision' => 'multiple',
                'sizes' => array_map(static fn(array $s): string => $s['label'], $hits),
                'consult' => false,
                'reason' => 'kilka rozmiarów pasuje równorzędnie — podaj kolejny wymiar, aby zawęzić wynik',
            ];
        }

        // Przecięcie puste — policz, ile podanych wymiarów pasuje w każdym rozmiarze.
        $best = 0;
        $scored = [];
        foreach ($sizes as $s) {
            $ok = 0;
            foreach ($dims as $dim => $val) {
                if (isset($s['dims'][$dim]) && $this->inRange($val, $s['dims'][$dim])) {
                    $ok++;
                }
            }
            $scored[] = ['ok' => $ok, 's' => $s];
            if ($ok > $best) {
                $best = $ok;
            }
        }

        // 5. Zero w przecięciu, ale co najmniej jeden wymiar coś trafia → częściowe dopasowanie.
        if ($best >= 1) {
            $partial = array_values(array_filter($scored, static fn(array $x): bool => $x['ok'] === $best));
            $outDims = [];
            foreach ($partial as $x) {
                foreach ($dims as $dim => $val) {
                    if (!isset($x['s']['dims'][$dim]) || !$this->inRange($val, $x['s']['dims'][$dim])) {
                        $outDims[$dim] = true;
                    }
                }
            }
            $out = array_keys($outDims);
            return [
                'decision' => 'partial',
                'sizes' => array_map(static fn(array $x): string => $x['s']['label'], $partial),
                'consult' => true,
                'out_of_range_dims' => $out,
                'reason' => 'żaden rozmiar nie spełnia wszystkich podanych wymiarów; '
                    . 'zwrócono częściowo pasujące — poza zakresem: ' . implode(', ', $out),
            ];
        }

        // 6. Zero trafień w ogóle → dwa najbliższe po SUMIE odległości od zakresów.
        $sumDist = function (array $s) use ($dims): float {
            $sum = 0.0;
            foreach ($dims as $dim => $val) {
                if (isset($s['dims'][$dim])) {
                    $sum += $this->distToRange($val, $s['dims'][$dim]);
                }
            }
            return $sum;
        };
        $byDist = $sizes;
        usort($byDist, fn(array $a, array $b): int => $sumDist($a) <=> $sumDist($b));
        $nearest = array_map(static fn(array $s): string => $s['label'], array_slice($byDist, 0, 2));

        return [
            'decision' => 'out_of_scale',
            'sizes' => $nearest,
            'consult' => true,
            'reason' => 'podane wymiary wypadają między rozmiary lub poza skalę — bez ekstrapolacji',
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

    /**
     * Czy wartość mieści się w zakresie [min, max, wyłączająca?]. Wierny port `inRange`
     * z front-sizechart.js (ATTR-T-058/073, D3):
     *   - min === null → dolny warunek spełniony (otwarty w dół),
     *   - max === null → górny warunek spełniony (otwarty w górę),
     *   - flaga [2] true (górna granica wyłączająca, ustawiana przez normalizeSizes) → v < max,
     *   - flaga [2] false/brak (domykająca) → v <= max.
     *
     * @param array{0: ?float, 1: ?float, 2?: bool} $rng
     */
    private function inRange(float $val, array $rng): bool
    {
        if ($rng[0] !== null && $val < $rng[0]) {
            return false;
        }
        if ($rng[1] !== null && (($rng[2] ?? false) ? $val >= $rng[1] : $val > $rng[1])) {
            return false;
        }
        return true;
    }

    /**
     * Odległość wartości od zakresu (0 gdy wewnątrz). Ta sama ścieżka co inRange (D7):
     * NULL na krańcu = zakres sięga w tę stronę bez końca (odległość 0), flaga wyłączająca
     * traktuje v == max jak poza zakresem. Port `distToRange` z front-sizechart.js.
     *
     * @param array{0: ?float, 1: ?float, 2?: bool} $rng
     */
    private function distToRange(float $val, array $rng): float
    {
        if ($rng[0] !== null && $val < $rng[0]) {
            return $rng[0] - $val;
        }
        if ($rng[1] !== null && (($rng[2] ?? false) ? $val >= $rng[1] : $val > $rng[1])) {
            return $val - $rng[1];
        }
        return 0.0;
    }
}
