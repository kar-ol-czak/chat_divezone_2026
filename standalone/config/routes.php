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
use DiveChat\Controller\AdminAnalyticsController;
use DiveChat\Controller\AdminController;
use DiveChat\Controller\AdminEditorialPicksController;
use DiveChat\Controller\AdminPricingController;
use DiveChat\Controller\AdminWhoamiController;
use DiveChat\Controller\ChatController;
use DiveChat\Controller\ConversationsController;
use DiveChat\Controller\HealthController;
use DiveChat\Controller\OrderStatusController;
use DiveChat\Controller\SettingsController;
use DiveChat\Controller\TestTokenController;
use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;
use DiveChat\Http\AdminAuthMiddleware;
use DiveChat\Router;
use DiveChat\Tools\OrderStatus;
use DiveChat\Usage\CostGuard;

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
    // CHAT-T-059: ConversationStore wstrzykniety dla GET /api/chat/history
    // (odtworzenie rozmowy po nawigacji miedzy stronami; weryfikacja wlasciciela
    // po HMAC customerId == ps_customer_id rozmowy).
    // CHAT-T-064: CostGuard — twardy dzienny cap kosztow + alert e-mail + limit
    // dlugosci inputu, sprawdzane PO HMAC, PRZED chatService (ochrona publiczna).
    $chatController = new ChatController(
        $chatService,
        new ConversationStore(),
        new CostGuard(PostgresConnection::getInstance()),
    );
    $router->post('/api/chat', $chatController->handle(...));
    $router->post('/api/chat/stream', $chatController->stream(...));
    $router->get('/api/chat/history', $chatController->history(...));

    // Order status (modal "Status zamówienia", CHAT-T-042 / ADR-063 — bez LLM, lookup MySQL).
    $orderStatusController = new OrderStatusController(new OrderStatus());
    $router->post('/api/order/status', $orderStatusController->handle(...));

    // CHAT-T-044/046: kanal serwerowy panelu PS (ADR-068, sekret DIVECHAT_SERVER_SECRET).
    // Tworzony wczesnie zeby wszyscy admini ponizej (Conversations/Settings/Pricing/
    // Whoami/Recommendations) korzystali z tej samej instancji.
    $db = PostgresConnection::getInstance();
    $serverSecret = Config::get('DIVECHAT_SERVER_SECRET', '');
    $serverVerifier = new ServerHmacVerifier($serverSecret);

    // Admin: Conversations (CHAT-T-046 — kanal serwerowy, any-role: operator+admin).
    // UWAGA: stary standalone/public/js/history.js przestanie dzialac do migracji
    // widoku do zakladki "Rozmowy" w panelu PS (decyzja 95b, oczekiwane).
    $convController = new ConversationsController(
        new ConversationStore(),
        $usageLogger,
        $serverVerifier,
        $db,
    );
    $router->get('/api/conversations', $convController->list(...));
    $router->get('/api/conversations/{session_id}', $convController->detail(...));
    $router->post('/api/conversations/{session_id}/status', $convController->updateStatus(...));

    // Admin: Settings (CHAT-T-044 — kanal serwerowy, rola admin-only)
    $settingsController = new SettingsController(
        new SettingsStore(),
        $pricingService,
        $exchangeRateService,
        $serverVerifier,
        $db,
    );
    $router->get('/api/settings', $settingsController->get(...));
    $router->post('/api/settings', $settingsController->post(...));

    // Admin: Pricing (CHAT-T-044 — kanal serwerowy, rola admin-only)
    $pricingController = new AdminPricingController($pricingService, $serverVerifier, $db);
    $router->get('/api/admin/pricing', $pricingController->list(...));
    $router->post('/api/admin/pricing', $pricingController->update(...));

    // Admin: Dashboard (TASK-055) – chronione przez AdminAuthMiddleware (basic auth + .htpasswd)
    // CHAT-T-049 (decyzja 118b): analityka (cost/* + conversations/top) PRZEPIETA na
    // nowy AdminAnalyticsController (kanal serwerowy, admin-only). Stary $adminController
    // zostaje TYLKO dla conversations/{id} (decyzja 109a — NIE migrujemy, ginie z /admin).
    $htpasswdPath = dirname(__DIR__) . '/admin/.htpasswd';
    $adminAuth = new AdminAuthMiddleware($htpasswdPath);
    $costAnalytics = new CostAnalytics($db, $pricingService, $exchangeRateService);
    $conversationViewer = new ConversationViewer($db, $exchangeRateService);
    $adminController = new AdminController($adminAuth, $costAnalytics, $conversationViewer);

    // CHAT-T-049 (Etap 2): Analityka na kanal serwerowy panelu PS, rola admin-only.
    // Stary AdminController nietkniety (118b). UI Analityki = CHAT-T-050.
    $adminAnalyticsController = new AdminAnalyticsController($costAnalytics, $serverVerifier, $db);
    $router->get('/api/admin/cost/kpi', $adminAnalyticsController->kpi(...));
    $router->get('/api/admin/cost/trend', $adminAnalyticsController->trend(...));
    $router->get('/api/admin/cost/by-model', $adminAnalyticsController->byModel(...));
    // Statyczna `conversations/top` MUSI byc PRZED parametryczna `conversations/{id}`
    // (Router matchuje po kolejnosci). Top idzie na nowy kontroler (kanal serwerowy),
    // {id} zostaje na starym (Basic Auth, decyzja 109a).
    $router->get('/api/admin/conversations/top', $adminAnalyticsController->topConversations(...));
    $router->get('/api/admin/conversations/{id}', $adminController->conversationDetail(...));

    // Admin: Editorial Picks (T-008, ADR-054).
    // CHAT-T-054 Etap 3 (127b/128a): przepiete z Basic Auth na kanal serwerowy
    // any-role (operator+admin); dodane aliasy POST /{id} i POST /{id}/delete
    // (callBackend w module PS wysyla body tylko dla POST). PUT/DELETE zostaja
    // dla zgodnosci wstecznej. UI Editorial w PS = CHAT-T-055.
    $editorialPicksService = new EditorialPicksService($db);
    $editorialPicksController = new AdminEditorialPicksController(
        $editorialPicksService,
        $serverVerifier,
        $db,
    );
    // Statyczne paths PRZED parametrycznym /{id} — Router dispatcher matchuje po kolejności.
    $router->get('/api/admin/editorial-picks/pending-reviews', $editorialPicksController->pendingReviews(...));
    $router->get('/api/admin/editorial-picks', $editorialPicksController->list(...));
    $router->post('/api/admin/editorial-picks', $editorialPicksController->add(...));
    // Aliasy POST (128a) — bardziej specyficzny /{id}/delete PRZED /{id}.
    $router->post('/api/admin/editorial-picks/{id}/delete', $editorialPicksController->delete(...));
    $router->post('/api/admin/editorial-picks/{id}', $editorialPicksController->update(...));
    $router->put('/api/admin/editorial-picks/{id}', $editorialPicksController->update(...));
    $router->delete('/api/admin/editorial-picks/{id}', $editorialPicksController->delete(...));

    // Admin: Products search (autocomplete dla form Add picka, T-012)
    $router->get('/api/admin/products/search', $editorialPicksController->productsSearch(...));

    // Panel admin PrestaShop — kanal serwerowy (ADR-068 174a/175a/176a, T-032).
    // Echo endpoint: weryfikacja podpisu modulu PS (DIVECHAT_SERVER_SECRET) +
    // lookup roli w divechat_admin_roles. NIE chroniony przez AdminAuthMiddleware
    // (basic auth) — wlasny kanal serwerowy z innym sekretem niz kliencki HMAC.
    // $serverVerifier juz utworzony wyzej (CHAT-T-044 — wspoldzielony z Settings/Pricing).
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
