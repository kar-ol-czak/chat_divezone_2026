<?php

declare(strict_types=1);

namespace DiveChat\Chat;

use DiveChat\AI\AIProviderInterface;
use DiveChat\AI\AIResponse;
use DiveChat\AI\ConversationCost;
use DiveChat\AI\ToolCall;
use DiveChat\AI\UsageLogger;
use DiveChat\Config;
use DiveChat\Enum\AIModel;
use DiveChat\Tools\ToolRegistry;

/**
 * Orkiestrator czatu.
 * Obsługuje tool loop: AI -> narzędzia -> AI -> ... -> odpowiedź.
 * Zbiera diagnostykę: timings, search quality, knowledge gaps.
 */
final class ChatService
{
    private const MAX_TOOL_ITERATIONS = 5;
    private const MAX_HISTORY_MESSAGES = 10;

    public function __construct(
        private readonly AIProviderInterface $aiProvider,
        private readonly ToolRegistry $toolRegistry,
        private readonly ConversationStore $conversationStore,
        private readonly SettingsStore $settingsStore,
        private readonly UsageLogger $usageLogger,
    ) {}

    /**
     * Główna metoda - obsługuje wiadomość klienta.
     *
     * @param ?callable(string): void $onStatus Callback emitujący status SSE
     * @return array{response: string, session_id: string, tools_used: string[], products: array, usage: array, diagnostics: array}
     */
    public function handle(string $sessionId, string $message, ?int $customerId, ?callable $onStatus = null): array
    {
        $startTime = microtime(true);
        $emit = $onStatus ?? static function (string $text): void {};

        $emit('Analizuję Twoje pytanie...');

        // Wczytaj ustawienia z bazy (fallback na .env/defaults)
        $settings = $this->loadSettings();

        // 1. Wczytaj lub utwórz sesję (PEŁNA historia + id rekordu).
        $session = $this->conversationStore->startOrResume($sessionId, $customerId);
        $conversationId = $session['id'];
        $fullHistory = $session['history'];

        // Rehydratuj ToolCall objects z historii (JSON zwraca tablice)
        foreach ($fullHistory as &$msg) {
            if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $msg['tool_calls'] = array_map(
                    fn(array $tc) => new ToolCall($tc['id'], $tc['name'], $tc['arguments'] ?? []),
                    $msg['tool_calls'],
                );
            }
        }
        unset($msg);

        // 2. Przytnij KOPIĘ dla LLM kontekstu (pełna historia nienaruszona)
        $trimmedHistory = $this->trimHistory($fullHistory);

        // 3. Zbuduj listę wiadomości dla LLM z PRZYCIĘTEJ historii
        $messages = [
            ['role' => 'system', 'content' => SystemPrompt::build($settings['emoji_enabled'])],
        ];

        foreach ($trimmedHistory as $msg) {
            if ($msg['role'] !== 'system') {
                $messages[] = $msg;
            }
        }

        // Dodaj nową wiadomość użytkownika
        $messages[] = ['role' => 'user', 'content' => $message];

        // 3. Przygotuj definicje narzędzi
        $toolDefinitions = $this->toolRegistry->getToolDefinitions();

        // 4. Tool loop z diagnostyką
        $toolsUsed = [];
        $products = [];
        $totalUsage = ['input_tokens' => 0, 'output_tokens' => 0];
        $searchDiagnostics = [];
        $knowledgeGap = false;
        $timings = ['ai_ms' => 0.0, 'tool_ms' => 0.0, 'embedding_ms' => 0.0];

        // Rozpoznaj aktualnego providera
        $currentProvider = Config::get('AI_PROVIDER', str_starts_with(Config::get('ANTHROPIC_MODEL', ''), 'claude') ? 'claude' : 'openai');

