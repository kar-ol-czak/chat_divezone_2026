# Synteza: OpenAI Deep Research vs Gemini 3.1 Pro — Encyklopedia sprzętowa

Data: 2026-02-25
Źródła: deep-research-report.md (OpenAI), Budowa_Encyklopedii_Nurkowej_z_AI.md (Gemini)

## 1. Porównanie rekomendacji

| Wymiar | OpenAI: Evidence-first Pipeline | Gemini: GCIE (Context Caching) |
|---|---|---|
| **Architektura** | 3-warstwowy pipeline: Chunks → Claims → Synthesis. Deterministyczny merge wg hierarchii źródeł | Cały korpus w cache Gemini 3.1 Pro, 40 iteracyjnych zapytań, post-processing Pythonem |
| **Model LLM** | Agnostyczny (GPT-4o mini do ekstrakcji, GPT-4o/Claude do syntezy) | Jednoznacznie Gemini 3.1 Pro (Context Caching jako fundament) |
| **Warstwa pośrednia** | TAK — "claims" (atomowe fakty z evidence). Kluczowa dla audytowalności | NIE — model generuje gotowy JSON per kategoria bezpośrednio z korpusu |
| **Walidacja** | Automatyczna (schema, język, unikalność, symetria) + human review queue | Post-processing Python (symetria nie_mylic_z, schema) |
| **Koszt MVP** | ~5-10 USD (embeddingi + ekstrakcja + synteza) | ~17 USD (cache write + storage + 40× cache read + output) |
| **Złożoność implementacji** | Wysoka (3 warstwy, tabele claims, pipeline orchestration) | Średnia (cache setup + pętla 40 zapytań + post-processing) |
| **Audytowalność** | Pełna (każdy fakt → claim → chunk → źródło) | Częściowa (pole evidence w JSON, ale brak warstwy pośredniej) |
| **Ryzyko halucynacji** | Niskie (model nie "zna nurkowania", operuje tylko na dowodach) | Niskie-średnie (model widzi cały korpus, ale nadal generuje swobodnie) |
| **Reużywalność** | Wysoka (claims layer reużywalna do artykułów, SEO, chatbota) | Średnia (JSON encyklopedii reużywalny, ale brak warstwy claims) |
| **Czas MVP** | 14 dni (plan dzień po dniu) | Kilka godzin technicznie, + czas na walidację |

## 2. Co każdy raport wnosi wartościowego

### OpenAI — mocne strony
- **Warstwa claims** jako architektura defensywna: każdy fakt ma dowód, konflikty widoczne przed syntezą
- **Deterministyczny merge** wg hierarchii źródeł per typ pola (nie globalnie)
- **Schemat danych** (chunks, claims, concepts, concept_entries) — gotowy do implementacji w PostgreSQL
- **Template prompt syntezy** z explicit rules: "nie tłumacz", "null jeśli brak dowodu"
- **Golden test cases** i wersjonowanie wpisów — regression testing
- **Rekomendacja LlamaIndex** z Pydantic programs i PGVectorStore

### Gemini — mocne strony
- **Context Caching** jako eleganckie rozwiązanie kosztu powtarzanych zapytań
- **Diagnoza przyczyn błędów** (sekcja 2) — znakomita analiza vector space proximity, neologizmów, cross-lingual contamination
- **Prompt Corpus-in-Context** (CiC) z kolejnością bloków — konkretna struktura (dane na początku, instrukcje na końcu)
- **Chain-of-Dictionary** do kontroli anglicyzmów — pragmatyczne
- **thinking_level: HIGH** w Gemini — wymuszenie głębszego rozumowania
- **Szybkość MVP** — technicznie kilka godzin vs 14 dni

## 3. Słabości i zagrożenia

### OpenAI
- **Overengineering na MVP?** 3-warstwowy pipeline z claims to solidna architektura produkcyjna, ale dla 40 kategorii i ~650k tokenów może być armata na muchę
- **14 dni** to dużo na MVP, który ma odblokować TASK-013 (synonimy)
- **Nie wykorzystuje** potencjału long-context models — chunking + retrieval to podejście sprzed ery 1M tokenów
- **LlamaIndex/LangChain** — dodatkowa zależność, learning curve, a pipeline jest na tyle prosty że custom Python wystarczy

### Gemini
- **Vendor lock-in** — architektura zbudowana wokół jednej funkcji jednego dostawcy (Context Caching Gemini)
- **Brak warstwy claims** — jeśli model wygeneruje błąd, trudno zdiagnozować "skąd" go wziął poza polem evidence
- **Optymistyczne założenia** o jakości — "model widzi cały korpus więc nie pomyli" to ta sama logika co "model zna nurkowanie"
- **Cennik >200k** — $4/1M input vs $2/1M dla <200k. Przy 650k tokenów wpadamy w droższy próg
- **Cache TTL** — jeśli iteracja trwa dłużej niż 1h (np. review + poprawki), cache wygasa i trzeba płacić ponownie

