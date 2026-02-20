<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\HmacVerifier;
use DiveChat\Chat\ChatService;
use DiveChat\Config;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Endpoint czatu.
 * POST /api/chat
 *
 * Wymaga headerów:
 * - X-DiveChat-Token: HMAC token
 * - X-DiveChat-Customer: customer_id (0 = niezalogowany)
 * - X-DiveChat-Time: timestamp
 */
final class ChatController
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    public function handle(Request $request): void
    {
        // 1. Weryfikacja HMAC
        $token = $request->getHeader('x-divechat-token');
        $customerId = $request->getHeader('x-divechat-customer');
        $timestamp = $request->getHeader('x-divechat-time');

        if ($token === null || $customerId === null || $timestamp === null) {
            Response::error('Brak wymaganych headerów autoryzacji', 401);
        }

        $secret = Config::get('DIVECHAT_SECRET', '');
        if ($secret === '') {
            Response::error('Brak konfiguracji DIVECHAT_SECRET', 500);
        }

        $verifier = new HmacVerifier($secret);
        if (!$verifier->verify($token, (int) $customerId, (int) $timestamp)) {
            Response::error('Nieprawidłowy token', 401);
        }

        // 2. Walidacja body
        $body = $request->getJsonBody();
        $message = trim($body['message'] ?? '');
        $sessionId = $body['session_id'] ?? $this->generateSessionId();

        if ($message === '') {
            Response::error('Pole "message" jest wymagane i nie może być puste', 400);
        }

        // 3. Obsługa czatu
        try {
            $result = $this->chatService->handle(
                sessionId: $sessionId,
                message: $message,
                customerId: (int) $customerId ?: null,
            );

            Response::json([
                'success' => true,
                'response' => $result['response'],
                'session_id' => $result['session_id'],
                'tools_used' => $result['tools_used'],
                'products' => $result['products'],
            ]);
        } catch (\Throwable $e) {
            $errorMessage = Config::isDebug()
                ? $e->getMessage()
                : 'Wystąpił błąd. Spróbuj ponownie.';

            Response::error($errorMessage, 500);
        }
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