        // Opcje AI: model override + temperature/effort wg flag wybranego modelu.
        $aiOptions = [];
        $primaryModel = null;
        if (!empty($settings['model_primary'])) {
            $primaryModel = AIModel::tryFrom($settings['model_primary']);
            if ($primaryModel !== null && $primaryModel->provider() === $currentProvider) {
                $aiOptions['model_override'] = $settings['model_primary'];
            }
        }

        if ($primaryModel !== null) {
            if ($primaryModel->supportsTemperature() && isset($settings['temperature'])) {
                $aiOptions['temperature'] = (float) $settings['temperature'];
            }
            if ($primaryModel->supportsReasoningEffort() && !empty($settings['reasoning_effort'])) {
                $aiOptions['effort'] = (string) $settings['reasoning_effort'];
            }
        }

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            // Status przed wywołaniem AI
            if ($i > 0) {
                $emit('Przygotowuję odpowiedź...');
            }

            $aiStart = microtime(true);
            $response = $this->aiProvider->chat($messages, $toolDefinitions, $aiOptions);
            $timings['ai_ms'] += (microtime(true) - $aiStart) * 1000;

            $totalUsage['input_tokens'] += $response->usage['input_tokens'];
            $totalUsage['output_tokens'] += $response->usage['output_tokens'];

            // Loguj usage per wywołanie providera (audit trail w divechat_message_usage).
            $modelForLogging = $aiOptions['model_override']
                ?? ($currentProvider === 'claude'
                    ? Config::get('ANTHROPIC_MODEL', 'claude-sonnet-4-6')
                    : Config::get('OPENAI_CHAT_MODEL', 'gpt-4.1'));

            try {
                $this->usageLogger->logMessage(
                    conversationId: $conversationId,
                    messageId: null,
                    modelId: $modelForLogging,
                    inputTokens: (int) $response->usage['input_tokens'],
                    outputTokens: (int) $response->usage['output_tokens'],
                    cacheReadTokens: (int) ($response->usage['cache_read_tokens'] ?? 0),
                    cacheCreationTokens: (int) ($response->usage['cache_creation_tokens'] ?? 0),
                );
            } catch (\Throwable $e) {
                // Nie blokuj odpowiedzi czatu jeśli logger się wywróci.
                error_log("[DiveChat] UsageLogger failed: " . $e->getMessage());
            }

            // Jeśli brak tool calls - mamy finalną odpowiedź
            if (!$response->hasToolCalls()) {
                break;
            }

            // Status: wyszukiwanie
            $emit('Przeszukuję ofertę nurkową — chwilka...');

