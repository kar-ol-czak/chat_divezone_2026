# TASK-052b: Backend – PricingService, UsageLogger, mapowanie reasoning effort

**Status:** TODO
**Instancja:** backend
**Powiązany ADR:** ADR-051
**Zależności:** TASK-052a wykonane i zmergowane (tabele istnieją w PG)

---

## Cel

Dodać do backendu PHP:
1. Aktualizację `AIModel` enum do 8 modeli z ADR-051.
2. `PricingService` czytający cennik z PG.
3. `UsageLogger` zapisujący per-message usage + agregaty rozmowy.
4. `ExchangeRateService` (NBP, 1× dziennie).
5. Mapowanie `reasoning_effort` (UI) → parametr API providera.
6. Rozszerzenie `SettingsController` i response chat o pola kosztu.
7. Endpoint do edycji cen (admin).

**STOP po wykonaniu tego tasku.** Karol robi review przed TASK-052c (frontend).

---

## Zakres – kontrakty klas i metod

### 1. Aktualizacja `src/Enum/AIModel.php`

Wymienić enum cases na 8 modeli z ADR-051. Zachować strukturę metod, ale:

- `provider()` – jak teraz (claude/openai)
- `tier()` – `'escalation'` dla `CLAUDE_OPUS_47` i `GPT_54`, reszta `'primary'`
- `label()` – z tabeli ADR-051
- `supportsTemperature()` – tylko `GPT_41` zwraca TRUE
- `supportsReasoningEffort()` – wszystkie poza `GPT_41` zwracają TRUE
- `effortParamName()` – `'reasoning_effort'` (openai), `'thinking'` (claude), `null` dla GPT-4.1
- `mapEffortToProviderValue(string $effort): mixed` – nowa metoda. Przyjmuje `minimal|low|medium|high`, zwraca:
  - openai → ten sam string (`minimal`/`low`/`medium`/`high`)
  - claude → `int` (budget_tokens): minimal=1024, low=4096, medium=8192, high=16384
  - GPT-4.1 → null
- `grouped()` – jak teraz, ale wzbogacona o ceny (joinowane z `PricingService`).

**Uwaga:** wartości enum musi pasować do `model_id` w bazie (`claude-opus-4-7`, `gpt-5.4`, etc.).

### 2. Nowy `src/AI/PricingService.php`

```php
final class PricingService {
    public function getPrice(string $modelId): ?ModelPrice;
    public function getAllActive(): array; // ModelPrice[]
    public function calculateCost(
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheCreationTokens = 0,
    ): CostBreakdown;
    public function updatePrice(string $modelId, array $fields): void; // dla edycji z admina
}

final class ModelPrice {
    public string $modelId;
    public string $provider;
    public string $label;
    public float $inputPricePerMillion;
    public float $outputPricePerMillion;
    public ?float $cacheReadPricePerMillion;
    public ?float $cacheCreationPricePerMillion;
    public bool $isEscalation;
    public bool $supportsTemperature;
    public bool $supportsReasoningEffort;
}

final class CostBreakdown {
    public float $costInputUsd;
    public float $costOutputUsd;
    public float $costCacheUsd;
    public float $costTotalUsd;
}
```

Zaokrąglanie kosztów: 6 miejsc po przecinku (NUMERIC(10,6) w bazie).

### 3. Nowy `src/AI/UsageLogger.php`

```php
final class UsageLogger {
    public function logMessage(
        string $conversationId,
        ?int $messageId,
        string $modelId,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens = 0,
        int $cacheCreationTokens = 0,
    ): void;
    
    public function getConversationCost(string $conversationId): ConversationCost;
}

final class ConversationCost {
    public string $conversationId;
    public float $totalCostUsd;
    public float $totalCostPln; // przeliczone przez ExchangeRateService
    public int $totalInputTokens;
    public int $totalOutputTokens;
    public int $totalCacheReadTokens;
    public int $messageCount;
}
```

Logika `logMessage`:
1. Wywołać `PricingService::calculateCost`
2. INSERT do `divechat_message_usage`
3. UPDATE `divechat_conversations` SET `total_cost_usd = total_cost_usd + :cost` (atomic, nie SELECT-then-UPDATE).

### 4. Nowy `src/AI/ExchangeRateService.php`

```php
final class ExchangeRateService {
    public function getUsdToPln(): float; // z cache, fallback na ostatni znany kurs
    public function refreshFromNBP(): void; // wywoływane przez cron daily
}
```

Endpoint NBP: `https://api.nbp.pl/api/exchangerates/rates/A/USD/?format=json` (rate_to_pln = `rates[0].mid`).
Cache: read z `divechat_exchange_rates` WHERE `rate_date = CURRENT_DATE`. Jeśli brak → fetch + insert.
Fallback: jeśli NBP zwraca 404 (weekend), wziąć ostatni dostępny kurs (ORDER BY rate_date DESC LIMIT 1).

Skrypt CLI: `scripts/refresh_exchange_rates.php` (do crona, 1× dziennie 09:00 UTC).

### 5. Modyfikacja `src/AI/ClaudeProvider.php`

