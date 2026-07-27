<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Database\PostgresConnection;

/**
 * Logger zużycia tokenów per wywołanie providera.
 *
 * Jeden zapis = jedno wywołanie `$provider->chat(...)`. W tool loopie ChatService
 * loguje 1-N razy per turn użytkownika. Agregaty na poziomie rozmowy są atomic
 * przez `UPDATE ... SET col = col + :delta`.
 */
final class UsageLogger
{
    public function __construct(
        private readonly PostgresConnection $db,
        private readonly PricingService $pricing,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * @param array<int, array<string, mixed>>|null $toolCalls Lista narzędzi
     *   wywołanych przez LLM w tej odpowiedzi (znormalizowana: `[{name, args}]`)
     *   lub null gdy brak.
     */
    public function logMessage(
        int $conversationId,
        ?int $messageId,
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheCreationTokens = 0,
        ?int $latencyMs = null,
        ?array $toolCalls = null,
    ): CostBreakdown {
        $cost = $this->pricing->calculateCost(
            $modelId,
            $inputTokens,
            $outputTokens,
            $cacheReadTokens,
            $cacheCreationTokens,
        );

        $this->db->query(
            'INSERT INTO divechat_message_usage (
                conversation_id, message_id, model_id,
                input_tokens, output_tokens, cache_read_tokens, cache_creation_tokens,
                cost_input_usd, cost_output_usd, cost_cache_usd, cost_total_usd,
                latency_ms, tool_calls
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)',
            [
                $conversationId,
                $messageId,
                $modelId,
                $inputTokens,
                $outputTokens,
                $cacheReadTokens,
                $cacheCreationTokens,
                $cost->costInputUsd,
                $cost->costOutputUsd,
                $cost->costCacheUsd,
                $cost->costTotalUsd,
                $latencyMs,
                $toolCalls === null ? null : json_encode($toolCalls, JSON_UNESCAPED_UNICODE),
            ],
        );

        // Atomic update agregatu na rozmowie. Stare kolumny tokens_input/tokens_output/estimated_cost
        // pozostają zgodne z poprzednim kontraktem (decyzja architekta 052a, pytanie 2).
        $this->db->query(
            'UPDATE divechat_conversations
             SET tokens_input = tokens_input + ?,
                 tokens_output = tokens_output + ?,
                 cache_read_tokens = cache_read_tokens + ?,
                 cache_creation_tokens = cache_creation_tokens + ?,
                 estimated_cost = estimated_cost + ?,
                 updated_at = NOW()
             WHERE id = ?',
            [
                $inputTokens,
                $outputTokens,
                $cacheReadTokens,
                $cacheCreationTokens,
                $cost->costTotalUsd,
                $conversationId,
            ],
        );

        return $cost;
    }

    public function getConversationCost(int $conversationId): ConversationCost
    {
        $row = $this->db->fetchOne(
            'SELECT
                tokens_input, tokens_output,
                cache_read_tokens, cache_creation_tokens,
                estimated_cost
             FROM divechat_conversations
             WHERE id = ?',
            [$conversationId],
        );

        $messageCount = (int) ($this->db->fetchOne(
            'SELECT COUNT(*) AS cnt FROM divechat_message_usage WHERE conversation_id = ?',
            [$conversationId],
        )['cnt'] ?? 0);

        $usd = (float) ($row['estimated_cost'] ?? 0);
        $rate = $this->exchangeRates->getUsdToPln();
        $pln = $usd * $rate;

        return new ConversationCost(
            conversationId: $conversationId,
            totalCostUsd: $usd,
            totalCostPln: $pln,
            totalInputTokens: (int) ($row['tokens_input'] ?? 0),
            totalOutputTokens: (int) ($row['tokens_output'] ?? 0),
            totalCacheReadTokens: (int) ($row['cache_read_tokens'] ?? 0),
            totalCacheCreationTokens: (int) ($row['cache_creation_tokens'] ?? 0),
            messageCount: $messageCount,
        );
    }

    /**
     * CHAT-T-134 (ADR-117): koszt rozmowy z JUŻ pobranego wiersza detalu —
     * ConversationStore::getBySessionId dokleja usage_message_count i usd_rate
     * w tym samym round-tripie, więc tu ZERO dodatkowych zapytań do Railway
     * (getConversationCost robił 2-3 SELECTy × RTT ~115 ms na to samo).
     * getConversationCost() zostaje dla ścieżek bez pełnego wiersza (ChatService).
     * Fallback na getUsdToPln() tylko gdy w bazie brak jakiegokolwiek kursu.
     */
    public function costFromDetailRow(array $conversation): ConversationCost
    {
        $usd = (float) ($conversation['estimated_cost'] ?? 0);
        $rate = $conversation['usd_rate'] ?? null;
        $rate = $rate !== null ? (float) $rate : $this->exchangeRates->getUsdToPln();

        return new ConversationCost(
            conversationId: (int) ($conversation['id'] ?? 0),
            totalCostUsd: $usd,
            totalCostPln: $usd * $rate,
            totalInputTokens: (int) ($conversation['tokens_input'] ?? 0),
            totalOutputTokens: (int) ($conversation['tokens_output'] ?? 0),
            totalCacheReadTokens: (int) ($conversation['cache_read_tokens'] ?? 0),
            totalCacheCreationTokens: (int) ($conversation['cache_creation_tokens'] ?? 0),
            messageCount: (int) ($conversation['usage_message_count'] ?? 0),
        );
    }
}
