<?php

declare(strict_types=1);

use DiveChat\AI\ExchangeRateService;
use DiveChat\AI\PricingService;
use DiveChat\AI\UsageLogger;
use DiveChat\Admin\ConversationViewer;
use DiveChat\Admin\CostAnalytics;
use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Config;
use DiveChat\Chat\ChatService;
use DiveChat\Controller\AdminRecommendationsController;
use DiveChat\Shop\MysqlProductEnrichmentService;
use DiveChat\Chat\ConversationStore;
use DiveChat\Chat\SettingsStore;
use DiveChat\Controller\AdminController;
use DiveChat\Controller\AdminEditorialPicksController;
use DiveChat\Controller\AdminPricingController;
use DiveChat\Controller\AdminWhoamiController;
use DiveChat\Controller\ChatController;
use DiveChat\Controller\ConversationsController;
use DiveChat\Controller\HealthController;
use DiveChat\Controller\SettingsController;
use DiveChat\Controller\TestTokenController;
use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;
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

    // Admin: Editorial Picks (T-008, ADR-054) — chronione przez AdminAuthMiddleware
    $editorialPicksService = new EditorialPicksService($db);
    $editorialPicksController = new AdminEditorialPicksController($editorialPicksService, $adminAuth);
    // Statyczne paths PRZED parametrycznym /{id} — Router dispatcher matchuje po kolejności.
    $router->get('/api/admin/editorial-picks/pending-reviews', $editorialPicksController->pendingReviews(...));
    $router->get('/api/admin/editorial-picks', $editorialPicksController->list(...));
    $router->post('/api/admin/editorial-picks', $editorialPicksController->add(...));
    $router->put('/api/admin/editorial-picks/{id}', $editorialPicksController->update(...));
    $router->delete('/api/admin/editorial-picks/{id}', $editorialPicksController->delete(...));

    // Admin: Products search (autocomplete dla form Add picka, T-012)
    $router->get('/api/admin/products/search', $editorialPicksController->productsSearch(...));

    // Panel admin PrestaShop — kanal serwerowy (ADR-068 174a/175a/176a, T-032).
    // Echo endpoint: weryfikacja podpisu modulu PS (DIVECHAT_SERVER_SECRET) +
    // lookup roli w divechat_admin_roles. NIE chroniony przez AdminAuthMiddleware
    // (basic auth) — wlasny kanal serwerowy z innym sekretem niz kliencki HMAC.
    $serverSecret = Config::get('DIVECHAT_SERVER_SECRET', '');
    $serverVerifier = new ServerHmacVerifier($serverSecret);
    $whoamiController = new AdminWhoamiController($serverVerifier, $db);
    $router->get('/api/admin/whoami', $whoamiController->handle(...));

    // T-035 CZESC B: sekcja read-only kuratorowanych rekomendacji w panelu PS.
    // Drugi endpoint kanalu serwerowego — pierwszy REALNY odczyt danych (po
    // echo /api/admin/whoami z T-032). Decyzje 35a/36a/37a/38b/41a.
    $recommendationsEnrichment = new MysqlProductEnrichmentService();
    $recommendationsController = new AdminRecommendationsController(
        $serverVerifier,
        $db,
        $recommendationsEnrichment,
    );
    $router->get('/api/admin/recommendations', $recommendationsController->handle(...));
};
