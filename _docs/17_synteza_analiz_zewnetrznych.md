# Synteza analiz zewnętrznych: OpenAI GPT-5.2 + Google Gemini 2.5 Pro
# Data: 2026-02-21
# Źródła: Analiza_Architektury_Wyszukiwania_Produktow_AI_-_OpenAI.md
#          Analiza_Architektury_Wyszukiwania_Produktow_AI_-_Gemini.md

## WERDYKT OGÓLNY

Oba modele potwierdzają, że 4-warstwowa architektura jest **fundamentalnie poprawna**
i prawidłowo adresuje vocabulary mismatch. Jednocześnie oba wskazują **te same 3
krytyczne błędy** do natychmiastowej korekty.

---

## KONSENSUS: 3 KRYTYCZNE ZMIANY (oba modele zgodne)

### ZMIANA 1: pg_trgm to za mało → Full-Text Search / BM25

**Problem:** pg_trgm nie rozumie struktury dokumentów. Ignoruje IDF (Inverse Document
Frequency) i normalizację długości. Zapytanie "Bare 7mm" traktuje rzadkie "Bare"
tak samo jak powszechne "7mm".

**Rozwiązania (dwie opcje):**

| Opcja | OpenAI | Gemini |
|---|---|---|
| A) PostgreSQL native FTS | tsvector/tsquery + dict_xsyn (słownik synonimów) + hunspell (lematyzacja PL) + unaccent | — |
| B) ParadeDB pg_search | — | BM25 jako extension do PostgreSQL, bez zewnętrznych silników |

pg_trgm zostaje, ale TYLKO do: literówek, fuzzy matching nazw własnych, dopasowań
"zawiera". NIE jako główny tor leksykalny.

**Nasza rekomendacja:** Opcja A (native FTS) jako start. Nie dodaje zależności
zewnętrznych (ParadeDB to dodatkowy extension na Railway). dict_xsyn daje nam
słownik synonimów nurkowych za darmo. Jeśli nie wystarczy, upgrade do ParadeDB.

### ZMIANA 2: Wagi liniowe 0.7/0.3 → Reciprocal Rank Fusion (RRF)

**Problem:** Cosine similarity (0.0-1.0, gęste wartości 0.55-0.85) i trigram/BM25
(różne skale) nie są porównywalne. Liniowa kombinacja jest niestabilna i
nieprzewidywalna.

**Rozwiązanie:** RRF ignoruje absolutne wartości, opiera się TYLKO na rangach:

```
RRF_score(d) = Σ 1 / (k + rank_i(d))
```

gdzie k = 60 (standard). Dokument na pozycji 1 w obu rankingach dostaje najwyższy
wynik. Implementacja w PostgreSQL przez CTE (Common Table Expressions).

**Konsensus:** Oba modele jednoznacznie rekomendują RRF. OpenAI dopuszcza też
strojenie wag na test secie, ale preferuje RRF gdy skale nieporównywalne.

### ZMIANA 3: Anti-phrases (frazy negatywne) w embeddingach → ZABRONIONE

**Problem:** Embeddingi nie rozumieją negacji. "To NIE jest latarka kempingowa"
przesuwa wektor BLIŻEJ latarek kempingowych, nie dalej. Semantyczna kolizja
przestrzenna.

**Rozwiązanie:** Negacja TYLKO przez filtry SQL w Warstwie 4 (LLM Query Planner).
LLM wykrywa negację → generuje WHERE category_name != 'Latarki kempingowe'.

**Konsensus:** Absolutnie jednoznaczny u obu modeli. Gemini szczególnie dobitnie
opisuje mechanizm porażki.

---

## KONSENSUS: ULEPSZENIA (oba modele zgodne, nie krytyczne)

### ULEPSZENIE 1: Multi-Vector Retrieval

**Zamiast jednego embedding_text → osobne wektory per aspekt:**

| Kolumna | Zawartość | Cel |
|---|---|---|
| embedding_name | Nazwa + marka + model | Zapytania nawigacyjne ("Shearwater Teric") |
| embedding_description | Opis + cechy + kategoria | Zapytania eksploracyjne ("komputer na trimix") |
| embedding_jargon | Wygenerowane frazy LLM | Vocabulary mismatch ("pianka na zimno") |

**Korzyść:** Frazy LLM nie są "rozmywane" przez marketingowy opis producenta.
Każdy wektor ma czysty sygnał.

**Koszt:** 3x embeddingów (3 * 2556 * $0.0001 ≈ $0.77), 3x storage wektorów
(nadal trivialny przy 2556 produktach).

**OpenAI:** "Rozdzielenie sygnałów zamiast jednego tekstu do osadzenia"
**Gemini:** "Gorąco polecane. Likwiduje zjawisko rozmycia."

### ULEPSZENIE 2: Agentic Query Planning (rozbudowa search_reasoning)

**Zamiast prostego stringa → strukturalny JSON:**

```json
{
  "intent": "exploratory",        // navigational | exploratory
  "semantic_query": "bezpieczny automat na zimną wodę",
  "exact_keywords": ["DIN", "nitrox"],
  "filters": {
    "category": "Automaty Oddechowe",
    "price_max": 3000,
    "exclude_categories": []
  },
  "routing": {
    "semantic_weight": 0.8,       // dynamiczne wagi per zapytanie
    "lexical_weight": 0.2
  }
}
```

**Korzyść:** LLM sam decyduje o proporcjach hybrid search per zapytanie.
Nawigacyjne → dominuje BM25. Eksploracyjne → dominuje embedding.

