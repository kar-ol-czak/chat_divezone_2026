<?php

declare(strict_types=1);

use DiveChat\Chat\ChatService;
use DiveChat\Controller\ChatController;
use DiveChat\Controller\HealthController;
use DiveChat\Router;

/**
 * Definicje endpointów API.
 *
 * @param Router $router
 * @param ChatService $chatService
 */
return static function (Router $router, ChatService $chatService): void {
    $router->get('/api/health', HealthController::handle(...));

    $chatController = new ChatController($chatService);
    $router->post('/api/chat', $chatController->handle(...));
};
