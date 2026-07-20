<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;
use DiveChat\Database\PostgresConnection;

/**
 * Informacje o dostawie krajowej (Polska) i EU.
 *
 * ŹRÓDŁA (decyzja 192b, CHAT-T-156):
 * - Treść opisowa metod (nazwy, czasy dostawy, pobranie, notki) → Railway PG
 *   `divechat_shipping_rates` + `divechat_shop_config` (ADR-059, edytowalne online).
 * - Stawki i PROGI darmowej dostawy dla Polski → ŻYWCEM z MySQL PrestaShop
 *   (`pr_delivery` per kurier), bo próg jest RÓŻNY per kurier (InPost od 299,
 *   DPD od 399 — zweryfikowane 2026-07-20), a płaska tabela PG trzymała jeden
 *   próg 299 dla wszystkich (błąd: conv 723). Łańcuch jak w InternationalShipping:
 *   strefa Polska → id_carrier → pr_range_price → price.
 *
 * Model MySQL (zweryfikowany na PROD 2026-07-20, strefa Polska = id_zone 9):
 * - InPost Kurier (345) / Paczkomaty (399): 0-299 → 10,57 netto, 299+ → 0. Próg 299.
 * - Kurier DPD (397): 0-399 → 17,88 netto, 399-1283 → 0, 1283+ → 0. Próg 399.
 * - `pr_delivery.price` trzyma NETTO. Brutto = netto × 1.23 (VAT 23%) — zweryfikowane
 *   na realnych zamówieniach: 10,57→13,00 (2907 zam.), 17,88→21,99 (31 zam.),
 *   21,1382→26,00 (pobranie). Mnożnik NIE zakładany, POTWIERDZONY na total_shipping_tax_*.
 * - Limit wagi per kurier: pr_carrier.max_weight (DPD 29, InPost Paczkomat 10 —
 *   z danych, koniec hardcode „flat do 31 kg" z buildNote, dług ADR-129).
 *
 * Mapowanie metoda(PG)→id_carrier(MySQL) po znormalizowanych tokenach nazwy, NIE po
 * hardcode'owanej liście id — przy klonowaniu kuriera w PS id rośnie (decyzja 192b).
 * Kandydaci: aktywni przewoźnicy (deleted=0, active=1) z realnymi stawkami w strefie PL.
 *
 * Strefa EU: bez zmian (płaska tabela PG; obecnie pusta → notka kontaktowa).
 */
final class ShippingInfo implements ToolInterface
{
    /** VAT 23% — pr_delivery trzyma netto, klient płaci brutto. Mnożnik POTWIERDZONY na zamówieniach (CHAT-T-156). */
    private const VAT_MULTIPLIER = 1.23;

    private ?MysqlConnection $mysql;

    /**
     * MySQL wstrzykiwane opcjonalnie (test), w produkcji tools.php podaje tylko $db (PG) —
     * dlatego łączenie z PrestaShop przez singleton, bez zmiany rejestracji w config/tools.php.
     */
    public function __construct(
        private readonly PostgresConnection $db,
        ?MysqlConnection $mysql = null,
    ) {
        $this->mysql = $mysql;
    }

    public function getName(): string
    {
        return 'get_shipping_info';
    }

