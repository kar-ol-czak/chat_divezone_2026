<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\HmacVerifier;
use DiveChat\Chat\ChatService;
use DiveChat\Chat\ConversationStore;
use DiveChat\Chat\SettingsStore;
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
        private readonly SettingsStore $settingsStore,
    ) {}

    /**
     * Odczyt progu liczbowego (float/int) z SettingsStore + fallback .env (CHAT-T-067).
     *
     * Decyzja 176a: SettingsStore wygrywa (panel PS = jeden punkt strojenia),
     * .env jako fallback gdy brak klucza w bazie. Sanity: jesli wartosc z
     * panelu jest bezsensowna (nie-liczba, <=0, NaN, INF) -> .env default
     * (NIE wylaczamy ochrony blednym wpisem — bezpiecznik niech zawsze dziala).
     *
     * @return float|int wg parametru asInt
     */
    private function readNumericThreshold(string $storeKey, string $envKey, float|int $default, bool $asInt): float|int
    {
        $raw = $this->settingsStore->get($storeKey, null);

        if ($raw !== null && is_numeric($raw)) {
            $val = $asInt ? (int) $raw : (float) $raw;
            if ($val > 0 && is_finite((float) $val)) {
                return $val;
            }
        }

        // Fallback .env (z defaultem gdy brak klucza). Tym samym filtrem sanity.
        $envVal = Config::get($envKey);
        if ($envVal !== null && is_numeric($envVal)) {
            $val = $asInt ? (int) $envVal : (float) $envVal;
            if ($val > 0 && is_finite((float) $val)) {
                return $val;
            }
        }

        return $default;
    }

    /**
     * Odczyt emaila z SettingsStore + fallback .env (CHAT-T-067).
     * Sanity: filter_var FILTER_VALIDATE_EMAIL; pusty/zly -> fallback.
     */
    private function readEmail(string $storeKey, string $envKey, string $default): string
    {
        $raw = $this->settingsStore->get($storeKey, null);
        if (is_string($raw) && filter_var($raw, FILTER_VALIDATE_EMAIL) !== false) {
            return $raw;
        }

        $envVal = Config::get($envKey);
        if (is_string($envVal) && filter_var($envVal, FILTER_VALIDATE_EMAIL) !== false) {
            return $envVal;
        }

        return $default;
    }

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
        // CHAT-T-067 (176a): progi z SettingsStore (panel PS), .env fallback. Sanity w readers.
        $hardCap = (float) $this->readNumericThreshold('protect_daily_cap_usd', 'DIVECHAT_DAILY_CAP_USD', 10.0, false);
        $alertThreshold = (float) $this->readNumericThreshold('protect_cost_alert_usd', 'DIVECHAT_COST_ALERT_USD', 5.0, false);
        $alertEmail = $this->readEmail('protect_cost_alert_email', 'DIVECHAT_COST_ALERT_EMAIL', 'k.susicki@divezone.pl');

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
        return mb_strlen($message) > $this->maxInputChars();
    }

    private function maxInputChars(): int
    {
        // CHAT-T-067: panel PS -> .env fallback -> default 2000.
        return (int) $this->readNumericThreshold('protect_max_input_chars', 'DIVECHAT_MAX_INPUT_CHARS', 2000, true);
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
        // CHAT-T-067: panel PS -> .env fallback -> defaults 10/300, 40/300.
        $sessMax = (int) $this->readNumericThreshold('protect_rl_session_max', 'DIVECHAT_RL_SESSION_MAX', 10, true);
        $sessWindow = (int) $this->readNumericThreshold('protect_rl_session_window', 'DIVECHAT_RL_SESSION_WINDOW', 300, true);
        $ipMax = (int) $this->readNumericThreshold('protect_rl_ip_max', 'DIVECHAT_RL_IP_MAX', 40, true);
        $ipWindow = (int) $this->readNumericThreshold('protect_rl_ip_window', 'DIVECHAT_RL_IP_WINDOW', 300, true);

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
        // CHAT-T-082 (sekcja 3 spec): front podaje UUID v4 generowany przy
        // nudge_shown. Backend waliduje format i przyjmuje TYLKO zaufane
        // ksztalty (UUID v4 lub legacy 32-hex z CHAT-T-059); cokolwiek innego
        // -> generujemy server-side UUID v4 (defensywa anty-podszycie).
        // Ownership mismatch obsluguje ConversationStore::startOrResume.
        $sessionId = $this->resolveSessionId($body['session_id'] ?? null);

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
        // CHAT-T-082 (sekcja 3 spec): client-supplied sessionId (UUID v4)
        // akceptowany z walidacja formatu + ownership check w startOrResume.
        $sessionId = $this->resolveSessionId($body['session_id'] ?? null);

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

    /**
     * Zwroc bezpieczny sessionId do uzycia w petli czatu (CHAT-T-082).
     *
     * - null/pusty -> generujemy UUID v4 (nowa sesja, server-side).
     * - poprawny UUID v4 (z frontu po CHAT-T-083) -> przepuszczamy.
     * - legacy 32-hex (server-generated sprzed CHAT-T-082, persystowany w
     *   localStorage CHAT-T-059) -> przepuszczamy dla zgodnosci wstecz.
     * - cokolwiek innego (smieci, proba SQL injection format-leveled, etc.)
     *   -> generujemy fresh UUID v4 (defensywa anty-podszycie).
     *
     * Ownership check (czy sessionId nalezy do customerId z HMAC) wykonuje
     * ConversationStore::startOrResume — tu tylko format.
     */
    private function resolveSessionId(mixed $clientSessionId): string
    {
        if (!is_string($clientSessionId)) {
            return $this->generateSessionId();
        }
        $trimmed = trim($clientSessionId);
        if ($trimmed === '') {
            return $this->generateSessionId();
        }
        if (self::isValidSessionIdFormat($trimmed)) {
            return $trimmed;
        }
        return $this->generateSessionId();
    }

    /**
     * Akceptujemy UUID v4 (nowy front, CHAT-T-083) ALBO legacy 32-hex
     * (server-generated sprzed CHAT-T-082, zachowany dla CHAT-T-059
     * persystencji localStorage).
     */
    private static function isValidSessionIdFormat(string $sessionId): bool
    {
        // UUID v4: 8-4-4-4-12 z '4' w pozycji 13 i [89ab] w pozycji 17.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $sessionId) === 1) {
            return true;
        }
        // Legacy: bin2hex(random_bytes(16)) = 32 znaki lowercase hex.
        if (preg_match('/^[0-9a-f]{32}$/', $sessionId) === 1) {
            return true;
        }
        return false;
    }

    /**
     * Generuje sessionId server-side jako UUID v4 (CHAT-T-082 — spojnosc
     * formatu z crypto.randomUUID() na froncie). Wczesniej bylo 32-hex
     * (random_bytes(16)); resolveSessionId nadal honoruje stare ID z
     * localStorage CHAT-T-059.
     */
    private function generateSessionId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
