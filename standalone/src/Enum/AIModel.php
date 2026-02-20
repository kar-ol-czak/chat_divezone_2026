<?php

declare(strict_types=1);

namespace DiveChat\Enum;

enum AIModel: string
{
    case CLAUDE_SONNET_4 = 'claude-sonnet-4-20250514';
    case CLAUDE_SONNET_45 = 'claude-sonnet-4-5-20250929';
    case GPT_41 = 'gpt-4.1';
    case GPT_4O = 'gpt-4o';

    public function provider(): string
    {
        return match ($this) {
            self::CLAUDE_SONNET_4, self::CLAUDE_SONNET_45 => 'claude',
            self::GPT_41, self::GPT_4O => 'openai',
        };
    }
}
