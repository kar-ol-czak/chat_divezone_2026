<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Config;
use DiveChat\Enum\AIModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Provider OpenAI API (GPT-4o, GPT-4.1).
 *
 * Konwertuje ujednolicony format wiadomości na natywny format OpenAI:
 * - system jako wiadomość z role 'system'
 * - tool_calls w wiadomości assistant
 * - tool results jako wiadomość z role 'tool'
 */
final class OpenAIProvider implements AIProviderInterface
{
    private readonly Client $http;
    private readonly string $apiKey;
    private readonly string $model;
    private readonly float $temperature;
    private readonly int $maxTokens;

    public function __construct()
    {
        $this->apiKey = Config::getRequired('OPENAI_API_KEY');
        $this->model = Config::get('OPENAI_CHAT_MODEL', 'gpt-4.1');
        $this->temperature = (float) Config::get('OPENAI_CHAT_TEMPERATURE', '0.4');
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

        $body = [
            'model' => $model,
            'messages' => $openaiMessages,
            'max_completion_tokens' => $this->maxTokens,
        ];

        // Temperature tylko dla modeli które ją obsługują (nie reasoning)
        $aiModel = AIModel::tryFrom($model);
        if ($aiModel === null || $aiModel->supportsTemperature()) {
            $body['temperature'] = $this->temperature;
        }

        // Reasoning effort dla modeli eskalacyjnych (np. gpt-5.2)
        if (!empty($options['effort']) && is_string($options['effort'])) {
            $body['reasoning_effort'] = $options['effort'];
        }

        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        $options = [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => $body,
        ];

        $response = $this->requestWithRetry('POST', 'v1/chat/completions', $options);

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

        return new AIResponse(
            content: $content,
            toolCalls: $toolCalls,
            usage: [
                'input_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                'output_tokens' => $data['usage']['completion_tokens'] ?? 0,
            ],
        );
    }
}
