<?php

declare(strict_types=1);

namespace DiveChat\Chat;

use DiveChat\Database\PostgresConnection;

/**
 * Zapis i odczyt rozmów z divechat_conversations (PostgreSQL).
 */
final class ConversationStore
{
    private readonly PostgresConnection $db;

    public function __construct()
    {
        $this->db = PostgresConnection::getInstance();
    }

    /**
     * Wznawia lub tworzy nową sesję.
     *
     * @return array{id: int, history: array} `id` = klucz PK z divechat_conversations,
     *   potrzebny dla UsageLogger (FK divechat_message_usage.conversation_id).
     */
    public function startOrResume(string $sessionId, ?int $customerId): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, messages FROM divechat_conversations
             WHERE session_id = ? AND closed_at IS NULL
             ORDER BY started_at DESC LIMIT 1',
            [$sessionId],
        );

        if ($row) {
            return [
                'id' => (int) $row['id'],
                'history' => json_decode($row['messages'], true) ?: [],
            ];
        }

        // Nowa sesja – RETURNING id w jednym roundtripie.
        $newRow = $this->db->fetchOne(
            'INSERT INTO divechat_conversations (session_id, ps_customer_id, messages)
             VALUES (?, ?, ?::jsonb)
             RETURNING id',
            [$sessionId, $customerId, '[]'],
        );

        return [
            'id' => (int) ($newRow['id'] ?? 0),
            'history' => [],
        ];
    }

    /**
     * Zapisuje historię wiadomości + diagnostykę.
     * Tokeny i koszt aktualizuje UsageLogger – tu tylko payload tekstowy + meta.
     */
    public function save(
        string $sessionId,
        array $messages,
        array $toolsUsed,
        string $modelUsed = '',
        array $responseTimes = [],
        array $searchDiagnostics = [],
        bool $knowledgeGap = false,
    ): void {
        $this->db->query(
            'UPDATE divechat_conversations
             SET messages = ?::jsonb,
                 tools_used = ?::jsonb,
                 model_used = COALESCE(?, model_used),
                 response_times = ?::jsonb,
                 search_diagnostics = ?::jsonb,
                 knowledge_gap = (? ::boolean OR COALESCE(knowledge_gap, false)),
                 updated_at = NOW()
             WHERE session_id = ? AND closed_at IS NULL',
            [
                json_encode($messages, JSON_UNESCAPED_UNICODE),
                json_encode(array_values($toolsUsed), JSON_UNESCAPED_UNICODE),
                $modelUsed ?: null,
                json_encode($responseTimes, JSON_UNESCAPED_UNICODE),
                json_encode($searchDiagnostics, JSON_UNESCAPED_UNICODE),
                $knowledgeGap ? 'true' : 'false',
                $sessionId,
            ],
        );
    }

    /**
     * Lista rozmów z paginacją i filtrami (dla admin API).
     */
    public function list(int $page, int $perPage, ?string $search, ?bool $knowledgeGap, ?string $adminStatus): array
    {
        $conditions = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $params[] = '%' . $search . '%';
            $conditions[] = 'messages::text ILIKE ?';
        }

        if ($knowledgeGap !== null) {
            $params[] = $knowledgeGap;
            $conditions[] = 'knowledge_gap = ?';
        }

        if ($adminStatus !== null && $adminStatus !== '') {
            $params[] = $adminStatus;
            $conditions[] = 'admin_status = ?';
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Policz total
        $countRow = $this->db->fetchOne(
            "SELECT COUNT(*) as total FROM divechat_conversations {$where}",
            $params,
        );
        $total = (int) ($countRow['total'] ?? 0);

        // Pobierz stronę
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;

        $rows = $this->db->fetchAll(
            "SELECT id, session_id, ps_customer_id, model_used, tools_used,
                    tokens_input, tokens_output,
                    cache_read_tokens, cache_creation_tokens,
                    estimated_cost,
                    knowledge_gap, admin_status,
                    jsonb_array_length(COALESCE(messages, '[]'::jsonb)) as message_count,
                    started_at, updated_at
             FROM divechat_conversations
             {$where}
             ORDER BY updated_at DESC
             LIMIT ? OFFSET ?",
            $params,
        );

        return [
            'conversations' => array_map(fn(array $row) => [
                'id' => (int) $row['id'],
                'session_id' => $row['session_id'],
                'customer_id' => (int) ($row['ps_customer_id'] ?? 0),
                'message_count' => (int) $row['message_count'],
                'model_used' => $row['model_used'],
                'tools_used' => array_values(json_decode($row['tools_used'] ?? '[]', true) ?: []),
                'tokens_input' => (int) $row['tokens_input'],
                'tokens_output' => (int) $row['tokens_output'],
                'cache_read_tokens' => (int) ($row['cache_read_tokens'] ?? 0),
                'cache_creation_tokens' => (int) ($row['cache_creation_tokens'] ?? 0),
                'estimated_cost' => (float) ($row['estimated_cost'] ?? 0),
                'knowledge_gap' => (bool) $row['knowledge_gap'],
                'admin_status' => $row['admin_status'] ?? 'new',
                'started_at' => $row['started_at'],
                'updated_at' => $row['updated_at'],
            ], $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Szczegóły jednej rozmowy.
     */
    public function getBySessionId(string $sessionId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM divechat_conversations WHERE session_id = ? ORDER BY started_at DESC LIMIT 1',
            [$sessionId],
        );

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'session_id' => $row['session_id'],
            'customer_id' => (int) ($row['ps_customer_id'] ?? 0),
            'messages' => json_decode($row['messages'] ?? '[]', true),
            'tools_used' => array_values(json_decode($row['tools_used'] ?? '[]', true) ?: []),
            'tokens_input' => (int) $row['tokens_input'],
            'tokens_output' => (int) $row['tokens_output'],
            'cache_read_tokens' => (int) ($row['cache_read_tokens'] ?? 0),
            'cache_creation_tokens' => (int) ($row['cache_creation_tokens'] ?? 0),
            'estimated_cost' => (float) ($row['estimated_cost'] ?? 0),
            'model_used' => $row['model_used'],
            'response_times' => json_decode($row['response_times'] ?? '{}', true),
            'search_diagnostics' => json_decode($row['search_diagnostics'] ?? '[]', true),
            'knowledge_gap' => (bool) $row['knowledge_gap'],
            'admin_status' => $row['admin_status'] ?? 'new',
            'admin_notes' => $row['admin_notes'],
            'started_at' => $row['started_at'],
            'updated_at' => $row['updated_at'],
            'closed_at' => $row['closed_at'],
        ];
    }

    /**
     * Aktualizuje admin_status i admin_notes.
     */
    public function updateAdminStatus(string $sessionId, string $status, ?string $notes): bool
    {
        $stmt = $this->db->query(
            'UPDATE divechat_conversations
             SET admin_status = ?, admin_notes = ?, updated_at = NOW()
             WHERE session_id = ?',
            [$status, $notes, $sessionId],
        );

        return $stmt->rowCount() > 0;
    }

    /**
     * Pobiera historię wiadomości z sesji.
     */
    public function getHistory(string $sessionId): array
    {
        $row = $this->db->fetchOne(
            'SELECT messages FROM divechat_conversations
             WHERE session_id = ? AND closed_at IS NULL
             ORDER BY started_at DESC LIMIT 1',
            [$sessionId],
        );

        return $row ? (json_decode($row['messages'], true) ?: []) : [];
    }

    /**
     * Zamyka sesję.
     */
    public function close(string $sessionId): void
    {
        $this->db->query(
            'UPDATE divechat_conversations SET closed_at = NOW() WHERE session_id = ?',
            [$sessionId],
        );
    }

    /**
     * Append wiadomości do `divechat_messages` (dual write – nadal jest też
     * historia w `divechat_conversations.messages` JSONB jako legacy).
     * Zwraca message_id potrzebny dla `UsageLogger::logMessage` (FK).
     *
     * @param array<int, array<string, mixed>>|null $toolCalls Lista wywołanych
     *   narzędzi (znormalizowana: `[{name, args}]`) – dla role='assistant' z
     *   tool_use; dla user/tool/system zwykle null.
     */
    public function appendMessage(
        int $conversationId,
        string $role,
        string $content,
        ?array $toolCalls = null,
    ): int {
        $row = $this->db->fetchOne(
            'INSERT INTO divechat_messages (conversation_id, role, content, tool_calls)
             VALUES (?, ?, ?, ?::jsonb)
             RETURNING id',
            [
                $conversationId,
                $role,
                $content,
                $toolCalls === null ? null : json_encode($toolCalls, JSON_UNESCAPED_UNICODE),
            ],
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * Lista wiadomości per rozmowa (dla admin dashboardu / modala podglądu).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(int $conversationId): array
    {
        return $this->db->fetchAll(
            'SELECT id, role, content, tool_calls, rating, rating_at, created_at
             FROM divechat_messages
             WHERE conversation_id = ?
             ORDER BY created_at, id',
            [$conversationId],
        );
    }
}
