<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;

/**
 * Wysyłka zagraniczna kurierem DPD (CHAT-T-151, ADR-129).
 *
 * OSOBNE narzędzie od get_shipping_info (ADR-059, zone=PL/EU z Railway PG):
 * to czyta ŻYWE stawki DPD z MySQL PrestaShop, bo model jest inny — kraj →
 * strefa PS → zakres wartości koszyka → stawka z pr_delivery. Wciśnięcie tego
 * w get_shipping_info oznaczałoby zamrożenie 22 stref w tabeli PG, która
 * cicho rozjeżdża się z PrestaShop (decyzja 154b).
 *
 * Model danych (zweryfikowany na PROD MySQL 2026-07-18):
 * - Kurier zagraniczny = WYŁĄCZNIE DPD (id_carrier 397). Paczkomat (399) = tylko PL.
 * - DPD proguje WARTOŚCIĄ koszyka, nie wagą: pr_carrier.shipping_method=2,
 *   pr_range_price 3 zakresy (0-399 / 399-1283 / 1283-999999 PLN), pr_range_weight puste.
 * - Trzeci zakres (1283+ PLN) = darmowa wysyłka (price 0) — próg czytany z danych.
 * - Waga to wyłącznie twardy limit górny: pr_carrier.max_weight (DPD 29 kg — z bazy).
 * - Kraj bez aktywnej stawki DPD (wyspy w strefie „Europe (non-EU)" id 7, kraje
 *   spoza zasięgu) → status not_supported. Mechanizm w danych (0 wierszy), zero
 *   listy wysp w kodzie (decyzja 156a).
 * - Kurs EUR: pr_currency id=2 conversion_rate (ten sam, po którym liczy checkout).
 *   Zaokrąglenie stawki EUR w górę do pełnego euro (decyzja 155a).
 *
 * ZERO hardkodowanych stawek/limitów/progów/wysp — wszystko z MySQL.
 */
final class InternationalShipping implements ToolInterface
{
    /** Kurier DPD — jedyny zagraniczny (ADR-129). Identyfikator, reszta z bazy. */
    private const CARRIER_ID = 397;

    /** pr_currency EUR (decyzja 155a — kurs spójny z checkoutem). */
    private const EUR_CURRENCY_ID = 2;

    /** Powyżej tego ułamka limitu wagi bot ostrzega przy weight_uncertain (decyzja 153b). */
    private const NEAR_WEIGHT_LIMIT_RATIO = 0.8;

    public function __construct(
        private readonly MysqlConnection $mysql,
    ) {}

    public function getName(): string
    {
        return 'get_international_shipping';
    }

