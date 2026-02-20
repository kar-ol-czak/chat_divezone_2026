<?php

declare(strict_types=1);

namespace DiveChat\Chat;

use DiveChat\AI\AIProviderInterface;
use DiveChat\AI\AIResponse;
use DiveChat\AI\ToolCall;
use DiveChat\Tools\ToolRegistry;

/**
 * Orkiestrator czatu.
 * Obsługuje tool loop: AI -> narzędzia -> AI -> ... -> odpowiedź.
 */
final class ChatService
{
    private const MAX_TOOL_ITERATIONS = 5;
    private const MAX_HISTORY_MESSAGES = 10;

    public function __construct(
        private readonly AIProviderInterface $aiProvider,
        private readonly ToolRegistry $toolRegistry,
        private readonly ConversationStore $conversationStore,
    ) {}

    /**
     * Główna metoda - obsługuje wiadomość klienta.
     *
     * @return array{response: string, session_id: string, tools_used: string[], products: array, usage: array}
     */
    public function handle(string $sessionId, string $message, ?int $customerId): array
    {
        // 1. Wczytaj lub utwórz sesję
        $history = $this->conversationStore->startOrResume($sessionId, $customerId);

        // 2. Zbuduj listę wiadomości
        $messages = [
            ['role' => 'system', 'content' => SystemPrompt::build()],
        ];

        // Rehydratuj ToolCall objects z historii (JSON zwraca tablice)
        foreach ($history as &$msg) {
            if (!empty($msg['tool_calls']) && is_array($msg['tool_calls'])) {
                $msg['tool_calls'] = array_map(
                    fn(array $tc) => new ToolCall($tc['id'], $tc['name'], $tc['arguments'] ?? []),
                    $msg['tool_calls'],
                );
            }
        }
        unset($msg);

        // Przytnij historię do ostatnich N wiadomości
        $history = $this->trimHistory($history);

        // Dodaj historię (bez systemu - jest na początku)
        foreach ($history as $msg) {
            if ($msg['role'] !== 'system') {
                $messages[] = $msg;
            }
        }

        // Dodaj nową wiadomość użytkownika
        $messages[] = ['role' => 'user', 'content' => $message];

        // 3. Przygotuj definicje narzędzi
        $toolDefinitions = $this->toolRegistry->getToolDefinitions();

        // 4. Tool loop
        $toolsUsed = [];
        $products = [];
        $totalUsage = ['input_tokens' => 0, 'output_tokens' => 0];

        for ($i = 0; $i < self::MAX_TOOL_ITERATIONS; $i++) {
            $response = $this->aiProvider->chat($messages, $toolDefinitions);
            $totalUsage['input_tokens'] += $response->usage['input_tokens'];
            $totalUsage['output_tokens'] += $response->usage['output_tokens'];

            // Jeśli brak tool calls - mamy finalną odpowiedź
            if (!$response->hasToolCalls()) {
                break;
            }

            // Dodaj odpowiedź asystenta z tool calls do historii
            $messages[] = [
                'role' => 'assistant',
                'content' => $response->content,
                'tool_calls' => $response->toolCalls,
            ];

            // Wykonaj każde narzędzie
            foreach ($response->toolCalls as $toolCall) {
                $toolsUsed[] = $toolCall->name;

                $result = $this->executeTool($toolCall->name, $toolCall->arguments);

                // Zbieraj produkty z wyników search_products
                if ($toolCall->name === 'search_products' && !empty($result['products'])) {
                    $products = array_merge($products, $result['products']);
                }

                // Dodaj wynik narzędzia do wiadomości
                $messages[] = [
                    'role' => 'tool_result',
                    'tool_call_id' => $toolCall->id,
                    'name' => $toolCall->name,
                    'content' => json_encode($result, JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        // Loguj wyczerpanie pętli narzędzi
        if ($response->hasToolCalls()) {
            error_log("[DiveChat] Tool loop wyczerpany po " . self::MAX_TOOL_ITERATIONS
                . " iteracjach, sesja: {$sessionId}");
        }

        $finalContent = $response->content ?? 'Przepraszam, nie udało się wygenerować odpowiedzi.';

        // 5. Zapisz historię (bez system prompta)
        $historyToSave = array_values(array_filter(
            $messages,
            fn(array $m) => $m['role'] !== 'system',
        ));

        // Serializuj tool_calls do zapisu (ToolCall -> array)
        $historyToSave = array_map(function (array $m) {
            if (!empty($m['tool_calls'])) {
                $m['tool_calls'] = array_map(fn($tc) => [
                    'id' => $tc->id,
                    'name' => $tc->name,
                    'arguments' => $tc->arguments,
                ], $m['tool_calls']);
            }
            return $m;
        }, $historyToSave);

        // Dodaj odpowiedź asystenta na końcu (jeśli nie ma tool calls)
        if (!$response->hasToolCalls()) {
            $historyToSave[] = ['role' => 'assistant', 'content' => $finalContent];
        }

        $this->conversationStore->save($sessionId, $historyToSave, array_unique($toolsUsed), $totalUsage);

        return [
            'response' => $finalContent,
            'session_id' => $sessionId,
            'tools_used' => array_values(array_unique($toolsUsed)),
            'products' => $products,
            'usage' => $totalUsage,
        ];
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
