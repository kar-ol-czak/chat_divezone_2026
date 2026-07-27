# MASTER CHECKLIST: Wdrożenie architektury wyszukiwania v3
# Data utworzenia: 2026-02-21
# Kontekst: ADR-024, ADR-025, ADR-026, _docs/14, _docs/17

## PRZED URUCHOMIENIEM TASKÓW (Karol)

### Pliki wiedzy nurkowej
- [x] `sprzet_do_nurkowania.json` → `_docs/wiedza_nurkowa/` ✅
- [x] `teoria_nurkowania_pelny.json` → `_docs/wiedza_nurkowa/` ✅

### Eksport danych
- [x] GSC → `data/search_phrases/gsc_queries.csv` (999 zapytań) ✅
- [x] GSC → `data/search_phrases/gsc_pages.csv` (999 stron) ✅
- [x] Luigi's Box → `data/search_phrases/lb_trending_all.csv` (1000 fraz) ✅
- [x] Luigi's Box → `data/search_phrases/lb_trending_search.csv` (1000 fraz) ✅
- [ ] GA4 → pominięte (wystarczą GSC + LB)

## KOLEJNOŚĆ TASKÓW (Claude Code)

```
TASK-014 baseline ─────────────────────────────────────┐
TASK-011a synonimy → TASK-012 hybrid+RRF ──────────────┤
TASK-011b enrichment → TASK-012b multi-vector ─────────┤
                       TASK-013 query planning ────────┤
                       TASK-014 porównanie ────────────┘
```

### 1. TASK-014 baseline (5 min)
Instancja: integration
Plik: `_instances/integration/tasks/TASK-014_golden_dataset_eval.md`
Polecenie: "Wykonaj TASK-014: utwórz golden dataset i zmierz baseline"
Wymaga review: TAK (golden dataset, 5 min)

### 2. TASK-011a synonimy (30 min)
Instancja: embeddings
Plik: `_instances/embeddings/tasks/TASK-011a_extract_synonyms.md`
Polecenie: "Wykonaj TASK-011a: wyciągnij synonimy z plików w _docs/wiedza_nurkowa/"
Wymaga review: TAK (draft synonimów, 15-20 min)

### 3. TASK-011b enrichment (45 min + review)
Instancja: embeddings
Plik: `_instances/embeddings/tasks/TASK-011b_llm_enrichment.md`
Polecenie: "Wykonaj TASK-011b: wygeneruj frazy wyszukiwania (test 30 produktów)"
Wymaga review: TAK (test 30 produktów, 10 min)
Potem: "Kontynuuj TASK-011b: full batch 2556 produktów"

### 4. TASK-012 hybrid search (30 min)
Instancja: integration + backend
Plik: `_instances/integration/tasks/TASK-012_hybrid_search_rrf.md`
Polecenie: "Wykonaj TASK-012: skonfiguruj FTS, trigram, RRF"
Wymaga review: NIE (ale uruchom testy z tabeli w tasku)

### 5. TASK-012b multi-vector (15 min)
Instancja: embeddings
Plik: `_instances/embeddings/tasks/TASK-012b_multi_vector.md`
Polecenie: "Wykonaj TASK-012b: utwórz 3 kolumny wektorowe i reembeduj"
Wymaga review: NIE

### 6. TASK-013 query planning (30 min)
Instancja: backend
Plik: `_instances/backend/tasks/TASK-013_agentic_query_planning.md`
Polecenie: "Wykonaj TASK-013: zaktualizuj tool schema i system prompt"
Wymaga review: TAK (system prompt, 10 min)

### 7. TASK-014 porównanie (5 min)
Instancja: integration
Polecenie: "Uruchom eval_search.py --tag after_all_changes i porównaj z baseline"

## SZACOWANY CZAS ŁĄCZNY
- Claude Code: ~3h pracy (rozłożone na sesje)
- Karol review: ~45 min
- Koszt API (LLM enrichment): ~$10-16
- Koszt API (embeddings): ~$1

## CHECKPOINTS (stop & review)
1. Po TASK-011a: review słownika synonimów
2. Po TASK-011b test 30: review fraz wyszukiwania
3. Po TASK-013: review system prompt
4. Po TASK-014 final: porównanie metryk baseline vs po zmianach

## DOKUMENTACJA
- Architektura: `_docs/14_architektura_wyszukiwania_rozwiazanie.md` (v3)
- Decyzje: `_docs/10_decyzje_projektowe.md` (ADR-024, 025, 026)
- Synteza analiz: `_docs/17_synteza_analiz_zewnetrznych.md`
- Instrukcja eksportu: `_docs/16_instrukcja_ekstrakcji_danych.md`
- Brief zewnętrzny: `_docs/15_brief_do_analizy_zewnetrznej.md`
- Analizy źródłowe: `data/external_reviews/` (skopiuj ręcznie)
