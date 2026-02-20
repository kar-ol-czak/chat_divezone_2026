# TASK-006b: AI Providers, Tools, ChatService
# Data: 2026-02-20
# Instancja: backend
# Priorytet: KRYTYCZNY
# Zależności: TASK-006a (standalone skeleton)

## Kontekst
Przeczytaj OBOWIĄZKOWO:
- ../../_docs/00_architektura_projektu.md (sekcja 7: definicje tools)
- ../../_docs/10_decyzje_projektowe.md (ADR-013: strategie doboru, ADR-014: konfigurowalny provider)
- ../../_docs/11_mapa_marek.md (whitelist marek)
- ../../_docs/02_schemat_bazy.md (tabele PostgreSQL)
- ../../standalone/src/ (kod z TASK-006a)

PHP 8.4. Używaj typed properties, enums, match, constructor promotion.
Cały kod w ../../standalone/src/

## Struktura plików (dodatkowe do TASK-006a)

```
standalone/src/
├── AI/
│   ├── AIProviderInterface.php
│   ├── AIProviderFactory.php
│   ├── ClaudeProvider.php
│   ├── OpenAIProvider.php
│   └── ToolCall.php              # Value object
├── Tools/
│   ├── ToolInterface.php
│   ├── ToolRegistry.php          # Rejestr wszystkich narzędzi
│   ├── ProductSearch.php
│   ├── ProductDetails.php
│   ├── ExpertKnowledge.php
│   ├── OrderStatus.php
│   └── ShippingInfo.php
├── Chat/
│   ├── ChatService.php           # Orkiestrator (tool loop)
│   ├── ChatController.php        # HTTP handler
│   ├── SystemPrompt.php          # Builder system prompta
│   └── ConversationStore.php     # Zapis/odczyt z divechat_conversations
└── Enum/
    ├── AIModel.php               # Enum z dostępnymi modelami
    └── SearchStrategy.php        # BESTSELLER, BUDGET, RANGE, SEMANTIC
```

## Krok 1: Enums

### AIModel.php
```php
enum AIModel: string
{
    case CLAUDE_SONNET_4 = 'claude-sonnet-4-20250514';
    case CLAUDE_SONNET_45 = 'claude-sonnet-4-5-20250929';
    case GPT_41 = 'gpt-4.1';
    case GPT_4O = 'gpt-4o';
}
```

### SearchStrategy.php
```php
enum SearchStrategy: string
{
    case BESTSELLER = 'bestseller';
    case BUDGET = 'budget';
    case RANGE = 'range';
    case SEMANTIC = 'semantic';
    case SPECIFIC = 'specific';
}
```

## Krok 2: AI Providers

### AIProviderInterface.php
```php
interface AIProviderInterface
{
    public function chat(array $messages, array $tools = []): AIResponse;
    public function getEmbedding(string $text): array; // float[1536]
}
```

### ToolCall.php (value object)
```php
readonly class ToolCall
{
    public function __construct(
        public string $id,
        public string $name,
        public array $arguments,
    ) {}
}
```

AIResponse: klasa lub named array z: content (string), toolCalls (ToolCall[]), usage (array).

### ClaudeProvider.php
- Guzzle POST do https://api.anthropic.com/v1/messages
- Headers: x-api-key, anthropic-version: 2023-06-01, content-type: application/json
- Tool format: Anthropic native (tool_use blocks)
- Parsowanie response: text blocks -> content, tool_use blocks -> ToolCall[]
- getEmbedding(): deleguj do OpenAI (Claude nie ma embeddingów)
- Timeout: 30s

### OpenAIProvider.php
- Guzzle POST do https://api.openai.com/v1/chat/completions
- Headers: Authorization: Bearer {key}
- Tool format: OpenAI functions
- Parsowanie: message.content -> content, tool_calls -> ToolCall[]
- getEmbedding(): POST /v1/embeddings, model text-embedding-3-large, dimensions 1536
- Timeout: 30s

### AIProviderFactory.php
```php
class AIProviderFactory
{
    public static function create(Config $config): AIProviderInterface
    {
        return match ($config->aiProvider) {
            'claude' => new ClaudeProvider($config),
            'openai' => new OpenAIProvider($config),
            default => throw new \InvalidArgumentException("Unknown provider: {$config->aiProvider}"),
        };
    }
}
```

## Krok 3: Tools

