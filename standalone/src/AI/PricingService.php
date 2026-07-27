<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Database\PostgresConnection;

/**
 * Serwis cennika modeli AI.
 * Czyta z `divechat_model_pricing`, kalkuluje koszt per wywołanie.
 */
final class PricingService
{
    /** @var array<string, ModelPrice>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    public function getPrice(string $modelId): ?ModelPrice
    {
        return $this->loadCache()[$modelId] ?? null;
    }

    /**
     * @return ModelPrice[]
     */
    public function getAllActive(): array
    {
        return array_values(array_filter(
            $this->loadCache(),
            static fn(ModelPrice $p): bool => $p->isActive,
        ));
    }

    public function calculateCost(
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheCreationTokens = 0,
    ): CostBreakdown {
        $price = $this->getPrice($modelId);

        if ($price === null) {
            // Nieznany model – koszt zerowy żeby nie blokować logowania.
            // Pozwala przetestować fallback OPENAI_CHAT_MODEL=gpt-5.2 (legacy w .env)
            // bez wywalania chat-u.
            return new CostBreakdown(0.0, 0.0, 0.0, 0.0);
        }

        $costInput = ($inputTokens / 1_000_000) * $price->inputPricePerMillion;
        $costOutput = ($outputTokens / 1_000_000) * $price->outputPricePerMillion;

        $costCache = 0.0;
        if ($cacheReadTokens > 0 && $price->cacheReadPricePerMillion !== null) {
            $costCache += ($cacheReadTokens / 1_000_000) * $price->cacheReadPricePerMillion;
        }
        if ($cacheCreationTokens > 0 && $price->cacheCreationPricePerMillion !== null) {
            $costCache += ($cacheCreationTokens / 1_000_000) * $price->cacheCreationPricePerMillion;
        }

        $costInput = round($costInput, 6);
        $costOutput = round($costOutput, 6);
        $costCache = round($costCache, 6);
        $costTotal = round($costInput + $costOutput + $costCache, 6);

        return new CostBreakdown($costInput, $costOutput, $costCache, $costTotal);
    }

    /**
     * @param array<string, mixed> $fields Pola do aktualizacji – białą listą
     */
    public function updatePrice(string $modelId, array $fields): void
    {
        $allowed = [
            'input_price_per_million',
            'output_price_per_million',
            'cache_read_price_per_million',
            'cache_creation_price_per_million',
            'is_active',
            'label',
            'is_escalation',
            'supports_temperature',
            'supports_reasoning_effort',
        ];

        $updates = [];
        $params = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $updates[] = "{$key} = ?";
            $params[] = $value;
        }

        if (empty($updates)) {
            return;
        }

        $updates[] = 'updated_at = NOW()';
        $params[] = $modelId;

        $this->db->query(
            'UPDATE divechat_model_pricing SET ' . implode(', ', $updates) . ' WHERE model_id = ?',
            $params,
        );

        $this->cache = null; // invalidate
    }

    /**
     * @return array<string, ModelPrice>
     */
    private function loadCache(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $rows = $this->db->fetchAll('SELECT * FROM divechat_model_pricing');
        $cache = [];
        foreach ($rows as $row) {
            $price = ModelPrice::fromRow($row);
            $cache[$price->modelId] = $price;
        }
        $this->cache = $cache;
        return $cache;
    }
}
