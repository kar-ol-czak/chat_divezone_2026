# TASK-ENC-010: Merge encyklopedii + pipeline do pgvector
# Data: 2026-03-05
# Status: DO ZROBIENIA
# Instancja: embeddings
# Zależność: TASK-ENC-009 R1-R8 DONE, wszystkie approved

---

## CEL

Scalenie 8 rund (R1-R8) w jeden plik master, walidacja kompletności,
przygotowanie do human review Karola, a następnie parser do JSON
i wgranie do pgvector.

## ETAP 1: MERGE (zrób od razu)

### Krok 1: Scal R1-R8

```bash
cat data/encyclopedia/v3/batch/R1_oddychanie.md \
    data/encyclopedia/v3/batch/R2_butle_zawory.md \
    data/encyclopedia/v3/batch/R3_kontrola_plywalnisci.md \
    data/encyclopedia/v3/batch/R4_instrumenty.md \
    data/encyclopedia/v3/batch/R5_maski_pianki.md \
    data/encyclopedia/v3/batch/R6_suchy_skafander.md \
    data/encyclopedia/v3/batch/R7_pletwy_bezp_oswietlenie.md \
    data/encyclopedia/v3/batch/R8_akcesoria_zestawy.md \
    > data/encyclopedia/v3/encyclopedia_v3_all.md
```

### Krok 2: Usuń artefakty batchowania

Z pliku master usuń:
- Linie `<!-- BATCH N -->` 
- Linie `====...====` (separatory batchy)
- Puste linie na początku pliku
- Trailing whitespace

### Krok 3: Walidacja kompletności

Napisz krótki skrypt Python `scripts/validate_encyclopedia.py` który:

1. **Zlicza hasła** — parsuj nagłówki `## N. NAZWA` i policz.
   Oczekiwane: 105 unikalnych haseł (106 minus duplikat ZESTAW SERWISOWY).

2. **Sprawdza brakujące concept keys** — porównaj z listą z FAZA1_concept_keys_v2.md.
   Wypisz które concept keys NIE mają hasła i odwrotnie.

3. **Sprawdza strukturę każdego hasła** — każde hasło MUSI mieć sekcje:
   - `### Definicja i zasada działania`
   - `### Podtypy i konstrukcje`
   - `### Synonimy i słowa kluczowe`
   - `### Nie mylić z`
   - `### Parametry zakupowe`
   - `### Powiązane produkty (Cross-selling)`
   - `### FAQ klienta`
   - `### Uwagi dla sprzedawcy`
   Wypisz hasła z brakującymi sekcjami.

4. **Sprawdza concept key linki** — zbierz wszystkie `(→ KEY)` i sprawdź
   czy każdy KEY istnieje jako hasło w encyklopedii. Wypisz broken links.

5. **Sprawdza DIN/INT** — grep po "INT" i "yoke" w kontekstach gdzie
   mogłyby być traktowane jako równorzędna opcja. Flaguj podejrzane.

6. **Statystyki** — chars/hasło, min, max, avg, median.

Output: `data/encyclopedia/v3/validation_report.md`

### Krok 4: Raport merge

Wygeneruj: `data/encyclopedia/v3/merge_report.md`
Zawiera:
- Łączna liczba haseł
- Łączna objętość (chars, KB)
- Lista haseł z numerami (spis treści)
- Wynik walidacji
- Koszt całkowity (zsumuj z raportów R1-R8)

**→ STOP po etapie 1. Czekaj na feedback architekta i human review Karola.**

---

## ETAP 2: HUMAN REVIEW (czeka na Karola)

Karol czyta `encyclopedia_v3_all.md` i nanosi poprawki.
Możliwe formaty feedbacku:
- Bezpośrednie edycje w pliku .md
- Lista poprawek "hasło X, sekcja Y, zmień Z na W"
- Komentarze w osobnym pliku

Po review Karola → CC nanosi poprawki → architekt zatwierdza.

---

## ETAP 3: PARSER DO JSON (po review Karola)

Napisz `scripts/parse_encyclopedia_to_json.py` który:

1. Parsuje `encyclopedia_v3_all.md` do listy obiektów JSON