W metodzie wysyłającej request:
- Po otrzymaniu response z `usage` → wywołać `UsageLogger::logMessage`.
- Anthropic zwraca: `usage.input_tokens`, `usage.output_tokens`, `usage.cache_read_input_tokens`, `usage.cache_creation_input_tokens`. Mapować 1:1.
- Jeśli ustawiony `reasoning_effort` w settings → dodać do request `thinking: {type: "enabled", budget_tokens: <mapped>}`.

### 6. Modyfikacja `src/AI/OpenAIProvider.php`

- Po response z `usage` → `UsageLogger::logMessage`.
- OpenAI zwraca: `usage.prompt_tokens` (= input), `usage.completion_tokens` (= output). OpenAI nie ma cache w OpenAI API w sposób Anthropic – cache_read/creation = 0.
- Jeśli model wspiera reasoning effort i ustawiony w settings → dodać `reasoning_effort` do request body.
- GPT-4.1 (`supportsTemperature() = true`) → wysyłać `temperature` z settings.
- Modele rozumujące → NIE wysyłać `temperature`.

### 7. Modyfikacja `src/Controller/SettingsController.php`

`GET /api/settings` – wzbogacić odpowiedź:

```json
{
  "settings": { ... },
  "available_models": {
    "claude": {
      "primary": [
        { "value": "claude-haiku-4-5", "label": "Claude Haiku 4.5",
          "input_price": 1.00, "output_price": 5.00,
          "supports_temperature": false, "supports_reasoning_effort": true }
      ],
      "escalation": [ { "value": "claude-opus-4-7", ... } ]
    },
    "openai": { ... }
  },
  "exchange_rate_usd_pln": 4.05
}
```

Settings schema (klucze w `divechat_settings`):
- `model_primary` (string, model_id)
- `model_escalation` (string, model_id)
- `temperature` (float 0-1, używane TYLKO dla modeli z supports_temperature)
- `reasoning_effort` (string `minimal|low|medium|high`, używane TYLKO dla modeli z supports_reasoning_effort)
- `emoji_enabled` (bool)
- `knowledge_gap_threshold` (float)

### 8. Nowy endpoint `POST /api/admin/pricing`

Body: `{ "model_id": "claude-opus-4-7", "input_price_per_million": 5.00, "output_price_per_million": 25.00, ... }`

Update przez `PricingService::updatePrice()`. Wymaga auth admin (jak istniejące endpointy admin).

### 9. Modyfikacja response chat

W `ChatController` po wygenerowaniu odpowiedzi dodać do JSON response:

```json
{
  "message": "...",
  "conversation_cost": {
    "total_usd": 0.0234,
    "total_pln": 0.0947,
    "input_tokens": 1234,
    "output_tokens": 567,
    "message_count": 5
  }
}
```

Kalkulacja przez `UsageLogger::getConversationCost()`.

---

## Kryteria akceptacji

1. Po wysłaniu wiadomości w chacie wpis pojawia się w `divechat_message_usage`.
2. `divechat_conversations.total_cost_usd` rośnie po każdej wiadomości.
3. `GET /api/settings` zwraca 8 modeli z cenami i flagami `supports_*`.
4. Zmiana modelu na rozumujący (np. `gpt-5.4`) i wysłanie z settings `reasoning_effort=medium` powoduje że request do OpenAI zawiera `reasoning_effort: medium`.
5. Zmiana modelu na `claude-haiku-4-5` z `reasoning_effort=high` powoduje że request do Anthropic zawiera `thinking.budget_tokens: 16384`.
6. Skrypt `scripts/refresh_exchange_rates.php` zapisuje kurs do bazy.
7. Response chat zawiera pole `conversation_cost`.
8. Endpoint `POST /api/admin/pricing` aktualizuje cenę i kolejne wiadomości używają nowej.

---

## Czego NIE robić

- NIE dotykać frontendu – to jest TASK-052c.
- NIE zmieniać schematu bazy – to było TASK-052a.
- NIE dodawać retry logic do NBP API – fallback na ostatni kurs wystarczy.
- NIE liczyć kosztu po stronie Pythona/embeddingów – tylko message usage z chatu.
- NIE używać DeepSeek API – ADR-051 nie obejmuje tego providera (osobna decyzja).
- NIE ustawiać domyślnego modelu na żaden konkretny – wartości startowe niech operator wybierze przez panel.

---

## Punkty kontrolne (sub-STOP do review)

Po każdym z tych etapów Claude Code zatrzymuje się i pyta o akceptację:
1. Aktualizacja `AIModel` enum + wartości pasują do bazy.
2. `PricingService` + `UsageLogger` z testami jednostkowymi (mock PG).
3. Integracja w Claude/OpenAI Providers (logowanie + reasoning effort mapping).
4. `SettingsController` + nowy `/api/admin/pricing`.
5. Modyfikacja response chat.

Po wszystkim: end-to-end test (wysyłka wiadomości na 2 różnych modelach, weryfikacja kosztu w bazie).

---

## Pytania do architekta

Jeśli pojawią się pytania (np. czy logger ma być sync czy async, jak dokładnie autoryzować
endpoint pricing) zapisać w `_instances/backend/handoff/052b_questions.md` i zatrzymać się.
