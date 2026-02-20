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
     * Wznawia lub tworzy nową sesję. Zwraca historię wiadomości.
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
            return json_decode($row['messages'], true) ?: [];
        }

        // Nowa sesja
        $this->db->query(
            'INSERT INTO divechat_conversations (session_id, ps_customer_id, messages)
             VALUES (?, ?, ?::jsonb)',
            [$sessionId, $customerId, '[]'],
        );

        return [];
    }

    /**
     * Zapisuje całą historię wiadomości do sesji.
     */
    public function save(string $sessionId, array $messages, array $toolsUsed, array $usage): void
    {
        $this->db->query(
            'UPDATE divechat_conversations
             SET messages = ?::jsonb,
                 tools_used = ?::jsonb,
                 tokens_input = tokens_input + ?,
                 tokens_output = tokens_output + ?,
                 updated_at = NOW()
             WHERE session_id = ? AND closed_at IS NULL',
            [
                json_encode($messages, JSON_UNESCAPED_UNICODE),
                json_encode($toolsUsed, JSON_UNESCAPED_UNICODE),
                $usage['input_tokens'] ?? 0,
                $usage['output_tokens'] ?? 0,
                $sessionId,
            ],
        );
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
}
