<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Config;
use DiveChat\Enum\AIModel;

/**
 * Fabryka providerów AI.
 *
 * CHAT-T-068 (184a): provider wynika z modelu wybranego w panelu PS
 * (AIModel->provider()). Jeśli panel nie wybrał modelu (lub model spoza enuma) →
 * fallback na .env (AI_PROVIDER / ANTHROPIC_MODEL / OPENAI_CHAT_MODEL).
 *
 * Instancje providerów cache'owane wewnątrz fabryki, żeby kolejne wywołania dla
 * tego samego providera w jednym requeście (np. tool loop, primary → escalation)
 * nie tworzyły nowych Guzzle Clientów.
 */
final class AIProviderFactory
{
    private ?ClaudeProvider $claude = null;
    private ?OpenAIProvider $openai = null;

    /**
     * Wybiera (i lazy-tworzy) providera dla danego modelu.
     *
     * - Jeśli $modelId jest podany i AIModel::tryFrom() rozpoznaje → provider = AIModel->provider().
     * - Jeśli $modelId jest podany ale spoza enuma → log warning + fallback .env.
     * - Jeśli $modelId pusty → fallback .env (AI_PROVIDER albo derive z ANTHROPIC_MODEL).
     */
    public function createForModel(?string $modelId): AIProviderInterface
    {
        $providerName = $this->resolveProviderName($modelId);

        return match ($providerName) {
            'claude' => $this->claude ??= new ClaudeProvider(),
            'openai' => $this->openai ??= new OpenAIProvider(),
            default => throw new \InvalidArgumentException("Nieznany provider: {$providerName}"),
        };
    }

    /**
     * Wsteczna kompatybilność: legacy entry point bez wybranego modelu.
     * Używa .env (jak przed CHAT-T-068).
     */
    public static function create(): AIProviderInterface
    {
        return (new self())->createForModel(null);
    }

    private function resolveProviderName(?string $modelId): string
    {
        if ($modelId !== null && $modelId !== '') {
            $model = AIModel::tryFrom($modelId);
            if ($model !== null) {
                return $model->provider();
            }
            // Bezpiecznik (kryterium 7): model spoza enuma — log warning, fallback .env.
            // Lepiej widoczny rozjazd w logach niż cichy gpt-4.1.
            error_log(sprintf(
                '[DiveChat] AIProviderFactory: model "%s" spoza AIModel enuma — fallback na .env',
                $modelId,
            ));
        }

        // Fallback .env: AI_PROVIDER override, inaczej derive z ANTHROPIC_MODEL/OPENAI_CHAT_MODEL.
        $envModel = Config::get('ANTHROPIC_MODEL', Config::get('OPENAI_CHAT_MODEL', 'claude-sonnet-4-6'));
        $derived = str_starts_with($envModel, 'claude') ? 'claude' : 'openai';

        return Config::get('AI_PROVIDER', $derived);
    }
}
