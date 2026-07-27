# TASK-054: Migracja 008 - rozszerzenie schematu telemetrii

**Status:** TODO  
**Instancja:** backend  
**Powiązany ADR:** ADR-052 sekcja 4  
**Zależności:** TASK-053 zmergowane (effort dropdown działa)

---

## Cel

Rozszerzyć schemat PG o pola potrzebne do admin dashboardu:
- `latency_ms` (czas odpowiedzi LLM, do D1 health/p95)
- `tool_calls JSONB` (lista wywołanych narzędzi, do C3 tool failures)
- Utworzenie tabeli `divechat_messages` (aktualna historia w `divechat_conversations.history JSONB` - niewystarczające do feedbacku per-message i ratingów)
- `rating` (thumbs up/down - na przyszłość C1 feedback)

**STOP po wykonaniu.** Karol robi review SQL przed TASK-055.

---

## Zakres

### 1. Plik `sql/008_telemetry_extension.sql`

#### 1.1. Rozszerzenie `divechat_message_usage`

```sql
ALTER TABLE divechat_message_usage
    ADD COLUMN IF NOT EXISTS latency_ms INTEGER,
    ADD COLUMN IF NOT EXISTS tool_calls JSONB;

-- Indeks na latency dla raportów p95/p99
CREATE INDEX IF NOT EXISTS idx_usage_latency 
    ON divechat_message_usage(latency_ms) 
    WHERE latency_ms IS NOT NULL;
```

#### 1.2. Nowa tabela `divechat_messages`

Dotychczas wiadomości były w `divechat_conversations.history JSONB`. To było OK
dla MVP, ale do feedbacku, ratingów i indywidualnej referencji potrzebujemy
osobnej tabeli.

```sql
CREATE TABLE IF NOT EXISTS divechat_messages (
    id BIGSERIAL PRIMARY KEY,
    conversation_id INTEGER NOT NULL REFERENCES divechat_conversations(id) ON DELETE CASCADE,
    role VARCHAR(16) NOT NULL CHECK (role IN ('user', 'assistant', 'system', 'tool')),
    content TEXT NOT NULL,
    tool_calls JSONB,
    rating SMALLINT CHECK (rating IN (-1, 0, 1)),
    rating_at TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_messages_conversation 
    ON divechat_messages(conversation_id, created_at);

CREATE INDEX IF NOT EXISTS idx_messages_rating 
    ON divechat_messages(rating, rating_at DESC) 
    WHERE rating IS NOT NULL AND rating != 0;
```

**UWAGA:** komentarz w migracji 007 mówił "FK do divechat_messages dodać gdy tabela powstanie".
Dodać teraz w tej migracji:

```sql
-- Domknięcie FK z migracji 007 (była zapowiedziana)
ALTER TABLE divechat_message_usage
    ADD CONSTRAINT fk_usage_message 
    FOREIGN KEY (message_id) REFERENCES divechat_messages(id) ON DELETE SET NULL;
```

#### 1.3. Indeksy do raportów dashboardu

Optymalizacja pod queries z TASK-055 (top N rozmów, breakdown per model):

```sql
-- Trendy dzienne/tygodniowe/miesięczne
CREATE INDEX IF NOT EXISTS idx_usage_created_model 
    ON divechat_message_usage(created_at, model_id);

-- Top N najdroższych rozmów
CREATE INDEX IF NOT EXISTS idx_conversations_cost 
    ON divechat_conversations(estimated_cost DESC) 
    WHERE estimated_cost > 0;
```

### 2. Plik `sql/008_telemetry_extension_rollback.sql`

```sql
ALTER TABLE divechat_message_usage 
    DROP CONSTRAINT IF EXISTS fk_usage_message;

DROP TABLE IF EXISTS divechat_messages CASCADE;

ALTER TABLE divechat_message_usage
    DROP COLUMN IF EXISTS latency_ms,
    DROP COLUMN IF EXISTS tool_calls;

DROP INDEX IF EXISTS idx_usage_created_model;
DROP INDEX IF EXISTS idx_conversations_cost;
```

### 3. Update `ConversationStore.php` - migracja danych z history JSONB

Backend musi zapisywać też do `divechat_messages` przy każdej wiadomości (oprócz
istniejącego JSONB w `divechat_conversations.history`).

