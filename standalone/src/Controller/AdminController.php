<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Admin\ConversationViewer;
use DiveChat\Admin\CostAnalytics;
use DiveChat\Http\AdminAuthMiddleware;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * API admin dashboardu (TASK-055).
 *
 * Wszystkie endpointy chronione przez `AdminAuthMiddleware` (basic auth +
 * weryfikacja hash z `.htpasswd`).
 *
 * - GET  /api/admin/cost/kpi
 * - GET  /api/admin/cost/trend?period=daily|weekly|monthly&days=30
 * - GET  /api/admin/cost/by-model?days=30
 * - GET  /api/admin/conversations/top?limit=10&days=30
 * - GET  /api/admin/conversations/{id}     (numeric id)
 */
final class AdminController
{
    public function __construct(
        private readonly AdminAuthMiddleware $auth,
        private readonly CostAnalytics $analytics,
        private readonly ConversationViewer $viewer,
    ) {}

    public function kpi(Request $request): void
    {
        $this->auth->check();
        Response::json($this->analytics->kpi());
    }

    public function trend(Request $request): void
    {
        $this->auth->check();

        $period = $request->getQueryParam('period') ?? 'daily';
        if (!in_array($period, ['daily', 'weekly', 'monthly'], true)) {
            Response::error('Nieprawidłowy period. Dozwolone: daily, weekly, monthly', 400);
        }

        $days = max(1, min(365, $request->getQueryInt('days', 30)));

        Response::json($this->analytics->trend($period, $days));
    }

    public function byModel(Request $request): void
    {
        $this->auth->check();

        $days = max(1, min(365, $request->getQueryInt('days', 30)));

        Response::json([
            'days' => $days,
            'models' => $this->analytics->byModel($days),
        ]);
    }

    public function topConversations(Request $request): void
    {
        $this->auth->check();

        $limit = max(1, min(100, $request->getQueryInt('limit', 10)));
        $days = max(1, min(365, $request->getQueryInt('days', 30)));

        Response::json([
            'limit' => $limit,
            'days' => $days,
            'conversations' => $this->analytics->topConversations($limit, $days),
        ]);
    }

    public function conversationDetail(Request $request): void
    {
        $this->auth->check();

        $idRaw = $request->params['id'] ?? '';
        if ($idRaw === '' || !ctype_digit($idRaw)) {
            Response::error('Nieprawidłowe id (oczekiwana liczba)', 400);
        }

        $conversation = $this->viewer->get((int) $idRaw);
        if ($conversation === null) {
            Response::error('Rozmowa nie znaleziona', 404);
        }

        Response::json($conversation);
    }
}
