<?php

declare(strict_types=1);

namespace DiveChat\AI;

/**
 * DTO ceny modelu z divechat_model_pricing.
 * Wszystkie pola w USD per milion tokenów.
 */
final readonly class ModelPrice
{
    public function __construct(
        public string $modelId,
        public string $provider,
        public string $label,
        public float $inputPricePerMillion,
        public float $outputPricePerMillion,
        public ?float $cacheReadPricePerMillion,
        public ?float $cacheCreationPricePerMillion,
        public bool $isActive,
        public bool $isEscalation,
        public bool $supportsTemperature,
        public bool $supportsReasoningEffort,
        public string $currency = 'USD',
    ) {}

    /**
     * @param array<string, mixed> $row Wiersz z divechat_model_pricing
     */
    public static function fromRow(array $row): self
    {
        return new self(
            modelId: (string) $row['model_id'],
            provider: (string) $row['provider'],
            label: (string) $row['label'],
            inputPricePerMillion: (float) $row['input_price_per_million'],
            outputPricePerMillion: (float) $row['output_price_per_million'],
            cacheReadPricePerMillion: $row['cache_read_price_per_million'] !== null
                ? (float) $row['cache_read_price_per_million']
                : null,
            cacheCreationPricePerMillion: $row['cache_creation_price_per_million'] !== null
                ? (float) $row['cache_creation_price_per_million']
                : null,
            isActive: (bool) $row['is_active'],
            isEscalation: (bool) $row['is_escalation'],
            supportsTemperature: (bool) $row['supports_temperature'],
            supportsReasoningEffort: (bool) $row['supports_reasoning_effort'],
            currency: (string) ($row['currency'] ?? 'USD'),
        );
    }
}
