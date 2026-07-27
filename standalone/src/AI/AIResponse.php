<?php

declare(strict_types=1);

namespace DiveChat\AI;

/**
 * Ujednolicona odpowiedź z AI (niezależna od providera).
 *
 * Pole `usage` zawiera klucze:
 * - input_tokens (int)
 * - output_tokens (int)
 * - cache_read_tokens (int, default 0) – tylko Claude (cache_read_input_tokens)
 * - cache_creation_tokens (int, default 0) – tylko Claude (cache_creation_input_tokens)
 *
 * OpenAI zawsze zwraca 0 dla cache (ich auto-cache nie eksponuje tokenów per-call).
 */
final readonly class AIResponse
{
    /**
     * @param ToolCall[] $toolCalls
     * @param array{input_tokens: int, output_tokens: int, cache_read_tokens?: int, cache_creation_tokens?: int} $usage
     */
    public function __construct(
        public ?string $content,
        public array $toolCalls,
        public array $usage,
    ) {}

    public function hasToolCalls(): bool
    {
        return count($this->toolCalls) > 0;
    }
}
