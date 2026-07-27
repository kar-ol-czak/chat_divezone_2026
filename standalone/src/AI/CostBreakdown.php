<?php

declare(strict_types=1);

namespace DiveChat\AI;

/**
 * Rozkład kosztu pojedynczego wywołania providera (USD).
 * Wszystkie wartości zaokrąglone do 6 miejsc po przecinku (NUMERIC(10,6) w bazie).
 */
final readonly class CostBreakdown
{
    public function __construct(
        public float $costInputUsd,
        public float $costOutputUsd,
        public float $costCacheUsd,
        public float $costTotalUsd,
    ) {}
}
