<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Admin\ConversationReviewRepository;
use DiveChat\Admin\InvalidReviewValueException;
use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Database\PostgresConnection;
use DiveChat\Enum\ReviewStatus;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * System recenzji rozmow (CHAT-T-104, ADR-102). Endpointy pod /api/admin/review.
 *
 * GET  /api/admin/review?status=&limit=&offset=  — lista rozmow z recenzja o danym
 *      statusie (default do_weryfikacji), sort malejaco po updated_at, paginacja.
 * GET  /api/admin/review/{conversationId}        — pelny wiersz recenzji albo null.
 * POST /api/admin/review/{conversationId}        — upsert (status?/verdict?/note?,
 *      id_employee wymagany). Tworzy lub aktualizuje TYLKO podane pola. 422 dla
 *      nieznanych wartosci enumow.
 *
 * Auth: kanal serwerowy panelu PS (ServerHmacVerifier, sekret DIVECHAT_SERVER_SECRET)
 * + DOWOLNA rola (operator+admin) — recenzja jest delegowalna do pracownika
 * (ADR-102). Wzorzec 1:1 z ConversationsController::requireAnyRole, NIE admin-only.
 *
 * Tozsamosc recenzenta (updated_by) = id_employee z payloadu (P17a/D2 — zaufany,
 * PS dostarcza z sesji; kanal juz uwierzytelniony).
 */
final class AdminConversationReviewController
{
    public function __construct(
        private readonly ConversationReviewRepository $repo,
        private readonly ServerHmacVerifier $serverVerifier,
        private readonly PostgresConnection $pg,
    ) {}

    public function list(Request $request): void
    {
        $this->requireAnyRole();

        $status = $request->getQueryParam('status') ?: ReviewStatus::DEFAULT->value;
        $limit = $request->getQueryInt('limit', 50);
        $offset = $request->getQueryInt('offset', 0);

        try {
            $result = $this->repo->listByStatus($status, $limit, $offset);
            // CHAT-T-106: liczniki per status ZAWSZE (niezaleznie od filtra) —
            // dane dla segmentowanego przelacznika CHAT-T-107.
            $result['counts'] = $this->repo->countsByStatus();
        } catch (InvalidReviewValueException $e) {
            Response::json(['error' => 'Unprocessable Entity', 'reason' => $e->getMessage()], 422);
            return;
        }

        Response::json($result);
    }

    public function get(Request $request): void
    {
        $this->requireAnyRole();

        $conversationId = $this->parseConversationId($request);
        $review = $this->repo->getByConversation($conversationId);

        // Brak wiersza = stan "nowy" implicytny (D3). Zwracamy review=null
        // (NIE 404 — rozmowa moze istniec bez recenzji).
        Response::json(['conversation_id' => $conversationId, 'review' => $review]);
    }

    public function upsert(Request $request): void
    {
        $this->requireAnyRole();

        $conversationId = $this->parseConversationId($request);
        $body = $request->getJsonBody();

        // id_employee wymagany (kontrakt pkt 3), zaufany (D2).
        $idEmployee = filter_var($body['id_employee'] ?? null, FILTER_VALIDATE_INT);
        if ($idEmployee === false || $idEmployee <= 0) {
            Response::json(['error' => 'Bad Request', 'reason' => 'id_employee wymagany (dodatni int)'], 400);
            return;
        }

        // Przekazujemy do repo TYLKO pola realnie obecne w body (upsert aktualizuje
        // wylacznie podane). array_key_exists, nie ??, by odroznic brak od null.
        $fields = [];
        foreach (['status', 'verdict', 'note'] as $key) {
            if (array_key_exists($key, $body)) {
                $fields[$key] = $body[$key];
            }
        }

        try {
            $review = $this->repo->upsert($conversationId, $fields, $idEmployee);
        } catch (InvalidReviewValueException $e) {
            Response::json(['error' => 'Unprocessable Entity', 'reason' => $e->getMessage()], 422);
            return;
        }

        Response::json(['conversation_id' => $conversationId, 'review' => $review]);
    }

    /** Waliduje {conversationId} z path. Konczy 400 gdy nie-int. */
    private function parseConversationId(Request $request): int
    {
        $raw = $request->params['conversationId'] ?? '';
        $id = filter_var($raw, FILTER_VALIDATE_INT);
        if ($id === false || $id <= 0) {
            Response::json(['error' => 'Bad Request', 'reason' => 'conversationId musi byc dodatnim int'], 400);
        }
        return (int) $id;
    }

    /**
     * Weryfikacja kanalu serwerowego + obecnosc DOWOLNEJ roli (operator+admin).
     * Wzorzec 1:1 z ConversationsController::requireAnyRole (CHAT-T-046, ADR-068).
     * Response::json konczy exit, wiec po nieudanej walidacji metoda nie wraca.
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
