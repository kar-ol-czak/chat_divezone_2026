<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\HmacVerifier;
use DiveChat\Chat\ChatService;
use DiveChat\Chat\ConversationStore;
use DiveChat\Config;
use DiveChat\Http\Request;
use DiveChat\Http\Response;
use DiveChat\Usage\CostGuard;
use DiveChat\Usage\RateLimiter;

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
        private readonly CostGuard $costGuard,
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * Komunikat pokazywany uzytkownikowi gdy dzienny cap zostal przekroczony.
     * Tekst uzgodniony w CHAT-T-064 (decyzja 161/165b/166).
     */
    private const CAP_MESSAGE = 'Czat jest chwilowo niedostępny. Napisz na dive@divezone.pl lub zadzwoń 56 307 03 03 — chętnie pomożemy.';

    /**
     * Komunikat przy przekroczeniu rate-limitu (CHAT-T-066, ADR-064/082).
     * Soft limit — wiadomosc bota, NIE blad sieci.
     */
    private const RATE_LIMIT_MESSAGE = 'Wysłałeś wiele wiadomości w krótkim czasie. Odczekaj chwilę albo napisz na dive@divezone.pl / 56 307 03 03.';

    /**
     * Sprawdza cap kosztow + ewentualnie wysyla alert. Idempotentnie.
     * Zwraca true jesli cap PRZEKROCZONY (caller ma NIE wolac LLM).
     */
    private function enforceCostGuard(): bool
    {
        $hardCap = (float) (Config::get('DIVECHAT_DAILY_CAP_USD') ?? '10');
        $alertThreshold = (float) (Config::get('DIVECHAT_COST_ALERT_USD') ?? '5');
        $alertEmail = Config::get('DIVECHAT_COST_ALERT_EMAIL') ?? 'k.susicki@divezone.pl';

        try {
            $spent = $this->costGuard->dailyCostUsd();
        } catch (\Throwable $e) {
            // DB chwilowo niedostepna -> NIE blokuj czatu (cap to bezpiecznik, nie zaleznosc krytyczna).
            error_log('[ChatController] cost guard read failed, fail-open: ' . $e->getMessage());
            return false;
        }

        if ($spent > $alertThreshold) {
            // Best-effort, NIE blokuje glownej sciezki nawet przy throw (defensywa).
            try {
                $this->costGuard->maybeSendAlert($spent, $hardCap, $alertEmail);
            } catch (\Throwable $e) {
                error_log('[ChatController] cost guard alert failed: ' . $e->getMessage());
            }
        }

        return $spent >= $hardCap;
    }

    private function inputTooLong(string $message): bool
    {
        $maxChars = (int) (Config::get('DIVECHAT_MAX_INPUT_CHARS') ?? '2000');
        return mb_strlen($message) > $maxChars;
    }

    private function maxInputChars(): int
    {
        return (int) (Config::get('DIVECHAT_MAX_INPUT_CHARS') ?? '2000');
    }

    /**
     * Rate-limit per sessionId i per IP (CHAT-T-066). Inkrement PRZED LLM.
     * Sprawdza OBA klucze (sess: i ip:); ktorykolwiek przekroczony -> true (odmowa).
     * IP=null (np. niepoprawny REMOTE_ADDR) -> tylko per-session.
     *
     * Zwraca true jesli request ma byc ODRZUCONY.
     */
    private function rateLimitExceeded(string $sessionId, ?string $ip): bool
    {
        $sessMax = (int) (Config::get('DIVECHAT_RL_SESSION_MAX') ?? '10');
        $sessWindow = (int) (Config::get('DIVECHAT_RL_SESSION_WINDOW') ?? '300');
        $ipMax = (int) (Config::get('DIVECHAT_RL_IP_MAX') ?? '40');
        $ipWindow = (int) (Config::get('DIVECHAT_RL_IP_WINDOW') ?? '300');

        // KAZDY request inkrementuje OBA liczniki (atomowo). NIE robimy
        // short-circuit po sess — chcemy widziec rzeczywiste obciazenie IP
        // (napastnik moglby ratowac sie zmiana sessionId po wyczerpaniu sess
        //  i wtedy ip: musi wykazac caly ruch, nie tylko czesc).
        $sessOk = $this->rateLimiter->check('sess:' . $sessionId, $sessMax, $sessWindow);
        $ipOk = $ip === null ? true : $this->rateLimiter->check('ip:' . $ip, $ipMax, $ipWindow);

        return !$sessOk || !$ipOk;
    }

    /**
     * Buduje payload odpowiedzi-przegrody (cap kosztow / rate-limit) ze wspolnym
     * ksztaltem oczekiwanym przez front (transport.js onDone -> appendBotMessage).
     */
    private function gatePayload(string $message, string $sessionId, string $reasonKey): array
    {
        return [
            'success' => false,
            'response' => $message,
            'session_id' => $sessionId,
            'tools_used' => [],
            'products' => [],
            'usage' => null,
            'conversation_cost' => null,
            'diagnostics' => [$reasonKey => true],
        ];
    }

    /**
     * Emituje gate-response w trybie SSE (event done, NIE error — front pokaze
     * jako wiadomosc bota, nie blad transportu).
     */
    private function emitSseGate(array $payload): never
    {
        Response::setCorsHeaders();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        echo "event: done\ndata: " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        exit;
    }

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

        // 2a. Limit dlugosci inputu (CHAT-T-064, ochrona publiczna PRZED LLM).
        if ($this->inputTooLong($message)) {
            Response::error(
                'Wiadomość jest za długa (max ' . $this->maxInputChars() . ' znaków). Skróć pytanie lub napisz na dive@divezone.pl.',
                400,
            );
        }

        // 2b. Dzienny cap kosztow (CHAT-T-064): jesli przekroczony — NIE wolaj LLM,
        // zwroc grzeczny komunikat jako wiadomosc bota (HTTP 200, success=false,
        // pole `response` z tekstem; front pokaze jako wiadomosc, nie blad sieci).
        if ($this->enforceCostGuard()) {
            Response::json($this->gatePayload(self::CAP_MESSAGE, $sessionId, 'cost_cap_reached'));
        }

        // 2c. Rate-limit per sessionId + per IP (CHAT-T-066, warstwa 3 ochrony).
        // Inkrement PRZED LLM (chcemy liczyc kazdy ruch, takze ten odrzucony — inaczej
        // napastnik nie "kumuluje" sie w liczniku). Soft limit: 200 + komunikat bota.
        if ($this->rateLimitExceeded($sessionId, $request->getClientIp())) {
            Response::json($this->gatePayload(self::RATE_LIMIT_MESSAGE, $sessionId, 'rate_limited'));
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

        // 2a. Limit dlugosci inputu (CHAT-T-064). 400 PRZED SSE — transport
        // pokaze jako blad walidacji (uzytkownik wpisal za dlugo, swiadomy feedback).
        if ($this->inputTooLong($message)) {
            Response::error(
                'Wiadomość jest za długa (max ' . $this->maxInputChars() . ' znaków). Skróć pytanie lub napisz na dive@divezone.pl.',
                400,
            );
        }

        // 2b. Dzienny cap kosztow (CHAT-T-064): jesli przekroczony — NIE wolaj LLM.
        // W trybie SSE emitujemy event `done` z komunikatem jako wiadomosc bota
        // (front transport.js: onDone(payload) -> appendBotMessage(payload.response)),
        // a nie blad transportu. Headery SSE musza pojsc PRZED echo.
        if ($this->enforceCostGuard()) {
            $this->emitSseGate($this->gatePayload(self::CAP_MESSAGE, $sessionId, 'cost_cap_reached'));
        }

        // 2c. Rate-limit per sessionId + per IP (CHAT-T-066, warstwa 3 ochrony).
        // Stream tez: event `done` (NIE `error`) — wiadomosc bota, nie blad sieci.
        if ($this->rateLimitExceeded($sessionId, $request->getClientIp())) {
            $this->emitSseGate($this->gatePayload(self::RATE_LIMIT_MESSAGE, $sessionId, 'rate_limited'));
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
     * GET /api/chat/history?sid={id}
     *
     * Odczyt historii aktywnej rozmowy do odtworzenia widgetu po nawigacji
     * miedzy stronami sklepu (CHAT-T-059). Auth HMAC identyczny jak handle().
     *
     * UWAGA — nazwa parametru `sid` (NIE `session_id`): LiteSpeed/ModSecurity
     * na hostingu blokuje query stringi z `session_id=` (regula PHPSESSID-like,
     * 403 zanim PHP zobaczy request). Krotsze `sid` przechodzi przez WAF i
     * jednoznacznie identyfikuje sesje rozmowy. Body POST /api/chat dalej uzywa
     * `session_id` (POST body nie podlega tej regule).
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

        $sessionId = trim((string) ($request->getQueryParam('sid') ?? ''));
        if ($sessionId === '') {
            Response::error('Parametr sid jest wymagany', 400);
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
