# TASK-052a: Migration – tabele cennika i logowania zużycia

**Status:** TODO
**Instancja:** backend
**Powiązany ADR:** ADR-051
**Zależności:** brak (pierwszy task w sekwencji 052a → 052b → 052c)

---

## Cel

Utworzyć schemat PostgreSQL dla cennika modeli AI oraz logowania zużycia tokenów per
wiadomość, plus agregaty kosztu w `divechat_conversations`. Seedować ceny z cennika
ADR-051 dla 8 modeli.

**STOP po wykonaniu tego tasku.** Karol robi review SQL przed TASK-052b.

---

## Zakres

### 1. Plik migracyjny `sql/007_model_pricing_and_usage.sql`

Trzy tabele i rozszerzenie istniejącej:

#### 1.1. `divechat_model_pricing`

```sql
CREATE TABLE divechat_model_pricing (
    model_id VARCHAR(64) PRIMARY KEY,
    provider VARCHAR(32) NOT NULL,
    label VARCHAR(128) NOT NULL,
    input_price_per_million NUMERIC(10,4) NOT NULL,
    output_price_per_million NUMERIC(10,4) NOT NULL,
    cache_read_price_per_million NUMERIC(10,4),
    cache_creation_price_per_million NUMERIC(10,4),
    currency VARCHAR(3) NOT NULL DEFAULT 'USD',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_escalation BOOLEAN NOT NULL DEFAULT FALSE,
    supports_temperature BOOLEAN NOT NULL DEFAULT FALSE,
    supports_reasoning_effort BOOLEAN NOT NULL DEFAULT FALSE,
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_pricing_provider ON divechat_model_pricing(provider) WHERE is_active = TRUE;
```

#### 1.2. `divechat_message_usage`

```sql
CREATE TABLE divechat_message_usage (
    id BIGSERIAL PRIMARY KEY,
    conversation_id VARCHAR(64) NOT NULL,
    message_id BIGINT,
    model_id VARCHAR(64) NOT NULL,
    input_tokens INTEGER NOT NULL DEFAULT 0,
    output_tokens INTEGER NOT NULL DEFAULT 0,
    cache_read_tokens INTEGER NOT NULL DEFAULT 0,
    cache_creation_tokens INTEGER NOT NULL DEFAULT 0,
    cost_input_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    cost_output_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    cost_cache_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    cost_total_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_usage_conversation ON divechat_message_usage(conversation_id);
CREATE INDEX idx_usage_created ON divechat_message_usage(created_at DESC);
CREATE INDEX idx_usage_model ON divechat_message_usage(model_id);
```

#### 1.3. `divechat_exchange_rates`

```sql
CREATE TABLE divechat_exchange_rates (
    rate_date DATE NOT NULL,
    currency VARCHAR(3) NOT NULL,
    rate_to_pln NUMERIC(10,4) NOT NULL,
    source VARCHAR(32) NOT NULL DEFAULT 'NBP',
    fetched_at TIMESTAMP NOT NULL DEFAULT NOW(),
    PRIMARY KEY (rate_date, currency)
);
```

#### 1.4. Rozszerzenie `divechat_conversations`

```sql
ALTER TABLE divechat_conversations
    ADD COLUMN IF NOT EXISTS total_cost_usd NUMERIC(10,6) NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_input_tokens INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_output_tokens INTEGER NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS total_cache_read_tokens INTEGER NOT NULL DEFAULT 0;
```

### 2. Seed cennika w tej samej migracji

Dane z ADR-051 (ceny w USD per milion tokenów):

| model_id | provider | label | input | output | cache_read | cache_create | escalation | temp | effort |
|----------|----------|-------|-------|--------|------------|--------------|------------|------|--------|
| `claude-opus-4-7` | claude | Claude Opus 4.7 | 5.00 | 25.00 | 0.50 | 6.25 | TRUE | FALSE | TRUE |
| `claude-sonnet-4-6` | claude | Claude Sonnet 4.6 | 3.00 | 15.00 | 0.30 | 3.75 | FALSE | FALSE | TRUE |
| `claude-haiku-4-5` | claude | Claude Haiku 4.5 | 1.00 | 5.00 | 0.10 | 1.25 | FALSE | FALSE | TRUE |
| `gpt-5.4` | openai | GPT-5.4 | 2.50 | 14.00 | NULL | NULL | TRUE | FALSE | TRUE |
| `gpt-4.1` | openai | GPT-4.1 | 2.00 | 8.00 | NULL | NULL | FALSE | TRUE | FALSE |
| `gpt-5.4-mini` | openai | GPT-5.4 Mini | 0.75 | 4.00 | NULL | NULL | FALSE | FALSE | TRUE |
| `o3-mini` | openai | o3-mini | 1.10 | 4.40 | NULL | NULL | FALSE | FALSE | TRUE |
| `gpt-5-mini` | openai | GPT-5 Mini | 0.25 | 2.00 | NULL | NULL | FALSE | FALSE | TRUE |

Insert jako `INSERT ... ON CONFLICT (model_id) DO UPDATE`, żeby migracja była idempotentna.

### 3. Rollback `sql/007_model_pricing_and_usage_rollback.sql`

DROP wszystkich trzech tabel + ALTER TABLE DROP COLUMN dla `divechat_conversations`.

---

## Kryteria akceptacji

1. Migracja wykonuje się czysto na świeżej bazie i jest idempotentna (kolejne wywołania nie błądzą).
2. Po seedingu `SELECT COUNT(*) FROM divechat_model_pricing WHERE is_active = TRUE` zwraca 8.
3. Indeksy stworzone, FK do `divechat_conversations` przez `conversation_id` (typ zgodny z istniejącą tabelą – sprawdzić w `001_create_tables.sql`).
4. Rollback usuwa wszystkie obiekty bez błędów na bazie po wykonanej migracji.

---

## Czego NIE robić

- NIE pisać żadnego PHP – tylko SQL.
- NIE dodawać RLS, polityk, triggerów – proste tabele wystarczają.
- NIE zmieniać istniejących migracji 001-006.
- NIE dodawać kolumn do `divechat_settings` – cennik to osobna tabela.
- NIE seedować kursów walut w tej migracji (to robi backend daily fetcher w TASK-052b).

---

## Pytania do architekta

Jeśli nasuną się wątpliwości implementacyjne (np. typ klucza w `divechat_conversations`,
nazwy ograniczeń, czy potrzebny jest FK), zapisać w `_instances/backend/handoff/052a_questions.md`
i zatrzymać się – nie zgadywać.


---

## AKTUALIZACJA 2026-04-30 (po review schemy przez backend)

Specyfikacja powyżej została skorygowana. **Stosować zmiany z `_instances/backend/handoff/052a_decisions.md`:**

- `conversation_id` typu INTEGER z FK (decyzja 19a)
- BEZ kolumn `total_*_tokens` i `total_cost_usd` – używamy istniejących `tokens_input`, `tokens_output`, `estimated_cost` (decyzja 20a, D-modified)
- `estimated_cost` zmienia typ z DECIMAL(8,6) na NUMERIC(10,6) (rozszerzenie precyzji)
- Nowe kolumny w `divechat_conversations`: tylko `cache_read_tokens` i `cache_creation_tokens`
- `message_id BIGINT` bez FK z komentarzem (decyzja 21a)
- Nazwa migracji `007_*` OK (decyzja 22a)

Plik decyzji jest źródłem prawdy w przypadku konfliktu z opisem powyżej.
