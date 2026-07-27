<?php

declare(strict_types=1);

namespace DiveChat\AI;

/**
 * Interfejs providera AI (chat only).
 *
 * Format wiadomości (ujednolicony):
 * - ['role' => 'system', 'content' => '...', 'cacheable' => bool]
 *   CHAT-T-176 (ADR-138): bloków system może być kilka, w kolejności. Opcjonalne
 *   `cacheable` (domyślnie true) mówi providerowi, gdzie postawić breakpoint cache:
 *   ClaudeProvider stawia go na OSTATNIM bloku cacheable, więc bloki zmienne
 *   (data, kontekst tury) trzeba oznaczyć `false` i dać NA KOŃCU.
 *   Providerzy bez prompt cachingu (OpenAI) po prostu wysyłają bloki po kolei.
 * - ['role' => 'user', 'content' => '...']
 * - ['role' => 'assistant', 'content' => '...', 'tool_calls' => ToolCall[]]
 * - ['role' => 'tool_result', 'tool_call_id' => '...', 'name' => '...', 'content' => '...']
 *
 * Format tools (ujednolicony, JSON Schema):
 * [['name' => '...', 'description' => '...', 'parameters' => [...]], ...]
 *
 * Provider konwertuje na natywny format wewnętrznie.
 * Embeddingi obsługuje osobny EmbeddingService.
 */
interface AIProviderInterface
{
    /**
     * Wysyła wiadomości do AI i zwraca odpowiedź.
     *
     * @param array $messages Lista wiadomości (ujednolicony format)
     * @param array $tools Lista definicji narzędzi
     * @param array $options Opcje: effort (string|int), model_override (string)
     */
    public function chat(array $messages, array $tools = [], array $options = []): AIResponse;
}
