<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\PostgresConnection;

/**
 * Informacje o dostawie.
 * Dane z tabeli divechat_shipping_rates + divechat_shop_config (edytowalne online).
 * ADR-059 (migracja 013).
 */
final class ShippingInfo implements ToolInterface
{
    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    public function getName(): string
    {
        return 'get_shipping_info';
    }

    public function getDescription(): string
    {
        return 'Informacje o metodach dostawy, kosztach i progach darmowej wysyłki w divezone.pl. '
             . 'Używaj gdy klient pyta o koszty wysyłki, czas dostawy lub darmową dostawę. '
             . 'Parametr zone: PL (domyślnie) lub EU (reszta Europy).';
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

        $thresholdRow = $this->db->fetchOne(
            "SELECT value FROM divechat_shop_config WHERE key = ?",
            ['free_shipping_threshold_' . strtolower($zone)],
        );
        $threshold = $thresholdRow !== null ? (float) $thresholdRow['value'] : 0.0;

        $freeShipping = $threshold > 0.0 && $cartTotal !== null && $cartTotal >= $threshold;

        // Cast NUMERIC -> float (PDO pgsql zwraca jako string)
        $methods = array_map(
            static fn(array $m): array => [
                'carrier_name'  => $m['carrier_name'],
                'price'         => (float) $m['price'],
                'cod_price'     => $m['cod_price'] !== null ? (float) $m['cod_price'] : null,
                'delivery_days' => $m['delivery_days'],
            ],
            $methods,
        );

        return [
            'zone' => $zone,
            'methods' => $methods,
            'free_shipping_threshold' => $threshold > 0.0 ? $threshold : null,
            'cart_total' => $cartTotal,
            'free_shipping' => $freeShipping,
            'note' => $this->buildNote($freeShipping, $cartTotal, $threshold),
        ];
    }

    private function buildNote(bool $freeShipping, ?float $cartTotal, float $threshold): string
    {
        if ($threshold <= 0.0) {
            return 'Stawki flat do 31 kg.';
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
