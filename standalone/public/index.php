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
use DiveChat\Chat\ChatService;
use DiveChat\Chat\ConversationStore;
use DiveChat\Chat\SettingsStore;
use DiveChat\Config;
use DiveChat\Http\Request;
use DiveChat\Http\Response;
use DiveChat\Router;

// Obsługa preflight CORS
Response::handlePreflight();

// Załaduj .env
Config::load(dirname(__DIR__));

// Inicjalizuj serwisy
$aiProvider = AIProviderFactory::create();
$embeddingService = new EmbeddingService();

$registerTools = require dirname(__DIR__) . '/config/tools.php';
$toolRegistry = $registerTools($embeddingService);

$chatService = new ChatService($aiProvider, $toolRegistry, new ConversationStore(), new SettingsStore());

// Inicjalizuj router i zarejestruj routes
$router = new Router();
$registerRoutes = require dirname(__DIR__) . '/config/routes.php';
$registerRoutes($router, $chatService);

// Dispatch
$request = new Request();
$router->dispatch($request);
