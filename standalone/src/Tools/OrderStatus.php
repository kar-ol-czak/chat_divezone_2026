<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;

/**
 * Status zamówienia z MySQL PrestaShop (read-only).
 * Weryfikuje tożsamość klienta przez email + numer zamówienia.
 */
final class OrderStatus implements ToolInterface
{
    private const LANG_ID = 1;

    public function getName(): string
    {
        return 'check_order_status';
    }

    public function getDescription(): string
    {
        return 'Sprawdza status zamówienia klienta. Wymaga kodu referencyjnego zamówienia (ciąg liter, np. AODMYANNV) i adresu email. '
             . 'Używaj gdy klient pyta o status zamówienia, przesyłkę lub dostawę.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'order_reference' => [
                    'type' => 'string',
                    'description' => 'Kod referencyjny zamówienia — ciąg liter w formacie np. "AODMYANNV" (klient znajdzie go u góry maila z potwierdzeniem zamówienia)',
                ],
                'customer_email' => [
                    'type' => 'string',
                    'description' => 'Adres email klienta (do weryfikacji tożsamości)',
                ],
            ],
            'required' => ['order_reference', 'customer_email'],
        ];
    }

    public function execute(array $params): array
    {
        $reference = trim($params['order_reference'] ?? '');
        $email = trim($params['customer_email'] ?? '');

        if ($reference === '' || $email === '') {
            return ['error' => 'Wymagany kod referencyjny zamówienia i email'];
        }

        $db = MysqlConnection::getInstance();

        // Znajdź zamówienie i zweryfikuj email klienta
        $order = $db->fetchOne(
            'SELECT o.id_order, o.reference, o.date_add, o.total_paid,
                    o.id_customer, o.current_state,
                    osl.name AS status_name,
                    c.email
             FROM pr_orders o
             JOIN pr_customer c ON o.id_customer = c.id_customer
             JOIN pr_order_state_lang osl ON o.current_state = osl.id_order_state AND osl.id_lang = ?
             WHERE o.reference = ? AND c.email = ?',
            [self::LANG_ID, $reference, $email],
        );

        if (!$order) {
            return ['error' => 'Nie znaleziono zamówienia o podanym kodzie referencyjnym i emailu. Sprawdź dane.'];
        }

        // Historia statusów
        $history = $db->fetchAll(
            'SELECT osl.name AS status_name, oh.date_add
             FROM pr_order_history oh
             JOIN pr_order_state_lang osl ON oh.id_order_state = osl.id_order_state AND osl.id_lang = ?
             WHERE oh.id_order = ?
             ORDER BY oh.date_add DESC',
            [self::LANG_ID, $order['id_order']],
        );

        // Numer przesyłki
        $carrier = $db->fetchOne(
            'SELECT oc.tracking_number, c.name AS carrier_name, c.url AS tracking_url
             FROM pr_order_carrier oc
             JOIN pr_carrier c ON oc.id_carrier = c.id_carrier
             WHERE oc.id_order = ?
             ORDER BY oc.date_add DESC LIMIT 1',
            [$order['id_order']],
        );

        $result = [
            'reference' => $order['reference'],
            'date' => $order['date_add'],
            'status' => $order['status_name'],
            'total' => (float) $order['total_paid'],
            'history' => array_map(fn(array $h) => [
                'status' => $h['status_name'],
                'date' => $h['date_add'],
            ], $history),
        ];

        if ($carrier && !empty($carrier['tracking_number'])) {
            $trackingUrl = $carrier['tracking_url']
                ? str_replace('@', $carrier['tracking_number'], $carrier['tracking_url'])
                : null;

            // Fallback: jeśli URL jest pusty lub nie zawiera numeru, zbuduj z nazwy przewoźnika
            if (empty($trackingUrl) || $trackingUrl === $carrier['tracking_url']) {
                $carrierLower = strtolower($carrier['carrier_name'] ?? '');
                if (str_contains($carrierLower, 'inpost') || str_contains($carrierLower, 'paczkomat')) {
                    $trackingUrl = 'https://inpost.pl/sledzenie-przesylek?number=' . urlencode($carrier['tracking_number']);
                } elseif (str_contains($carrierLower, 'dpd')) {
                    $trackingUrl = 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1=' . urlencode($carrier['tracking_number']);
                } elseif (str_contains($carrierLower, 'dhl')) {
                    $trackingUrl = 'https://www.dhl.com/pl-pl/home/tracking.html?tracking-id=' . urlencode($carrier['tracking_number']);
                }
            }

            $result['tracking'] = [
                'carrier' => $carrier['carrier_name'],
                'number' => $carrier['tracking_number'],
                'url' => $trackingUrl,
            ];
        }

        return $result;
    }
}