    public function getDescription(): string
    {
        return 'Koszt wysyłki ZAGRANICZNEJ kurierem DPD z divezone.pl (poza Polską). '
             . 'Używaj gdy klient pyta o dostawę do innego kraju niż Polska ("ile wysyłka do Austrii", '
             . '"wysyłka do Niemiec/Hiszpanii"). Zwraca stawkę wg wartości koszyka, próg darmowej wysyłki, '
             . 'limit wagi i kurs EUR — żywcem z PrestaShop. Kraje spoza zasięgu DPD (wyspy, część krajów) '
             . 'zwracają status not_supported. Dla wysyłki krajowej (Polska) użyj get_shipping_info, NIE tego.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'country' => [
                    'type' => 'string',
                    'description' => 'Kraj docelowy: polska nazwa ("Austria", "Niemcy", "Hiszpania") '
                        . 'lub dwuliterowy kod ISO ("AT", "DE", "ES"). Wymagany.',
                ],
                'cart_total' => [
                    'type' => 'number',
                    'description' => 'Opcjonalnie: wartość koszyka w PLN. Podaj, by dostać konkretną stawkę '
                        . 'i informację o darmowej wysyłce. Bez tego zwracane są wszystkie progi cenowe.',
                ],
                'cart_weight' => [
                    'type' => 'number',
                    'description' => 'Opcjonalnie: sumaryczna waga koszyka w kg — do sprawdzenia limitu kuriera.',
                ],
                'weight_uncertain' => [
                    'type' => 'boolean',
                    'description' => 'Ustaw true, gdy któryś produkt w koszyku ma nieznaną wagę (weight=0) '
                        . 'i suma wagi jest niepewna. Domyślnie false.',
                ],
            ],
            'required' => ['country'],
        ];
    }

    public function execute(array $params): array
    {
        $countryInput = trim((string) ($params['country'] ?? ''));
        if ($countryInput === '') {
            return [
                'status' => 'unknown_country',
                'country' => null,
                'note' => 'Nie podano kraju docelowego.',
            ];
        }

        $cartTotal = isset($params['cart_total']) && is_numeric($params['cart_total'])
            ? (float) $params['cart_total']
            : null;
        $cartWeight = isset($params['cart_weight']) && is_numeric($params['cart_weight'])
            ? (float) $params['cart_weight']
            : null;
        $weightUncertain = (bool) ($params['weight_uncertain'] ?? false);

        try {
            $country = $this->resolveCountry($countryInput);
            if ($country === null) {
                return [
                    'status' => 'unknown_country',
                    'country' => $countryInput,
                    'note' => 'Nie rozpoznano kraju. Poproś klienta o pełną nazwę lub kod kraju.',
                ];
            }

            // Kraj ROZPOZNANY, ale wyłączony z wysyłki (pr_country.active=0). Przykład:
            // Szwajcaria (CH) ma active=0, lecz id_zone=25 (strefa „Węgry") ze stawkami DPD —
            // bez tego filtra narzędzie zwracało cennik i status:ok, a bot podważał obsługę
            // („my system shows...", conv 761). Sprawdzamy PRZED pobraniem stawek. Kraj ma być
            // rozpoznany (nie unknown_country), a status ma mówić prawdę. Note jest dla bota —
            // nie zdradza klientowi mechanizmu (CHAT-T-156, decyzja 192).
            if ((int) $country['active'] === 0) {
                return [
                    'status' => 'not_supported',
                    'country' => $country['country_name'],
                    'zone_name' => $country['zone_name'],
                    'carrier' => 'Kurier DPD',
                    'note' => 'Kraj obecnie wyłączony z wysyłki — sklep nie wysyła do tego kraju.',
                ];
            }

            $zoneId = (int) $country['id_zone'];
            $rangeRows = $this->fetchDpdRanges($zoneId);
            $ranges = self::normalizeRanges($rangeRows, $this->fetchEurRate());

            // Brak realnej stawki (0 wierszy albo same zera) = wyspa / poza zasięgiem DPD.
            if ($ranges === [] || !self::hasPaidRange($ranges)) {
                return [
                    'status' => 'not_supported',
                    'country' => $country['country_name'],
                    'zone_name' => $country['zone_name'],
                    'carrier' => 'Kurier DPD',
                    'note' => null,
                ];
            }

            $maxWeight = $this->fetchMaxWeight();
        } catch (\Throwable $e) {
            error_log('[DiveChat intl_shipping] MySQL query failed (country=' . $countryInput . '): ' . $e->getMessage());
            return [
                'status' => 'error',
                'country' => $countryInput,
                'note' => 'Dane o wysyłce zagranicznej chwilowo niedostępne — zaproponuj kontakt: '
                    . 'dive@divezone.pl / 56 307 03 03.',
            ];
        }

        $freeThresholdPln = self::freeShippingThreshold($ranges);

        $result = [
            'status' => 'ok',
            'country' => $country['country_name'],
            'zone_name' => $country['zone_name'],
            'carrier' => 'Kurier DPD',
            'ranges' => $ranges,
            'free_shipping_threshold_pln' => $freeThresholdPln,
            'free_shipping_threshold_eur' => $freeThresholdPln !== null
                ? self::toEurCeil($freeThresholdPln, $this->fetchEurRate())
                : null,
            'max_weight_kg' => $maxWeight,
            'weight_uncertain' => $weightUncertain,
            'note' => null,
        ];

        // Stawka dla konkretnej wartości koszyka (gdy podana).
        if ($cartTotal !== null) {
            $selected = self::selectRange($ranges, $cartTotal);
            $result['cart_total'] = $cartTotal;
            $result['rate_pln'] = $selected !== null ? $selected['pln'] : null;
            $result['rate_eur'] = $selected !== null ? $selected['eur'] : null;
            $result['free_shipping'] = $selected !== null && $selected['pln'] === 0.0;
        }

        // Limit wagi (twardy, z bazy).
        if ($cartWeight !== null) {
            $result['cart_weight'] = $cartWeight;
            $result['over_weight_limit'] = $cartWeight > $maxWeight;
            $result['near_weight_limit'] = $cartWeight >= $maxWeight * self::NEAR_WEIGHT_LIMIT_RATIO;
        }

        return $result;
    }

    /**
     * Kraj → id_zone. Dopasowanie: kod ISO (dokładny) > nazwa PL dokładna > nazwa PL LIKE.
     * pr_country_lang id_lang=1 (polski). null = nierozpoznany.
     * Pobiera też `c.active` — kraj wyłączony z wysyłki (active=0) ma być rozpoznany,
     * ale zwrócony jako not_supported (CHAT-T-156).
     *
     * @return array{id_country:int, iso_code:string, id_zone:int, active:int, country_name:string, zone_name:?string}|null
     */
    private function resolveCountry(string $input): ?array
    {
        $row = $this->mysql->fetchOne(
            "SELECT c.id_country,
                    c.iso_code,
                    c.id_zone,
                    c.active,
                    TRIM(cl.name) AS country_name,
                    TRIM(z.name)  AS zone_name
             FROM pr_country c
             JOIN pr_country_lang cl ON cl.id_country = c.id_country AND cl.id_lang = 1
             LEFT JOIN pr_zone z ON z.id_zone = c.id_zone
             WHERE UPPER(c.iso_code) = UPPER(?)
                OR UPPER(TRIM(cl.name)) = UPPER(?)
                OR UPPER(cl.name) LIKE UPPER(?)
             ORDER BY (UPPER(c.iso_code) = UPPER(?)) DESC,
                      (UPPER(TRIM(cl.name)) = UPPER(?)) DESC,
                      CHAR_LENGTH(cl.name) ASC
             LIMIT 1",
            [$input, $input, '%' . $input . '%', $input, $input],
        );

        if ($row === null) {
            return null;
        }

        return [
            'id_country'   => (int) $row['id_country'],
            'iso_code'     => (string) $row['iso_code'],
            'id_zone'      => (int) $row['id_zone'],
            'active'       => (int) $row['active'],
            'country_name' => self::cleanName((string) $row['country_name']),
            'zone_name'    => $row['zone_name'] !== null ? self::cleanName((string) $row['zone_name']) : null,
        ];
    }

    /**
     * Nazwy krajów/stref w PrestaShop bywają poprzedzone twardą spacją (U+00A0),
     * której SQL TRIM (tylko 0x20) nie usuwa (np. " Austria" = C2A0...). Czyścimy
     * wszystkie białe znaki (ze spacją niełamliwą) z obu brzegów.
     */
    public static function cleanName(string $name): string
    {
        return preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $name) ?? $name;
    }

    /**
     * Zakresy cenowe DPD dla strefy (pr_delivery JOIN pr_range_price).
     *
     * @return list<array{delimiter1:string, delimiter2:string, price:string}>
     */
    private function fetchDpdRanges(int $zoneId): array
    {
        return $this->mysql->fetchAll(
            "SELECT rp.delimiter1, rp.delimiter2, dl.price
             FROM pr_delivery dl
             JOIN pr_range_price rp ON rp.id_range_price = dl.id_range_price
             WHERE dl.id_carrier = ? AND dl.id_zone = ?
             ORDER BY rp.delimiter1",
            [self::CARRIER_ID, $zoneId],
        );
    }

    private function fetchMaxWeight(): float
    {
        $row = $this->mysql->fetchOne(
            "SELECT max_weight FROM pr_carrier WHERE id_carrier = ?",
            [self::CARRIER_ID],
        );

        return $row !== null ? (float) $row['max_weight'] : 0.0;
    }

    private function fetchEurRate(): float
    {
        static $rate = null;
        if ($rate === null) {
            $row = $this->mysql->fetchOne(
                "SELECT conversion_rate FROM pr_currency WHERE id_currency = ?",
                [self::EUR_CURRENCY_ID],
            );
            $rate = $row !== null ? (float) $row['conversion_rate'] : 0.0;
        }

        return $rate;
    }

    /**
     * Surowe wiersze pr_range_price → znormalizowane zakresy z ceną PLN + EUR.
     * Cast NUMERIC→float (PDO zwraca string), jak w ShippingInfo.
     *
     * @param list<array{delimiter1:string, delimiter2:string, price:string}> $rows
     * @return list<array{from:float, to:float, pln:float, eur:int}>
     */
    public static function normalizeRanges(array $rows, float $eurRate): array
    {
        return array_map(
            static fn(array $r): array => [
                'from' => (float) $r['delimiter1'],
                'to'   => (float) $r['delimiter2'],
                'pln'  => (float) $r['price'],
                'eur'  => self::toEurCeil((float) $r['price'], $eurRate),
            ],
            $rows,
        );
    }

    /**
     * Czy strefa ma choć jeden zakres z realną (>0) stawką. Brak = wyspa/poza zasięgiem.
     *
     * @param list<array{from:float, to:float, pln:float, eur:int}> $ranges
     */
    public static function hasPaidRange(array $ranges): bool
    {
        foreach ($ranges as $r) {
            if ($r['pln'] > 0.0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Zakres dopasowany do wartości koszyka: from <= cart_total < to (konwencja PS).
     *
     * @param list<array{from:float, to:float, pln:float, eur:int}> $ranges
     * @return array{from:float, to:float, pln:float, eur:int}|null
     */
    public static function selectRange(array $ranges, float $cartTotal): ?array
    {
        foreach ($ranges as $r) {
            if ($cartTotal >= $r['from'] && $cartTotal < $r['to']) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Próg darmowej wysyłki = dolna granica pierwszego zakresu z ceną 0 (z danych, nie hardcode).
     *
     * @param list<array{from:float, to:float, pln:float, eur:int}> $ranges
     */
    public static function freeShippingThreshold(array $ranges): ?float
    {
        $threshold = null;
        foreach ($ranges as $r) {
            if ($r['pln'] === 0.0 && ($threshold === null || $r['from'] < $threshold)) {
                $threshold = $r['from'];
            }
        }

        return $threshold;
    }

    /**
     * PLN → EUR, zaokrąglenie W GÓRĘ do pełnego euro (decyzja 155a — klient nie zapłaci
     * więcej niż usłyszał). 0 PLN → 0 EUR.
     */
    public static function toEurCeil(float $pln, float $eurRate): int
    {
        if ($pln <= 0.0 || $eurRate <= 0.0) {
            return 0;
        }

        return (int) ceil($pln * $eurRate);
    }
}
