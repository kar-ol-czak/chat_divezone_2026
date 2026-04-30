<?php

declare(strict_types=1);

namespace DiveChat\Admin;

use DiveChat\AI\ExchangeRateService;
use DiveChat\Database\PostgresConnection;

/**
 * Pełna rozmowa do modala dashboardu (TASK-055 endpoint /api/admin/conversations/:id).
 * Łączy `divechat_messages` + odpowiadające im wpisy `divechat_message_usage`.
 */
final class ConversationViewer
{
    public function __construct(
        private readonly PostgresConnection $db,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @return array<string, mixed>|null Null gdy rozmowa nie istnieje.
     */
    public function get(int $conversationId): ?array
    {
        $conv = $this->db->fetchOne(
            'SELECT id, session_id, ps_customer_id, model_used,
                    tokens_input, tokens_output, cache_read_tokens, cache_creation_tokens,
                    estimated_cost, started_at, updated_at, closed_at
             FROM divechat_conversations
             WHERE id = ?',
            [$conversationId],
        );

        if ($conv === null) {
            return null;
        }

        $messages = $this->db->fetchAll(
            'SELECT id, role, content, tool_calls, rating, rating_at, created_at
             FROM divechat_messages
             WHERE conversation_id = ?
             ORDER BY created_at, id',
            [$conversationId],
        );

        // Mapa message_id -> usage row (dla assistant messages mamy FK)
        $usageRows = $this->db->fetchAll(
            'SELECT message_id, model_id, input_tokens, output_tokens,
                    cache_read_tokens, cache_creation_tokens,
                    cost_total_usd, latency_ms, tool_calls, created_at
             FROM divechat_message_usage
             WHERE conversation_id = ?
             ORDER BY created_at, id',
            [$conversationId],
        );

        $usageByMessageId = [];
        $orphanUsage = []; // wpisy bez message_id (legacy / błąd)
        foreach ($usageRows as $u) {
            $mid = $u['message_id'];
            if ($mid !== null) {
                $usageByMessageId[(int) $mid] = $u;
            } else {
                $orphanUsage[] = $u;
            }
        }

        $rate = $this->exchangeRates->getUsdToPln();

        $messagesOut = array_map(function (array $m) use ($usageByMessageId, $rate): array {
            $row = [
                'id' => (int) $m['id'],
                'role' => $m['role'],
                'content' => $m['content'],
                'tool_calls' => $m['tool_calls'] ? json_decode($m['tool_calls'], true) : null,
                'rating' => $m['rating'] !== null ? (int) $m['rating'] : null,
                'rating_at' => $m['rating_at'],
                'created_at' => $m['created_at'],
            ];

            $usage = $usageByMessageId[(int) $m['id']] ?? null;
            if ($usage !== null) {
                $usd = (float) $usage['cost_total_usd'];
                $row['usage'] = [
                    'model_id' => $usage['model_id'],
                    'input_tokens' => (int) $usage['input_tokens'],
                    'output_tokens' => (int) $usage['output_tokens'],
                    'cache_read_tokens' => (int) $usage['cache_read_tokens'],
                    'cache_creation_tokens' => (int) $usage['cache_creation_tokens'],
                    'cost_usd' => round($usd, 6),
                    'cost_pln' => round($usd * $rate, 4),
                    'latency_ms' => $usage['latency_ms'] !== null ? (int) $usage['latency_ms'] : null,
                ];
            }

            return $row;
        }, $messages);

        return [
            'id' => (int) $conv['id'],
            'session_id' => $conv['session_id'],
            'customer_id' => (int) ($conv['ps_customer_id'] ?? 0),
            'model_used' => $conv['model_used'],
            'started_at' => $conv['started_at'],
            'updated_at' => $conv['updated_at'],
            'closed_at' => $conv['closed_at'],
            'totals' => [
                'input_tokens' => (int) $conv['tokens_input'],
                'output_tokens' => (int) $conv['tokens_output'],
                'cache_read_tokens' => (int) $conv['cache_read_tokens'],
                'cache_creation_tokens' => (int) $conv['cache_creation_tokens'],
                'cost_usd' => round((float) $conv['estimated_cost'], 6),
                'cost_pln' => round((float) $conv['estimated_cost'] * $rate, 4),
            ],
            'messages' => $messagesOut,
            'orphan_usage_count' => count($orphanUsage),
        ];
    }
}
