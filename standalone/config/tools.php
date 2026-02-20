<?php

declare(strict_types=1);

use DiveChat\AI\AIProviderInterface;
use DiveChat\Tools\ToolRegistry;
use DiveChat\Tools\ProductSearch;
use DiveChat\Tools\ProductDetails;
use DiveChat\Tools\ExpertKnowledge;
use DiveChat\Tools\OrderStatus;
use DiveChat\Tools\ShippingInfo;

/**
 * Rejestracja narzędzi AI (function calling).
 *
 * @param AIProviderInterface $aiProvider Potrzebny do narzędzi z embeddingami
 */
return static function (AIProviderInterface $aiProvider): ToolRegistry {
    $registry = new ToolRegistry();

    $registry->register(new ProductSearch($aiProvider));
    $registry->register(new ProductDetails());
    $registry->register(new ExpertKnowledge($aiProvider));
    $registry->register(new OrderStatus());
    $registry->register(new ShippingInfo());

    return $registry;
};
