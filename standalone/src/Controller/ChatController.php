<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\HmacVerifier;
use DiveChat\Chat\ChatService;
use DiveChat\Chat\ConversationStore;
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
        private readonly ConversationStore $conversationStore,
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
                'usage' => $result['usage'],
                'conversation_cost' => $result['conversation_cost'],
                'diagnostics' => $result['diagnostics'],
            ]);
        } catch (\Throwable $e) {
            $errorMessage = Config::isDebug()
                ? $e->getMessage()
                : 'Wystąpił błąd. Spróbuj ponownie.';

            Response::error($errorMessage, 500);
        }
    }

    /**
     * POST /api/chat/stream — SSE streaming statusów.
     * Emituje event: status (postęp), event: done (pełna odpowiedź), event: error.
     */
    public function stream(Request $request): void
    {
        // 1. Weryfikacja HMAC (identycznie jak handle)
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

        // 3. Ustaw headery SSE + CORS
        Response::setCorsHeaders();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no'); // nginx

        // Wyłącz buforowanie
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        // 4. Callback emitujący status
        $emitStatus = static function (string $text): void {
            echo "event: status\ndata: " . json_encode(['text' => $text], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        };

        // 5. Obsługa czatu ze streamem statusów
        try {
            $result = $this->chatService->handle(
                sessionId: $sessionId,
                message: $message,
                customerId: (int) $customerId ?: null,
                onStatus: $emitStatus,
            );

            // event: done z pełną odpowiedzią
            echo "event: done\ndata: " . json_encode([
                'success' => true,
                'response' => $result['response'],
                'session_id' => $result['session_id'],
                'tools_used' => $result['tools_used'],
                'products' => $result['products'],
                'usage' => $result['usage'],
                'conversation_cost' => $result['conversation_cost'],
                'diagnostics' => $result['diagnostics'],
            ], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        } catch (\Throwable $e) {
            $errorMessage = Config::isDebug()
                ? $e->getMessage()
                : 'Wystąpił błąd. Spróbuj ponownie.';

            echo "event: error\ndata: " . json_encode(['error' => $errorMessage], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
    }

    /**
     * GET /api/chat/history?session_id={id}
     *
     * Odczyt historii aktywnej rozmowy do odtworzenia widgetu po nawigacji
     * miedzy stronami sklepu (CHAT-T-059). Auth HMAC identyczny jak handle().
     *
     * Weryfikacja wlasciciela (decyzja 145a, kryterium bezpieczenstwa #7):
     * rozmowa nalezy do zadajacego tylko jesli ps_customer_id rozmowy ==
     * customerId z weryfikowanego HMAC. Goscie (customerId=0) korzystaja z
     * sessionId jako sekretu (generowany losowo server-side, nieprzewidywalny).
     * Niedopasowanie -> {exists:false} (NIE zwracamy informacji "rozmowa
     * istnieje ale to nie twoja" — to by ulatwialo enumeracje).
     *
     * Rozmowa nieaktywna (nieistniejaca, closed_at IS NOT NULL, cudza) ->
     * {exists:false, messages:[]} 200. NIE blad — front gracefully startuje
     * nowa rozmowe.
     */
    public function history(Request $request): void
    {
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

        $sessionId = trim((string) ($request->getQueryParam('session_id') ?? ''));
        if ($sessionId === '') {
            Response::error('Parametr session_id jest wymagany', 400);
        }

        $conversation = $this->conversationStore->findActiveBySessionId($sessionId);
        $customerIdInt = (int) $customerId;

        // Brak rozmowy lub niedopasowanie wlasciciela -> {exists:false}.
        // Niedopasowanie traktujemy tak samo jak brak (NIE rozrozniamy w
        // odpowiedzi, zeby nie ulatwiac enumeracji sessionId — decyzja 145a).
        if ($conversation === null || $conversation['ps_customer_id'] !== $customerIdInt) {
            Response::json(['exists' => false, 'messages' => []]);
        }

        Response::json([
            'exists' => true,
            'session_id' => $sessionId,
            'messages' => $conversation['messages'],
        ]);
    }

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }
}
