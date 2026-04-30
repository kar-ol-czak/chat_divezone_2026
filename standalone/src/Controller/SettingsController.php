<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\AI\ExchangeRateService;
use DiveChat\AI\PricingService;
use DiveChat\Chat\SettingsStore;
use DiveChat\Enum\AIModel;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Admin API ustawień czatu.
 *
 * GET /api/settings — settings + lista modeli z cenami + kurs USD/PLN.
 * POST /api/settings — single (key/value) lub bulk (settings: {...}).
 */
final class SettingsController
{
    public function __construct(
        private readonly SettingsStore $settingsStore,
        private readonly PricingService $pricingService,
        private readonly ExchangeRateService $exchangeRateService,
    ) {}

    public function get(Request $request): void
    {
        Response::json([
            'settings' => $this->settingsStore->getAll(),
            'available_models' => $this->buildAvailableModels(),
            'exchange_rate_usd_pln' => round($this->exchangeRateService->getUsdToPln(), 4),
        ]);
    }

    public function post(Request $request): void
    {
        $body = $request->getJsonBody();

        if (isset($body['settings']) && is_array($body['settings'])) {
            $this->settingsStore->setMany($body['settings']);
            $this->cleanupLegacyKeys($body['settings']);
            Response::json(['success' => true, 'updated' => array_keys($body['settings'])]);
        }

        $key = $body['key'] ?? '';
        if ($key === '') {
            Response::error('Pole "key" jest wymagane (lub "settings" dla bulk update)', 400);
        }

        if (!array_key_exists('value', $body)) {
            Response::error('Pole "value" jest wymagane', 400);
        }

        $this->settingsStore->set($key, $body['value']);
        $this->cleanupLegacyKeys([$key => $body['value']]);
        Response::json(['success' => true, 'key' => $key, 'value' => $body['value']]);
    }

    /**
     * Usuwa legacy klucze gdy zapisany został odpowiadający im nowy klucz.
     * Zapobiega narastającemu stale state po refaktorze TASK-052b/052c.
     */
    private function cleanupLegacyKeys(array $written): void
    {
        $legacyMap = [
            'model_primary' => 'primary_model',
            'model_escalation' => 'escalation_model',
            'reasoning_effort' => 'escalation_effort',
        ];
        foreach ($legacyMap as $newKey => $legacyKey) {
            if (array_key_exists($newKey, $written)) {
                $this->settingsStore->delete($legacyKey);
            }
        }
    }

    /**
     * Łączy AIModel::grouped() z cenami z PricingService – dla każdego modelu
     * dorzuca input_price/output_price/cache_*. Modele bez wpisu w cenniku
     * (np. legacy fallback) pomijane.
     */
    private function buildAvailableModels(): array
    {
        $grouped = AIModel::grouped();
        $enriched = [];

        foreach ($grouped as $provider => $tiers) {
            foreach ($tiers as $tier => $models) {
                foreach ($models as $model) {
                    $price = $this->pricingService->getPrice($model['value']);
                    if ($price === null || !$price->isActive) {
                        continue;
                    }
                    $enriched[$provider][$tier][] = [
                        'value' => $model['value'],
                        'label' => $price->label,
                        'input_price' => $price->inputPricePerMillion,
                        'output_price' => $price->outputPricePerMillion,
                        'cache_read_price' => $price->cacheReadPricePerMillion,
                        'cache_creation_price' => $price->cacheCreationPricePerMillion,
                        'supports_temperature' => $price->supportsTemperature,
                        'supports_reasoning_effort' => $price->supportsReasoningEffort,
                        'effort_param' => $model['effort_param'],
                    ];
                }
                if (!empty($enriched[$provider][$tier])) {
                    usort(
                        $enriched[$provider][$tier],
                        static fn(array $a, array $b): int => $a['input_price'] <=> $b['input_price'],
                    );
                }
            }
        }

        return $enriched;
    }
}
