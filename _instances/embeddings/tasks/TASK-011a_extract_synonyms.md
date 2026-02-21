# TASK-011a: Ekstrakcja słownika synonimów z nurkomania.pl
# Instancja: embeddings (Python)
# Zależności: pliki JSON w data/knowledge_sources/
# Priorytet: WYSOKI (blokuje TASK-012 FTS)

## CEL
Wyciągnąć pary/grupy synonimów nurkowych z plików JSON (nurkomania.pl)
i zapisać je jako tabelę w PostgreSQL + plik referencyjny.

## PLIKI WEJŚCIOWE
- _docs/wiedza_nurkowa/sprzet_do_nurkowania.json (~728KB)
- _docs/wiedza_nurkowa/teoria_nurkowania_pelny.json (~5.9MB)

Format każdego pliku: tablica JSON obiektów:
```json
{
  "url": "https://nurkomania.pl/...",
  "tytul": "Tytuł artykułu",
  "kategoria": "kategoria",
  "tresc": "Pełna treść artykułu (plain text, bez HTML)",
  "obrazki": [],
  "linki_wewnetrzne": []
}
```

## PODEJŚCIE

### Krok 1: Ekstrakcja kandydatów na synonimy przez LLM

Dla każdego artykułu z JSON-ów, wyślij treść do Claude API (claude-sonnet-4-20250514)
z promptem:

```
Przeanalizuj poniższy tekst o nurkowaniu. Wypisz WSZYSTKIE grupy synonimów
które znajdziesz, tzn. różne słowa/frazy oznaczające TO SAMO.

Format wyjścia (JSON array of arrays):
[
  ["pianka", "skafander mokry", "wetsuit", "kombinezon neoprenowy"],
  ["automat oddechowy", "regulator", "lung"],
  ["jacket", "BCD", "kamizelka wypornościowa"]
]

ZASADY:
- Tylko synonimy z dziedziny nurkowania
- Uwzględnij polskie i angielskie warianty
- Uwzględnij skróty (BCD, AO, HP, LP, DIN, INT)
- Jedna grupa = jedno pojęcie, różne nazwy
- Jeśli tekst nie zawiera synonimów, zwróć pustą tablicę []

TEKST:
{tresc}
```

### Krok 2: Deduplikacja i merge

Wiele artykułów zwróci te same grupy. Skrypt musi:
1. Zebrać wszystkie grupy ze wszystkich artykułów
2. Zmerge'ować grupy które mają wspólne elementy
   (jeśli grupa A zawiera "pianka" i grupa B też zawiera "pianka", merge)
3. Usunąć duplikaty wewnątrz grup
4. Posortować grupy po pierwszym elemencie

### Krok 3: Zapis do pliku review

Zapisz do `data/synonyms/diving_synonyms_draft.json`:
```json
[
  {
    "canonical": "automat oddechowy",
    "synonyms": ["regulator", "lung", "AO"],
    "source_articles": ["Automat oddechowy", "Sprzęt pomocniczy"]
  },
  ...
]
```

### Krok 4: Zapis do PostgreSQL

Po REVIEW przez Karola (plik _reviewed.json), utwórz tabelę:

```sql
CREATE TABLE IF NOT EXISTS divechat_synonyms (
    id SERIAL PRIMARY KEY,
    canonical_term VARCHAR(100) NOT NULL,
    synonym VARCHAR(100) NOT NULL,
    language CHAR(2) DEFAULT 'pl',
    created_at TIMESTAMP DEFAULT NOW(),
    UNIQUE(canonical_term, synonym)
);

-- Przykładowe dane:
INSERT INTO divechat_synonyms (canonical_term, synonym) VALUES
('automat oddechowy', 'regulator'),
('automat oddechowy', 'lung'),
('automat oddechowy', 'AO'),
('skafander mokry', 'pianka'),
('skafander mokry', 'wetsuit'),
('skafander mokry', 'kombinezon neoprenowy');
```

## PLIKI WYJŚCIOWE
- data/synonyms/diving_synonyms_draft.json (do review)
- data/synonyms/diving_synonyms_reviewed.json (po review, input do SQL)
- embeddings/extract_synonyms.py (skrypt ekstrakcji)
- embeddings/load_synonyms.py (skrypt ładowania do PG)
- sql/003_create_synonyms_table.sql (migracja)

## KOSZTY
- ~100-200 artykułów * ~2000 tokenów input * ~200 tokenów output
- Sonnet: ~$0.30-0.60
- Czas: ~10-15 minut (batch)

## KRYTERIA AKCEPTACJI
- [ ] Minimum 30 grup synonimów
- [ ] Każda grupa ma canonical_term + min 1 synonim
- [ ] Plik draft do review istnieje
- [ ] Tabela divechat_synonyms istnieje w PostgreSQL
- [ ] Skrypt load_synonyms.py wczytuje reviewed JSON do tabeli
