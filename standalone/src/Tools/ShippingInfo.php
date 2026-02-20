<?php

declare(strict_types=1);

namespace DiveChat\Tools;

/**
 * Informacje o dostawie.
 * MVP: hardkodowane dane o metodach dostawy.
 * Docelowo: z pr_carrier + pr_delivery.
 */
final class ShippingInfo implements ToolInterface
{
    public function getName(): string
    {
        return 'get_shipping_info';
    }

    public function getDescription(): string
    {
        return 'Informacje o metodach dostawy, kosztach i progach darmowej wysyłki w divezone.pl. '
             . 'Używaj gdy klient pyta o koszty wysyłki, czas dostawy lub darmową dostawę.';
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
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): array
    {
        $cartTotal = isset($params['cart_total']) ? (float) $params['cart_total'] : null;

        // MVP: hardkodowane dane o dostawie divezone.pl
        $methods = [
            [
                'name' => 'Kurier DPD',
                'price' => 15.99,
                'delivery_time' => '1-2 dni robocze',
                'free_from' => 499.0,
            ],
            [
                'name' => 'Kurier InPost',
                'price' => 14.99,
                'delivery_time' => '1-2 dni robocze',
                'free_from' => 499.0,
            ],
            [
                'name' => 'Paczkomat InPost',
                'price' => 12.99,
                'delivery_time' => '1-2 dni robocze',
                'free_from' => 499.0,
            ],
            [
                'name' => 'Odbiór osobisty (Warszawa)',
                'price' => 0.0,
                'delivery_time' => 'Po potwierdzeniu dostępności',
                'free_from' => 0.0,
            ],
        ];

        $freeThreshold = 499.0;
        $freeShipping = $cartTotal !== null && $cartTotal >= $freeThreshold;

        return [
            'methods' => $methods,
            'free_shipping_threshold' => $freeThreshold,
            'cart_total' => $cartTotal,
            'free_shipping' => $freeShipping,
            'note' => $freeShipping
                ? 'Darmowa dostawa przy zamówieniu powyżej 499 zł!'
                : ($cartTotal !== null
                    ? sprintf('Do darmowej dostawy brakuje %.2f zł.', $freeThreshold - $cartTotal)
                    : 'Darmowa dostawa od 499 zł.'),
        ];
    }
}
