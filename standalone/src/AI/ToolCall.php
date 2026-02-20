<?php

declare(strict_types=1);

namespace DiveChat\AI;

/**
 * Value object reprezentujący wywołanie narzędzia przez AI.
 */
final readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
