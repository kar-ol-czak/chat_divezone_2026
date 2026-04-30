<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\AI\UsageLogger;
use DiveChat\Chat\ConversationStore;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Admin API do przeglądania rozmów.
 *
 * GET /api/conversations — lista z paginacją i filtrami.
 * GET /api/conversations/{session_id} — szczegóły + conversation_cost.
 * POST /api/conversations/{session_id}/status — zmiana admin_status.
 */
final class ConversationsController
{
    public function __construct(
        private readonly ConversationStore $conversationStore,
        private readonly UsageLogger $usageLogger,
    ) {}

    public function list(Request $request): void
    {
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
        $sessionId = $request->params['session_id'] ?? '';

        if ($sessionId === '') {
            Response::error('Brak session_id', 400);
        }

        $conversation = $this->conversationStore->getBySessionId($sessionId);

        if ($conversation === null) {
            Response::error('Rozmowa nie znaleziona', 404);
        }

        // Wzbogacamy o conversation_cost (USD + PLN po przeliczeniu).
        try {
            $conversation['conversation_cost'] = $this->usageLogger
                ->getConversationCost((int) $conversation['id'])
                ->toArray();
        } catch (\Throwable $e) {
            $conversation['conversation_cost'] = null;
        }

        Response::json($conversation);
    }

    public function updateStatus(Request $request): void
    {
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
}