## 4. Kluczowe rozbieżności wymagające decyzji

### 4a. Claims layer: tak czy nie?
- OpenAI: absolutnie tak, to fundament
- Gemini: nie, model generuje gotowy wynik

### 4b. Full context vs retrieval?
- OpenAI: chunk + retrieve + extract
- Gemini: cały korpus w kontekście

### 4c. Jaki model?
- OpenAI: mix (GPT-4o mini + GPT-4o/Claude)
- Gemini: wyłącznie Gemini 3.1 Pro

### 4d. Ile czasu na MVP?
- OpenAI: 14 dni
- Gemini: "godziny" (realnie 2-3 dni z walidacją)


## 5. REKOMENDACJA: Architektura hybrydowa (Gemini GCIE + walidacja OpenAI-style)

### Decyzja główna
Wziąć najlepsze z obu podejść. Gemini GCIE jako silnik generacji, OpenAI-style walidacja jako sieć bezpieczeństwa.

### Uzasadnienie
1. Cel to MVP encyklopedii do odblokowania TASK-013 (synonimy). Nie budujemy systemu produkcyjnego do ciągłej ekstrakcji wiedzy.
2. 650k tokenów to za mało żeby uzasadnić 3-warstwowy pipeline z claims. Claims layer ma sens przy ciągłym przetwarzaniu nowych źródeł, nie przy jednorazowym batch.
3. Context Caching realnie rozwiązuje problem spójności (model widzi szpulkę i kołowrotek jednocześnie).
4. Walidacja post-processing jest konieczna niezależnie od podejścia — tu OpenAI ma rację.

### Architektura MVP (3 fazy)

**Faza 1: Przygotowanie korpusu (1 dzień)**
- Normalizacja źródeł do tagowanego formatu (XML tags per source, language)
- Kolejność CiC: PADI (EN) → IANTD (PL) → CMAS (PL) → nurkomania (PL) → divezone metadata
- Hierarchia źródeł + JSON Schema jako instrukcje na końcu (bottom-heavy)
- Lista 40 concept keys z canonical terms PL/EN

**Faza 2: GCIE z Gemini 3.1 Pro (1 dzień)**
- Cache Write: korpus + system prompt
- Pętla 40 zapytań z JSON Schema enforcement
- thinking_level: HIGH
- Chain-of-Dictionary dla problematycznych anglicyzmów
- Output: 40 plików JSON

**Faza 3: Walidacja i merge (1-2 dni)**
- Schema validation (Pydantic)
- Language isolation check (regex/langdetect per pole)
- Global synonym uniqueness (żaden termin w 2+ kategoriach, chyba że alias)
- Symetria nie_mylic_z (automatyczna injekcja brakujących)
- Min 2 sources per definition check → auto-flag needs_review
- Human review flagged entries
- Merge do jednego encyklopedia_sprzetowa.json
- Golden test cases (kołowrotek/szpulka, automat/aparat, bojka/sausage)

