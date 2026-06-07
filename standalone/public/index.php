<?php

declare(strict_types=1);

/**
 * DiveChat API - Front Controller
 *
 * Entry point dla standalone API chat.divezone.pl
 */

// Autoload Composer
require_once dirname(__DIR__) . '/vendor/autoload.php';

use DiveChat\AI\AIProviderFactory;
use DiveChat\AI\EmbeddingService;
use DiveChat\AI\ExchangeRateService;
use DiveChat\AI\PricingService;
use DiveChat\AI\UsageLogger;
use DiveChat\Chat\ChatService;
use DiveChat\Chat\ConversationStore;
use DiveChat\Chat\SettingsStore;
use DiveChat\Config;
use DiveChat\Database\PostgresConnection;
use DiveChat\Http\Request;
use DiveChat\Http\Response;
use DiveChat\Router;
use DiveChat\Usage\DbHealthAlert;

// Obsługa preflight CORS
Response::handlePreflight();

// Załaduj .env
Config::load(dirname(__DIR__));

// Inicjalizuj serwisy.
// CHAT-T-068 (184a): ChatService dostaje fabrykę zamiast jednej instancji providera —
// instancja jest tworzona per-request na podstawie modelu wybranego w panelu PS
// (ClaudeProvider dla claude-*, OpenAIProvider dla gpt-*). Bez tego .env AI_PROVIDER
// przebijał panel.
$providerFactory = new AIProviderFactory();
$embeddingService = new EmbeddingService();

$registerTools = require dirname(__DIR__) . '/config/tools.php';
$toolRegistry = $registerTools($embeddingService);

$db = PostgresConnection::getInstance();
$pricingService = new PricingService($db);
$exchangeRateService = new ExchangeRateService($db);
$usageLogger = new UsageLogger($db, $pricingService, $exchangeRateService);

// CHAT-T-079 (ADR-088): alert na awarie polaczenia DB w ChatService::executeTool.
// SettingsStore -> klucz protect_cost_alert_email (reuse CostGuard, decyzja 248a);
// fallback .env DIVECHAT_COST_ALERT_EMAIL.
$dbHealthAlert = new DbHealthAlert($db, new SettingsStore());

$chatService = new ChatService(
    $providerFactory,
    $toolRegistry,
    new ConversationStore(),
    new SettingsStore(),
    $usageLogger,
    $dbHealthAlert,
);

// Inicjalizuj router i zarejestruj routes
$router = new Router();
$registerRoutes = require dirname(__DIR__) . '/config/routes.php';
$registerRoutes($router, $chatService, $pricingService, $exchangeRateService, $usageLogger);

// Dispatch
$request = new Request();
$router->dispatch($request);
