# TASK-014: Encyklopedia sprzętowa v2 (regeneracja od zera)

## Status: FAZA 1 DONE, FAZA 2 IN PROGRESS (pilot Grupa A)

## Cel
Wygenerować ustrukturyzowaną encyklopedię 105 kategorii sprzętu nurkowego jako Single Source of Truth
dla systemu czatu AI i bloga SEO (TASK-016).

## Zmiana vs v1
v1 miała 46 kategorii, adversarial review 3 modeli wykazał 85% błędów.
v2 regeneruje od zera z lepszym procesem: GPT-5.2 thinking + walidacja Claude Opus 4.6 extended.
Lista concept keys: `_docs/FAZA1_concept_keys_v2.md` (ZATWIERDZONA, 105 pojęć, 13 grup)
Mapa marek: `_docs/11_mapa_marek-reviewed.md` (do użycia w definicjach)
Dane keyword: `data/dataforseo/processed/all_keywords.csv` (1404 fraz z Google PL)

## ADR
ADR-033, ADR-034, ADR-035 w `_docs/10_decyzje_projektowe.md`

## Architektura: GCIE + walidacja
- Gemini 3.1 Pro z Context Caching (cały korpus ~650k tokenów w cache)
- 40 iteracyjnych zapytań (1 per kategoria)
- Walidacja automatyczna (7 gate'ów) + human review

## FAZA 1: Przygotowanie (2-3 dni)

### 1a. Lista 40 concept keys z kanonicznymi terminami PL/EN
Źródło: kategorie divezone.pl + podręczniki PADI/IANTD.

Format: `concept_key | canonical_term_pl | canonical_term_en`
Przykłady:
```
automat_oddechowy | automat oddechowy | regulator
pierwszy_stopien | pierwszy stopień | first stage
drugi_stopien | drugi stopień | second stage
szpulka | szpulka nurkowa | finger spool
kolowrotek | kołowrotek nurkowy | dive reel
boja_deko | boja dekompresyjna | surface marker buoy (SMB)
skrzydlo | skrzydło (wing) | wing / backplate & wing
jacket | jacket (BCD) | jacket BCD
maska_jednoszybowa | maska jednoszybowa | single lens mask
maska_dwuszybowa | maska dwuszybowa | two-lens mask
pianka_mokra | pianka mokra (skafander mokry) | wetsuit
suchy_skafander | suchy skafander | drysuit
komputer_nurkowy | komputer nurkowy | dive computer
pletwy_paskowe | płetwy paskowe | open heel fins
pletwy_kaloszowe | płetwy kaloszowe | full foot fins
...
```
Pełna lista do uzupełnienia w trakcie Fazy 1. Cel: pokryć wszystkie kategorie z divezone.pl + kluczowe pojęcia techniczne.

Deliverable: `data/encyclopedia/concept_keys.json`

### 1b. Golden test set (~50 par mylących + ~100 zapytań)

**Źródło par mylących:** `_docs/synonyms/synonyms_review_v3.csv` zawiera znane błędy:
- kołowrotek ↔ szpulka (ID 220, 222: "szpulka" i "spool" jako synonimy kołowrotka)
- uprząż stage → "aparat oddechowy" (ID 230: uprząż sklasyfikowana jako aparat)
- 1. stopień / 2. stopień → "aparat oddechowy" (ID 17, 18, 24, 25: komponent = całość)
- boja → "safety sausage" w PL kontekście (ID 213, 214)
- automat oddechowy → "lung" jako EN synonim (ID 11: archaizm, niezalecany)

**Format golden test:**
```json
{
  "contrast_pairs": [
    {
      "concept_a": "szpulka",
      "concept_b": "kolowrotek",
      "disambiguation": "szpulka nie ma korby, obsługiwana oburącz",
      "test_queries": ["szpulka do bojki", "spool 30m", "szpulka czy kołowrotek"]
    }
  ],
  "customer_queries": [
    {
      "query": "szukam dobrej oddechówki",
      "expected_concept": "automat_oddechowy",
      "expected_flag": "misleading_term",
      "forbidden_concepts": []
    }
  ]
}
```
Źródła zapytań: logi czatu divezone, GSC, Luigi's Box (Karol dostarcza).

Deliverable: `data/encyclopedia/golden_test_set.json`

### 1c. Normalizacja korpusu do formatu CiC (Corpus-in-Context)

Skrypt `scripts/encyclopedia/prepare_corpus.py`:
- Łączy źródła w jeden plik z XML tagami per źródło
- Kolejność (bottom-heavy: dane na początku, instrukcje na końcu):
  1. `<source_data_1_padi_en>` — PADI Encyclopedia Ch3+Ch5
  2. `<source_data_2_iantd_pl>` — Książka OWD IANTD
  3. `<source_data_3_cmas_pl>` — Skrypt CMAS
  4. `<source_data_4_nurkomania_pl>` — nurkomania.pl (sprzęt + teoria)
  5. `<source_data_5_divezone_metadata>` — kategorie sklepowe, golden test pairs
- Na końcu: system prompt z hierarchią źródeł, JSON Schema, conflict resolution rules, Chain-of-Dictionary
- Output: `data/encyclopedia/corpus_cached.txt` (~650k tokenów)

Pliki źródłowe: `_docs/wiedza_nurkowa/`

Deliverable: `scripts/encyclopedia/prepare_corpus.py`, `data/encyclopedia/corpus_cached.txt`

### 1d. JSON Schema rekordu pojęcia (Pydantic)

Skrypt `scripts/encyclopedia/models.py`:

```python
class Synonym(BaseModel):
    term: str
    type: Literal["exact_synonym", "near_synonym", "colloquial", 
                  "legacy_name", "brand_term", "anglicyzm", 
                  "misleading_term", "niezalecany"]
    language: Literal["pl", "en"]
    note: str | None = None

class Relation(BaseModel):
    type: Literal["nie_mylic_z", "nadrzedny", "podrzedny", 
                  "czesc_zestawu", "wariant"]
    target_concept_key: str
    why: str | None = None  # obowiązkowe dla nie_mylic_z
    disambiguation_clues: list[str] = []  # cechy odróżniające

class Evidence(BaseModel):
    source_id: str  # padi_ch3, iantd_owd, nurkomania_sprzet, etc.
    quote: str
    language: Literal["pl", "en"]

class ConceptEntry(BaseModel):
    concept_key: str
    canonical_term_pl: str
    canonical_term_en: str
    definition_operational_pl: str  # 1-2 zdania, OBOWIĄZKOWE
    definition_pl: str | None = None
    definition_en: str | None = None
    synonyms: list[Synonym] = []
    relations: list[Relation] = []
    evidence: list[Evidence] = []
    confidence: Literal["high", "medium", "low"] = "medium"
    status: Literal["validated", "needs_review"] = "needs_review"
    version: int = 1
```

Deliverable: `scripts/encyclopedia/models.py`

---

## FAZA 2: Generacja GCIE z Gemini 3.1 Pro (1 dzień)

Skrypt `scripts/encyclopedia/generate_gcie.py`:

### Krok 1: Cache Write
- Wysłanie `corpus_cached.txt` do Gemini Context Caching API
- TTL: 2h (bufor na ewentualne reruns)
- Model: `gemini-3.1-pro-preview` (lub aktualny stabilny)

### Krok 2: Pętla 40 zapytań
- Dla każdego concept_key z `concept_keys.json`:
  - Prompt iteracyjny (bottom-heavy): instrukcja ekstrakcji + JSON Schema + golden test pairs dla tej kategorii
  - `thinking_level: HIGH`
  - `response_mime_type: "application/json"` z Pydantic schema
  - Chain-of-Dictionary dla problematycznych anglicyzmów
  - Output: `data/encyclopedia/raw/{concept_key}.json`

### Prompt iteracyjny (szablon)
```
Na podstawie załadowanego korpusu, wygeneruj ustrukturyzowany obiekt JSON
wyłącznie dla kategorii: "{canonical_term_pl}" ({canonical_term_en}).

ZASADY:
1. Użyj TYLKO informacji z korpusu. Jeśli brak danych, ustaw null/[].
2. definition_operational_pl: 1-2 zdania odróżniające od najbliższego mylonego pojęcia.
3. Synonimy PL: TYLKO polskie. Anglicyzmy z etykietą "anglicyzm".
4. NIE tłumacz dosłownie slangu EN→PL (np. "safety sausage" ≠ "kiełbasa").
5. Relacje nie_mylic_z: OBOWIĄZKOWE jeśli w korpusie istnieją podobne pojęcia.
   Podaj why i disambiguation_clues.
6. Evidence: minimum 1 cytat per definicja, minimum 2 źródła jeśli dostępne.
7. confidence=high tylko jeśli 2+ źródeł potwierdza.

ZNANE PARY MYLĄCE (sprawdź relacje):
{relevant_contrast_pairs_for_this_concept}

Zwróć JSON zgodny z podanym schematem.
```

### Krok 3: Obsługa błędów
- Retry z backoff jeśli API error
- Jeśli JSON nie przechodzi Pydantic validation: retry z feedbackiem o błędzie
- Max 3 retry per concept, potem `status: "needs_review"` i skip
- Log: `data/encyclopedia/generation_log.jsonl`

### Fallback (jeśli >20% needs_review po Fazie 3)
- Przejście na Claude Opus z batchami po 5-10 kategorii
- Pełny korpus w kontekście (1M window)
- Ten sam JSON Schema i prompt

Deliverable: `scripts/encyclopedia/generate_gcie.py`, `data/encyclopedia/raw/*.json`

---

## FAZA 3: Walidacja i merge (1-2 dni)

Skrypt `scripts/encyclopedia/validate_and_merge.py`:

### 7 automatycznych gate'ów walidacji

1. **Schema validation** — Pydantic parse, odrzuć jeśli invalid
2. **Language isolation** — definicja_pl musi być PL, definicja_en musi być EN. Synonimy PL bez EN tokenów (chyba że typ=anglicyzm). Detekcja: langdetect + regex na angielskie słowa w polach PL.
3. **Global synonym uniqueness** — żaden termin nie może być exact_synonym w 2+ kategoriach. Buduj index `(language, normalized_term) → concept_key`.
4. **Symetria nie_mylic_z** — jeśli A→B, to B→A musi istnieć. Auto-injekcja brakujących.
5. **Min evidence rule** — definicja z <2 źródeł → `confidence: "medium"`, `status: "needs_review"`.
6. **Cross-field consistency** — jeśli concept ma `podrzedny: X`, to X musi mieć `nadrzedny: concept`.
7. **Golden test regression** — uruchom testy z golden_test_set.json. Sprawdź: (a) pary mylące mają nie_mylic_z, (b) misleading_term jest poprawnie oznaczony, (c) brak wycieków synonimów.

### Output walidacji
- `data/encyclopedia/validation_report.json` — wyniki per concept per gate
- `data/encyclopedia/equipment_encyclopedia.json` — zmergowany finał
- Logi: ile validated, ile needs_review, które gate'y failują najczęściej

### Lint terminologiczny (dodatkowe reguły z cross-review)
- Termin w exact_synonym i jednocześnie w nie_mylic_z innego pojęcia → ERROR
- Pojęcie z grupy ryzyka bez nie_mylic_z → WARNING
- Termin EN bez mapowania PL → WARNING
- Zbyt ogólne terminy (1 słowo, np. "maska") bez kontekstu → WARNING

Deliverable: `scripts/encyclopedia/validate_and_merge.py`, `data/encyclopedia/equipment_encyclopedia.json`

---

## FAZA 4: Integracja (po human review)

### 4a. Storage w PostgreSQL
```sql
CREATE TABLE IF NOT EXISTS equipment_concepts (
    concept_key VARCHAR(100) PRIMARY KEY,
    canonical_term_pl VARCHAR(255) NOT NULL,
    canonical_term_en VARCHAR(255) NOT NULL,
    entry_json JSONB NOT NULL,
    version INT DEFAULT 1,
    status VARCHAR(20) DEFAULT 'needs_review',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

CREATE TABLE IF NOT EXISTS validation_log (
    id SERIAL PRIMARY KEY,
    concept_key VARCHAR(100) REFERENCES equipment_concepts(concept_key),
    version INT,
    gate_name VARCHAR(50),
    result VARCHAR(10),  -- pass/fail/warning
    details TEXT,
    created_at TIMESTAMP DEFAULT NOW()
);
```

### 4b. Odblokowanie TASK-013
Po zatwierdzeniu encyklopedii, TASK-013 używa `equipment_encyclopedia.json` jako:
- Whitelist poprawnych synonimów per kategoria
- Blacklist (misleading_term, nie_mylic_z) do odrzucania halucynacji
- Few-shot examples generowane z encyklopedii zamiast ręcznych

### 4c. Integracja z czatem AI
- Function `get_equipment_terminology(concept_key)` w standalone API
- System prompt zawiera listę concept_keys
- AI używa encyklopedii do walidacji swoich rekomendacji

---

## Zależności
- **Wymaga:** Pliki źródłowe w `_docs/wiedza_nurkowa/` ✅ JEST
- **Wymaga:** Gemini API key ✅ JEST w `.env` jako `GOOGLE_GEMINI_API`
- **Wymaga:** Golden set z zapytań klientów ✅ JEST:
  - GSC: `_docs/dane_zewnetrzne_wyszukiwania/GSC-Performance-on-Search-2026-02-23/Zapytania.csv` (1000 zapytań) — pełne intencje klientów, GŁÓWNE źródło na golden set
  - Luigi's Box: `_docs/dane_zewnetrzne_wyszukiwania/luigisbox_416288_trending_searches-search_results_1771832038.csv` (1000 zapytań) — UWAGA: autocomplete z podpowiedziami, frazy są fragmentaryczne (klient wpisał 3-5 znaków i kliknął podpowiedź). Wartościowe jako źródło potocznych skrótów i żargonu (noz, waz, rekawice, bojka) do synonimów colloquial, NIE jako pełne zapytania testowe.
  - Znane błędy synonimów: `_docs/synonyms/synonyms_review_v3.csv` (100 produktów z błędnymi synonimami)
- **Blokuje:** TASK-013 (synonimy produktowe)

## Struktura plików
```
scripts/encyclopedia/
├── models.py              # Pydantic schemas
├── prepare_corpus.py      # Faza 1c: normalizacja korpusu
├── generate_gcie.py       # Faza 2: generacja z Gemini
├── validate_and_merge.py  # Faza 3: walidacja 7 gate'ów + merge
└── load_to_db.py          # Faza 4a: zapis do PostgreSQL

data/encyclopedia/
├── concept_keys.json      # Faza 1a: 40 concept keys
├── golden_test_set.json   # Faza 1b: pary mylące + zapytania
├── corpus_cached.txt      # Faza 1c: znormalizowany korpus
├── raw/                   # Faza 2: surowe JSONy z Gemini (per concept)
├── generation_log.jsonl   # Faza 2: log generacji
├── validation_report.json # Faza 3: raport walidacji
└── equipment_encyclopedia.json  # Faza 3: finalny zmergowany plik
```

## Szacunki
- Koszt API: ~$20-25 (Gemini Context Caching)
- Czas: 4-5 dni (2-3 Faza 1, 1 Faza 2, 1-2 Faza 3)
- Instancja: embeddings (cały task w Pythonie)

## Kolejność wykonania
1. `models.py` — schema first
2. `concept_keys.json` — lista pojęć (ręcznie + AI-assisted)
3. `golden_test_set.json` — pary mylące z synonyms_review_v3.csv + zapytania klientów
4. `prepare_corpus.py` — normalizacja źródeł
5. `generate_gcie.py` — generacja (wymaga Gemini API key)
6. `validate_and_merge.py` — walidacja + merge
7. Human review flagged entries
8. `load_to_db.py` — zapis do PostgreSQL
9. Odblokowanie TASK-013


---

# TASK-014 v2: PRZEGENEROWANIE ENCYKLOPEDII OD ZERA (2026-02-27)

## Powód
Adversarial review (3 modele) wykazał 85% błędów w v1.
Decyzja architekta + Karola: przegenerować ~90 kategorii od zera.
ADR-034 w `_docs/10_decyzje_projektowe.md`.

## Zmiany względem v1
- ~90 kategorii zamiast 46
- Pipeline: pojęcie po pojęcie z adversarial self-check (nie batch)
- Model: minimum GPT-5.2 thinking / Opus 4.6 extended (do uzgodnienia z Karolem)
- Dual output: JSON (AI) + artykuły blogowe (SEO)
- "Lista znanych błędów" z adversarial review wbudowana w prompt generacyjny
- Cross-walidacja każdej definicji przez 2. niezależny model

## Nowy pipeline
1. Lista ~90 concept keys → zatwierdzenie Karola
2. Per pojęcie: generuj → self-check (SKU? absoluty? pary?) → zapisz
3. Cross-walidacja: 2. model sprawdza każdą definicję
4. Karol rozstrzyga rozbieżności
5. Dual output: JSON + Blog markdown

## Pliki v1 (ARCHIWALNE, nie używać jako źródła prawdy)
- `data/encyclopedia/equipment_encyclopedia.json` — 46 wpisów z błędami
- `data/encyclopedia/validation_report.json` — walidacja strukturalna v1

## Pliki v2 (nowe)
- `_docs/15_raport_adversarial_review.md` — merge wyników
- `_docs/adversarial_review_encyklopedia-{Claude,GPT,Gemini}.md` — pełne wyniki
- `_docs/TASK-016_encyklopedia_blog.md` — spec dual output blogowego

## Status: CZEKA NA FAZĘ 1 (lista concept keys)