### ToolInterface.php
```php
interface ToolInterface
{
    public function getName(): string;
    public function getDescription(): string;
    public function getParametersSchema(): array; // JSON Schema
    public function execute(array $params): array;
}
```

### ToolRegistry.php
Przechowuje wszystkie narzędzia. Metody:
- register(ToolInterface $tool): void
- get(string $name): ToolInterface
- getToolDefinitions(): array (do wysłania do AI API)
  Format output: kompatybilny z Anthropic I OpenAI (konwersja w providerze)

### ProductSearch.php
Wyszukiwanie hybrydowe: embedding + SQL.
Parametry (JSON Schema):
- query: string (required) - co klient szuka
- category: string (optional) - filtr kategorii
- min_price: number (optional)
- max_price: number (optional)
- brand: string (optional)
- in_stock_only: boolean (default true)

Implementacja:
1. Generuje embedding zapytania: AIProvider::getEmbedding(query)
2. SQL do PostgreSQL:
```sql
SELECT ps_product_id, product_name, brand_name, category_name,
       price, in_stock, product_url, image_url,
       1 - (embedding <=> $1::vector) as similarity
FROM divechat_product_embeddings
WHERE is_active = true
  AND ($2::boolean IS FALSE OR in_stock = true)
  AND ($3::text IS NULL OR category_name ILIKE '%' || $3 || '%')
  AND ($4::numeric IS NULL OR price >= $4)
  AND ($5::numeric IS NULL OR price <= $5)
  AND ($6::text IS NULL OR brand_name ILIKE '%' || $6 || '%')
ORDER BY embedding <=> $1::vector
LIMIT 5
```
3. Zwraca: array of [id, name, brand, category, price, in_stock, url, image_url, similarity]

### ExpertKnowledge.php
Parametry: query (string, required), category (string, optional)
1. Embedding zapytania
2. SQL:
```sql
SELECT question, content, category,
       1 - (embedding <=> $1::vector) as similarity
FROM divechat_knowledge
WHERE active = true
  AND 1 - (embedding <=> $1::vector) > 0.5
ORDER BY embedding <=> $1::vector
LIMIT 3
```
3. Zwraca: array of [question, content, category, similarity]

### ProductDetails.php
Parametry: product_id (int, required)
SQL do MySQL PrestaShop (read-only):
- pr_product + pr_product_lang (name, description, link_rewrite)
- pr_feature_product + pr_feature_lang + pr_feature_value_lang (cechy)
- pr_stock_available (ilość, out_of_stock)
- pr_image (cover image)
- pr_specific_price (promocje, jeśli są)
- pr_product_lang.available_later (info o dostępności)
Zwraca: kompletna specyfikacja produktu

### OrderStatus.php
Parametry: order_reference (string), customer_email (string)
SQL do MySQL:
- pr_orders WHERE reference = $1
- pr_customer WHERE email = $2
- Weryfikacja: customer.id_customer = order.id_customer
- pr_order_history (statusy)
- pr_order_carrier (numer przesyłki)
Zwraca: status, data, historia, tracking

### ShippingInfo.php
Parametry: brak lub cart_total (number)
Na MVP: hardkodowane dane o dostawie (progi, ceny).
Docelowo: z pr_carrier + pr_delivery.
Zwraca: metody dostawy, ceny, progi darmowej

## Krok 4: SystemPrompt.php

Builder system prompta. Generuje tekst na podstawie konfiguracji.
Zawiera:
- Rola: ekspert ds. sprzętu nurkowego divezone.pl
- Zasady: język polski, sprawdzaj dostępność, pytania doprecyzowujące
- Whitelist marek: ładowany z config (docelowo z bazy/pliku)
  "NIGDY nie wymieniaj marek spoza oferty. Dozwolone marki: TECLINE, SCUBAPRO, ..."
- Reguła: "Produkty proponuj TYLKO na podstawie wyników narzędzi, nie z pamięci"
- Strategia: "Gdy pytanie ogólne, zadaj max 2 pytania doprecyzowujące"

## Krok 5: ConversationStore.php

Zapis/odczyt rozmów z divechat_conversations (PostgreSQL).
- startOrResume(string $sessionId, ?int $customerId): array (messages)
- append(string $sessionId, array $message): void
- getHistory(string $sessionId): array
- close(string $sessionId, array $metadata): void

