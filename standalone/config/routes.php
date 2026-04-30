<?php

declare(strict_types=1);

use DiveChat\AI\ExchangeRateService;
use DiveChat\AI\PricingService;
use DiveChat\AI\UsageLogger;
use DiveChat\Admin\ConversationViewer;
use DiveChat\Admin\CostAnalytics;
use DiveChat\Chat\ChatService;
use DiveChat\Chat\ConversationStore;
use DiveChat\Chat\SettingsStore;
use DiveChat\Controller\AdminController;
use DiveChat\Controller\AdminPricingController;
use DiveChat\Controller\ChatController;
use DiveChat\Controller\ConversationsController;
use DiveChat\Controller\HealthController;
use DiveChat\Controller\SettingsController;
use DiveChat\Controller\TestTokenController;
use DiveChat\Database\PostgresConnection;
use DiveChat\Http\AdminAuthMiddleware;
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

    // Admin: Conversations (po session_id - legacy widget testowy)
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

    // Admin: Dashboard (TASK-055) – chronione przez AdminAuthMiddleware (basic auth + .htpasswd)
    $htpasswdPath = dirname(__DIR__) . '/admin/.htpasswd';
    $adminAuth = new AdminAuthMiddleware($htpasswdPath);
    $db = PostgresConnection::getInstance();
    $costAnalytics = new CostAnalytics($db, $pricingService, $exchangeRateService);
    $conversationViewer = new ConversationViewer($db, $exchangeRateService);
    $adminController = new AdminController($adminAuth, $costAnalytics, $conversationViewer);

    $router->get('/api/admin/cost/kpi', $adminController->kpi(...));
    $router->get('/api/admin/cost/trend', $adminController->trend(...));
    $router->get('/api/admin/cost/by-model', $adminController->byModel(...));
    $router->get('/api/admin/conversations/top', $adminController->topConversations(...));
    $router->get('/api/admin/conversations/{id}', $adminController->conversationDetail(...));
};
