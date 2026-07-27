<?php

declare(strict_types=1);

namespace DiveChat\AI;

/**
 * Sumaryczny koszt rozmowy (wszystkie wywołania providera w danej sesji).
 */
final readonly class ConversationCost
{
    public function __construct(
        public int $conversationId,
        public float $totalCostUsd,
        public float $totalCostPln,
        public int $totalInputTokens,
        public int $totalOutputTokens,
        public int $totalCacheReadTokens,
        public int $totalCacheCreationTokens,
        public int $messageCount,
    ) {}

    public function toArray(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'total_usd' => round($this->totalCostUsd, 6),
            'total_pln' => round($this->totalCostPln, 4),
            'input_tokens' => $this->totalInputTokens,
            'output_tokens' => $this->totalOutputTokens,
            'cache_read_tokens' => $this->totalCacheReadTokens,
            'cache_creation_tokens' => $this->totalCacheCreationTokens,
            'message_count' => $this->messageCount,
        ];
    }
}
