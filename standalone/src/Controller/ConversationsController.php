<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\AI\UsageLogger;
use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Chat\ConversationStore;
use DiveChat\Database\PostgresConnection;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Admin API do przeglądania rozmów.
 *
 * GET /api/conversations — lista z paginacją i filtrami.
 * GET /api/conversations/{session_id} — szczegóły + conversation_cost.
 * POST /api/conversations/{session_id}/status — zmiana admin_status.
 *
 * Auth (CHAT-T-046, ADR-068): kanał serwerowy panelu PS — ServerHmacVerifier
 * (X-DiveChat-Server-Token/-Employee/-Time, sekret DIVECHAT_SERVER_SECRET).
 * Wymagana DOWOLNA rola (operator+admin) w divechat_admin_roles — operator
 * obsługuje rozmowy klientów, to jego praca. Wzorzec 1:1 z
 * AdminRecommendationsController, NIE admin-only jak SettingsController.
 *
 * UWAGA: stary widok historii (standalone/public/js/history.js) NIE wysyła
 * nagłówków serwerowych — przestanie działać do czasu migracji widoku do
 * zakładki "Rozmowy" w panelu PS (decyzja 95b). Oczekiwane.
 */
final class ConversationsController
{
    public function __construct(
        private readonly ConversationStore $conversationStore,
        private readonly UsageLogger $usageLogger,
        private readonly ServerHmacVerifier $serverVerifier,
        private readonly PostgresConnection $pg,
    ) {}

    public function list(Request $request): void
    {
        $this->requireAnyRole();

        $page = max(1, $request->getQueryInt('page', 1));
        $perPage = min(100, max(1, $request->getQueryInt('per_page', 20)));
        $search = $request->getQueryParam('search') ?: null;
        $knowledgeGap = $request->getQueryBool('knowledge_gap');
        $adminStatus = $request->getQueryParam('admin_status') ?: null;

        $result = $this->conversationStore->list($page, $perPage, $search, $knowledgeGap, $adminStatus);

        Response::json($result);
    }

    public function detail(Request $request): void
    {
        $this->requireAnyRole();

        $sessionId = $request->params['session_id'] ?? '';

        if ($sessionId === '') {
            Response::error('Brak session_id', 400);
        }

        $conversation = $this->conversationStore->getBySessionId($sessionId);

        if ($conversation === null) {
            Response::error('Rozmowa nie znaleziona', 404);
        }

        // Wzbogacamy o conversation_cost (USD + PLN po przeliczeniu).
        // CHAT-T-134 (ADR-117): koszt liczony z JUŻ pobranego wiersza
        // (usage_message_count + usd_rate doklejone w SELECT getBySessionId) —
        // bez dodatkowych round-tripów do Railway. Odpowiedź zawiera też
        // `review` (stan recenzji), żeby panel PS nie robił drugiego
        // wywołania HTTP na GET /api/admin/review/:id.
        try {
            $conversation['conversation_cost'] = $this->usageLogger
                ->costFromDetailRow($conversation)
                ->toArray();
        } catch (\Throwable $e) {
            $conversation['conversation_cost'] = null;
        }

        // Pola pomocnicze kosztu — nie wystawiamy w JSON (są już w conversation_cost).
        unset($conversation['usage_message_count'], $conversation['usd_rate']);

        Response::json($conversation);
    }

    public function updateStatus(Request $request): void
    {
        $this->requireAnyRole();

        $sessionId = $request->params['session_id'] ?? '';

        if ($sessionId === '') {
            Response::error('Brak session_id', 400);
        }

        $body = $request->getJsonBody();
        $status = $body['status'] ?? '';
        $notes = $body['notes'] ?? null;

        $allowed = ['new', 'reviewed', 'knowledge_created', 'ignored'];
        if (!in_array($status, $allowed, true)) {
            Response::error('Nieprawidłowy status. Dozwolone: ' . implode(', ', $allowed), 400);
        }

        $updated = $this->conversationStore->updateAdminStatus($sessionId, $status, $notes);

        if (!$updated) {
            Response::error('Rozmowa nie znaleziona', 404);
        }

        Response::json(['success' => true, 'session_id' => $sessionId, 'status' => $status]);
    }

    /**
     * Weryfikacja kanału serwerowego + obecność DOWOLNEJ roli (operator+admin).
     * Wzorzec 1:1 z AdminRecommendationsController — operator MUSI widzieć
     * rozmowy klientów, to jego praca (decyzja 36a). Stricter check 'admin'
     * leży w SettingsController/AdminPricingController (konfiguracja silnika).
     *
     * Response::json kończy `exit`, więc po nieudanej walidacji metoda nie wraca.
     */
    private function requireAnyRole(): void
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
    }
}