### Co bierzemy z OpenAI
- Schemat walidacji (7 automatycznych gate'ów)
- Golden test cases i regression testing
- Template prompt syntezy (reguły: null jeśli brak dowodu, nie tłumacz, nie wymyślaj)
- Deterministyczny merge per typ pola
- Wersjonowanie wpisów

### Co bierzemy z Gemini
- Context Caching jako mechanizm dostarczenia korpusu
- Prompt CiC (Corpus-in-Context, bottom-heavy)
- Chain-of-Dictionary dla anglicyzmów
- thinking_level: HIGH
- Iteracyjna ekstrakcja (40 osobnych zapytań)
- Post-processing Python do symetrii relacji

### Czego NIE bierzemy
- Claims layer (OpenAI) — overengineering na MVP 40 kategorii
- LlamaIndex/LangChain (OpenAI) — custom Python wystarczy
- Embeddingi chunków (OpenAI) — niepotrzebne gdy mamy full context
- Vendor lock-in na Gemini (Gemini) — jeśli GCIE zawiedzie jakościowo, fallback na Claude Opus z mniejszym kontekstem per batch 10 kategorii

### Schemat danych (uproszczony vs OpenAI)

Zamiast 6 tabel (sources, docs, chunks, claims, concepts, concept_entries):
- `equipment_concepts` — 40 rekordów, wersjonowany JSON
- `validation_log` — wyniki automatycznych gate'ów per concept per version
- Opcjonalnie w przyszłości: claims layer jeśli encyklopedia rośnie

### Szacowany koszt i czas

| Element | Koszt | Czas |
|---|---|---|
| Cache Write (650k tokenów) | ~$2.60 | minuty |
| Cache Storage (1-2h TTL) | ~$3-6 | — |
| 40× Cache Read | ~$10.40 | ~15-30 min |
| Output (50k tokenów) | ~$0.90 | — |
| Ewentualny rerun (10 poprawek) | ~$3-5 | — |
| **Razem** | **~$20-25** | **3-4 dni** |

### Fallback
Jeśli jakość GCIE okaże się niedostateczna (>20% entries z needs_review po walidacji):
1. Przejście na Claude Opus z batches po 5-10 kategorii + pełny korpus w kontekście (1M window)
2. Opcjonalnie: dodanie claims layer dla najbardziej problematycznych kategorii

### Następny krok
Aktualizacja TASK-014 zgodnie z tą architekturą.

---

## 6. Wnioski z cross-review (dodane 2026-02-25)

### 6.1. Kto oceniał kogo
- **OpenAI Deep Research** ocenił ogólną metodologię MVP → `ocena_metodologii_mvp_i_pipeline_encyklopedia_nurkowa.md`
- **Gemini 3.1 Pro** ocenił pipeline OpenAI → `Porównanie metodologii RAG i GCIE.md`

### 6.2. Zgodności (oba cross-review potwierdzają)
- Hybryda GCIE + deterministyczna walidacja to optymalne rozwiązanie. Gemini explicite rekomenduje fuzję: „silnik wiedzy z GCIE + logika decyzyjna z OpenAI".
- RAG/chunking jest nadmiarowy dla 40 kategorii i 650k tokenów. Full-context eliminuje ryzyko utraty relacji między pojęciami.
- Deterministyczny merge per typ pola (Warstwa C OpenAI) jest bezpieczniejszy niż poleganie na modelu w rozstrzyganiu konfliktów.
- Walidacja automatyczna (schema, symetria, język) jest konieczna niezależnie od podejścia generacji.

### 6.3. Nowe argumenty z cross-review (wzmocnienia do rekomendacji)

**A. Reframing celu: terminologia > encyklopedia (OpenAI CR)**
Cel MVP to nie „encyklopedia tekstowa", lecz **pipeline inżynierii terminologii**: pojęcie + definicja operacyjna + relacje + testy. Artykuły/opisy to warstwa wtórna.

**B. Typowane relacje (OpenAI CR)**
Dotychczasowy schemat miał uproszczone relacje. Cross-review wymaga granularności: `exact_synonym`, `near_synonym`, `colloquial`, `legacy_name`, `brand_term`, `misleading_term`, `not_same_as`, `part_of/has_part`. To kluczowe, bo brak typów prowadzi do „przecieków synonimów między kategoriami".

**C. Testy regresji jako element MVP (OpenAI CR)**
Nie jako etap końcowy, lecz od dnia 1. Trzy zestawy: (1) testy kontrastowe `nie_mylic_z`, (2) testy PL/EN mapowań, (3) testy wycieków synonimów. Golden set: 100+ realnych zapytań + 50+ par mylących.

**D. Definicja operacyjna (OpenAI CR)**
Każde pojęcie potrzebuje 1-2 zdań, które pozwalają odróżnić je od najbliższego mylonego pojęcia. To ważniejsze niż długa definicja encyklopedyczna.

**E. Claims via GCIE, nie via RAG (Gemini CR)**
Interesujący kompromis: zamiast budować warstwy chunków i embeddingów (OpenAI), można wyciągać „atomowe twierdzenia" przez GCIE, gdzie model widzi cały korpus. To daje audytowalność claims bez kosztu infrastruktury RAG. Opcja do rozważenia jako wzmocnienie jakości, jeśli MVP bez claims okaże się zbyt trudne do debugowania.

### 6.4. Korekta rekomendacji (sekcja 5)

Architektura hybrydowa (GCIE + walidacja) **się broni i jest wzmocniona** przez cross-review. Korekty:

1. **Schemat danych**: Rozszerzyć typowanie relacji (pkt 6.3.B). Dodać `definition_operational_pl` jako pole obowiązkowe (1-2 zdania odróżniające od najbliższego mylonego pojęcia).
2. **Golden test set**: Wchodzi do Fazy 1 (przygotowanie), nie Fazy 3. Zbierać realne zapytania klientów i pary mylące PRZED generacją.
3. **Testy regresji**: Obowiązkowe od MVP. Walidator „lint terminologiczny" sprawdza konflikty relacji.
4. **Opcjonalnie**: Claims via GCIE (nie RAG) jako dodatkowa warstwa audytu dla problematycznych kategorii, jeśli post-review wykaże trudności diagnostyczne.
5. **Scope**: Encyklopedia ~40 kategorii + golden set ~100 zapytań + ~50 par kontrastowych.

### 6.5. Czego cross-review NIE zmienił
- Wybór GCIE/Context Caching jako silnika generacji: bez zmian.
- Fallback na Claude Opus: bez zmian.
- Szacowany koszt (~$20-25) i czas (3-4 dni): bez zmian (golden set wymaga dodatkowego dnia, razem ~4-5 dni).
- Odrzucenie LlamaIndex/LangChain, embeddingów chunków, full claims layer dla MVP: bez zmian.
