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
    case CLAUDE_SONNET_5 = 'claude-sonnet-5';
    case CLAUDE_SONNET_46 = 'claude-sonnet-4-6';
    case CLAUDE_HAIKU_45 = 'claude-haiku-4-5';

    // OpenAI
    case GPT_55 = 'gpt-5.5';
    case GPT_54 = 'gpt-5.4';
    case GPT_41 = 'gpt-4.1';
    case GPT_54_MINI = 'gpt-5.4-mini';
    case O3_MINI = 'o3-mini';
    case GPT_5_MINI = 'gpt-5-mini';

    public function provider(): string
    {
        return match ($this) {
            self::CLAUDE_OPUS_47,
            self::CLAUDE_SONNET_5,
            self::CLAUDE_SONNET_46,
            self::CLAUDE_HAIKU_45 => 'claude',
            default => 'openai',
        };
    }

    public function tier(): string
    {
        // gpt-5.5 zostaje primary zgodnie z migracją 015 (is_escalation=false);
        // produkcyjne użycie czatu na gpt-5.5 to osobna decyzja, na razie wpis gotowy.
        return match ($this) {
            self::CLAUDE_OPUS_47, self::GPT_54 => 'escalation',
            default => 'primary',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CLAUDE_OPUS_47 => 'Claude Opus 4.7',
            self::CLAUDE_SONNET_5 => 'Claude Sonnet 5',
            self::CLAUDE_SONNET_46 => 'Claude Sonnet 4.6',
            self::CLAUDE_HAIKU_45 => 'Claude Haiku 4.5',
            self::GPT_55 => 'GPT-5.5',
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
     * CHAT-T-177 (ADR-139): model sterowany adaptive thinking zamiast budget_tokens.
     *
     * Na tych modelach `thinking: {type: enabled, budget_tokens: N}` jest ODRZUCANE
     * błędem HTTP 400 — sterujemy `thinking: {type: adaptive}` + `output_config.effort`.
     * Starsze modele (Sonnet 4.6, Haiku 4.5, Opus 4.7) zostają na budget_tokens.
     */
    public function supportsAdaptiveThinking(): bool
    {
        return $this === self::CLAUDE_SONNET_5;
    }

    /**
     * CHAT-T-177 (ADR-139): model odrzuca niedomyślne temperature/top_p/top_k
     * błędem HTTP 400 na KAŻDYM żądaniu. Bezpiecznik niezależny od
     * supportsTemperature() — ta mówi „model rozumie temperaturę", ta mówi
     * „wysłanie temperatury zabije żądanie".
     */
    public function rejectsNonDefaultTemperature(): bool
    {
        return $this === self::CLAUDE_SONNET_5;
    }

    /**
     * Rezerwa tokenów myślenia doliczana do `max_tokens`.
     *
     * Na Claude `max_tokens` obejmuje myślenie ORAZ tekst odpowiedzi, więc bez
     * rezerwy długie myślenie ucina odpowiedź. Skala jest ta sama co historyczne
     * budget_tokens — dzięki temu migracja na adaptive (ADR-139) nie zmienia
     * efektywnego sufitu odpowiedzi.
     */
    public function thinkingHeadroomTokens(string $effort): int
    {
        return match ($effort) {
            'minimal' => 1024,
            'low' => 4096,
            'medium' => 8192,
            'high', 'xhigh', 'max' => 16384,
            default => 8192,
        };
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
     * - claude z adaptive thinking → string effort do `output_config` (ADR-139)
     * - claude sprzed adaptive → int budget_tokens (1024/4096/8192/16384)
     * - GPT-4.1 → null (model nie wspiera reasoning_effort)
     *
     * CHAT-T-177 (decyzja Karola Q32a): `minimal` mapuje się na `low`, bo Anthropic
     * nie ma poziomu „minimal" w adaptive. To mapowanie jest OBOWIĄZKOWE, nie
     * kosmetyczne — pozostawienie domyślnego `high` podniosłoby koszt i latencję
     * wobec dzisiejszego budżetu 1024.
     */
    public function mapEffortToProviderValue(string $effort): mixed
    {
        if (!$this->supportsReasoningEffort()) {
            return null;
        }

        if ($this->provider() === 'claude' && $this->supportsAdaptiveThinking()) {
            return match ($effort) {
                'minimal', 'low' => 'low',
                'medium' => 'medium',
                'high' => 'high',
                'xhigh' => 'xhigh',
                'max' => 'max',
                default => 'medium',
            };
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