    public function getDescription(): string
    {
        return 'Informacje o metodach dostawy, kosztach i progach darmowej wysyłki w divezone.pl. '
             . 'Używaj gdy klient pyta o koszty wysyłki, czas dostawy lub darmową dostawę. '
             . 'Dla Polski (zone PL) zwraca stawkę i PRÓG DARMOWEJ DOSTAWY OSOBNO dla każdego kuriera '
             . '(InPost darmowy od innej kwoty niż DPD). Parametr zone: PL (domyślnie) lub EU (reszta Europy).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'cart_total' => [
                    'type' => 'number',
                    'description' => 'Wartość koszyka w PLN (do sprawdzenia progu darmowej dostawy)',
                ],
                'zone' => [
                    'type' => 'string',
                    'enum' => ['PL', 'EU'],
                    'description' => 'Strefa dostawy: PL (Polska) lub EU (reszta Europy). Default PL.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): array
    {
        $cartTotal = isset($params['cart_total']) ? (float) $params['cart_total'] : null;
        $zone = (($params['zone'] ?? 'PL') === 'EU') ? 'EU' : 'PL';

        $methods = $this->db->fetchAll(
            "SELECT carrier_name, price, cod_price, delivery_days
             FROM divechat_shipping_rates
             WHERE zone = ? AND active = TRUE
             ORDER BY sort_order, price",
            [$zone],
        );

        // Brak danych dla strefy (np. EU jeszcze nie seedowane)
        if (empty($methods)) {
            return [
                'zone' => $zone,
                'methods' => [],
                'note' => $zone === 'EU'
                    ? 'Dla wysyłki poza Polskę skontaktuj się: dive@divezone.pl lub 56 307 03 03 — podamy dokładny koszt dla Twojego kraju.'
                    : 'Brak danych o dostawie. Kontakt: dive@divezone.pl',
            ];
        }

        // Polska: progi/stawki per kurier z żywego PrestaShop (decyzja 192b).
        if ($zone === 'PL') {
            return $this->buildPlResult($methods, $cartTotal);
        }

        // EU i pozostałe: płaska tabela PG (bez zmian względem ADR-059).
        return $this->buildFlatResult($zone, $methods, $cartTotal);
    }

    /**
     * Strefa Polska: nakłada na opisowe metody PG (nazwy, czasy, pobranie) żywe stawki
     * i progi darmowej dostawy z MySQL PrestaShop, per kurier.
     *
     * @param list<array<string,mixed>> $pgMethods
     */
    private function buildPlResult(array $pgMethods, ?float $cartTotal): array
    {
        $carriers = [];
        $source = 'live';
        try {
            $carriers = $this->fetchPlCarriers();
        } catch (\Throwable $e) {
            // MySQL niedostępny → degradacja do stawek opisowych PG (bez per-kurier progu).
            error_log('[DiveChat shipping_info] MySQL PL rates unavailable: ' . $e->getMessage());
            $source = 'pg_fallback';
        }

        $methods = [];
        foreach ($pgMethods as $m) {
            $carrierName = (string) $m['carrier_name'];
            $codPrice    = $m['cod_price'] !== null ? (float) $m['cod_price'] : null;
            $deliveryDays = $m['delivery_days'];

            $match = $source === 'live'
                ? self::matchCarrier(self::carrierTokens($carrierName), $carriers)
                : null;

            if ($match !== null) {
                // Cena i próg z żywego PrestaShop (brutto z netto), limit wagi per kurier.
                $price    = $match['base_netto'] !== null ? self::vatBrutto($match['base_netto']) : 0.0;
                $freeFrom = $match['free_from'];
                $maxWeight = $match['max_weight'];
            } else {
                // Brak dopasowania (np. odbiór osobisty — brak stawki w pr_delivery) lub
                // MySQL down → cena z PG (już brutto), próg nieznany per kurier.
                $price    = (float) $m['price'];
                $freeFrom = null;
                $maxWeight = null;
            }

            $method = [
                'carrier_name'  => $carrierName,
                'price'         => $price,
                'cod_price'     => $codPrice,
                'delivery_days' => $deliveryDays,
                'max_weight_kg' => $maxWeight,
                'free_from'     => $freeFrom,
            ];
            if ($cartTotal !== null && $freeFrom !== null) {
                // Czy TEN kurier jest już darmowy dla tego koszyka.
                $method['free_now'] = $cartTotal >= $freeFrom;
            }
            $methods[] = $method;
        }

        return [
            'zone' => 'PL',
            'methods' => $methods,
            'cart_total' => $cartTotal,
            'note' => self::buildPlNote($methods, $cartTotal),
        ];
    }

    /**
     * Strefa płaska (EU) — zachowanie ADR-059 (jeden próg z divechat_shop_config).
     *
     * @param list<array<string,mixed>> $pgMethods
     */
    private function buildFlatResult(string $zone, array $pgMethods, ?float $cartTotal): array
    {
        $thresholdRow = $this->db->fetchOne(
            "SELECT value FROM divechat_shop_config WHERE key = ?",
            ['free_shipping_threshold_' . strtolower($zone)],
        );
        $threshold = $thresholdRow !== null ? (float) $thresholdRow['value'] : 0.0;

        $freeShipping = $threshold > 0.0 && $cartTotal !== null && $cartTotal >= $threshold;

        $methods = array_map(
            static fn(array $m): array => [
                'carrier_name'  => $m['carrier_name'],
                'price'         => (float) $m['price'],
                'cod_price'     => $m['cod_price'] !== null ? (float) $m['cod_price'] : null,
                'delivery_days' => $m['delivery_days'],
            ],
            $pgMethods,
        );

        return [
            'zone' => $zone,
            'methods' => $methods,
            'free_shipping_threshold' => $threshold > 0.0 ? $threshold : null,
            'cart_total' => $cartTotal,
            'free_shipping' => $freeShipping,
            'note' => $this->buildFlatNote($freeShipping, $cartTotal, $threshold),
        ];
    }

    /**
     * Aktywni przewoźnicy (deleted=0, active=1) z realnymi stawkami w strefie Polska,
     * ze znormalizowanymi tokenami nazwy do mapowania. Strefę PL bierzemy z pr_country
     * (iso='PL'), nie hardcode'em id (decyzja 192b).
     *
     * @return list<array{id:int, name:string, tokens:list<string>, base_netto:?float, free_from:?float, max_weight:?int}>
     */
    private function fetchPlCarriers(): array
    {
        $mysql = $this->mysql ?? MysqlConnection::getInstance();

        $zoneRow = $mysql->fetchOne(
            "SELECT id_zone FROM pr_country WHERE UPPER(iso_code) = 'PL' LIMIT 1",
        );
        if ($zoneRow === null) {
            return [];
        }
        $zoneId = (int) $zoneRow['id_zone'];

        $rows = $mysql->fetchAll(
            "SELECT ca.id_carrier, ca.name, ca.max_weight,
                    rp.delimiter1, dl.price
             FROM pr_delivery dl
             JOIN pr_range_price rp ON rp.id_range_price = dl.id_range_price
             JOIN pr_carrier ca ON ca.id_carrier = dl.id_carrier
             WHERE dl.id_zone = ? AND ca.deleted = 0 AND ca.active = 1
             ORDER BY ca.id_carrier, rp.delimiter1",
            [$zoneId],
        );

        return self::groupCarrierRanges($rows);
    }

    /**
     * Surowe wiersze (kurier × zakres) → jeden wpis per kurier z ceną bazową netto
     * (zakres startujący od 0) i progiem darmowej dostawy (dolna granica pierwszego
     * zakresu z ceną 0). Static — testowalne bez DB.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{id:int, name:string, tokens:list<string>, base_netto:?float, free_from:?float, max_weight:?int}>
     */
    public static function groupCarrierRanges(array $rows): array
    {
        $byCarrier = [];
        foreach ($rows as $r) {
            $id = (int) $r['id_carrier'];
            if (!isset($byCarrier[$id])) {
                $byCarrier[$id] = [
                    'id' => $id,
                    'name' => (string) $r['name'],
                    'max_weight' => (int) round((float) $r['max_weight']),
                    'base_netto' => null,
                    'free_from' => null,
                ];
            }
            $from  = (float) $r['delimiter1'];
            $price = (float) $r['price'];

            // Cena bazowa = stawka zakresu od 0 (koszyk poniżej progu darmowej dostawy).
            if ($from === 0.0 && $price > 0.0) {
                $byCarrier[$id]['base_netto'] = $price;
            }
            // Próg darmowej dostawy = najniższa granica zakresu z ceną 0.
            if ($price === 0.0 && ($byCarrier[$id]['free_from'] === null || $from < $byCarrier[$id]['free_from'])) {
                $byCarrier[$id]['free_from'] = $from;
            }
        }

        $out = [];
        foreach ($byCarrier as $c) {
            // max_weight 0 (np. odbiór osobisty) → null (limit nieistotny).
            $c['max_weight'] = $c['max_weight'] > 0 ? $c['max_weight'] : null;
            $c['tokens'] = self::carrierTokens($c['name']);
            $out[] = $c;
        }

        return array_values($out);
    }

    /**
     * Nazwa kuriera → znormalizowane tokeny literowe (bez cyfr, bez polskich znaków,
     * min. 3 znaki). „INPOST Paczkomaty 24" → ['inpost','paczkomaty'].
     *
     * @return list<string>
     */
    public static function carrierTokens(string $name): array
    {
        $ascii = strtr(
            $name,
            [
                'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
                'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
                'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n',
                'Ó' => 'o', 'Ś' => 's', 'Ż' => 'z', 'Ź' => 'z',
            ],
        );
        $lower = strtolower($ascii);
        $lower = preg_replace('/[^a-z0-9\s]/', ' ', $lower) ?? '';
        $raw = preg_split('/\s+/', trim($lower)) ?: [];

        $tokens = [];
        foreach ($raw as $t) {
            if (strlen($t) >= 3 && !ctype_digit($t)) {
                $tokens[$t] = true;
            }
        }

        return array_keys($tokens);
    }

    /**
     * Dwa tokeny „pasują", gdy są równe lub jeden jest prefiksem drugiego (min. 4 znaki
     * wspólne) — obsługuje odmianę „paczkomat"/„paczkomaty".
     */
    public static function tokenMatches(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        $min = min(strlen($a), strlen($b));

        return $min >= 4 && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }

    /**
     * Metoda PG (tokeny nazwy) → najlepiej pasujący kurier MySQL. Wymaga dopasowania
     * WSZYSTKICH tokenów metody PG; przy remisie wygrywa kurier z mniejszą liczbą
     * niedopasowanych tokenów (np. „Kurier DPD" wygrywa nad „Pobranie - Kurier DPD").
     * null = brak pewnego dopasowania (np. odbiór osobisty — brak stawki w pr_delivery).
     *
     * @param list<string> $pgTokens
     * @param list<array{id:int, name:string, tokens:list<string>, base_netto:?float, free_from:?float, max_weight:?int}> $carriers
     * @return array{id:int, name:string, tokens:list<string>, base_netto:?float, free_from:?float, max_weight:?int}|null
     */
    public static function matchCarrier(array $pgTokens, array $carriers): ?array
    {
        if ($pgTokens === []) {
            return null;
        }

        $best = null;
        $bestScore = 0;
        $bestExtra = PHP_INT_MAX;

        foreach ($carriers as $c) {
            $score = 0;
            foreach ($pgTokens as $pt) {
                foreach ($c['tokens'] as $ct) {
                    if (self::tokenMatches($pt, $ct)) {
                        $score++;
                        break;
                    }
                }
            }
            if ($score === 0) {
                continue;
            }
            $extra = count($c['tokens']) - $score;
            if ($score > $bestScore || ($score === $bestScore && $extra < $bestExtra)) {
                $best = $c;
                $bestScore = $score;
                $bestExtra = $extra;
            }
        }

        // Pewność: wszystkie tokeny metody PG muszą się dopasować.
        return ($best !== null && $bestScore >= count($pgTokens)) ? $best : null;
    }

    /**
     * Netto → brutto (VAT 23%), 2 miejsca. 17,88 → 21,99.
     */
    public static function vatBrutto(float $netto): float
    {
        return round($netto * self::VAT_MULTIPLIER, 2);
    }

    /**
     * Notka PL: progi darmowej dostawy pogrupowane po kwocie (różne per kurier).
     *
     * @param list<array<string,mixed>> $methods
     */
    private static function buildPlNote(array $methods, ?float $cartTotal): string
    {
        // Grupuj nazwy kurierów po progu darmowej dostawy.
        $byThreshold = [];
        foreach ($methods as $m) {
            if ($m['free_from'] !== null) {
                $key = (string) $m['free_from'];
                $byThreshold[$key][] = (string) $m['carrier_name'];
            }
        }

        if ($byThreshold === []) {
            return 'Koszty dostawy powyżej. Próg darmowej dostawy potwierdzi obsługa: dive@divezone.pl.';
        }

        uksort($byThreshold, static fn(string $a, string $b): int => (float) $a <=> (float) $b);

        $parts = [];
        foreach ($byThreshold as $thr => $names) {
            $parts[] = sprintf('%s — darmowa od %s zł', implode(', ', $names), self::formatZl((float) $thr));
        }
        $note = 'Progi darmowej dostawy różnią się per kurier: ' . implode('; ', $parts) . '.';

        if ($cartTotal !== null) {
            $freeNow = [];
            foreach ($methods as $m) {
                if (($m['free_now'] ?? false) === true) {
                    $freeNow[] = (string) $m['carrier_name'];
                }
            }
            $note .= $freeNow !== []
                ? sprintf(' Dla koszyka %s zł darmowa już: %s.', self::formatZl($cartTotal), implode(', ', $freeNow))
                : sprintf(' Koszyk %s zł — jeszcze bez darmowej dostawy.', self::formatZl($cartTotal));
        }

        return $note;
    }

    /**
     * Kwota PLN do notki: bez końcówki „,00" gdy całość, inaczej dwie cyfry z przecinkiem.
     */
    private static function formatZl(float $v): string
    {
        if ($v === floor($v)) {
            return number_format($v, 0, ',', '');
        }

        return number_format($v, 2, ',', '');
    }

    private function buildFlatNote(bool $freeShipping, ?float $cartTotal, float $threshold): string
    {
        if ($threshold <= 0.0) {
            // Dług ADR-129 usunięty: bez hardcode „flat do 31 kg" (limit różni się per kurier).
            return 'Koszty i metody dostawy powyżej.';
        }

        if ($freeShipping) {
            return sprintf('Darmowa dostawa przy zamówieniu powyżej %.0f zł!', $threshold);
        }

        if ($cartTotal !== null) {
            return sprintf('Do darmowej dostawy brakuje %.2f zł.', $threshold - $cartTotal);
        }

        return sprintf('Darmowa dostawa od %.0f zł.', $threshold);
    }
}
