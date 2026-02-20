<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Config;
use DiveChat\Enum\AIModel;

/**
 * Fabryka providerów AI.
 * Wybiera provider na podstawie modelu w konfiguracji.
 */
final class AIProviderFactory
{
    public static function create(): AIProviderInterface
    {
        $modelId = Config::get('ANTHROPIC_MODEL', Config::get('OPENAI_CHAT_MODEL', 'claude-sonnet-4-20250514'));

        // Rozpoznaj provider po nazwie modelu
        $provider = str_starts_with($modelId, 'claude') ? 'claude' : 'openai';

        // Opcjonalny override z .env
        $provider = Config::get('AI_PROVIDER', $provider);

        return match ($provider) {
            'claude' => new ClaudeProvider(),
            'openai' => new OpenAIProvider(),
            default => throw new \InvalidArgumentException("Nieznany provider: {$provider}"),
        };
    }
}
