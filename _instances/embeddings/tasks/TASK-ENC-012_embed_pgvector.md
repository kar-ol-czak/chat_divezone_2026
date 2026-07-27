# TASK-ENC-012: Embedding encyklopedii do pgvector
# Data: 2026-03-06
# Status: DO ZROBIENIA
# Instancja: embeddings
# Zależność: TASK-ENC-011 DONE (105/105 GREEN, encyclopedia_v3_all.md + .json)

---

## CEL

Chunking encyklopedii + embedding text-embedding-3-large + wgranie do PostgreSQL/pgvector.
Strategia: 5 chunków per hasło (definition, synonyms, purchase, faq, seller).

## KROK 1: TABELA

Połączenie: Railway PostgreSQL (dane w .env: DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD)

```sql
CREATE TABLE IF NOT EXISTS encyclopedia_chunks (
  id SERIAL PRIMARY KEY,
  concept_key VARCHAR(100) NOT NULL,
  concept_number INTEGER,
  name_pl VARCHAR(200),
  name_en VARCHAR(200),
  chunk_type VARCHAR(50) NOT NULL,
  content TEXT NOT NULL,
  embedding VECTOR(3072),
  metadata JSONB DEFAULT '{}',
  created_at TIMESTAMPTZ DEFAULT NOW(),
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Indeksy
CREATE INDEX IF NOT EXISTS idx_enc_embedding 
  ON encyclopedia_chunks USING ivfflat (embedding vector_cosine_ops) WITH (lists = 20);
CREATE INDEX IF NOT EXISTS idx_enc_concept ON encyclopedia_chunks (concept_key);
CREATE INDEX IF NOT EXISTS idx_enc_type ON encyclopedia_chunks (chunk_type);
CREATE UNIQUE INDEX IF NOT EXISTS idx_enc_concept_type 
  ON encyclopedia_chunks (concept_key, chunk_type);
```

Unique index na (concept_key, chunk_type) umożliwia UPSERT przy poprawkach.

## KROK 2: CHUNKING

Wczytaj `gen_v2/encyclopedia_v3.json` (master JSON, 105 haseł).
Albo jeśli nie istnieje, wczytaj poszczególne `gen_v2/raw/{KEY}.json`.

Per hasło twórz 5 chunków:

### Chunk 1: definition
```
{name_pl} / {name_en}

{definition}

Podtypy klienckie: {subtypes_client names joined}
Podtypy techniczne: {subtypes_technical names joined}
```
Cel: ogólne zapytania "co to jest X", "jak działa Y"

### Chunk 2: synonyms
```
Synonimy i frazy wyszukiwania dla: {name_pl}

Oficjalne: {official texts joined}
Bliskie: {close texts joined}
Slang: {slang texts joined}
Anglicyzmy: {anglicisms texts joined}
Błędne zapytania: {misspelled texts joined}

Frazy long-tail: {longtail texts joined}
```
Cel: semantic matching klienckich zapytań do hasła

### Chunk 3: purchase
```
Parametry zakupowe: {name_pl}

{purchase_parameters name: description joined}

Powiązane produkty:
{cross_sell product: description joined}

Nie mylić z:
{not_to_confuse concept_key: explanation joined}
```
Cel: zapytania zakupowe "jaki X wybrać", "co kupić do Y"

### Chunk 4: faq
```
FAQ: {name_pl}

{for each faq: Q: question \n A: answer}
```
Cel: bezpośrednie pytania klientów (najlepszy match do PAA)

### Chunk 5: seller
```
Uwagi dla sprzedawcy: {name_pl}

{seller_notes}

Powiązane hasła: {related_concept_keys joined}
```
Cel: wewnętrzny kontekst dla AI — nie pokazywany klientom bezpośrednio

### Metadata JSONB per chunk:
```json
{
  "concept_number": 1,
  "name_pl": "AUTOMAT ODDECHOWY",
  "name_en": "REGULATOR",
  "related_keys": ["OCTOPUS", "MANOMETR", ...],
  "evidence_count": 36,
  "pipeline_version": "v2"
}
```

## KROK 3: EMBEDDING

Model: text-embedding-3-large (3072 dimensions)
API: OpenAI (klucz w .env: OPENAI_API_KEY)

Batchuj po 20 chunków per API call (OpenAI embedding endpoint akceptuje array).
105 haseł × 5 chunków = 525 chunków → ~27 wywołań.

Szacowany koszt: ~$0.10-0.20 (embedding jest tani).

## KROK 4: UPSERT DO POSTGRESQL

```python
INSERT INTO encyclopedia_chunks 
  (concept_key, concept_number, name_pl, name_en, chunk_type, content, embedding, metadata)
VALUES ($1, $2, $3, $4, $5, $6, $7, $8)
ON CONFLICT (concept_key, chunk_type) 
DO UPDATE SET 
  content = EXCLUDED.content,
  embedding = EXCLUDED.embedding,
  metadata = EXCLUDED.metadata,
  updated_at = NOW();
```

UPSERT na unique(concept_key, chunk_type) — pozwala na łatwe poprawki później.

## KROK 5: WERYFIKACJA

Po wgraniu:
```sql
-- Ile chunków
SELECT COUNT(*) FROM encyclopedia_chunks;  -- oczekiwane: 525

-- Ile per type
SELECT chunk_type, COUNT(*) FROM encyclopedia_chunks GROUP BY chunk_type;
-- definition: 105, synonyms: 105, purchase: 105, faq: 105, seller: 105

-- Ile z embeddingami
SELECT COUNT(*) FROM encyclopedia_chunks WHERE embedding IS NOT NULL;  -- 525

-- Test semantic search
SELECT concept_key, chunk_type, 
       1 - (embedding <=> $query_embedding) as similarity
FROM encyclopedia_chunks
ORDER BY embedding <=> $query_embedding
LIMIT 10;
```

Zrób 3 testowe query:
1. "jaki automat do nurkowania wybrać" → powinien matchować AUTOMAT_ODDECHOWY
2. "suchy skafander ocieplacz" → SUCHY_SKAFANDER lub OCIEPLACZ
3. "bojka dekompresyjna szpulka" → BOJA_NURKOWA lub SZPULKA

## KROK 6: RAPORT

`data/encyclopedia/v3/gen_v2/embedding_report.md`:
- Chunków total: 525
- Embedding model: text-embedding-3-large
- Koszt embedding: $X
- Czas: Xs
- Wyniki 3 testowych query (top 5 matchów z similarity score)

## SKRYPT

Nowy plik: `scripts/embed_encyclopedia.py`

Tryby:
```bash
# Pełny run
python3 scripts/embed_encyclopedia.py --mode full

# Upsert pojedyncze hasło (do poprawek)
python3 scripts/embed_encyclopedia.py --mode single --concept MANOMETR

# Tylko test query (bez wgrywania)
python3 scripts/embed_encyclopedia.py --mode test-query --query "jaki automat wybrać"
```

→ STOP po full run. Czekaj na review architekta.

## NIE RÓB

- Nie modyfikuj encyclopedia_v3_all.md ani raw JSON-ów
- Nie usuwaj istniejących danych w encyclopedia_chunks (UPSERT)
- Nie zmieniaj modelu embedding (text-embedding-3-large, 3072 dim — to sam model co produkty)
