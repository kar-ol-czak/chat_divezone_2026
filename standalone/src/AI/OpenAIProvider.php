<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Config;
use DiveChat\Enum\AIModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Provider OpenAI API.
 *
 * Konwertuje ujednolicony format wiadomości na natywny format OpenAI.
 * Reasoning effort: settings.reasoning_effort (UI string) → wprost
 * jako `reasoning_effort` w body (modele wspierające).
 *
 * GPT-4.1 (single model with `supports_temperature=true`) → wysyłane temperature.
 * Modele rozumujące → bez temperature.
 *
 * Cache (T-022, decyzja 101b): OpenAI eksponuje cached prefix przez
 * `usage.prompt_tokens_details.cached_tokens` (od końca 2024, prompt caching
 * automatyczny dla prefiksów >= 1024 tok). UWAGA na konwencję: u OpenAI
 * `prompt_tokens` ZAWIERA cached (TOTAL), inaczej niż u Anthropic (gdzie
 * `input_tokens` jest już non-cached). Tu wystawiamy w ujednoliconym formacie
 * `input_tokens = prompt_tokens - cached_tokens`, żeby PricingService liczyło
 * non-cached × input_price + cached × cache_read_price bez podwójnego liczenia.
 */
final class OpenAIProvider implements AIProviderInterface
{
    private readonly Client $http;
    private readonly string $apiKey;
    private readonly string $model;
    private readonly int $maxTokens;

    public function __construct()
    {
        $this->apiKey = Config::getRequired('OPENAI_API_KEY');
        $this->model = Config::get('OPENAI_CHAT_MODEL', 'gpt-4.1');
        $this->maxTokens = (int) Config::get('AI_MAX_TOKENS', '4096');
        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/',
            'timeout' => 30,
        ]);
    }

    public function chat(array $messages, array $tools = [], array $options = []): AIResponse
    {
        $openaiMessages = [];

        foreach ($messages as $msg) {
            match ($msg['role']) {
                'system' => $openaiMessages[] = [
                    'role' => 'system',
                    'content' => $msg['content'],
                ],
                'user' => $openaiMessages[] = [
                    'role' => 'user',
                    'content' => $msg['content'],
                ],
                'assistant' => $openaiMessages[] = $this->formatAssistantMessage($msg),
                'tool_result' => $openaiMessages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $msg['tool_call_id'],
                    'content' => $msg['content'],
                ],
                default => null,
            };
        }

        $model = $options['model_override'] ?? $this->model;
        $aiModel = AIModel::tryFrom($model);

        // CHAT-T-041: max_tokens preferuje override z divechat_settings (przez $options),
        // fallback na konstruktorową wartość z .env (AI_MAX_TOKENS / 4096).
        $maxCompletion = isset($options['max_tokens']) && (int) $options['max_tokens'] > 0
            ? (int) $options['max_tokens']
            : $this->maxTokens;

        $body = [
            'model' => $model,
            'messages' => $openaiMessages,
            'max_completion_tokens' => $maxCompletion,
        ];

        // Temperature tylko dla modeli które ją obsługują (GPT-4.1).
        if ($aiModel === null || $aiModel->supportsTemperature()) {
            if (isset($options['temperature'])) {
                $body['temperature'] = (float) $options['temperature'];
            } else {
                $body['temperature'] = (float) Config::get('OPENAI_CHAT_TEMPERATURE', '0.4');
            }
        }

        // Reasoning effort tylko dla modeli wspierających (wszystko poza GPT-4.1).
        if (!empty($options['effort']) && $aiModel !== null && $aiModel->supportsReasoningEffort()) {
            if (is_string($options['effort'])) {
                $mapped = $aiModel->mapEffortToProviderValue($options['effort']);
                if (is_string($mapped)) {
                    $body['reasoning_effort'] = $mapped;
                }
            }
        }

        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        $requestOptions = [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ];

        $response = $this->requestWithRetry('POST', 'v1/chat/completions', $requestOptions);

        $data = json_decode($response->getBody()->getContents(), true);
        return $this->parseResponse($data);
    }

    /**
     * Konwertuje ujednolicony format narzędzi na format OpenAI functions.
     */
    private function formatTools(array $tools): array
    {
        return array_map(fn(array $tool) => [
            'type' => 'function',
            'function' => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'parameters' => $tool['parameters'],
            ],
        ], $tools);
    }

    /**
     * Formatuje wiadomość asystenta z tool_calls na format OpenAI.
     */
    private function formatAssistantMessage(array $msg): array
    {
        $result = [
            'role' => 'assistant',
            'content' => $msg['content'] ?? null,
        ];

        if (!empty($msg['tool_calls'])) {
            $result['tool_calls'] = array_map(fn(ToolCall $tc) => [
                'id' => $tc->id,
                'type' => 'function',
                'function' => [
                    'name' => $tc->name,
                    'arguments' => json_encode($tc->arguments),
                ],
            ], $msg['tool_calls']);
        }

        return $result;
    }

    /**
     * Retry: 1 ponowienie po 2s przy HTTP 429 lub 5xx.
     */
    private function requestWithRetry(string $method, string $uri, array $options): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->http->request($method, $uri, $options);
        } catch (ServerException $e) {
            sleep(2);
            return $this->http->request($method, $uri, $options);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 429) {
                sleep(2);
                return $this->http->request($method, $uri, $options);
            }
            throw $e;
        }
    }

    /**
     * Parsuje odpowiedź OpenAI na ujednolicony AIResponse.
     */
    private function parseResponse(array $data): AIResponse
    {
        $choice = $data['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $content = $message['content'] ?? null;
        $toolCalls = [];

        foreach ($message['tool_calls'] ?? [] as $tc) {
            $toolCalls[] = new ToolCall(
                id: $tc['id'],
                name: $tc['function']['name'],
                arguments: json_decode($tc['function']['arguments'] ?? '{}', true) ?: [],
            );
        }

        $usage = $data['usage'] ?? [];
        $promptTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $cachedTokens = (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0);
        // U OpenAI prompt_tokens = TOTAL (zawiera cached). Wystawiamy w ujednoliconym
        // formacie input_tokens = część NIE-cached (zgodnie z konwencją Claude).
        // max(0, ...) na wypadek dziwactw API (cached > prompt nigdy nie powinno wystąpić,
        // ale lepiej nie wypluwać ujemnej liczby tokenów do logera).
        $nonCachedInput = max(0, $promptTokens - $cachedTokens);

        return new AIResponse(
            content: $content,
            toolCalls: $toolCalls,
            usage: [
                'input_tokens' => $nonCachedInput,
                'output_tokens' => (int) ($usage['completion_tokens'] ?? 0),
                'cache_read_tokens' => $cachedTokens,
                // OpenAI: cache creation jest automatyczne i bezpłatne (brak osobnego pola w usage).
                'cache_creation_tokens' => 0,
            ],
        );
    }
}
