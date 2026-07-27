<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Database\PostgresConnection;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Endpoint raportu CTR nudge (CHAT-T-084, ADR-090 faza 2 — sekcja 7 spec).
 *
 * GET /api/admin/nudge-ctr?days=N
 *
 * Auth: kanal serwerowy panelu PS (ServerHmacVerifier, sekret DIVECHAT_SERVER_SECRET)
 * + rola 'admin' w divechat_admin_roles. Wzorzec 1:1 z AdminAnalyticsController.
 *
 * Cztery metryki per (bucket, ab_active) — CHAT-T-086 dodal nudge_dismiss
 * + decyzja 257a ignored = max(0, shown-clicks-dismissals):
 *  - shown        = COUNT DISTINCT session_id WHERE event_type='nudge_shown'
 *  - clicks       = COUNT DISTINCT session_id WHERE event_type='nudge_cta_click'
 *  - dismissals   = COUNT DISTINCT session_id WHERE event_type='nudge_dismiss' (klik X)
 *  - ignored      = max(0, shown - clicks - dismissals) (reszta: brak reakcji)
 *  - ctr          = clicks/shown (null gdy shown=0)
 *  - dismiss_rate = dismissals/shown
 *  - conversations    = COUNT DISTINCT c.nudge_sid gdzie rozmowa ma >=1 wiad. role='user'
 *                       (JOIN events.session_id = c.nudge_sid, korelacja ADR-092)
 *  - conversion_rate  = conversations/shown
 *
 * Stare rozmowy sprzed CHAT-T-085 maja c.nudge_sid=NULL → nie wlicza ich do
 * conversations (swiadome — atrybucja od momentu wdrozenia, ADR-092).
 */
final class AdminNudgeCtrController
{
    public function __construct(
        private readonly ServerHmacVerifier $serverVerifier,
        private readonly PostgresConnection $pg,
    ) {}

    public function handle(Request $request): void
    {
        $this->requireAdmin();

        $days = max(1, min(365, $request->getQueryInt('days', 30)));
        $rows = $this->fetchMetrics($days);

        Response::json([
            'days' => $days,
            'rows' => $rows,
        ]);
    }

    /**
     * Agregacja per (bucket, ab_active) z opcjonalna konwersja po nudge_sid.
     *
     * Pojedyncze zapytanie z CTE — events to malo wierszy, JOIN po nudge_sid
     * uzywa partial index idx_conversations_nudge_sid (CHAT-T-085). Filter
     * jsonb_array_elements role='user' kosztowniejszy ale konwersacja to MALY
     * pod-zbior rozmow (tylko te z nudge_sid).
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchMetrics(int $days): array
    {
        // (? || ' days')::interval — wzorzec z RateLimiter, bezpieczny dla bind.
        $interval = ' AND e.created_at > NOW() - (? || \' days\')::interval';
        $sql = <<<SQL
            WITH agg AS (
                SELECT
                    e.bucket,
                    e.ab_active,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'nudge_shown'     THEN e.session_id END) AS shown,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'nudge_cta_click' THEN e.session_id END) AS clicks,
                    COUNT(DISTINCT CASE WHEN e.event_type = 'nudge_dismiss'   THEN e.session_id END) AS dismissals
                FROM divechat_nudge_events e
                WHERE TRUE{$interval}
                GROUP BY e.bucket, e.ab_active
            ),
            conv AS (
                SELECT
                    e.bucket,
                    e.ab_active,
                    COUNT(DISTINCT c.nudge_sid) AS conversations
                FROM divechat_nudge_events e
                JOIN divechat_conversations c ON c.nudge_sid = e.session_id
                WHERE e.event_type = 'nudge_shown'{$interval}
                  AND EXISTS (
                      SELECT 1 FROM jsonb_array_elements(c.messages) m
                      WHERE m->>'role' = 'user'
                  )
                GROUP BY e.bucket, e.ab_active
            )
            SELECT
                a.bucket,
                a.ab_active,
                a.shown,
                a.clicks,
                a.dismissals,
                GREATEST(0, a.shown - a.clicks - a.dismissals) AS ignored,
                CASE WHEN a.shown > 0 THEN a.clicks::float      / a.shown ELSE NULL END AS ctr,
                CASE WHEN a.shown > 0 THEN a.dismissals::float  / a.shown ELSE NULL END AS dismiss_rate,
                COALESCE(c.conversations, 0) AS conversations,
                CASE WHEN a.shown > 0 THEN COALESCE(c.conversations, 0)::float / a.shown ELSE NULL END AS conversion_rate
            FROM agg a
            LEFT JOIN conv c ON c.bucket = a.bucket AND c.ab_active = a.ab_active
            ORDER BY a.bucket, a.ab_active
        SQL;

        $daysStr = (string) $days;
        $rows = $this->pg->fetchAll($sql, [$daysStr, $daysStr]);

        // Normalizacja typow do JSON (PG zwraca ints jako string przez PDO).
        return array_map(static function (array $r): array {
            return [
                'bucket'          => (string) $r['bucket'],
                'ab_active'       => (bool) $r['ab_active'],
                'shown'           => (int) $r['shown'],
                'clicks'          => (int) $r['clicks'],
                'dismissals'      => (int) $r['dismissals'],
                'ignored'         => (int) $r['ignored'],
                'ctr'             => $r['ctr']             !== null ? (float) $r['ctr']             : null,
                'dismiss_rate'    => $r['dismiss_rate']    !== null ? (float) $r['dismiss_rate']    : null,
                'conversations'   => (int) $r['conversations'],
                'conversion_rate' => $r['conversion_rate'] !== null ? (float) $r['conversion_rate'] : null,
            ];
        }, $rows);
    }

    /**
     * Weryfikacja kanalu serwerowego + rola 'admin'. Wzorzec 1:1 z
     * AdminAnalyticsController::requireAdmin (CHAT-T-049, decyzja 107a/108a).
     */
    private function requireAdmin(): void
    {
        $token = (string) ($_SERVER['HTTP_X_DIVECHAT_SERVER_TOKEN'] ?? '');
        $employeeRaw = $_SERVER['HTTP_X_DIVECHAT_SERVER_EMPLOYEE'] ?? '';
        $timeRaw = $_SERVER['HTTP_X_DIVECHAT_SERVER_TIME'] ?? '';

        if ($token === '' || $employeeRaw === '' || $timeRaw === '') {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $employeeId = filter_var($employeeRaw, FILTER_VALIDATE_INT);
        $timestamp = filter_var($timeRaw, FILTER_VALIDATE_INT);
        if ($employeeId === false || $timestamp === false || $employeeId <= 0) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        if (!$this->serverVerifier->verify($token, $employeeId, $timestamp)) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->pg->fetchOne(
            'SELECT role FROM divechat_admin_roles WHERE employee_id = ?',
            [$employeeId],
        );

        if ($row === null) {
            Response::json(['error' => 'Forbidden', 'reason' => 'no_role'], 403);
        }

        if ((string) $row['role'] !== 'admin') {
            Response::json(['error' => 'Forbidden', 'reason' => 'admin_only'], 403);
        }
    }
}
