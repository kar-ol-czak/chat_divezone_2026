# TASK-008: Rozszerzenie schematu conversations + endpointy admin API
# Data: 2026-02-20
# Instancja: backend
# Priorytet: WYSOKI (wymagane przez frontend testowy)
# Zależności: TASK-006b-fix ukończony, Railway DB aktywna

## Kontekst
Frontend testowy (chat.divezone.pl) potrzebuje:
1. Rozszerzonej diagnostyki w zapisie rozmów
2. API do odczytu historii czatów
3. API do ustawień (model, temperature, emoji)
4. Timestampy response do konsoli debug

## Część 1: Migracja SQL

### Plik: sql/002_extend_conversations.sql
Wykonać na Railway (postgresql://postgres:<RAILWAY_PASSWORD_REDACTED>@switchback.proxy.rlwy.net:14368/railway?sslmode=disable):

```sql
-- Nowe kolumny w divechat_conversations
ALTER TABLE divechat_conversations
  ADD COLUMN IF NOT EXISTS model_used VARCHAR(64),
  ADD COLUMN IF NOT EXISTS response_times JSONB,
  ADD COLUMN IF NOT EXISTS search_diagnostics JSONB,
  ADD COLUMN IF NOT EXISTS knowledge_gap BOOLEAN DEFAULT false,
  ADD COLUMN IF NOT EXISTS admin_status VARCHAR(20) DEFAULT 'new',
  ADD COLUMN IF NOT EXISTS admin_notes TEXT;

-- Indeksy
CREATE INDEX IF NOT EXISTS idx_conversations_knowledge_gap
  ON divechat_conversations (knowledge_gap) WHERE knowledge_gap = true;
CREATE INDEX IF NOT EXISTS idx_conversations_admin_status
  ON divechat_conversations (admin_status);

-- Tabela ustawień czatu
CREATE TABLE IF NOT EXISTS divechat_settings (
  key VARCHAR(100) PRIMARY KEY,
  value JSONB NOT NULL,
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Domyślne ustawienia
INSERT INTO divechat_settings (key, value) VALUES
  ('ai_provider', '"openai"'),
  ('primary_model', '"gpt-4.1"'),
  ('escalation_model', '"gpt-5.2"'),
  ('temperature', '0.6'),
  ('max_tokens', '4096'),
  ('emoji_enabled', 'true'),
  ('knowledge_gap_threshold', '0.5')
ON CONFLICT (key) DO NOTHING;
```

## Część 2: Zapis diagnostyki w ChatService

### Plik: standalone/src/Chat/ChatService.php
W metodzie handle(), zbieraj dane diagnostyczne:

**response_times:** Mierz czas każdego kroku:
```php
$timings = ['start' => microtime(true)];
// ... po embedding:
$timings['embedding_ms'] = (microtime(true) - $stepStart) * 1000;
// ... po AI call:
$timings['ai_ms'] = ...;
// ... po tool execution:
$timings['tool_ms'] = ...;
$timings['total_ms'] = (microtime(true) - $timings['start']) * 1000;
```

**search_diagnostics:** Przy wykonaniu każdego narzędzia, zbieraj:
```php
// W executeTool() lub po nim:
$diagnostic = [
    'tool' => $toolCall->name,
    'query_text' => $toolCall->arguments['query'] ?? null,
    'results' => [], // name + similarity + matched_text (skrócony)
    'min_similarity' => null,
    'max_similarity' => null,
    'knowledge_gap' => false, // true jeśli brak wyników lub max_sim < threshold
];
```

Narzędzia ProductSearch i ExpertKnowledge muszą zwracać similarity w wynikach (już zwracają).

**knowledge_gap:** true jeśli JAKIKOLWIEK tool call z search_products lub get_expert_knowledge miał:
- Brak wyników, lub
- max similarity < threshold (z divechat_settings.knowledge_gap_threshold)

**model_used:** Zapisuj nazwę modelu z configu.

### Plik: standalone/src/Chat/ConversationStore.php
Rozszerz metodę save() o nowe kolumny:
```php
public function save(
    string $sessionId,
    array $messages,
    array $toolsUsed,
    array $usage,
    string $modelUsed,
    array $responseTimes,
    array $searchDiagnostics,
    bool $knowledgeGap
): void
```

## Część 3: Nowe endpointy API

### GET /api/conversations
Lista rozmów dla panelu admina.

Query params:
- page (int, default 1)
- per_page (int, default 20, max 100)
- search (string, szukaj w messages JSON)
- knowledge_gap (bool, filtruj po luce w wiedzy)
- admin_status (string, filtruj po statusie)

Response:
```json
{
  "conversations": [
    {
      "id": 1,
      "session_id": "abc123",
      "customer_id": 0,
      "message_count": 5,
      "model_used": "claude-sonnet-4-6",
      "tools_used": ["search_products", "get_expert_knowledge"],
      "tokens_input": 5427,
      "tokens_output": 525,
      "estimated_cost": 0.019,
      "knowledge_gap": false,
      "admin_status": "new",
      "started_at": "2026-02-20T17:43:30Z",
      "updated_at": "2026-02-20T17:44:15Z"
    }
  ],
  "total": 42,
  "page": 1,
  "per_page": 20
}
```

### GET /api/conversations/{session_id}
Szczegóły jednej rozmowy.

Response: pełne messages + search_diagnostics + response_times + admin_notes.
Messages: każda wiadomość AI ma pole `_diagnostics` z model, tokens, time_ms.
Tool calls i tool results: dostępne w messages JSON (frontend pokaże po "pokaż szczegóły").

### GET /api/settings
Zwraca wszystkie ustawienia z divechat_settings.

### POST /api/settings
Aktualizuje ustawienia. Body:
```json
{"key": "temperature", "value": 0.7}
```
Lub bulk:
```json
{"settings": {"temperature": 0.7, "emoji_enabled": false}}
```

### POST /api/conversations/{session_id}/status
Zmiana admin_status i admin_notes:
```json
{"status": "reviewed", "notes": "Klient pytał o Cressi, AI poprawnie odmówił"}
```

## Część 4: Ustawienia w ChatService
ChatService czyta ustawienia z divechat_settings (nie z .env) dla:
- primary_model, escalation_model
- temperature, max_tokens
- emoji_enabled (dodaj do system prompt: "Nie używaj emoji" jeśli false)
- knowledge_gap_threshold

Fallback na .env jeśli brak w bazie.

## Część 5: Response rozszerzony
POST /api/chat response dodaje pole `diagnostics`:
```json
{
  "success": true,
  "response": "...",
  "session_id": "...",
  "tools_used": [...],
  "products": [...],
  "usage": {"input_tokens": 5427, "output_tokens": 525},
  "diagnostics": {
    "model_used": "claude-sonnet-4-6",
    "response_times": {
      "total_ms": 3200,
      "ai_ms": 2300,
      "embedding_ms": 120,
      "tool_ms": 780
    },
    "search_diagnostics": [
      {
        "tool": "search_products",
        "query_text": "komputery nurkowe Suunto",
        "result_count": 3,
        "max_similarity": 0.735,
        "min_similarity": 0.512,
        "knowledge_gap": false
      }
    ],
    "knowledge_gap": false
  }
}
```

## Nowe pliki
- sql/002_extend_conversations.sql
- standalone/src/Controller/ConversationsController.php
- standalone/src/Controller/SettingsController.php
- standalone/src/Chat/SettingsStore.php
- standalone/config/routes.php (nowe routy)

## Pliki do modyfikacji
- standalone/src/Chat/ChatService.php (diagnostyka, timings, settings)
- standalone/src/Chat/ConversationStore.php (nowe kolumny w save)
- standalone/src/Chat/SystemPrompt.php (emoji toggle)
- standalone/src/Tools/ProductSearch.php (zwracaj matched_text w wynikach)
- standalone/src/Tools/ExpertKnowledge.php (zwracaj matched_text w wynikach)

## Definition of Done
- [ ] Migracja SQL wykonana na Railway
- [ ] ChatService zbiera timings, diagnostics, knowledge_gap
- [ ] GET /api/conversations zwraca listę z paginacją i filtrami
- [ ] GET /api/conversations/{id} zwraca pełne szczegóły
- [ ] GET/POST /api/settings działają
- [ ] POST /api/chat zwraca diagnostics w response
- [ ] Emoji toggle działa (wyłączenie = brak emoji w odpowiedzi)
- [ ] knowledge_gap poprawnie wykrywany przy similarity < threshold
