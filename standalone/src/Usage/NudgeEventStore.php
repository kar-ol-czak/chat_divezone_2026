<?php

declare(strict_types=1);

namespace DiveChat\Usage;

use DiveChat\Database\PostgresConnection;

/**
 * Lekki zapis zdarzen ekspozycji/klikow zachety (CHAT-T-082, ADR-090 faza 2).
 *
 * Pisze do divechat_nudge_events (Railway PG). INSERT ON CONFLICT DO NOTHING
 * gwarantuje dedup po UNIQUE (session_id, event_type) — front wysyla beacon
 * raz/sesje, ale defensywnie tolerujemy retry sendBeacon vs fetch keepalive.
 *
 * Fail-soft: blad DB NIE wybucha do gory (caller zwraca 204 jak w happy
 * path — beacon jest fire-and-forget, klient ignoruje body). Loguje przez
 * error_log dla diagnostyki.
 *
 * Wzorzec: RateLimiter (Usage/RateLimiter.php) — singleton PG, fail-open.
 */
final class NudgeEventStore
{
    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    /**
     * Wstawia zdarzenie (ON CONFLICT DO NOTHING). Cisza przy bledzie DB.
     *
     * Caller MUSI juz zwalidowac whitelist (event_type, bucket) i format
     * sessionId — store ufa wejsciu (constrainty CHECK w SQL chronia tabele).
     */
    public function record(string $sessionId, string $eventType, string $bucket, bool $abActive): void
    {
        try {
            $this->db->query(
                'INSERT INTO divechat_nudge_events (session_id, event_type, bucket, ab_active)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (session_id, event_type) DO NOTHING',
                [$sessionId, $eventType, $bucket, $abActive ? 'true' : 'false'],
            );
        } catch (\Throwable $e) {
            // Fire-and-forget: nie psujemy beacona bledem DB. Telemetria CTR
            // to dane "nice-to-have" — chwilowa awaria PG nie ma blokowac UX.
            error_log('[NudgeEventStore] insert failed: ' . $e->getMessage());
        }
    }
}
