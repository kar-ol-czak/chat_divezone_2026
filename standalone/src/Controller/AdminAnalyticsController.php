<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Admin\CostAnalytics;
use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Database\PostgresConnection;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * API analityki kosztow i top rozmow dla zakladki Analityka w panelu PS
 * (CHAT-T-049, Etap 2 migracji, ADR-070).
 *
 * - GET /api/admin/cost/kpi
 * - GET /api/admin/cost/trend?period=daily|weekly|monthly&days=30
 * - GET /api/admin/cost/by-model?days=30
 * - GET /api/admin/conversations/top?limit=10&days=30
 *
 * Auth (CHAT-T-044/049, ADR-068): kanal serwerowy panelu PS — ServerHmacVerifier
 * (X-DiveChat-Server-Token/-Employee/-Time, sekret DIVECHAT_SERVER_SECRET).
 * Wymagana rola 'admin' w divechat_admin_roles (decyzja 107a/108a — koszty
 * widoczne tylko dla admina). 401 brak/zly podpis, 403 employee bez roli admin.
 *
 * Logika danych (CostAnalytics) bez zmian — to czysty refaktor warstwy auth
 * z Basic Auth (AdminController) na kanal serwerowy (decyzja 118b: nowy
 * kontroler, stary AdminController nietkniety, obsluguje tylko conversations/{id}).
 */
final class AdminAnalyticsController
{
    public function __construct(
        private readonly CostAnalytics $analytics,
        private readonly ServerHmacVerifier $serverVerifier,
        private readonly PostgresConnection $pg,
    ) {}

    public function kpi(Request $request): void
    {
        $this->requireAdmin();
        Response::json($this->analytics->kpi());
    }

    public function trend(Request $request): void
    {
        $this->requireAdmin();

        $period = $request->getQueryParam('period') ?? 'daily';
        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            Response::error('Nieprawidłowy period. Dozwolone: daily, weekly, monthly', 400);
        }

        $days = max(1, min(365, $request->getQueryInt('days', 30)));

        Response::json($this->analytics->trend($period, $days));
    }

    public function byModel(Request $request): void
    {
        $this->requireAdmin();

        $days = max(1, min(365, $request->getQueryInt('days', 30)));

        Response::json([
            'days' => $days,
            'models' => $this->analytics->byModel($days),
        ]);
    }

    public function topConversations(Request $request): void
    {
        $this->requireAdmin();

        $limit = max(1, min(100, $request->getQueryInt('limit', 10)));
        $days = max(1, min(365, $request->getQueryInt('days', 30)));

        Response::json([
            'limit' => $limit,
            'days' => $days,
            'conversations' => $this->analytics->topConversations($limit, $days),
        ]);
    }

    /**
     * Weryfikacja kanalu serwerowego + wymagana rola 'admin' (CHAT-T-049).
     * Wzorzec 1:1 z SettingsController::requireAdmin() — analityka kosztow
     * widoczna tylko dla admina (decyzja 107a/108a).
     *
     * Response::json konczy `exit`, wiec po nieudanej walidacji metoda nie wraca.
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