**OpenAI:** "osobny krok planowania, który zwraca strukturę"
**Gemini:** "Agentic Query Routing, zmuszając asystenta do rozbicia intencji"

### ULEPSZENIE 3: Język polski wymaga specjalnej obsługi

**Oba modele wskazują:**
- Lematyzacja polska (hunspell/Morfeusz) w FTS
- unaccent (klienci piszą "pianka" bez polskich znaków, ale też "płetwy")
- BPE tokenizacja jest suboptymalna dla polskiego (wysoka "subword fertility")
- Silna warstwa leksykalna (BM25/FTS) kompensuje słabość embeddingów dla PL

### ULEPSZENIE 4: Metryki do śledzenia

| Metryka | Typ | Opis |
|---|---|---|
| NDCG@K | IR | Czy trafne produkty są na szczycie wyników? |
| MRR | IR | Pozycja pierwszego trafnego wyniku |
| Zero Results Rate | Operacyjna | % zapytań bez wyników (cel: <5%) |
| Conversation Abandonment | UX | Gdzie użytkownicy porzucają czat |
| Switch-to-Search Ratio | UX | Ilu wraca do tradycyjnej wyszukiwarki (Gemini) |

---

## ROZBIEŻNOŚCI MIĘDZY MODELAMI

### 1. Re-ranking

**OpenAI:** "Mały etap re-rankingu, nawet przy 5-10 kandydatach. Cross-encoder
to standardowy element retrieve-then-rerank."
**Gemini:** Nie wspomina wprost o re-rankingu, skupia się na RRF i query planning.

**Nasza decyzja:** Odłożone. Przy LLM w pętli czatu i RRF, re-ranking jest
trzecim priorytetem. Wrócimy jeśli metryki pokażą problem z top-K jakością.

### 2. ColBERT

**Gemini:** Entuzjastyczny. "Może samodzielnie zastąpić potrzebę budowania
złożonych systemów hybrydowych."
**OpenAI:** Ostrożny. "Najpierw warto wycisnąć tańsze elementy."

**Nasza decyzja:** Nie teraz. ColBERT wymaga osobnej infrastruktury (nie jest
natywny w pgvector). Quick wins (FTS + RRF + multi-vector) dadzą więcej za mniej.

### 3. GraphRAG

**Gemini:** Widzi wartość dla kompatybilności sprzętowej (węże HP + automaty +
butle), ale rekomenduje jako "długoterminową strategię po uzyskaniu metryk".
**OpenAI:** "Klasyczna baza relacyjna z atrybutami często jest praktyczniejsza."

**Nasza decyzja:** Nie teraz. Kompatybilność sprzętowa rozwiązywalna przez
knowledge base (artykuły eksperckie) + metadane w JSON.

---

## NOWE POMYSŁY (których nie mieliśmy)

### A. Query Rewrite Cache (OpenAI)
LLM offline przepisuje znane frazy z GSC/Luigi's Box. Online: najpierw sprawdź
cache, potem LLM. Oszczędność tokenów + spójność.

### B. HyDE - Hypothetical Document Embeddings (Gemini)
Zamiast embeddować zapytanie, LLM generuje "hipotetyczny idealny produkt",
embedduje to i szuka. Odrzucone: dodatkowa latencja (kolejny LLM call).

### C. Dynamic Routing (Gemini)
LLM decyduje per zapytanie: nawigacyjne → BM25 dominuje, eksploracyjne →
embedding dominuje. Włączone do Agentic Query Planning.

### D. Syntetyczne dane treningowe (OpenAI)
Generowanie par (zapytanie, produkt) przez LLM do przyszłego fine-tuningu
embeddingów. Na razie odłożone, ale logowanie search_reasoning buduje dataset.

### E. Uwaga o wymiarach embeddingów (OpenAI)
text-embedding-3-large ma domyślnie 3072 wymiarów. My używamy 1536 (świadomie,
potwierdzone w TASK-002). OpenAI sugeruje sprawdzenie. SPRAWDZONE: 1536 to
celowa redukcja (Matryoshka), potwierdzona w testach z marginalną stratą jakości.

---

## ZAKTUALIZOWANY PLAN IMPLEMENTACJI

### Faza 1: TASK-011 (bez zmian, enrichment)
LLM Product Enrichment. GPT-5.2 generuje, Opus 4.6 waliduje.
BEZ anti-phrases (potwierdzone przez obie analizy).

### Faza 2: TASK-012 (ZMIENIONY)
Było: hybrid search (embedding + pg_trgm, wagi 0.7/0.3)
Jest: hybrid search 3-torowy z RRF

```
Tor 1: Embedding similarity (pgvector cosine)
Tor 2: Full-Text Search (tsvector/tsquery + dict_xsyn + unaccent)
Tor 3: Trigram (pg_trgm, TYLKO nazwy własne i fuzzy)
Fuzja: RRF (k=60)
```

### Faza 2b: TASK-012b (NOWY)
Multi-Vector Retrieval. Trzy kolumny wektorowe zamiast jednej.
Zależy od wyników TASK-011 (potrzebne wygenerowane frazy).

### Faza 3: TASK-013 (ZMIENIONY)
Było: search_reasoning string + category filter
Jest: Agentic Query Planning (strukturalny JSON z intent, filters, routing)

### Faza 4: TASK-014 (NOWY)
Ewaluacja: Golden Dataset (30-50 zapytań z GSC/Luigi's Box),
metryki NDCG@K, MRR, Zero Results Rate.
Kalibracja RRF k-parameter na golden dataset.

---

## DOKUMENTY ŹRÓDŁOWE ZACHOWANE
Pełne analizy w: data/external_reviews/
