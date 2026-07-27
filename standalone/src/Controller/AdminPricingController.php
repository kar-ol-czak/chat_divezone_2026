<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\AI\PricingService;
use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Database\PostgresConnection;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Admin API edycji cennika modeli.
 *
 * GET /api/admin/pricing — lista wszystkich modeli (włącznie z is_active=false).
 * POST /api/admin/pricing — update jednego modelu.
 *
 * Auth (CHAT-T-044, ADR-068): kanał serwerowy panelu PS (ServerHmacVerifier),
 * wymagana rola 'admin' (zmiana cennika wpływa na rozliczanie kosztów AI —
 * stricter niż recommendations).
 */
final class AdminPricingController
{
    public function __construct(
        private readonly PricingService $pricingService,
        private readonly ServerHmacVerifier $serverVerifier,
        private readonly PostgresConnection $pg,
    ) {}

    public function list(Request $request): void
    {
        $this->requireAdmin();

        $items = [];
        foreach ($this->pricingService->getAllActive() as $price) {
            $items[] = [
                'model_id' => $price->modelId,
                'provider' => $price->provider,
                'label' => $price->label,
                'input_price_per_million' => $price->inputPricePerMillion,
                'output_price_per_million' => $price->outputPricePerMillion,
                'cache_read_price_per_million' => $price->cacheReadPricePerMillion,
                'cache_creation_price_per_million' => $price->cacheCreationPricePerMillion,
                'is_active' => $price->isActive,
                'is_escalation' => $price->isEscalation,
                'supports_temperature' => $price->supportsTemperature,
                'supports_reasoning_effort' => $price->supportsReasoningEffort,
                'currency' => $price->currency,
            ];
        }

        Response::json(['models' => $items]);
    }

    public function update(Request $request): void
    {
        $this->requireAdmin();

        $body = $request->getJsonBody();
        $modelId = trim((string) ($body['model_id'] ?? ''));

        if ($modelId === '') {
            Response::error('Pole "model_id" jest wymagane', 400);
        }

        if ($this->pricingService->getPrice($modelId) === null) {
            Response::error('Nieznany model: ' . $modelId, 404);
        }

        $allowed = [
            'input_price_per_million',
            'output_price_per_million',
            'cache_read_price_per_million',
            'cache_creation_price_per_million',
            'is_active',
            'label',
        ];

        $fields = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $body)) {
                continue;
            }
            $value = $body[$key];

            if (in_array($key, ['input_price_per_million', 'output_price_per_million'], true)) {
                if (!is_numeric($value) || (float) $value < 0) {
                    Response::error("Pole {$key} musi być liczbą >= 0", 400);
                }
                $fields[$key] = round((float) $value, 6);
            } elseif (in_array($key, ['cache_read_price_per_million', 'cache_creation_price_per_million'], true)) {
                if ($value !== null && (!is_numeric($value) || (float) $value < 0)) {
                    Response::error("Pole {$key} musi być liczbą >= 0 lub null", 400);
                }
                $fields[$key] = $value === null ? null : round((float) $value, 6);
            } elseif ($key === 'is_active') {
                $fields[$key] = (bool) $value;
            } elseif ($key === 'label') {
                $fields[$key] = (string) $value;
            }
        }

        if (empty($fields)) {
            Response::error('Brak pól do aktualizacji', 400);
        }

        $this->pricingService->updatePrice($modelId, $fields);

        Response::json([
            'success' => true,
            'model_id' => $modelId,
            'updated' => array_keys($fields),
        ]);
    }

    /**
     * Weryfikacja kanału serwerowego + wymagana rola 'admin' (CHAT-T-044).
     * Identyczny wzorzec jak SettingsController::requireAdmin — duplikacja
     * świadoma, zgodna ze stylem AdminWhoamiController/AdminRecommendationsController.
     * Refaktor do middleware/traita: osobny task gdy ≥3 kontrolery powtórzą blok.
     */
    private function requireAdmin(): void
    {
        $token = (string) ($_SERVER['HTTP_X_DIVECHAT_SERVER_TOKEN'] ?? '');
        $employeeRaw = $_SERVER['HTTP_X_DIVECHAT_SERVER_EMPLOYEE'] ?? '';
        $timeRaw = $_SERVER['HTTP_X_DIVECHAT_SERVER_TIME'] ?? '';

        if ($token === '' || $employeeRaw === '' || $timeRaw === '') {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $employeeId = filter_var($employeeRaw, FILTER_VALIDATE_INT);
        $timestamp = filter_var($timeRaw, FILTER_VALIDATE_INT);
        if ($employeeId === false || $timestamp === false || $employeeId <= 0) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        if (!$this->serverVerifier->verify($token, $employeeId, $timestamp)) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->pg->fetchOne(
            'SELECT role FROM divechat_admin_roles WHERE employee_id = ?',
            [$employeeId],
        );

        if ($row === null) {
            Response::json(['error' => 'Forbidden', 'reason' => 'no_role'], 403);
        }

        if ((string) $row['role'] !== 'admin') {
            Response::json(['error' => 'Forbidden', 'reason' => 'admin_only'], 403);
        }
    }
}
