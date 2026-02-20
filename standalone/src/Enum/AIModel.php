<?php

declare(strict_types=1);

namespace DiveChat\Enum;

/**
 * Registry modeli AI z metadanymi.
 * Jedno źródło prawdy o modelach, tierach i obsłudze effort.
 */
enum AIModel: string
{
    // OpenAI primary
    case GPT_5_MINI = 'gpt-5-mini';
    case GPT_41 = 'gpt-4.1';

    // OpenAI escalation
    case GPT_52 = 'gpt-5.2';

    // Claude primary
    case CLAUDE_SONNET_46 = 'claude-sonnet-4-6';
    case CLAUDE_HAIKU_45 = 'claude-haiku-4-5';

    // Claude escalation
    case CLAUDE_OPUS_46 = 'claude-opus-4-6';

    public function provider(): string
    {
        return match ($this) {
            self::CLAUDE_SONNET_46, self::CLAUDE_HAIKU_45, self::CLAUDE_OPUS_46 => 'claude',
            default => 'openai',
        };
    }

    public function tier(): string
    {
        return match ($this) {
            self::GPT_52, self::CLAUDE_OPUS_46 => 'escalation',
            default => 'primary',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GPT_5_MINI => 'GPT-5 Mini',
            self::GPT_41 => 'GPT-4.1',
            self::GPT_52 => 'GPT-5.2',
            self::CLAUDE_SONNET_46 => 'Claude Sonnet 4.6',
            self::CLAUDE_HAIKU_45 => 'Claude Haiku 4.5',
            self::CLAUDE_OPUS_46 => 'Claude Opus 4.6',
        };
    }

    public function supportsTemperature(): bool
    {
        return match ($this) {
            self::GPT_41 => true,
            default => false, // reasoning models (gpt-5-mini, gpt-5.2, claude) nie obsługują temperature
        };
    }

    public function supportsEffort(): bool
    {
        return $this->tier() === 'escalation';
    }

    public function effortParamName(): ?string
    {
        if (!$this->supportsEffort()) {
            return null;
        }
        return match ($this->provider()) {
            'openai' => 'reasoning_effort',
            'claude' => 'extended_thinking',
            default => null,
        };
    }

    /**
     * Zwraca wszystkie modele pogrupowane per provider + tier.
     * Do użycia w /api/settings.
     */
    public static function grouped(): array
    {
        $result = [];
        foreach (self::cases() as $model) {
            $result[$model->provider()][$model->tier()][] = [
                'value' => $model->value,
                'label' => $model->label(),
                'supports_effort' => $model->supportsEffort(),
                'effort_param' => $model->effortParamName(),
            ];
        }
        return $result;
    }
}
