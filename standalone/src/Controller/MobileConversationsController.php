<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\AI\UsageLogger;
use DiveChat\Chat\ConversationStore;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Mobilny widok rozmow (CHAT-T-071, ADR-086).
 *
 * Bramka auth: cookie dz_madmin -> MobileSessionStore (przez MobileAuthController).
 * Reuse logiki: ConversationStore (list/getBySessionId/updateAdminStatus) +
 * UsageLogger (conversation_cost) — 1:1 z desktopowym ConversationsController.
 * Rozdzielenie kanalow auth: ZERO modyfikacji ConversationsController,
 * ZERO duplikacji SQL.
 *
 * Endpointy:
 *  GET  /m/api/conversations
 *  GET  /m/api/conversations/{session_id}
 *  POST /m/api/conversations/{session_id}/status
 *
 * Filtr "wymagajace uwagi" ustala FRONT (knowledge_gap=true lub
 * admin_status=new) — backend zostaje generyczny (ADR-086 214c).
 */
final class MobileConversationsController
{
    public function __construct(
        private readonly MobileAuthController $auth,
        private readonly ConversationStore $conversationStore,
        private readonly UsageLogger $usageLogger,
    ) {}

    public function list(Request $request): void
    {
        $this->auth->validateOrFail();

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
        $this->auth->validateOrFail();

        $sessionId = $request->params['session_id'] ?? '';
        if ($sessionId === '') {
            Response::error('Brak session_id', 400);
        }

        $conversation = $this->conversationStore->getBySessionId($sessionId);

        if ($conversation === null) {
            Response::error('Rozmowa nie znaleziona', 404);
        }

        // CHAT-T-134 (ADR-117): koszt z już pobranego wiersza — spójnie
        // z ConversationsController::detail, bez dodatkowych zapytań.
        try {
            $conversation['conversation_cost'] = $this->usageLogger
                ->costFromDetailRow($conversation)
                ->toArray();
        } catch (\Throwable) {
            $conversation['conversation_cost'] = null;
        }

        unset($conversation['usage_message_count'], $conversation['usd_rate']);

        Response::json($conversation);
    }

    public function updateStatus(Request $request): void
    {
        $this->auth->validateOrFail();

        $sessionId = $request->params['session_id'] ?? '';
        if ($sessionId === '') {
            Response::error('Brak session_id', 400);
        }

        $body = $request->getJsonBody();
        $status = $body['status'] ?? '';
        $notes = $body['notes'] ?? null;

        $allowed = ['new', 'reviewed', 'knowledge_created', 'ignored'];
        if (!in_array($status, $allowed, true)) {
            Response::error('Nieprawidlowy status. Dozwolone: ' . implode(', ', $allowed), 400);
        }

        $updated = $this->conversationStore->updateAdminStatus($sessionId, $status, $notes);
        if (!$updated) {
            Response::error('Rozmowa nie znaleziona', 404);
        }

        Response::json(['success' => true, 'session_id' => $sessionId, 'status' => $status]);
    }
}
