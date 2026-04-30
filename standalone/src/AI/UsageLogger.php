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

    public function logMessage(
        int $conversationId,
        ?int $messageId,
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheCreationTokens = 0,
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
                cost_input_usd, cost_output_usd, cost_cache_usd, cost_total_usd
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
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
}
