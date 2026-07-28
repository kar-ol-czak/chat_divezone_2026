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
 * AIModel::mapEffortToProviderValue() na int budget_tokens (modele sprzed adaptive)
 * albo na string effort w `output_config` (Sonnet 5+, CHAT-T-177 / ADR-139).
 */
final class ClaudeProvider implements AIProviderInterface
{
    /**
     * Poziom effortu użyty, gdy model wspiera adaptive thinking, a ustawienia nie
     * podają własnego (decyzja Karola Q32a — nigdy nie zostawiamy domyślnego `high`).
     */
    private const DEFAULT_ADAPTIVE_EFFORT = 'low';

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
        // max_tokens jest jeszcze podbijane w buildRequestBody(), by zmieścić myślenie.
        $effectiveMax = isset($options['max_tokens']) && (int) $options['max_tokens'] > 0
            ? (int) $options['max_tokens']
            : $this->maxTokens;

        $body = $this->buildRequestBody(
            $model,
            $aiModel,
            $effectiveMax,
            $claudeMessages,
            $systemParts,
            $tools,
            $options,
        );

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
     * Składa ciało żądania `POST /v1/messages`.
     *
     * Wydzielone z chat(), żeby dało się asertować kształt żądania bez sieci
     * (patrz tests/AI/ThinkingRequestTest.php) — to jedyne miejsce, w którym
     * decyduje się, czy model dostanie adaptive thinking, budget_tokens czy
     * temperaturę, a pomyłka tutaj oznacza HTTP 400 na każdym żądaniu.
     *
     * @param list<array<string, mixed>> $claudeMessages
     * @param list<array{text: string, cacheable: bool}> $systemParts
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildRequestBody(
        string $model,
        ?AIModel $aiModel,
        int $effectiveMax,
        array $claudeMessages,
        array $systemParts,
        array $tools,
        array $options,
    ): array {
        $body = [
            'model' => $model,
            'max_tokens' => $effectiveMax,
            'messages' => $claudeMessages,
        ];

        // CHAT-T-177 (ADR-139): dwa rozłączne tryby myślenia.
        //  - modele adaptive (Sonnet 5+): thinking {type: adaptive} + output_config.effort,
        //    BEZ budget_tokens (odrzucane błędem 400),
        //  - modele sprzed adaptive (Sonnet 4.6, Opus 4.7, Haiku 4.5): jak dotąd
        //    thinking {type: enabled} + budget_tokens.
        // options['effort'] przychodzi z ChatService jako string (minimal/low/medium/high)
        // albo int (legacy budget_tokens) — rozpoznajemy oba.
        $adaptive = $aiModel !== null
            && $aiModel->supportsReasoningEffort()
            && $aiModel->supportsAdaptiveThinking();

        $budgetTokens = null;    // tylko modele sprzed adaptive
        $adaptiveEffort = null;  // tylko modele adaptive
        $thinkingHeadroom = 0;   // rezerwa tokenów myślenia w max_tokens

        if (!empty($options['effort']) && $aiModel !== null && $aiModel->supportsReasoningEffort()) {
            if (is_int($options['effort'])) {
                $thinkingHeadroom = $options['effort'];
                if (!$adaptive) {
                    $budgetTokens = $options['effort'];
                }
            } elseif (is_string($options['effort'])) {
                $thinkingHeadroom = $aiModel->thinkingHeadroomTokens($options['effort']);
                $mapped = $aiModel->mapEffortToProviderValue($options['effort']);
                if ($adaptive && is_string($mapped)) {
                    $adaptiveEffort = $mapped;
                } elseif (!$adaptive && is_int($mapped)) {
                    $budgetTokens = $mapped;
                }
            }
        }

        if ($adaptive) {
            // display: "omitted" — nic w systemie nie czyta bloków thinking
            // (parseResponse je pomija, panel recenzji ich nie pokazuje), a omitted
            // daje szybszy time-to-first-token.
            $body['thinking'] = ['type' => 'adaptive', 'display' => 'omitted'];
            // Effort ZAWSZE jawnie: pominięcie output_config to na Sonnet 5 domyślne
            // `high`, czyli droższe i wolniejsze niż dzisiejszy stan (decyzja Q32a).
            $body['output_config'] = ['effort' => $adaptiveEffort ?? self::DEFAULT_ADAPTIVE_EFFORT];
            // max_tokens obejmuje myślenie ORAZ odpowiedź — trzymamy tę samą rezerwę
            // co przy budget_tokens, żeby migracja nie ucięła odpowiedzi.
            $body['max_tokens'] = max(
                $effectiveMax,
                ($thinkingHeadroom > 0 ? $thinkingHeadroom : $aiModel->thinkingHeadroomTokens(self::DEFAULT_ADAPTIVE_EFFORT)) + 4096,
            );
        } elseif ($budgetTokens !== null) {
            $body['thinking'] = [
                'type' => 'enabled',
                'budget_tokens' => $budgetTokens,
            ];
            // Extended thinking wymaga max_tokens > budget_tokens.
            $body['max_tokens'] = max($effectiveMax, $budgetTokens + 4096);
            // Z thinking nie wysyłamy temperature.
        } elseif ($aiModel !== null
            && $aiModel->supportsTemperature()
            && !$aiModel->rejectsNonDefaultTemperature()
            && isset($options['temperature'])
        ) {
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

        return $body;
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
