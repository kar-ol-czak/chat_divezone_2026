<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Http\Request;
use DiveChat\Http\Response;
use DiveChat\Usage\NudgeEventStore;
use DiveChat\Usage\RateLimiter;

/**
 * Publiczny endpoint zdarzen widgetu (CHAT-T-082, ADR-090 faza 2).
 *
 * POST /api/widget/event — przyjmuje beacony 'nudge_shown' / 'nudge_cta_click'
 * / 'nudge_dismiss' (CHAT-T-086 dodal trzeci typ — klik X) z frontu (sklep
 * divezone.pl → chat.divezone.pl cross-origin). Body JSON
 * {session_id, event_type, bucket, ab_active}.
 *
 * Ochrona lekka (zdarzenia fire-and-forget, NIE LLM):
 *  - whitelist event_type (2 wartosci), bucket (v1|v2), sessionId (UUID v4 regex),
 *    ab_active (bool). Niezgodne -> cichy no-op.
 *  - RateLimiter per IP (reuse z LLM, luzny limit 60/60s). Przekroczenie -> no-op.
 *  - BEZ HMAC klienckiego (beacon nie niesie nagłówków auth latwo).
 *  - BEZ CostGuard.
 *
 * Odpowiedz: ZAWSZE 204 No Content. Beacon i tak ignoruje body i status. Nie
 * zwracamy 4xx zeby nie zaśmiecać logu sklepu szumem (skanery, boty, stary
 * widget z cache po deployu). Wszelki niezgodny request konczy sie 204 + brak
 * wpisu w tabeli.
 *
 * sendBeacon wysyla Content-Type: text/plain (lub multipart) — Request::getJsonBody
 * parsuje JSON z php://input niezaleznie od Content-Type (sekcja 5 spec).
 */
final class WidgetEventController
{
    // UUID v4: 8-4-4-4-12 hex z '4' na pozycji 13 i [89ab] na pozycji 17.
    // crypto.randomUUID() na froncie produkuje v4; akceptujemy strict v4 zeby
    // odsiac smieci (sessionId='1', 'test', zwykly losowy ciag).
    private const UUID_V4_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    // CHAT-T-086 (decyzje 256b/257a): dodany 'nudge_dismiss' (klik X) jako
    // trzeci typ ekspozycji. Migracja 027 rozszerzyla CHECK constraint na
    // wszystkie trzy wartosci. UNIQUE (session_id, event_type) zostaje —
    // dedup na poziomie kombinacji typ+sesja dziala dla wszystkich typow.
    private const VALID_EVENT_TYPES = ['nudge_shown', 'nudge_cta_click', 'nudge_dismiss'];
    private const VALID_BUCKETS = ['v1', 'v2'];

    // Luzny limit: dwa beacony per sesja x kilka uzytkownikow z tego samego
    // IP (NAT/firma) miesci sie z naddatkiem. Limit per IP, NIE per sessionId
    // (sessionId z frontu jest tani do podmiany, IP twardsze).
    private const RATE_LIMIT_PER_IP = 60;
    private const RATE_LIMIT_WINDOW_SEC = 60;

    public function __construct(
        private readonly NudgeEventStore $store,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function handle(Request $request): void
    {
        // Cokolwiek pojdzie nie tak -> 204 (no-op). Beacon i tak ignoruje.
        $body = $request->getJsonBody();

        $sessionId = is_string($body['session_id'] ?? null) ? trim($body['session_id']) : '';
        $eventType = is_string($body['event_type'] ?? null) ? $body['event_type'] : '';
        $bucket = is_string($body['bucket'] ?? null) ? $body['bucket'] : '';

        // ab_active: tolerujemy true/false bool oraz typowe stringowe formy
        // (sendBeacon czesto stringifikuje JSON.stringify, ale niektore frameworki
        // moga rzucic stringiem przez pomylke — defensywa).
        $abActiveRaw = $body['ab_active'] ?? null;
        if (is_bool($abActiveRaw)) {
            $abActive = $abActiveRaw;
        } elseif ($abActiveRaw === 'true' || $abActiveRaw === 1 || $abActiveRaw === '1') {
            $abActive = true;
        } elseif ($abActiveRaw === 'false' || $abActiveRaw === 0 || $abActiveRaw === '0') {
            $abActive = false;
        } else {
            // Brak / niepoprawny ab_active -> no-op (wymagane pole).
            $this->respond204();
        }

        if ($sessionId === '' || !preg_match(self::UUID_V4_REGEX, $sessionId)) {
            $this->respond204();
        }
        if (!in_array($eventType, self::VALID_EVENT_TYPES, true)) {
            $this->respond204();
        }
        if (!in_array($bucket, self::VALID_BUCKETS, true)) {
            $this->respond204();
        }

        // Rate-limit per IP. fail-open (RateLimiter zwraca true przy bledzie DB).
        // Przekroczenie -> cichy no-op (NIE 429 — beacon i tak ignoruje).
        $ip = $request->getClientIp();
        if ($ip !== null && !$this->rateLimiter->check('widget_event_ip:' . $ip, self::RATE_LIMIT_PER_IP, self::RATE_LIMIT_WINDOW_SEC)) {
            $this->respond204();
        }

        // Zapis (NudgeEventStore zjada bledy DB do error_log — nie przebija sie tu).
        $this->store->record($sessionId, $eventType, $bucket, $abActive);

        $this->respond204();
    }

    /**
     * 204 No Content z CORS. Beacon ignoruje body — nie wysylamy zadnego.
     */
    private function respond204(): never
    {
        Response::setCorsHeaders();
        http_response_code(204);
        exit;
    }
}
