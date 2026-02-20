<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Chat\SettingsStore;
use DiveChat\Enum\AIModel;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Admin API do zarządzania ustawieniami czatu.
 *
 * GET /api/settings — pobierz wszystkie
 * POST /api/settings — aktualizuj jedno lub wiele
 */
final class SettingsController
{
    public function __construct(
        private readonly SettingsStore $settingsStore,
    ) {}

    /**
     * GET /api/settings
     */
    public function get(Request $request): void
    {
        Response::json([
            'settings' => $this->settingsStore->getAll(),
            'available_models' => AIModel::grouped(),
        ]);
    }

    /**
     * POST /api/settings
     * Body: {"key": "temperature", "value": 0.7}
     * Lub bulk: {"settings": {"temperature": 0.7, "emoji_enabled": false}}
     */
    public function post(Request $request): void
    {
        $body = $request->getJsonBody();

        // Bulk update
        if (isset($body['settings']) && is_array($body['settings'])) {
            $this->settingsStore->setMany($body['settings']);
            Response::json(['success' => true, 'updated' => array_keys($body['settings'])]);
        }

        // Single update
        $key = $body['key'] ?? '';
        if ($key === '') {
            Response::error('Pole "key" jest wymagane (lub "settings" dla bulk update)', 400);
        }

        if (!array_key_exists('value', $body)) {
            Response::error('Pole "value" jest wymagane', 400);
        }

        $this->settingsStore->set($key, $body['value']);
        Response::json(['success' => true, 'key' => $key, 'value' => $body['value']]);
    }
}
