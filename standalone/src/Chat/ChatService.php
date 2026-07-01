<?php

declare(strict_types=1);

namespace DiveChat\Chat;

use DiveChat\AI\AIProviderFactory;
use DiveChat\AI\AIResponse;
use DiveChat\AI\ConversationCost;
use DiveChat\AI\ToolCall;
use DiveChat\AI\UsageLogger;
use DiveChat\Config;
use DiveChat\Enum\AIModel;
use DiveChat\Tools\ToolRegistry;
use DiveChat\Usage\DbHealthAlert;

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
        private readonly AIProviderFactory $providerFactory,
        private readonly ToolRegistry $toolRegistry,
        private readonly ConversationStore $conversationStore,
        private readonly SettingsStore $settingsStore,
        private readonly UsageLogger $usageLogger,
        private readonly DbHealthAlert $dbHealthAlert,
    ) {}

    /**
     * Główna metoda - obsługuje wiadomość klienta.
     *
     * @param ?callable(string): void $onStatus Callback emitujący status SSE
     * @param ?string $nudgeSid CHAT-T-085 / ADR-091: opcjonalna atrybucja
     *   ekspozycji nudge. Sid z ekspozycji nudge, OSOBNY od session_id (który
     *   może być zmieniony przez restore na froncie). Zapisywany w bazie TYLKO
     *   przy INSERT nowej rozmowy — przy resume istniejącej nie nadpisujemy.
     * @param ?string $chipContext CHAT-T-088e / ADR-097 (decyzja 65b): kontekst
     *   ścieżki chipów ("Dobór rozmiaru › Kaptur" + opcjonalny ai_prompt liścia)
     *   składany przez front z chipStack. Wstrzykiwany jako instrukcja systemowa
     *   TEJ TURY (prefiks do promptu), NIE jako wiadomość user — historia zostaje
     *   czysta (user message = realna treść klienta). Ulotny: nie zapisywany w bazie.
     * @param ?list<array{node_key: string, label: string, level: int}> $chipPath
     *   CHAT-T-122 / ADR-110 (8b/9a): strukturalna ścieżka chipów do UTRWALENIA
     *   w kolumnie jsonb. ROZŁĄCZNA z $chipContext (który jest ulotny dla LLM):
     *   zapisywana RAZ na rozmowę (przy INSERT lub gdy chip_path rozmowy NULL),
     *   idempotentna na kolejnych turach. null = wolne pisanie bez chipów.
     * @return array{response: string, session_id: string, tools_used: string[], products: array, usage: array, diagnostics: array}
     */
    public function handle(string $sessionId, string $message, ?int $customerId, ?callable $onStatus = null, ?string $nudgeSid = null, ?string $chipContext = null, ?array $chipPath = null): array
    {
        $startTime = microtime(true);
        $emit = $onStatus ?? static function (string $text): void {};

        $emit('Analizuję Twoje pytanie...');

        // Wczytaj ustawienia z bazy (fallback na .env/defaults)
        $settings = $this->loadSettings();

        // 1. Wczytaj lub utwórz sesję (PEŁNA historia + id rekordu).
        // CHAT-T-082: startOrResume moze podmienic sessionId przy ownership
        // mismatch (sekcja 3 spec) — przyjmujemy EFEKTYWNY id z wyniku.
        // CHAT-T-085: nudge_sid przekazywany dalej; zapis w bazie tylko przy
        // INSERT nowej rozmowy (resume istniejącej nie nadpisuje atrybucji).
        // CHAT-T-122 (ADR-110): chip_path utrwalany raz na rozmowę (INSERT lub gdy
        // dotąd NULL) — idempotentny w startOrResume (warunek chip_path IS NULL).
        $session = $this->conversationStore->startOrResume($sessionId, $customerId, $nudgeSid, $chipPath);
        $conversationId = $session['id'];
        $fullHistory = $session['history'];
        $sessionId = $session['session_id'];

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

        // 3. Zbuduj listę wiadomości dla LLM z PRZYCIĘTEJ historii.
        // CHAT-T-088e (decyzja 65b): kontekst ścieżki chipów doklejamy do system
        // promptu TEJ TURY (instrukcja systemowa), NIE jako osobna wiadomość user.
        // Dzięki temu historia (divechat_messages + JSONB) zostaje czysta, a system[0]
        // i tak jest filtrowany przy zapisie. Działa dla obu providerów (Claude bierze
        // system osobno; OpenAI jako pierwszą wiadomość role=system).
        $systemPrompt = SystemPrompt::build($settings['emoji_enabled']);
        if ($chipContext !== null && $chipContext !== '') {
            $systemPrompt .= self::buildChipContextBlock($chipContext);
        }
        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        foreach ($trimmedHistory as $msg) {
            if ($msg['role'] !== 'system') {
                $messages[] = $msg;
            }
        }

        // Dodaj nową wiadomość użytkownika
        $messages[] = ['role' => 'user', 'content' => $message];

        // Dual write: zapisz user message do divechat_messages (faza 1 dashboardu).
        try {
            $this->conversationStore->appendMessage($conversationId, 'user', $message);
        } catch (\Throwable $e) {
            error_log("[DiveChat] appendMessage(user) failed: " . $e->getMessage());
        }

        // 3. Przygotuj definicje narzędzi
        $toolDefinitions = $this->toolRegistry->getToolDefinitions();

        // 4. Tool loop z diagnostyką
        $toolsUsed = [];
        $products = [];
        $totalUsage = ['input_tokens' => 0, 'output_tokens' => 0];
        $searchDiagnostics = [];
        $knowledgeGap = false;
        $timings = ['ai_ms' => 0.0, 'tool_ms' => 0.0, 'embedding_ms' => 0.0];

        // CHAT-T-068 (184a): provider wynika z modelu wybranego w panelu PS,
        // NIE z .env. Panel = źródło prawdy. .env jest fallbackiem tylko gdy panel
        // pusty (lub model spoza enuma — wtedy AIProviderFactory loguje warning).
        $panelModelId = !empty($settings['model_primary']) ? $settings['model_primary'] : null;
        $primaryModel = $panelModelId !== null ? AIModel::tryFrom($panelModelId) : null;

        if ($primaryModel !== null) {
            $currentProvider = $primaryModel->provider();
        } else {
            // Panel pusty albo model spoza enuma → fallback .env (zachowanie sprzed CHAT-T-068).
            $currentProvider = Config::get(
                'AI_PROVIDER',
                str_starts_with(Config::get('ANTHROPIC_MODEL', ''), 'claude') ? 'claude' : 'openai',
            );
        }

        // Instancja providera dopasowana do modelu z panelu (lub fallback .env).
        // Bez tego request np. dla Claude poszedłby do API OpenAI (rozjazd warstwy 1).
        $aiProvider = $this->providerFactory->createForModel($panelModelId);

        // Opcje AI: model override + temperature/effort wg flag wybranego modelu.
        // Po naprawie warstwy 2 $currentProvider zawsze zgadza się z $primaryModel->provider(),
        // więc model_override przejdzie zawsze gdy panel ustawił rozpoznawalny model.
        $aiOptions = [];
        if ($primaryModel !== null && $primaryModel->provider() === $currentProvider) {
            $aiOptions['model_override'] = $primaryModel->value;
        }

        if ($primaryModel !== null) {
            if ($primaryModel->supportsTemperature() && isset($settings['temperature'])) {
                $aiOptions['temperature'] = (float) $settings['temperature'];
            }
            if ($primaryModel->supportsReasoningEffort() && !empty($settings['reasoning_effort'])) {
                $aiOptions['effort'] = (string) $settings['reasoning_effort'];
            }
        }

        // CHAT-T-041: max_tokens z divechat_settings (override fallbacka z .env).
        // Wcześniej DB-wa wartość była orphaned; teraz providery preferują settings.
        if (!empty($settings['max_tokens'])) {
            $aiOptions['max_tokens'] = (int) $settings['max_tokens'];
        }

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            // Status przed wywołaniem AI
            if ($i > 0) {
                $emit('Przygotowuję odpowiedź...');
            }

            $aiStart = microtime(true);
            $response = $aiProvider->chat($messages, $toolDefinitions, $aiOptions);
            $iterLatencyMs = (int) ((microtime(true) - $aiStart) * 1000);
            $timings['ai_ms'] += $iterLatencyMs;

            $totalUsage['input_tokens'] += $response->usage['input_tokens'];
            $totalUsage['output_tokens'] += $response->usage['output_tokens'];

            // Znormalizuj tool_calls dla telemetrii i divechat_messages.
            $toolCallsNormalized = $response->hasToolCalls()
                ? array_map(
                    fn(ToolCall $tc) => [
                        'id' => $tc->id,
                        'name' => $tc->name,
                        'arguments' => $tc->arguments,
                    ],
                    $response->toolCalls,
                )
                : null;

            // Dual write: zapisz assistant message do divechat_messages (z tool_calls jeśli były).
            $assistantMessageId = null;
            try {
                $assistantMessageId = $this->conversationStore->appendMessage(
                    $conversationId,
                    'assistant',
                    (string) ($response->content ?? ''),
                    $toolCallsNormalized,
                );
            } catch (\Throwable $e) {
                error_log("[DiveChat] appendMessage(assistant) failed: " . $e->getMessage());
            }

            // Loguj usage per wywołanie providera (audit trail w divechat_message_usage).
            $modelForLogging = $aiOptions['model_override']
                ?? ($currentProvider === 'claude'
                    ? Config::get('ANTHROPIC_MODEL', 'claude-sonnet-4-6')
                    : Config::get('OPENAI_CHAT_MODEL', 'gpt-4.1'));

            try {
                $this->usageLogger->logMessage(
                    conversationId: $conversationId,
                    messageId: $assistantMessageId,
                    modelId: $modelForLogging,
                    inputTokens: (int) $response->usage['input_tokens'],
                    outputTokens: (int) $response->usage['output_tokens'],
                    cacheReadTokens: (int) ($response->usage['cache_read_tokens'] ?? 0),
                    cacheCreationTokens: (int) ($response->usage['cache_creation_tokens'] ?? 0),
                    latencyMs: $iterLatencyMs,
                    toolCalls: $toolCallsNormalized,
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

                // CHAT-T-041: wyciągnij embedding_ms z search_debug (jeśli tool je raportuje).
                // Nie odejmujemy od tool_ms — embedding jest podzbiorem (informacyjny rozkład).
                if (!empty($result['search_debug']['embedding_ms'])) {
                    $timings['embedding_ms'] += (float) $result['search_debug']['embedding_ms'];
                }

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
                $toolResultJson = json_encode($resultForAI, JSON_UNESCAPED_UNICODE);
                $messages[] = [
                    'role' => 'tool_result',
                    'tool_call_id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'content' => $toolResultJson,
                ];

                // Dual write: tool result do divechat_messages (role='tool') – do podglądu w dashboardzie.
                try {
                    $this->conversationStore->appendMessage(
                        $conversationId,
                        'tool',
                        $toolResultJson,
                        [['tool_call_id' => $toolCall->id, 'name' => $toolCall->name]],
                    );
                } catch (\Throwable $e) {
                    error_log("[DiveChat] appendMessage(tool) failed: " . $e->getMessage());
                }
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
            'max_tokens' => isset($dbSettings['max_tokens']) ? (int) $dbSettings['max_tokens'] : null,
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
     * Buduje blok kontekstu ścieżki chipów doklejany do system promptu tej tury
     * (CHAT-T-088e / ADR-097, decyzja 65b). Front przekazuje gotowy string: ścieżkę
     * nawigacji ("Dobór rozmiaru › Kaptur") + opcjonalny ai_prompt liścia.
     *
     * Framing jest celowo cienki — treść steruje front. Instruujemy model, by
     * potraktował to jako intencję klienta i NIE cytował dosłownie (to kontekst
     * nawigacyjny, nie wiadomość użytkownika).
     */
    private static function buildChipContextBlock(string $chipContext): string
    {
        return "\n\n--- KONTEKST TEJ TURY (nawigacja chipami) ---\n"
            . "Klient trafił tutaj przez chipy: {$chipContext}\n"
            . "Potraktuj to jako jego intencję i odpowiedz zgodnie z nią. "
            . "To kontekst nawigacyjny — nie cytuj go dosłownie.";
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
     *
     * CHAT-T-079 (ADR-088): w catch wolamy DbHealthAlert::maybeAlert — gdy blad
     * to awaria polaczenia DB (MySQL 1045/2002/..., PG klasa 08 / 57P0[13]),
     * idzie alert e-mail (dedup 30 min/baza) + log [DB-DOWN]. Best-effort: alert
     * NIGDY nie rzuca, NIE zmienia zachowania czatu — dalej zwracamy
     * ['error' => ...] do tool_result tak jak przed CHAT-T-079.
     */
    private function executeTool(string $name, array $arguments): array
    {
        try {
            if (!$this->toolRegistry->has($name)) {
                return ['error' => "Nieznane narzędzie: {$name}"];
            }

            return $this->toolRegistry->get($name)->execute($arguments);
        } catch (\Throwable $e) {
            try {
                $this->dbHealthAlert->maybeAlert($e, $name);
            } catch (\Throwable $ignore) {
                error_log('[ChatService] dbHealthAlert path failed: ' . $ignore->getMessage());
            }
            return ['error' => "Błąd narzędzia {$name}: {$e->getMessage()}"];
        }
    }
}
