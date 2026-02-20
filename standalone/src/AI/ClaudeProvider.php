<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Config;
use DiveChat\Enum\AIModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Provider Anthropic Claude API.
 *
 * Konwertuje ujednolicony format wiadomości na natywny format Anthropic:
 * - system osobno (nie w messages)
 * - tool_use blocks w odpowiedzi asystenta
 * - tool_result jako content block w wiadomości user
 */
final class ClaudeProvider implements AIProviderInterface
{
    private readonly Client $http;
    private readonly string $apiKey;
    private readonly string $model;
    private readonly float $temperature;
    private readonly int $maxTokens;

    public function __construct()
    {
        $this->apiKey = Config::getRequired('ANTHROPIC_API_KEY');
        $this->model = Config::get('ANTHROPIC_MODEL', 'claude-sonnet-4-20250514');
        $this->temperature = (float) Config::get('CLAUDE_TEMPERATURE', '0.6');
        $this->maxTokens = (int) Config::get('AI_MAX_TOKENS', '4096');
        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com/',
            'timeout' => 30,
        ]);
    }

    public function chat(array $messages, array $tools = [], array $options = []): AIResponse
    {
        // Wydziel system prompt i konwertuj wiadomości
        $system = '';
        $claudeMessages = [];

        foreach ($messages as $msg) {
            match ($msg['role']) {
                'system' => $system = $msg['content'],
                'user' => $claudeMessages[] = [
                    'role' => 'user',
                    'content' => $msg['content'],
                ],
                'assistant' => $claudeMessages[] = $this->formatAssistantMessage($msg),
                'tool_result' => $this->appendToolResult($claudeMessages, $msg),
                default => null,
            };
        }

        $model = $options['model_override'] ?? $this->model;

        $body = [
            'model' => $model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'messages' => $claudeMessages,
        ];

        // Extended thinking tylko dla modeli eskalacyjnych (np. claude-opus-4-6)
        if (!empty($options['effort']) && is_int($options['effort'])) {
            $aiModel = AIModel::tryFrom($model);
            if ($aiModel !== null && $aiModel->supportsEffort()) {
                $body['thinking'] = [
                    'type' => 'enabled',
                    'budget_tokens' => $options['effort'],
                ];
                // Extended thinking wymaga wyższego max_tokens
                $body['max_tokens'] = max($this->maxTokens, $options['effort'] + 4096);
                // Temperature musi być 1 z extended thinking
                unset($body['temperature']);
            }
        }

        if ($system !== '') {
            $body['system'] = $system;
        }

        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        $options = [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => $body,
        ];

        $response = $this->requestWithRetry('POST', 'v1/messages', $options);

        $data = json_decode($response->getBody()->getContents(), true);
        return $this->parseResponse($data);
    }

    /**
     * Konwertuje ujednolicony format narzędzi na natywny Anthropic.
     */
    private function formatTools(array $tools): array
    {
        return array_map(fn(array $tool) => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'input_schema' => $tool['parameters'],
        ], $tools);
    }

    /**
     * Formatuje wiadomość asystenta z tool_calls na format Claude.
     */
    private function formatAssistantMessage(array $msg): array
    {
        $content = [];

        if (!empty($msg['content'])) {
            $content[] = ['type' => 'text', 'text' => $msg['content']];
        }

        foreach ($msg['tool_calls'] ?? [] as $tc) {
            $content[] = [
                'type' => 'tool_use',
                'id' => $tc->id,
                'name' => $tc->name,
                'input' => (object) $tc->arguments,
            ];
        }

        return [
            'role' => 'assistant',
            'content' => $content ?: $msg['content'],
        ];
    }

    /**
     * Grupuje kolejne tool_result w jedną wiadomość user z wieloma content blocks.
     * Claude API wymaga aby wyniki narzędzi z jednego turnu były w jednym user message.
     */
    private function appendToolResult(array &$messages, array $msg): void
    {
        $block = [
            'type' => 'tool_result',
            'tool_use_id' => $msg['tool_call_id'],
            'content' => $msg['content'],
        ];

        $last = count($messages) - 1;
        if ($last >= 0
            && $messages[$last]['role'] === 'user'
            && is_array($messages[$last]['content'])
            && ($messages[$last]['content'][0]['type'] ?? '') === 'tool_result'
        ) {
            $messages[$last]['content'][] = $block;
        } else {
            $messages[] = [
                'role' => 'user',
                'content' => [$block],
            ];
        }
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
     * Parsuje odpowiedź Claude na ujednolicony AIResponse.
     */
    private function parseResponse(array $data): AIResponse
    {
        $content = null;
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            match ($block['type']) {
                'text' => $content = $block['text'],
                'tool_use' => $toolCalls[] = new ToolCall(
                    id: $block['id'],
                    name: $block['name'],
                    arguments: $block['input'] ?? [],
                ),
                default => null,
            };
        }

        return new AIResponse(
            content: $content,
            toolCalls: $toolCalls,
            usage: [
                'input_tokens' => $data['usage']['input_tokens'] ?? 0,
                'output_tokens' => $data['usage']['output_tokens'] ?? 0,
            ],
        );
    }
}
