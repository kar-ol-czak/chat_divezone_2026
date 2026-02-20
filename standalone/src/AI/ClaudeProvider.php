<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Config;
use GuzzleHttp\Client;

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

    public function chat(array $messages, array $tools = []): AIResponse
    {
        // Wydziel system prompt z listy wiadomości
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
                'tool_result' => $claudeMessages[] = $this->formatToolResult($msg),
                default => null,
            };
        }

        $body = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'messages' => $claudeMessages,
        ];

        if ($system !== '') {
            $body['system'] = $system;
        }

        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        $response = $this->http->post('v1/messages', [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => $body,
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $this->parseResponse($data);
    }

    public function getEmbedding(string $text): array
    {
        // Claude nie ma embeddingów - delegujemy do OpenAI
        $openaiKey = Config::getRequired('OPENAI_API_KEY');
        $http = new Client(['timeout' => 15]);

        $response = $http->post('https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => "Bearer {$openaiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'text-embedding-3-large',
                'input' => $text,
                'dimensions' => 1536,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['data'][0]['embedding'];
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
     * Formatuje wynik narzędzia na format Claude (user message z tool_result block).
     */
    private function formatToolResult(array $msg): array
    {
        return [
            'role' => 'user',
            'content' => [[
                'type' => 'tool_result',
                'tool_use_id' => $msg['tool_call_id'],
                'content' => $msg['content'],
            ]],
        ];
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
