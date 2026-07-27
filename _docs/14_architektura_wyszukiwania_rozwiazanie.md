# Architektura wyszukiwania eksperckiego: rozwiązanie v3
# Data: 2026-02-21
# Status: ZATWIERDZONY (po walidacji zewnętrznej)
# Kontekst: v2 + analizy GPT-5.2 i Gemini 2.5 Pro (_docs/17)
# Zastępuje: v2 tego dokumentu
# Decyzje: ADR-024, ADR-025

## Kontekst branżowy

Standardowy pipeline e-commerce search (Amazon, Taobao, Walmart, Zalando):
1. Query Understanding / Query Rewriting (pre-retrieval)
2. Hybrid Retrieval (semantic + lexical, nigdy jedno bez drugiego)
3. Re-ranking (cross-encoder lub LLM-as-judge)

Źródła: Amazon "Query Understanding" (2024), Taobao "BEQUE" (WWW 2024),
Zalando "LLM-as-judge" (2024), Amazon OpenSearch hybrid +8-12% vs keyword.

## Walidacja zewnętrzna

Architektura zwalidowana przez GPT-5.2 i Gemini 2.5 Pro (luty 2026).
Oba modele potwierdziły poprawność fundamentów, wskazały 3 krytyczne korekty
i 4 ulepszenia. Szczegóły: _docs/17_synteza_analiz_zewnetrznych.md

## Architektura DiveZone: 4 warstwy + ulepszenia

---

### WARSTWA 1: LLM Product Enrichment (build time)

Dla każdego z ~2556 produktów, silny LLM generuje 5-8 alternatywnych fraz
wyszukiwania, wzbogaconych o realne dane z GSC/Luigi's Box/GA4.