**Strategia: dual write na MVP, później decyzja.**

Dla każdej wiadomości:
1. Jak teraz - append do `divechat_conversations.history` JSONB
2. NOWE - INSERT do `divechat_messages` (id zwracane do `UsageLogger::logMessage`)

Modyfikacja `ConversationStore::appendMessage()` (lub jak ona się nazywa):

```php
public function appendMessage(int $conversationId, string $role, string $content, ?array $toolCalls = null): int
{
    // Existing: append do history JSONB
    // ...
    
    // NEW: insert do divechat_messages
    $stmt = $this->pdo->prepare(
        'INSERT INTO divechat_messages (conversation_id, role, content, tool_calls) 
         VALUES (:cid, :role, :content, :tc) 
         RETURNING id'
    );
    $stmt->execute([
        ':cid' => $conversationId,
        ':role' => $role,
        ':content' => $content,
        ':tc' => $toolCalls ? json_encode($toolCalls) : null,
    ]);
    
    return (int) $stmt->fetchColumn();
}
```

Zwracane `message_id` używane przez `UsageLogger::logMessage($conversationId, $messageId, ...)`.

### 4. Update `UsageLogger.php` - zapisywanie latency_ms

Sygnatura `logMessage` rozszerzona o opcjonalny `latency_ms`:

```php
public function logMessage(
    int $conversationId,
    ?int $messageId,
    string $modelId,
    int $inputTokens,
    int $outputTokens,
    int $cacheReadTokens = 0,
    int $cacheCreationTokens = 0,
    ?int $latencyMs = null,
    ?array $toolCalls = null,
): void
```

INSERT do `divechat_message_usage` zawiera `latency_ms` i `tool_calls`.

### 5. Update `ClaudeProvider` i `OpenAIProvider`

Pomiar czasu LLM call:

```php
$start = microtime(true);
$response = $this->client->post(...);
$latencyMs = (int) ((microtime(true) - $start) * 1000);

$this->usageLogger->logMessage(
    $conversationId,
    $messageId,
    $modelId,
    $inputTokens,
    $outputTokens,
    $cacheReadTokens,
    $cacheCreationTokens,
    $latencyMs,
    $toolCallsArray, // jeśli były
);
```

`$toolCallsArray` to lista nazw narzędzi i ich parametrów (z response.tool_calls dla
OpenAI lub `content[].type === 'tool_use'` dla Anthropic).

---

## Kryteria akceptacji

1. Migracja 008 wykonuje się czysto, idempotentnie (ON CONFLICT, IF NOT EXISTS)
2. Tabela `divechat_messages` istnieje, kolumny zgodne ze spec
3. FK `divechat_message_usage.message_id → divechat_messages.id` aktywne
4. Po wysłaniu testowej wiadomości w czacie:
   - `divechat_messages` ma 2 nowe wpisy (user + assistant)
   - `divechat_message_usage` ma wpis z niezerowym `latency_ms`
   - Jeśli LLM użył tool - `tool_calls` zawiera JSON z nazwą narzędzia
5. Rollback usuwa wszystko bez błędów

---

## Czego NIE robić

- NIE migruj danych ze starego `divechat_conversations.history` do `divechat_messages`
  (zostają jako legacy, dual write tylko od teraz)
- NIE usuwaj kolumny `history` z `divechat_conversations` (decyzja na późniejszy ADR)
- NIE rozpoczynaj TASK-055 przed mergem TASK-054
- NIE dodawaj endpointu `/api/messages/:id/rating` - to do TASK-055 lub późniejszego
- NIE rotuj kluczy API ani haseł

---

## Punkty kontrolne (sub-STOP)

1. Migracja SQL napisana → review architekta
2. ConversationStore + dual write → test E2E (curl /api/chat, sprawdź obie tabele)
3. UsageLogger + latency → test E2E z różnymi modelami
4. Tool calls capture → test z toolami search_products

Po każdym sub-STOP commit + push.

---

## Pytania do architekta

Jeśli pojawią się wątpliwości:
- Czy zachować JSON tool_calls w surowym formacie providera czy znormalizować?
- Czy `rating` ma default 0 czy NULL?

Zapisz w `_instances/backend/handoff/054_questions.md` i czekaj.
