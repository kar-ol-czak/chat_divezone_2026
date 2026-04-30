<?php

declare(strict_types=1);

use DiveChat\AI\ExchangeRateService;
use DiveChat\AI\PricingService;
use DiveChat\AI\UsageLogger;
use DiveChat\Chat\ChatService;
use DiveChat\Chat\ConversationStore;
use DiveChat\Chat\SettingsStore;
use DiveChat\Controller\AdminPricingController;
use DiveChat\Controller\ChatController;
use DiveChat\Controller\ConversationsController;
use DiveChat\Controller\HealthController;
use DiveChat\Controller\SettingsController;
use DiveChat\Controller\TestTokenController;
use DiveChat\Router;

/**
 * Definicje endpointów API.
 */
return static function (
    Router $router,
    ChatService $chatService,
    PricingService $pricingService,
    ExchangeRateService $exchangeRateService,
    UsageLogger $usageLogger,
): void {
    // Health
    $router->get('/api/health', HealthController::handle(...));

    // Test token (dev only)
    $router->get('/api/test-token', TestTokenController::handle(...));

    // Chat
    $chatController = new ChatController($chatService);
    $router->post('/api/chat', $chatController->handle(...));
    $router->post('/api/chat/stream', $chatController->stream(...));

    // Admin: Conversations
    $convController = new ConversationsController(new ConversationStore(), $usageLogger);
    $router->get('/api/conversations', $convController->list(...));
    $router->get('/api/conversations/{session_id}', $convController->detail(...));
    $router->post('/api/conversations/{session_id}/status', $convController->updateStatus(...));

    // Admin: Settings
    $settingsController = new SettingsController(
        new SettingsStore(),
        $pricingService,
        $exchangeRateService,
    );
    $router->get('/api/settings', $settingsController->get(...));
    $router->post('/api/settings', $settingsController->post(...));

    // Admin: Pricing
    $pricingController = new AdminPricingController($pricingService);
    $router->get('/api/admin/pricing', $pricingController->list(...));
    $router->post('/api/admin/pricing', $pricingController->update(...));
};
