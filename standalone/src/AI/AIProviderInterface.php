<?php

declare(strict_types=1);

namespace DiveChat\AI;

/**
 * Interfejs providera AI.
 *
 * Format wiadomości (ujednolicony):
 * - ['role' => 'system', 'content' => '...']
 * - ['role' => 'user', 'content' => '...']
 * - ['role' => 'assistant', 'content' => '...', 'tool_calls' => ToolCall[]]
 * - ['role' => 'tool_result', 'tool_call_id' => '...', 'name' => '...', 'content' => '...']
 *
 * Format tools (ujednolicony, JSON Schema):
 * [['name' => '...', 'description' => '...', 'parameters' => [...]], ...]
 *
 * Provider konwertuje na natywny format wewnętrznie.
 */
interface AIProviderInterface
{
    /**
     * Wysyła wiadomości do AI i zwraca odpowiedź.
     *
     * @param array $messages Lista wiadomości (ujednolicony format)
     * @param array $tools Lista definicji narzędzi
     */
    public function chat(array $messages, array $tools = []): AIResponse;

    /**
     * Generuje embedding dla tekstu.
     *
     * @return float[] Wektor 1536-wymiarowy
     */
    public function getEmbedding(string $text): array;
}
