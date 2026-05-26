# Stan projektu encyklopedii: 2026-02-28 13:00

## KRYTYCZNA DECYZJA W TOKU

Trwa rewizja architektoniczna pipeline'u encyklopedii. Prompt wysłany do 3 modeli:
- Claude (nowa konwersacja w tym projekcie)
- Gemini 3.1 Pro
- OpenAI Deep Research

Pliki w: `_docs/pytanie_architektoniczne/` (16 plików, ~160KB)
Kluczowy prompt: `00_PROMPT.md`
Wadliwy oryginalny prompt: `14_ORYGINALNY_PROMPT_wadliwy.md`

### Diagnoza problemu

Pipeline ma 4 warstwy LLM (głuchy telefon):
1. LLM ekstrakcja źródeł → 46 plików JSON v1 (237KB)
2. LLM grupowanie → FAZA1, reguły domenowe
3. GPT-5.2 generuje v2 OD ZERA (ignorując v1)
4. Claude Opus waliduje v2

Każda warstwa oddala od prawdy źródłowej (7.6MB ludzkiej wiedzy).
Root cause: oryginalny prompt do OpenAI/Gemini zakładał LLM pipeline w pytaniach.

### Oczekiwany wynik rewizji

Odpowiedzi 3 modeli → decyzja architektoniczna:
- Kontynuować obecny pipeline (mało prawdopodobne)
- Przebudować na: Python deterministyczny + minimalny LLM
- Inna architektura

## CO JEST ZROBIONE

### Pipeline generacyjny (TASK-ENC-001) - DONE ale prawdopodobnie do wyrzucenia
- 7 plików Python + 2 szablony Jinja2
- Lokalizacja: `generate_encyclopedia/`
- Bugi naprawione: GPT-5.2 temperature, Claude streaming, thinking mode
- Sub-batch (max 8 pojęć per batch) zaimplementowany częściowo (TASK-ENC-003)

### Grupy przetworzone
- Grupa A (15 pojęć): DONE, ręcznie
- Grupa B (9 pojęć): DONE, ręcznie  
- Grupa C (16 pojęć): DONE pipeline, 1 FAIL + 11 PASS z uwagami
- Grupy D-M (61 pojęć): NIE ROZPOCZĘTE, czekają na decyzję architektoniczną

### Pliki wygenerowane przez pipeline
- `generate_encyclopedia/output/grupa_C/` - pełny output grupy C
- `generate_encyclopedia/output/grupa_A/`, `grupa_B/` - wcześniejsze ręczne

## ŹRÓDŁA LUDZKIE (ground truth)

| Źródło | Lokalizacja | Rozmiar |
|--------|-------------|---------|
| Książka OWD | `_docs/wiedza_nurkowa/Książka OWD/` (6 plików .md) | 515KB |
| PADI Encyclopedia Ch3 | `_docs/wiedza_nurkowa/Encyclopedia of Recreational Diving/...Ch3...md` | 325KB |
| PADI Encyclopedia Ch5 | `_docs/wiedza_nurkowa/Encyclopedia of Recreational Diving/...Ch5...md` | 330KB |
| Artykuły nurkomania (sprzęt) | `_docs/wiedza_nurkowa/sprzet_do_nurkowania.json` | 728KB |
| Artykuły nurkomania (teoria) | `_docs/wiedza_nurkowa/teoria_nurkowania_pelny.json` | 5.9MB |
| Mapa marek | `_docs/11_mapa_marek-reviewed.md` | 4KB |
| Opisy produktów | baza MySQL PrestaShop (pr_) | ~2600 produktów |

## DANE ZEWNĘTRZNE (twarde dane liczbowe)

| Źródło | Lokalizacja |
|--------|-------------|
| DataForSEO | `data/dataforseo/raw/` (kilka plików .json) |
| Luigi's Box | `_docs/dane_zewnetrzne_wyszukiwania/luigisbox_*.csv` |
| Google Search Console | `_docs/dane_zewnetrzne_wyszukiwania/GSC-Performance-on-Search-2026-02-23/` |

## LLM-GENERATED (pochodne, z błędami)

| Co | Lokalizacja | Status |
|----|-------------|--------|
| 46 plików JSON v1 | `data/encyclopedia/raw/*.json` | needs_review, LLM-generated |
| FAZA1 lista pojęć | `_docs/FAZA1_concept_keys_v2.md` | LLM-generated |
| Reguły domenowe | `_docs/17_reguly_domenowe_grupy_C-M.md` | LLM-generated |
| Output grup A/B/C | `generate_encyclopedia/output/` | LLM-generated |

## NUMERACJA PYTAŃ

Ostatnie pytanie w tym czacie: **151**
Kontynuuj od **152** w nowym czacie.

## TASKI

| Task | Status | Opis |
|------|--------|------|
| TASK-ENC-001 | DONE | Pipeline generacyjny (prawdopodobnie do wyrzucenia) |
| TASK-ENC-002 | TODO | Dashboard HTML (czeka na decyzję) |
| TASK-ENC-003 | PARTIAL | Bug fixes + sub-batch (częściowo zrobione, czeka na decyzję) |

## KLUCZOWE PLIKI PROJEKTU

- `_docs/10_decyzje_projektowe.md` - log decyzji architektonicznych
- `_docs/00_architektura_projektu.md` - architektura całego czatu
- `_docs/11_mapa_marek-reviewed.md` - poprawiona mapa marek
- `generate_encyclopedia/spec.md` - specyfikacja pipeline'u (v1, może do wyrzucenia)
- `_docs/pytanie_architektoniczne/` - folder z materiałami do rewizji

## CO ROBIĆ W NOWYM CZACIE

1. Karol dostarczy odpowiedzi z 3 modeli (Claude, Gemini, OpenAI)
2. Przeanalizować i porównać rekomendacje
3. Podjąć decyzję architektoniczną
4. Zapisać w `_docs/10_decyzje_projektowe.md`
5. Zaprojektować nowy pipeline (lub naprawić istniejący)
6. Przygotować taski dla Claude Code

## KONTEKST TECHNICZNY

- PHP 8.4 API na chat.divezone.pl
- PostgreSQL 17.8 + pgvector 0.8.1 na Railway
- PrestaShop 1.7.6, prefix pr_, MySQL
- Python 3 dla pipeline'u embeddingów i encyklopedii
- Claude/OpenAI API dla czatu klientów
- 4 instancje Claude Code: backend, embeddings, frontend, integration
