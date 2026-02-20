<?php

declare(strict_types=1);

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;
use DiveChat\Tools\ToolRegistry;
use DiveChat\Tools\ProductSearch;
use DiveChat\Tools\ProductDetails;
use DiveChat\Tools\ExpertKnowledge;
use DiveChat\Tools\OrderStatus;
use DiveChat\Tools\ShippingInfo;

/**
 * Rejestracja narzędzi AI (function calling).
 */
return static function (EmbeddingService $embeddingService): ToolRegistry {
    $registry = new ToolRegistry();
    $pg = PostgresConnection::getInstance();

    $registry->register(new ProductSearch($embeddingService, $pg));
    $registry->register(new ProductDetails());
    $registry->register(new ExpertKnowledge($embeddingService, $pg));
    $registry->register(new OrderStatus());
    $registry->register(new ShippingInfo());

    return $registry;
};