Schema per hasło:
```json
{
  "concept_number": 1,
  "concept_key": "AUTOMAT_ODDECHOWY",
  "name_pl": "AUTOMAT ODDECHOWY",
  "name_en": "REGULATOR",
  "definition": "...",
  "subtypes_client": [...],
  "subtypes_technical": [...],
  "synonyms": {
    "official": [...],
    "close": [...],
    "slang": [...],
    "anglicisms": [...],
    "misspelled": [...]
  },
  "longtail_phrases": [...],
  "not_to_confuse": [...],
  "purchase_parameters": [...],
  "cross_sell": [...],
  "faq": [
    {"question": "...", "answer": "...", "source_tag": "[PAA]"}
  ],
  "seller_notes": "...",
  "related_concept_keys": ["OCTOPUS", "MANOMETR", ...]
}
```

2. Wyciąga `related_concept_keys` z linków `(→ KEY)` w tekście

3. Waliduje JSON schema (każde pole wymagane)

Output: `data/encyclopedia/v3/encyclopedia_v3.json`

---

## ETAP 4: WALIDACJA AUTOMATYCZNA (po parserze)

Rozszerz `validate_encyclopedia.py` o:

1. **Cross-ref integrity** — każdy KEY w `related_concept_keys` musi
   istnieć jako `concept_key` w innym haśle

2. **Marka vs mapa marek** — każda marka wymieniona w tekście sprawdzona
   vs `_docs/11_mapa_marek-reviewed.md`. Flaguj nieznane marki.

3. **Duplikaty synonimów** — ten sam synonim nie powinien być w 2+ hasłach
   jako "Oficjalny" (dopuszczalne w "Bliskie")

4. **Minimalne progi** — flaguj hasła z <4 FAQ, <5 long-tail, <3 cross-sell

Output: `data/encyclopedia/v3/validation_v2_report.md`

---

## ETAP 5: WGRANIE DO PGVECTOR (po walidacji)

Rozszerz `scripts/embed_encyclopedia.py` (lub nowy skrypt):

1. Załaduj `encyclopedia_v3.json`
2. Dla każdego hasła utwórz chunki do embeddingu:
   - Chunk 1: definicja + podtypy (kontekst ogólny)
   - Chunk 2: synonimy + long-tail (search matching)
   - Chunk 3: parametry + cross-sell (zakupowy)
   - Chunk 4: FAQ (Q&A pairs)
   - Chunk 5: uwagi sprzedawcy (internal)
3. Embedduj text-embedding-3-large (3072 dim)
4. Zapisz do tabeli `encyclopedia_chunks` w PostgreSQL

Schema tabeli:
```sql
CREATE TABLE encyclopedia_chunks (
  id SERIAL PRIMARY KEY,
  concept_key VARCHAR(100) NOT NULL,
  chunk_type VARCHAR(50) NOT NULL,  -- 'definition', 'synonyms', 'purchase', 'faq', 'seller'
  content TEXT NOT NULL,
  embedding VECTOR(3072),
  metadata JSONB,  -- concept_number, name_pl, name_en, related_keys
  created_at TIMESTAMPTZ DEFAULT NOW()
);

CREATE INDEX idx_enc_chunks_embedding ON encyclopedia_chunks 
  USING ivfflat (embedding vector_cosine_ops) WITH (lists = 20);
CREATE INDEX idx_enc_chunks_concept ON encyclopedia_chunks (concept_key);
CREATE INDEX idx_enc_chunks_type ON encyclopedia_chunks (chunk_type);
```

## KOLEJNOŚĆ REALIZACJI

```
ETAP 1: Merge + walidacja        ← ZRÓB TERAZ
        ↓
    [STOP → review architekt + Karol]
        ↓
ETAP 2: Human review + poprawki  ← po feedbacku Karola
        ↓
ETAP 3: Parser JSON              ← po zatwierdzeniu poprawek
        ↓
ETAP 4: Walidacja automatyczna   ← po parserze
        ↓
    [STOP → review architekt]
        ↓
ETAP 5: Embedding + pgvector     ← finał
```

## NIE RÓB

- Nie modyfikuj plików R1-R8 w batch/ (to archiwum)
- Nie uruchamiaj etapów 2-5 bez OK architekta
- Nie zmieniaj promptu (generacja zakończona)
