<?php

declare(strict_types=1);

namespace DiveChat\Enum;

/**
 * Registry modeli AI (ADR-051).
 * Wartości enum muszą pasować do `model_id` w `divechat_model_pricing`.
 */
enum AIModel: string
{
    // Claude
    case CLAUDE_OPUS_47 = 'claude-opus-4-7';
    case CLAUDE_SONNET_46 = 'claude-sonnet-4-6';
    case CLAUDE_HAIKU_45 = 'claude-haiku-4-5';

    // OpenAI
    case GPT_54 = 'gpt-5.4';
    case GPT_41 = 'gpt-4.1';
    case GPT_54_MINI = 'gpt-5.4-mini';
    case O3_MINI = 'o3-mini';
    case GPT_5_MINI = 'gpt-5-mini';

    public function provider(): string
    {
        return match ($this) {
            self::CLAUDE_OPUS_47, self::CLAUDE_SONNET_46, self::CLAUDE_HAIKU_45 => 'claude',
            default => 'openai',
        };
    }

    public function tier(): string
    {
        return match ($this) {
            self::CLAUDE_OPUS_47, self::GPT_54 => 'escalation',
            default => 'primary',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CLAUDE_OPUS_47 => 'Claude Opus 4.7',
            self::CLAUDE_SONNET_46 => 'Claude Sonnet 4.6',
            self::CLAUDE_HAIKU_45 => 'Claude Haiku 4.5',
            self::GPT_54 => 'GPT-5.4',
            self::GPT_41 => 'GPT-4.1',
            self::GPT_54_MINI => 'GPT-5.4 Mini',
            self::O3_MINI => 'o3-mini',
            self::GPT_5_MINI => 'GPT-5 Mini',
        };
    }

    public function supportsTemperature(): bool
    {
        return $this === self::GPT_41;
    }

    public function supportsReasoningEffort(): bool
    {
        return $this !== self::GPT_41;
    }

    /**
     * Alias backward-compat dla kodu który jeszcze używa supportsEffort().
     */
    public function supportsEffort(): bool
    {
        return $this->supportsReasoningEffort();
    }

    public function effortParamName(): ?string
    {
        if (!$this->supportsReasoningEffort()) {
            return null;
        }
        return match ($this->provider()) {
            'openai' => 'reasoning_effort',
            'claude' => 'thinking',
            default => null,
        };
    }

    /**
     * Mapuje effort z UI (minimal/low/medium/high) na wartość przekazywaną do API providera.
     * - openai → ten sam string
     * - claude → int budget_tokens (1024/4096/8192/16384)
     * - GPT-4.1 → null (model nie wspiera reasoning_effort)
     */
    public function mapEffortToProviderValue(string $effort): mixed
    {
        if (!$this->supportsReasoningEffort()) {
            return null;
        }

        return match ($this->provider()) {
            'openai' => match ($effort) {
                'minimal', 'low', 'medium', 'high' => $effort,
                default => 'medium',
            },
            'claude' => match ($effort) {
                'minimal' => 1024,
                'low' => 4096,
                'medium' => 8192,
                'high' => 16384,
                default => 8192,
            },
            default => null,
        };
    }

    /**
     * Wszystkie modele pogrupowane per provider/tier z metadanymi.
     * Ceny dorzucane przez SettingsController z PricingService (joinowanie poza enumem,
     * żeby enum nie zależał od bazy).
     */
    public static function grouped(): array
    {
        $result = [];
        foreach (self::cases() as $model) {
            $result[$model->provider()][$model->tier()][] = [
                'value' => $model->value,
                'label' => $model->label(),
                'supports_temperature' => $model->supportsTemperature(),
                'supports_reasoning_effort' => $model->supportsReasoningEffort(),
                'effort_param' => $model->effortParamName(),
            ];
        }
        return $result;
    }
}
