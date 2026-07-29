<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Config;
use GuzzleHttp\Client;

/**
 * Ponowna wysyłka informacji o zamówieniu na adres użyty przy jego składaniu
 * (CHAT-T-180 część B, karta Chat-68). Narzędzie NIE wysyła maila samo — woła
 * kanałem serwerowym front controller modułu PrestaShop, który buduje i wysyła
 * NOWY, prosty mail HTML (część A tego zadania).
 *
 * BEZPIECZEŃSTWO: mail idzie WYŁĄCZNIE na adres z zamówienia. Parametr
 * customer_email jest jednocześnie WERYFIKACJĄ tożsamości i CELEM wysyłki —
 * nie ma osobnego pola „wyślij na". Schemat parametrów identyczny z OrderStatus
 * (check_order_status), żeby bot mógł reużyć dane z wcześniejszej tury.
 *
 * Kanał backend->moduł: HMAC sha256 sekretem serwerowym (DIVECHAT_SERVER_SECRET,
 * TEN SAM sekret co panel admina, tylko nowy kierunek). Payload
 * order_reference|email|timestamp, nagłówki X-DiveChat-Resend-Token/-Time,
 * anti-replay ±300s po stronie modułu.
 */
final class ResendOrderInfo implements ToolInterface
{
    // Brak konfiguracji URL sklepu w .env backendu (potwierdzone KROK B0: tylko
    // DATABASE_URL/DIVECHAT_SECRET/DIVECHAT_SERVER_SECRET). Inne narzędzia też
    // hardkodują https://divezone.pl — trzymamy ten sam wzorzec.
    private const MODULE_URL = 'https://divezone.pl/module/divezone_chat/resend_order_info';

    // Synchroniczne wywołanie w turze rozmowy — klient czeka, więc krótki timeout.
    private const HTTP_TIMEOUT_SEC = 5;

    private ?Client $http;

    public function __construct(?Client $http = null)
    {
        $this->http = $http;
    }

    public function getName(): string
    {
        return 'resend_order_info';
    }

    public function getDescription(): string
    {
        return 'Wysyła PONOWNIE informacje o zamówieniu na adres email użyty przy jego składaniu. '
             . 'Wymaga kodu referencyjnego zamówienia (ciąg liter, np. AODMYANNV) i adresu email. '
             . 'Używaj gdy klient mówi, że nie dostał / zgubił maila z potwierdzeniem zamówienia. '
             . 'Mail trafia WYŁĄCZNIE na adres z zamówienia — nigdy na inny adres podany w rozmowie.';
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
                    'description' => 'Adres email klienta (do weryfikacji tożsamości ORAZ jako adres docelowy wysyłki — mail idzie tylko tutaj)',
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
            return ['sent' => false, 'reason' => 'error'];
        }

        $secret = (string) Config::get('DIVECHAT_SERVER_SECRET', '');
        if ($secret === '') {
            error_log('[ResendOrderInfo] brak DIVECHAT_SERVER_SECRET w .env');
            return ['sent' => false, 'reason' => 'error'];
        }

        // Podpisujemy TRIMOWANE wartości i te same wysyłamy jako pola POST —
        // moduł też trimuje przed weryfikacją HMAC, więc kontrakt jest spójny.
        $timestamp = time();
        $token = $this->buildToken($reference, $email, $timestamp, $secret);

        try {
            $client = $this->http ?? new Client();
            $response = $client->post(self::MODULE_URL, [
                'headers' => [
                    'X-DiveChat-Resend-Token' => $token,
                    'X-DiveChat-Resend-Time' => (string) $timestamp,
                ],
                'form_params' => [
                    'order_reference' => $reference,
                    'email' => $email,
                ],
                'timeout' => self::HTTP_TIMEOUT_SEC,
                'http_errors' => false, // 401/500 nie rzucają — sami interpretujemy kod
            ]);

            return $this->interpretResponse(
                $response->getStatusCode(),
                (string) $response->getBody(),
            );
        } catch (\Throwable $e) {
            // Timeout / błąd sieci / DNS — nie ujawniamy szczegółów technicznych botowi.
            error_log('[ResendOrderInfo] HTTP błąd: ' . $e->getMessage());
            return ['sent' => false, 'reason' => 'error'];
        }
    }

    /**
     * HMAC sha256 hex, payload order_reference|email|timestamp. Musi być
     * identyczny z weryfikacją w module (verifyHmac w resend_order_info.php).
     */
    public function buildToken(string $reference, string $email, int $timestamp, string $secret): string
    {
        return hash_hmac('sha256', $reference . '|' . $email . '|' . $timestamp, $secret);
    }

    /**
     * Mapuje odpowiedź modułu na kontrakt dla bota:
     *   {"sent":true} | {"sent":false,"reason":"not_found|rate_limited|error"}
     * Wszystko inne niż jednoznaczne 200+ok/not_found/rate_limited => error
     * (401, 500, pusty/niepoprawny JSON, nieznany kod błędu).
     */
    public function interpretResponse(int $httpCode, string $body): array
    {
        if ($httpCode !== 200) {
            return ['sent' => false, 'reason' => 'error'];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return ['sent' => false, 'reason' => 'error'];
        }

        if (($data['ok'] ?? false) === true) {
            return ['sent' => true];
        }

        $reason = (string) ($data['error'] ?? '');
        if ($reason === 'not_found' || $reason === 'rate_limited') {
            return ['sent' => false, 'reason' => $reason];
        }

        return ['sent' => false, 'reason' => 'error'];
    }
}
