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
 * - system osobno (nie w messages), z `cache_control: ephemeral` żeby skorzystać
 *   z prompt caching
 * - tool_use blocks w odpowiedzi asystenta
 * - tool_result jako content block w wiadomości user
 *
 * Reasoning effort: settings.reasoning_effort (UI string) → mapowane przez
 * AIModel::mapEffortToProviderValue() na int budget_tokens.
 */
final class ClaudeProvider implements AIProviderInterface
{
    private readonly Client $http;
    private readonly string $apiKey;
    private readonly string $model;
    private readonly int $maxTokens;

    public function __construct()
    {
        $this->apiKey = Config::getRequired('ANTHROPIC_API_KEY');
        $this->model = Config::get('ANTHROPIC_MODEL', 'claude-sonnet-4-6');
        $this->maxTokens = (int) Config::get('AI_MAX_TOKENS', '4096');
        $this->http = new Client([
            'base_uri' => 'https://api.anthropic.com/',
            'timeout' => 30,
        ]);
    }

    public function chat(array $messages, array $tools = [], array $options = []): AIResponse
    {
        // Wydziel system prompt i konwertuj wiadomości.
        // CHAT-T-176 (ADR-138): bloków systemowych może być kilka. Zachowujemy ich
        // kolejność i flagę `cacheable` — cache_control trafi TYLKO na ostatni
        // cache'owalny blok (patrz buildSystemBlocks()).
        $systemParts = [];
        $claudeMessages = [];

        foreach ($messages as $msg) {
            match ($msg['role']) {
                'system' => $systemParts[] = [
                    'text' => (string) $msg['content'],
                    'cacheable' => $msg['cacheable'] ?? true,
                ],
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
        $aiModel = AIModel::tryFrom($model);

        // CHAT-T-041: max_tokens preferuje override z divechat_settings (przez $options),
        // fallback na konstruktorową wartość z .env. Dla modeli z thinking finalne
        // max_tokens jest jeszcze podbijane poniżej, by zmieścić budget_tokens.
        $effectiveMax = isset($options['max_tokens']) && (int) $options['max_tokens'] > 0
            ? (int) $options['max_tokens']
            : $this->maxTokens;

        $body = [
            'model' => $model,
            'max_tokens' => $effectiveMax,
            'messages' => $claudeMessages,
        ];

        // Reasoning effort → budget_tokens dla modeli wspierających thinking.
        // options['effort'] przychodzi z ChatService jako string (minimal/low/medium/high)
        // lub int (legacy budget_tokens) – rozpoznajemy oba.
        $budgetTokens = null;
        if (!empty($options['effort'])) {
            if (is_int($options['effort'])) {
                $budgetTokens = $options['effort'];
            } elseif (is_string($options['effort']) && $aiModel !== null) {
                $mapped = $aiModel->mapEffortToProviderValue($options['effort']);
                if (is_int($mapped)) {
                    $budgetTokens = $mapped;
                }
            }
        }

        if ($budgetTokens !== null && $aiModel !== null && $aiModel->supportsReasoningEffort()) {
            $body['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $budgetTokens,
            ];
            // Extended thinking wymaga max_tokens > budget_tokens.
            $body['max_tokens'] = max($effectiveMax, $budgetTokens + 4096);
            // Z thinking nie wysyłamy temperature.
        } elseif ($aiModel !== null && $aiModel->supportsTemperature() && isset($options['temperature'])) {
            $body['temperature'] = (float) $options['temperature'];
        }

        // System prompt z cache_control → prompt caching Anthropic.
        $systemBlocks = $this->buildSystemBlocks($systemParts);
        if ($systemBlocks !== []) {
            $body['system'] = $systemBlocks;
        }

        if (!empty($tools)) {
            $body['tools'] = $this->formatTools($tools);
        }

        $requestOptions = [
            'headers' => [
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ],
            'json' => $body,
        ];

        $response = $this->requestWithRetry('POST', 'v1/messages', $requestOptions);

        $data = json_decode($response->getBody()->getContents(), true);
        return $this->parseResponse($data);
    }

    /**
     * CHAT-T-176 (ADR-138): składa bloki `system` z pojedynczym breakpointem cache.
     *
     * `cache_control` ląduje na OSTATNIM bloku oznaczonym jako cache'owalny — cache
     * Anthropic obejmuje wtedy cały prefiks (narzędzia + bloki systemowe do tego
     * miejsca włącznie), a wszystko za breakpointem (data, kontekst chipów) jest
     * liczone normalnie i może się zmieniać bez unieważniania prefiksu.
     *
     * Puste bloki pomijamy — Anthropic odrzuca pusty blok tekstowy (HTTP 400).
     * Gdy żaden blok nie jest cache'owalny, wysyłamy je bez cache_control (nic się
     * nie psuje, tracimy tylko cache — zachowanie bezpieczne przy błędnym wywołaniu).
     *
     * @param list<array{text: string, cacheable: bool}> $parts
     * @return list<array<string, mixed>>
     */
    private function buildSystemBlocks(array $parts): array
    {
        $parts = array_values(array_filter($parts, static fn(array $p) => $p['text'] !== ''));
        if ($parts === []) {
            return [];
        }

        $breakpoint = null;
        foreach ($parts as $i => $part) {
            if ($part['cacheable']) {
                $breakpoint = $i;
            }
        }

        $blocks = [];
        foreach ($parts as $i => $part) {
            $block = ['type' => 'text', 'text' => $part['text']];
            if ($i === $breakpoint) {
                $block['cache_control'] = ['type' => 'ephemeral'];
            }
            $blocks[] = $block;
        }

        return $blocks;
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

        $usage = $data['usage'] ?? [];

        return new AIResponse(
            content: $content,
            toolCalls: $toolCalls,
            usage: [
                'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
                'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
                'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
                'cache_creation_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            ],
        );
    }
}
