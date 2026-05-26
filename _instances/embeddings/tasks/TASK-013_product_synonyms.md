# TASK-013: Generowanie synonimów produktowych (PL + EN)
# STATUS: ⛔ ZABLOKOWANY — wymaga TASK-014 (encyklopedia sprzętowa)

## UWAGA: Znane problemy w few-shot examples
Poniższe examples zawierają błędy, które encyklopedia (TASK-014) ma wyeliminować:
- "oddechówka" jako synonim automatu → powinno być `misleading_term`
- "lung" jako EN synonim → powinno być `legacy_name` / `niezalecany`
- szpulka/spool jako synonim kołowrotka → to OSOBNE pojęcia (nie_mylic_z)
- "aparat oddechowy" jako synonim uprzęży stage → BŁĄD (komponent ≠ całość)

Po ukończeniu TASK-014: zaktualizować few-shot examples z `equipment_encyclopedia.json`
i dodać walidację synonimów przez encyklopedię (whitelist/blacklist).

---

## Cel
Wygenerować synonimy nazw produktowych i kategorii dla całego katalogu (~2600 produktów) i zapisać w PostgreSQL do użycia przez pipeline embeddingów oraz eksport CSV.

## Kontekst
- ADR-032 w `_docs/10_decyzje_projektowe.md`
- Synonimy poprawiają: pgvector search, SEO opisów, widoczność w wyszukiwarkach AI
- Przykłady: pianka neoprenowa = skafander mokry = wetsuit, jacket = skrzydło = BCD, automat oddechowy = aparat oddechowy = regulator

## Deliverables

### 1. Schema migration
Dodaj kolumny do `divechat_products`:
```sql
ALTER TABLE divechat_products
ADD COLUMN synonyms_pl JSONB DEFAULT '[]',
ADD COLUMN synonyms_en JSONB DEFAULT '[]';
```

### 2. Skrypt `scripts/generate_synonyms.py`

**Input per produkt:** nazwa, kategoria, krótki opis (pierwsze 200 znaków)

**Prompt (few-shot):**
- Generuj synonimy nazw produktowych i kategorii w PL i EN
- Kontekst: sprzęt nurkowy (scuba diving), nie pływanie, nie snorkeling
- Uwzględnij: nazwy potoczne, profesjonalne, angielskie odpowiedniki używane w PL
- Nie generuj synonimów cech (kolor, rozmiar) — tylko nazwy produktowe
- Format output: JSON `{"pl": [...], "en": [...]}`

**Few-shot examples w prompcie:**
```json
{"name": "Pianka Mares Flexa 8.6.5", "category": "Pianki neoprenowe"}
→ {"pl": ["skafander mokry", "pianka do nurkowania", "pianka neoprenowa", "neopren"], "en": ["wetsuit", "neoprene suit", "wet suit", "diving suit"]}

{"name": "Jacket Aqualung Axiom", "category": "Jackety"}
→ {"pl": ["skrzydło", "kamizelka wypornościowa", "BCD", "jacket nurkowy"], "en": ["BCD", "buoyancy compensator", "buoyancy control device", "dive jacket"]}

{"name": "Automat oddechowy Apeks XTX 200", "category": "Automaty oddechowe"}
→ {"pl": ["aparat oddechowy", "regulator", "oddechówka", "lung"], "en": ["regulator", "breathing apparatus", "demand valve", "scuba regulator"]}
```

**Batch processing:**
- Używaj Claude API (Sonnet 4.5), nie OpenAI
- Batch po 10 produktów per request (zmniejsza liczbę wywołań)
- Rate limiting: max 50 req/min
- Retry z exponential backoff
- Progress bar (tqdm)
- Zapis do PostgreSQL po każdym batchu (nie na końcu)

**Flagi CLI:**
- `--dry-run` — pokaż prompt dla 3 produktów, nie wysyłaj
- `--limit N` — przetworz tylko N produktów (do testów)
- `--export-csv output.csv` — eksport synonimów do CSV
- `--only-missing` — pomiń produkty które już mają synonimy

### 3. Integracja z pipeline embeddingów
W `generate_embeddings.py` (lub odpowiednim skrypcie): przy tworzeniu tekstu do wektoryzacji, dołącz synonimy:
```
{nazwa} {opis} Synonimy: {synonyms_pl joined} {synonyms_en joined}
```

## Kolejność
1. Migration (kolumny)
2. Skrypt + dry-run na 10 produktach → review output
3. Po akceptacji: pełny batch
4. Integracja z embeddings pipeline
5. Re-embedding całego katalogu

## Zależności
- TASK-013 wymaga wcześniejszego diffu baza prod vs dev (patrz notatki z sesji)
- Re-embedding (krok 5) powinien być na danych produkcyjnych, nie dev

## Dane dostępowe
- Claude API key: w `.env` jako `ANTHROPIC_API_KEY`
- PostgreSQL: connection string w `.env`
- Produkcyjny MySQL: do ustalenia (patrz diff task)