<?php

declare(strict_types=1);

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;
use DiveChat\Shop\DbOverrideProvider;
use DiveChat\Shop\ShopCalendar;
use DiveChat\Tools\ToolRegistry;
use DiveChat\Tools\ProductSearch;
use DiveChat\Tools\ProductDetails;
use DiveChat\Tools\ExpertKnowledge;
use DiveChat\Tools\GetShopSchedule;
use DiveChat\Tools\OrderStatus;
use DiveChat\Tools\SynonymExpander;
use DiveChat\Tools\ShippingInfo;

/**
 * Rejestracja narzędzi AI (function calling).
 */
return static function (EmbeddingService $embeddingService): ToolRegistry {
    $registry = new ToolRegistry();
    $pg = PostgresConnection::getInstance();
    $synonymExpander = new SynonymExpander($pg);

    $editorialPicks = new EditorialPicksService($pg);
    $registry->register(new ProductSearch($embeddingService, $pg, $synonymExpander, $editorialPicks));
    $registry->register(new ProductDetails());
    $registry->register(new ExpertKnowledge($embeddingService, $pg));
    $registry->register(new OrderStatus());
    $registry->register(new ShippingInfo());
    $registry->register(new GetShopSchedule(new ShopCalendar(new DbOverrideProvider($pg))));

    return $registry;
};