## Krok 6: ChatService.php (orkiestrator)

Główna logika. Metoda handle():
```
1. Input: sessionId, message, customerId
2. conversationStore->startOrResume(sessionId, customerId)
3. systemPrompt = SystemPrompt::build(config)
4. messages = [system, ...history, user message]
5. tools = toolRegistry->getToolDefinitions()
6. TOOL LOOP (max 5 iteracji):
   a. response = aiProvider->chat(messages, tools)
   b. Jeśli response->toolCalls puste -> BREAK (mamy finalną odpowiedź)
   c. Dla każdego toolCall:
      - tool = toolRegistry->get(toolCall->name)
      - result = tool->execute(toolCall->arguments)
      - Dodaj tool result do messages
   d. Kontynuuj loop (AI dostanie wyniki narzędzi)
7. conversationStore->append(sessionId, messages)
8. Return: odpowiedź tekstowa + metadata (tools_used, tokens, cost)
```

WAŻNE: format messages (tool results) różni się między Claude i OpenAI.
Provider musi to abstrahować. AIProviderInterface::chat() przyjmuje
ujednolicony format, provider konwertuje na natywny.

## Krok 7: ChatController.php

HTTP handler dla POST /api/chat.
```
1. Parsuj request (JSON body)
2. Walidacja: message required, nie pusty
3. Auth: HmacVerifier->verify(token, customerId, timestamp)
4. Jeśli moduł wyłączony -> 403
5. Rate limit: max 10 req/min per sessionId (prosty in-memory counter lub file-based)
6. chatService->handle(sessionId, message, customerId)
7. Response JSON:
   {
     "success": true,
     "response": "tekst odpowiedzi AI",
     "session_id": "abc123",
     "tools_used": ["search_products", "get_expert_knowledge"],
     "products": [...] // opcjonalnie, jeśli AI szukał produktów
   }
8. Error handling: try/catch -> error response z kodem
```

Pole "products" w response: jeśli AI wywołał ProductSearch, dołącz wyniki
jako osobne pole (frontend wyświetli karty produktów, nie tylko tekst).

## Krok 8: Rejestracja routes + tools

config/routes.php:
```php
return [
    'POST /api/chat' => [ChatController::class, 'handle'],
    'GET /api/health' => [HealthController::class, 'handle'],
];
```

config/tools.php:
```php
return [
    ProductSearch::class,
    ProductDetails::class,
    ExpertKnowledge::class,
    OrderStatus::class,
    ShippingInfo::class,
];
```

## Krok 9: Test end-to-end (lokalny)

```bash
cd ../../standalone
php -S localhost:8080 -t public/

# Generuj HMAC token do testów
php -r "echo hash_hmac('sha256', '0:' . time(), 'test_secret');"

# Test: pytanie produktowe
curl -X POST http://localhost:8080/api/chat \
  -H "Content-Type: application/json" \
  -H "X-DiveChat-Token: {token}" \
  -H "X-DiveChat-Customer: 0" \
  -H "X-DiveChat-Time: {timestamp}" \
  -d '{"message": "Szukam maski do nurkowania"}'

# Test: pytanie doradcze
curl -X POST http://localhost:8080/api/chat \
  -H "Content-Type: application/json" \
  -H "X-DiveChat-Token: {token}" \
  -H "X-DiveChat-Customer: 0" \
  -H "X-DiveChat-Time: {timestamp}" \
  -d '{"message": "Jaka jest różnica między jacketem a skrzydłem?"}'
```

Oczekiwania:
- Maska: AI wywołuje ProductSearch, zwraca maski z oferty, karty produktów
- Jacket/skrzydło: AI wywołuje ExpertKnowledge, odpowiada z bazy wiedzy

## Definition of Done
- [ ] AIProviders: Claude i OpenAI wysyłają requesty i parsują odpowiedzi
- [ ] Tool loop: AI wywołuje narzędzia, dostaje wyniki, kontynuuje
- [ ] ProductSearch: zwraca realne produkty z pgvector (2670)
- [ ] ExpertKnowledge: zwraca wiedzę z pgvector (37 wpisów)
- [ ] ProductDetails: zwraca dane z MySQL
- [ ] POST /api/chat: end-to-end działa z oboma providerami
- [ ] Handoff w ../../_instances/backend/handoff/ z kontraktem API dla frontendu