            // Dodaj odpowiedź asystenta z tool calls do historii
            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => $response->toolCalls,
            ];

            // Wykonaj każde narzędzie
            foreach ($response->toolCalls as $toolCall) {
                $toolsUsed[] = $toolCall->name;

                $toolStart = microtime(true);
                $result = $this->executeTool($toolCall->name, $toolCall->arguments);
                $toolElapsed = (microtime(true) - $toolStart) * 1000;
                $timings['tool_ms'] += $toolElapsed;

                // Zbieraj produkty z wyników search_products
                if ($toolCall->name === 'search_products' && !empty($result['products'])) {
                    $products = array_merge($products, $result['products']);
                }

                // Diagnostyka search quality
                $diag = $this->buildSearchDiagnostic($toolCall, $result, $settings['knowledge_gap_threshold']);
                if ($diag !== null) {
                    $searchDiagnostics[] = $diag;
                    if ($diag['knowledge_gap']) {
                        $knowledgeGap = true;
                    }
                }

                // Dodaj wynik narzędzia do wiadomości (bez search_debug — diagnostyka zbyt duża dla LLM)
                $resultForAI = $result;
                unset($resultForAI['search_debug']);
                $messages[] = [
                    'role' => 'tool_result',
                    'tool_call_id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'content' => json_encode($resultForAI, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        // Loguj wyczerpanie pętli narzędzi
        if ($response->hasToolCalls()) {
            error_log("[DiveChat] Tool loop wyczerpany po " . self::MAX_TOOL_ITERATIONS
                . " iteracjach, sesja: {$sessionId}");
        }

        $finalContent = $response->content ?? 'Przepraszam, nie udało się wygenerować odpowiedzi.';

        $timings['total_ms'] = (microtime(true) - $startTime) * 1000;

        // Model raportowany = override jeśli użyty, inaczej z .env
        $modelUsed = $aiOptions['model_override']
            ?? ($currentProvider === 'claude'
                ? Config::get('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514')
                : Config::get('OPENAI_CHAT_MODEL', 'gpt-4o'));

        // 5. Zapisz PEŁNĄ historię + nowe wiadomości z tego turnu
        //    Serializuj ToolCall objects w pełnej historii
        $fullHistorySerialized = array_map(function (array $m) {
            if (!empty($m['tool_calls'])) {
                $m['tool_calls'] = array_map(function ($tc) {
                    if ($tc instanceof ToolCall) {
                        return ['id' => $tc->id, 'name' => $tc->name, 'arguments' => $tc->arguments];
                    }
                    return $tc;
                }, $m['tool_calls']);
            }
            return $m;
        }, $fullHistory);

        // Wyciągnij NOWE wiadomości z tego turnu (po trimmed history + user msg)
        //   $messages = [system, ...trimmedHistory, userMsg, ...toolLoopMsgs]
        //   Nowe = userMsg + toolLoopMsgs (od pozycji 1 + count(trimmedHistory))
        $newStartIdx = 1 + count($trimmedHistory); // skip system + trimmed history
        $newMessages = array_slice($messages, $newStartIdx);

        // Serializuj tool_calls w nowych wiadomościach
        $newMessages = array_map(function (array $m) {
            if (!empty($m['tool_calls'])) {
                $m['tool_calls'] = array_map(fn($tc) => [
                    'id' => $tc->id,
                    'name' => $tc->name,
                    'arguments' => $tc->arguments,
                ], $m['tool_calls']);
            }
            return $m;
        }, $newMessages);

        // Dodaj finalną odpowiedź asystenta (jeśli nie ma tool calls)
        if (!$response->hasToolCalls()) {
            $assistantMsg = ['role' => 'assistant', 'content' => $finalContent];
            if (!empty($products)) {
                $assistantMsg['products'] = $products;
            }
            $newMessages[] = $assistantMsg;
        }

        // Złącz: pełna historia (bez system) + nowe wiadomości
        $historyToSave = array_merge(
            array_values(array_filter($fullHistorySerialized, fn(array $m) => $m['role'] !== 'system')),
            $newMessages,
        );

        $roundedTimings = array_map(fn($v) => round((float) $v, 1), $timings);

        $this->conversationStore->save(
            $sessionId,
            $historyToSave,
            array_unique($toolsUsed),
            $modelUsed,
            $roundedTimings,
            $searchDiagnostics,
            $knowledgeGap,
        );

        // Sumaryczny koszt rozmowy (po atomic UPDATE w UsageLogger).
        try {
            $conversationCost = $this->usageLogger->getConversationCost($conversationId)->toArray();
        } catch (\Throwable $e) {
            error_log("[DiveChat] getConversationCost failed: " . $e->getMessage());
            $conversationCost = null;
        }

        return [
            'response' => $finalContent,
            'session_id' => $sessionId,
            'tools_used' => array_values(array_unique($toolsUsed)),
            'products' => $products,
            'usage' => $totalUsage,
            'conversation_cost' => $conversationCost,
            'diagnostics' => [
                'model_used' => $modelUsed,
                'response_times' => $roundedTimings,
                'search_diagnostics' => $searchDiagnostics,
                'knowledge_gap' => $knowledgeGap,
            ],
        ];
    }

    /**
     * Wczytuje ustawienia z bazy z fallbackami na .env/defaults.
     * Akceptuje nowe klucze (model_primary, model_escalation, temperature,
     * reasoning_effort) i legacy (primary_model, escalation_model, escalation_effort)
     * dla kompatybilności wstecznej. Frontend zapisuje pod nowymi nazwami.
     */
    private function loadSettings(): array
    {
        $dbSettings = $this->settingsStore->getAll();

        return [
            'emoji_enabled' => $dbSettings['emoji_enabled'] ?? true,
            'knowledge_gap_threshold' => (float) ($dbSettings['knowledge_gap_threshold'] ?? 0.5),
            'model_primary' => $dbSettings['model_primary'] ?? $dbSettings['primary_model'] ?? null,
            'model_escalation' => $dbSettings['model_escalation'] ?? $dbSettings['escalation_model'] ?? null,
            'temperature' => $dbSettings['temperature'] ?? null,
            'reasoning_effort' => $dbSettings['reasoning_effort']
                ?? (is_string($dbSettings['escalation_effort'] ?? null)
                    ? $dbSettings['escalation_effort']
                    : 'medium'),
        ];
    }

    /**
     * Buduje diagnostykę dla narzędzi wyszukujących.
     */
    private function buildSearchDiagnostic(ToolCall $toolCall, array $result, float $threshold): ?array
    {
        $searchTools = ['search_products', 'get_expert_knowledge'];
        if (!in_array($toolCall->name, $searchTools, true)) {
            return null;
        }

        // Wyciągnij similarity z wyników
        $items = $result['products'] ?? $result['knowledge'] ?? [];
        $similarities = array_map(fn(array $item) => (float) ($item['similarity'] ?? 0), $items);

        $maxSim = !empty($similarities) ? max($similarities) : null;
        $minSim = !empty($similarities) ? min($similarities) : null;
        $gap = empty($items) || ($maxSim !== null && $maxSim < $threshold);

        $diag = [
            'tool' => $toolCall->name,
            'query_text' => $toolCall->arguments['query'] ?? null,
            'result_count' => count($items),
            'max_similarity' => $maxSim !== null ? round($maxSim, 3) : null,
            'min_similarity' => $minSim !== null ? round($minSim, 3) : null,
            'knowledge_gap' => $gap,
        ];

        // Dołącz search_plan jeśli obecny
        if (!empty($toolCall->arguments['search_plan'])) {
            $diag['search_plan'] = $toolCall->arguments['search_plan'];
        }

        // Dołącz pełny search_debug z ProductSearch (kandydaci, MySQL, odfiltrowane)
        if ($toolCall->name === 'search_products' && !empty($result['search_debug'])) {
            $diag['search_debug'] = $result['search_debug'];
        }

        return $diag;
    }

    /**
     * Przycina historię do ostatnich MAX_HISTORY_MESSAGES wiadomości.
     * Upewnia się, że historia nie zaczyna się od tool_result ani assistant z tool_calls.
     */
    private function trimHistory(array $history): array
    {
        if (count($history) <= self::MAX_HISTORY_MESSAGES) {
            return $history;
        }

        $trimmed = array_slice($history, -self::MAX_HISTORY_MESSAGES);

        // Upewnij się że zaczynamy od wiadomości user (nie tool_result/assistant)
        while (!empty($trimmed) && $trimmed[0]['role'] !== 'user') {
            array_shift($trimmed);
        }

        return array_values($trimmed);
    }

    /**
     * Wykonuje narzędzie, obsługuje błędy.
     */
    private function executeTool(string $name, array $arguments): array
    {
        try {
            if (!$this->toolRegistry->has($name)) {
                return ['error' => "Nieznane narzędzie: {$name}"];
            }

            return $this->toolRegistry->get($name)->execute($arguments);
        } catch (\Throwable $e) {
            return ['error' => "Błąd narzędzia {$name}: {$e->getMessage()}"];
        }
    }
}