**Pipeline:**
1. Zebranie realnych fraz klientów (GSC, Luigi's Box, GA4)
2. Grupowanie fraz per kategoria produktowa
3. Dla każdego produktu: prompt do GPT-5.2 z danymi produktu + realne frazy jako kontekst
4. Walidacja przez Claude Opus 4.6 (drugi LLM ocenia jakość)
5. Zapis fraz do search_phrases JSONB
6. Reembedding z wzbogaconym tekstem

**Model enrichment:** GPT-5.2 generuje, Claude Opus 4.6 waliduje.
Test na 30 produktach (po 10 z 3 różnych kategorii), potem full batch.

**Prompt:** Pełna wersja w _docs/14 v2 (z kontekstem realnych fraz klientów).

**⚠️ ZAKAZ anti-phrases:** Frazy negatywne ("to NIE jest latarka kempingowa")
ZABRONIONE w embeddingach. Embeddingi nie rozumieją negacji, przesuną wektor
BLIŻEJ negowanego pojęcia. Negacja wyłącznie przez filtry SQL (Warstwa 4).
Potwierdzone przez obie analizy zewnętrzne.

**Koszt:** ~$2-6 za pełny run (zależy od modelu). Jednorazowy.

---

### WARSTWA 2: Hybrid Search 3-torowy z RRF (query time)

**ZMIANA vs v2:** pg_trgm nie jest głównym torem leksykalnym. Dodany Full-Text
Search. Kombinacja liniowa zastąpiona przez RRF.

**3 tory retrieval:**

| Tor | Technologia | Łapie co | Indeks |
|---|---|---|---|
| Semantyczny | pgvector cosine distance | "pianka na zimno" → Skafandry | HNSW |
| Full-Text Search | tsvector/tsquery + dict_xsyn | "automat oddechowy DIN" → z wagami IDF | GIN |
| Fuzzy/nazwy | pg_trgm similarity | "Shearwater Teric", literówki | GIN |

**Full-Text Search konfiguracja:**

```sql
-- Słownik synonimów nurkowych (dict_xsyn)
-- Plik: /usr/share/postgresql/tsearch_data/diving_synonyms.syn
-- Format: pianka skafander wetsuit neopren
--         automat regulator oddechowy
--         jacket bcd kamizelka wyrównawcza
--         skrzydło wing backplate

-- Konfiguracja FTS z polską lematyzacją + synonimami + unaccent
CREATE TEXT SEARCH CONFIGURATION diving_pl (COPY = pg_catalog.simple);
-- Dodać: unaccent, diving_synonyms, polish stemmer/hunspell

-- Indeks FTS na document_text
CREATE INDEX idx_product_fts
    ON divechat_product_embeddings
    USING gin (to_tsvector('diving_pl', document_text));
```

**Reciprocal Rank Fusion (RRF):**

```sql
WITH semantic AS (
    SELECT ps_product_id,
           ROW_NUMBER() OVER (ORDER BY embedding <=> $1::vector) AS rank
    FROM divechat_product_embeddings
    WHERE is_active = true AND (filtry...)
    ORDER BY embedding <=> $1::vector
    LIMIT 30
),
fulltext AS (
    SELECT ps_product_id,
           ROW_NUMBER() OVER (ORDER BY ts_rank_cd(
               to_tsvector('diving_pl', document_text),
               plainto_tsquery('diving_pl', $2)
           ) DESC) AS rank
    FROM divechat_product_embeddings
    WHERE is_active = true
      AND to_tsvector('diving_pl', document_text) @@ plainto_tsquery('diving_pl', $2)
    LIMIT 30
),
trigram AS (
    SELECT ps_product_id,
           ROW_NUMBER() OVER (ORDER BY similarity(product_name, $2) DESC) AS rank
    FROM divechat_product_embeddings
    WHERE is_active = true
      AND similarity(product_name, $2) > 0.3
    LIMIT 30
),
combined AS (
    SELECT COALESCE(s.ps_product_id, f.ps_product_id, t.ps_product_id) AS ps_product_id,
           COALESCE(1.0 / (60 + s.rank), 0) +
           COALESCE(1.0 / (60 + f.rank), 0) +
           COALESCE(1.0 / (60 + t.rank), 0) AS rrf_score
    FROM semantic s
    FULL OUTER JOIN fulltext f USING (ps_product_id)
    FULL OUTER JOIN trigram t USING (ps_product_id)
)
SELECT c.rrf_score, p.*
FROM combined c
JOIN divechat_product_embeddings p ON p.ps_product_id = c.ps_product_id
ORDER BY c.rrf_score DESC
LIMIT $limit;
```

**k=60:** Standard w literaturze. Do kalibracji na golden dataset (TASK-014).

---

### WARSTWA 3: Agentic Query Planning (query time, w AI)

**ZMIANA vs v2:** search_reasoning ewoluuje z prostego stringa do
strukturalnego JSON. LLM jest "Query Plannerem".

**Tool schema (search_products):**

```json
{
  "search_plan": {
    "type": "object",
    "description": "Plan wyszukiwania. Wypełnij PRZED wywołaniem search.",
    "properties": {
      "intent": {
        "type": "string",
        "enum": ["navigational", "exploratory"],
        "description": "navigational = klient zna produkt/markę. exploratory = szuka porady."
      },
      "reasoning": {
        "type": "string",
        "description": "Krótki reasoning: co klient potrzebuje, dlaczego taki query."
      },
      "semantic_query": {
        "type": "string",
        "description": "Zapytanie do wyszukiwania semantycznego (embedding)."
      },
      "exact_keywords": {
        "type": "array",
        "items": {"type": "string"},
        "description": "Nazwy własne, modele, parametry do dopasowania literalnego."
      }
    }
  },
  "category": {
    "type": "string",
    "description": "WYMAGANE przy exploratory. Opcjonalne przy navigational."
  },
  "filters": {
    "type": "object",
    "properties": {
      "price_min": {"type": "number"},
      "price_max": {"type": "number"},
      "brand": {"type": "string"},
      "in_stock_only": {"type": "boolean", "default": true},
      "exclude_categories": {
        "type": "array",
        "items": {"type": "string"},
        "description": "Kategorie do WYKLUCZENIA (obsługa negacji)."
      }
    }
  }
}
```

**Negacja przez filtry:** Gdy klient mówi "nie szukam latarki kempingowej",
LLM dodaje do exclude_categories zamiast do query embeddingowego.

---

### WARSTWA 4: Category + Structured Filters (query time)

Category staje się częścią search_plan (Warstwa 3).
Filtry SQL: category, price range, brand, in_stock, exclude_categories.

---

### Warstwa pominięta: Re-ranking

Odłożona. Przy LLM w pętli czatu + RRF + multi-vector, re-ranking jest
trzecim priorytetem. Cross-encoder do rozważenia jeśli metryki pokażą
problem z top-K jakością po wdrożeniu warstw 1-4.

## Ulepszenie: Multi-Vector Retrieval (TASK-012b)

**NOWE (rekomendacja obu analiz zewnętrznych).**

Zamiast jednej kolumny embedding → 3 wyspecjalizowane:

| Kolumna | Zawartość | Kiedy dominuje |
|---|---|---|
| embedding_name | Nazwa + marka + model | Zapytania nawigacyjne |
| embedding_desc | Opis + cechy + kategoria | Zapytania eksploracyjne |
| embedding_jargon | Frazy LLM (search_phrases) | Vocabulary mismatch |

**Korzyść:** Frazy wygenerowane przez LLM nie są "rozmywane" przez marketingowy
opis producenta. Każdy wektor ma czysty, izolowany sygnał.

**Koszt:** 3x embeddingów = 3 * 2556 * ~$0.0001 ≈ $0.77. Trivialny.
Storage: 3 * 2556 * 1536 * 4 bytes ≈ 45 MB. Trivialny.

**RRF z multi-vector:** Każda kolumna wektorowa staje się osobnym torem
w RRF (5 torów zamiast 3: 3 wektory + FTS + trigram).

---

## Schema changes (v3)

```sql
-- Nowe kolumny
ALTER TABLE divechat_product_embeddings
    ADD COLUMN search_phrases JSONB DEFAULT '[]'::jsonb,
    ADD COLUMN embedding_name vector(1536),
    ADD COLUMN embedding_desc vector(1536),
    ADD COLUMN embedding_jargon vector(1536);

-- Indeksy HNSW dla multi-vector
CREATE INDEX idx_embedding_name_hnsw
    ON divechat_product_embeddings
    USING hnsw (embedding_name vector_cosine_ops);
CREATE INDEX idx_embedding_desc_hnsw
    ON divechat_product_embeddings
    USING hnsw (embedding_desc vector_cosine_ops);
CREATE INDEX idx_embedding_jargon_hnsw
    ON divechat_product_embeddings
    USING hnsw (embedding_jargon vector_cosine_ops);

-- Full-Text Search
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;

-- Indeks FTS
CREATE INDEX idx_product_fts
    ON divechat_product_embeddings
    USING gin (to_tsvector('simple', document_text));

-- Indeksy trigram (TYLKO na nazwę i markę)
CREATE INDEX idx_product_name_trgm
    ON divechat_product_embeddings
    USING gin (product_name gin_trgm_ops);
CREATE INDEX idx_brand_name_trgm
    ON divechat_product_embeddings
    USING gin (brand_name gin_trgm_ops);
```

---

## Plan implementacji (v3, zaktualizowany)

### TASK-011: LLM Product Enrichment
Instancja: embeddings (Python) | Czas: 1 dzień | Koszt: ~$6 API
1. Ekstrakcja i czyszczenie fraz z GSC/Luigi's Box/GA4 (skrypt Python)
2. Grupowanie fraz per kategoria
3. generate_search_phrases.py: batch GPT-5.2 z kontekstem realnych fraz
4. validate_search_phrases.py: batch Claude Opus 4.6 (walidacja)
5. ALTER TABLE + zapis search_phrases do JSONB
6. Modyfikacja build_document_text() o "Szukaj też jako:"
7. Reembedding
8. Test: 10 zapytań, similarity before/after

### TASK-012: Hybrid Search 3-torowy z RRF
Instancja: integration (SQL) + backend (PHP) | Czas: 1 dzień
1. Konfiguracja FTS: dict_xsyn z synonimami nurkowymi, unaccent
2. CREATE INDEX (FTS GIN, trigram GIN)
3. ProductSearch.php: zapytanie RRF (3 CTE + fusion)
4. Test: 15 zapytań (literalne + semantyczne + mieszane)

### TASK-012b: Multi-Vector Retrieval
Instancja: embeddings + integration | Czas: 0.5 dnia
Zależy od: TASK-011 (potrzebne search_phrases)
1. Generowanie 3 tekstów per produkt (name, desc, jargon)
2. Batch embedding 3 kolumn
3. CREATE INDEX HNSW * 3
4. Rozszerzenie RRF o dodatkowe tory wektorowe
5. Test: porównanie 1-vector vs 3-vector na golden dataset

### TASK-013: Agentic Query Planning
Instancja: backend (PHP) | Czas: 0.5 dnia
1. Nowy tool schema z search_plan (JSON)
2. System prompt: instrukcja planowania + 5 przykładów
3. Obsługa exclude_categories (negacja przez SQL)
4. Logowanie search_plan do diagnostyki
5. Test E2E: 15 scenariuszy

### TASK-014: Golden Dataset + Ewaluacja
Instancja: integration | Czas: 0.5 dnia
1. 30-50 zapytań z GSC/Luigi's Box (realne frazy klientów)
2. Ręczne oznaczenie expected products per zapytanie
3. Skrypt ewaluacji: NDCG@5, MRR, Recall@10, Zero Results Rate
4. Baseline (przed zmianami) vs po każdym TASK
5. Kalibracja RRF k-parameter

### Kolejność:
```
TASK-011 (enrichment) → TASK-012 (hybrid+RRF) → TASK-012b (multi-vector)
                                                      ↓
                      TASK-014 (golden dataset) ← TASK-013 (query planning)
```

TASK-014 idealnie powinien być PRZED pozostałymi (baseline), ale wymaga
zebranych danych z GSC/Luigi's Box, więc można robić równolegle z TASK-011.

---

## Metryki sukcesu

| Zapytanie testowe | Oczekiwany wynik | Metryka |
|---|---|---|
| "pianka" | Skafandry w top 5 | Recall@5 |
| "pianka na zimno" | Skafandry Na ZIMNE wody w top 3 | MRR |
| "BARE 7mm" | Produkty BARE w top 3 | Trigram hit |
| "komputer do trimixu" | Shearwater w top 5 | Semantic hit |
| "latarka do jaskiń" | Latarki główne w top 5 | Enrichment hit |
| "coś na prezent dla nurka" | Różnorodne propozycje | Exploratory |
| Zero results | < 5% zapytań | Zero Results Rate |
| search_plan wypełniony | > 90% tool calls | Coverage |

---

## Pliki do zmodyfikowania

| Plik | Zmiana | Warstwa | Task |
|---|---|---|---|
| embeddings/clean_search_data.py | NOWY: czyszczenie GSC/LB/GA4 | 1 | 011 |
| embeddings/generate_search_phrases.py | NOWY: batch GPT-5.2 | 1 | 011 |
| embeddings/validate_search_phrases.py | NOWY: batch Opus 4.6 | 1 | 011 |
| embeddings/extract_products.py | build_document_text() + phrases | 1 | 011 |
| embeddings/batch_embed_products.py | reembedding + multi-vector | 1 | 012b |
| sql/migration_v3.sql | Schema changes + indeksy | 1+2 | 011+012 |
| config/diving_synonyms.syn | NOWY: słownik synonimów FTS | 2 | 012 |
| standalone/src/Tools/ProductSearch.php | RRF + multi-vector + filters | 2+3 | 012+013 |
| standalone/src/Chat/SystemPrompt.php | Agentic query planning examples | 3 | 013 |
| integration/golden_dataset.json | NOWY: 30-50 zapytań testowych | eval | 014 |
| integration/eval_search.py | NOWY: NDCG, MRR, Recall | eval | 014 |
