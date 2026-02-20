<?php

declare(strict_types=1);

namespace DiveChat\AI;

/**
 * Ujednolicona odpowiedź z AI (niezależna od providera).
 */
final readonly class AIResponse
{
    /**
     * @param string|null $content Tekstowa odpowiedź (null jeśli tylko tool_calls)
     * @param ToolCall[] $toolCalls Lista wywołań narzędzi
     * @param array{input_tokens: int, output_tokens: int} $usage Zużycie tokenów
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
