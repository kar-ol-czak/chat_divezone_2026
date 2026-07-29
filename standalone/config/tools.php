<?php

declare(strict_types=1);

use DiveChat\AI\EmbeddingService;
use DiveChat\Database\MysqlConnection;
use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;
use DiveChat\Shop\DbOverrideProvider;
use DiveChat\Shop\MysqlProductEnrichmentService;
use DiveChat\Shop\ShopCalendar;
use DiveChat\Tools\CuratedRecommendations;
use DiveChat\Tools\ExpertKnowledge;
use DiveChat\Tools\FindWetsuitsByMeasurements;
use DiveChat\Tools\GetProductCombinations;
use DiveChat\Tools\GetShopLinks;
use DiveChat\Tools\GetShopSchedule;
use DiveChat\Tools\InternationalShipping;
use DiveChat\Tools\OrderStatus;
use DiveChat\Tools\PopularProducts;
use DiveChat\Tools\ProductDetails;
use DiveChat\Tools\ProductSearch;
use DiveChat\Tools\ResendOrderInfo;
use DiveChat\Tools\ShippingInfo;
use DiveChat\Tools\SizeRecommender;
use DiveChat\Tools\SynonymExpander;
use DiveChat\Tools\ToolRegistry;

/**
 * Rejestracja narzędzi AI (function calling).
 */
return static function (EmbeddingService $embeddingService): ToolRegistry {
    $registry = new ToolRegistry();
    $pg = PostgresConnection::getInstance();
    $synonymExpander = new SynonymExpander($pg);
    $enrichmentService = new MysqlProductEnrichmentService();

    $editorialPicks = new EditorialPicksService($pg);
    $registry->register(new ProductSearch($embeddingService, $pg, $synonymExpander, $enrichmentService, $editorialPicks));
    // CHAT-T-062 (E5): ProductDetails wola enrichment dla pojedynczego product_id —
    // ta sama logika ceny brutto z VAT + specific_price co ProductSearch (jedno zrodlo).
    $registry->register(new ProductDetails($enrichmentService));
    $registry->register(new ExpertKnowledge($embeddingService, $pg));
    $registry->register(new OrderStatus());
    // CHAT-T-180 (ADR-140): ponowna wysyłka informacji o zamówieniu — woła kanałem
    // serwerowym front controller modułu PS (część A), który buduje i wysyła mail.
    $registry->register(new ResendOrderInfo());
    $registry->register(new ShippingInfo($pg));
    // CHAT-T-151 (ADR-129): wysyłka ZAGRANICZNA kurierem DPD — żywe stawki ze stref
    // PrestaShop (MySQL), osobne od get_shipping_info (Railway PG, zone=PL/EU).
    $registry->register(new InternationalShipping(MysqlConnection::getInstance()));
    $registry->register(new GetShopLinks($pg));
    $registry->register(new GetShopSchedule(new ShopCalendar(new DbOverrideProvider($pg))));
    $registry->register(new CuratedRecommendations($pg, $enrichmentService));
    // CHAT-T-132 (ADR-115): dynamiczna popularnosc z PrestaShop na zywo (pr_orders),
    // dwie sekcje bestsellers + new_arrivals (<90 dni), enrich jak curated.
    $registry->register(new PopularProducts(MysqlConnection::getInstance(), $enrichmentService));
    // CHAT-T-100 (ADR-099): deterministyczny dobór rozmiaru skafandra mokrego (NIE embeddingi).
    // CHAT-T-103: źródło prawdy rozmiarów na MySQL PrestaShop (divezone_attr_*), nie Railway/PG.
    $registry->register(new SizeRecommender(MysqlConnection::getInstance()));
    // ATTR-T-052 (ADR-025): warianty kolor/rozmiar + nazwy wypowiadalne (divezone_attr_color_lang).
    // SystemPrompt.php:463-478 opisuje kontrakt (nieznany_kolor, domyslny_wariant); to go spełnia. READ-ONLY.
    $registry->register(new GetProductCombinations(MysqlConnection::getInstance()));
    // CHAT-T-163 (ADR-132): wyszukiwanie pianek po wymiarach klienta z przeliczeniem rozmiaru
    // OSOBNO w charcie każdej marki (przecięcie w SQL, nie mapowanie etykiet). Enrichment jak ProductSearch.
    $registry->register(new FindWetsuitsByMeasurements(MysqlConnection::getInstance(), $enrichmentService));

    return $registry;
};
