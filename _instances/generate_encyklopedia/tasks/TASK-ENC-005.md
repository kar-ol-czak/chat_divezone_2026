# TASK-ENC-005: Pipeline encyklopedii v2 (kroki 1-2: Python deterministyczny)
# Instancja: generate_encyklopedia (Python)
# Priorytet: WYSOKI
# Status: DO ZROBIENIA
# Data: 2026-02-28
# ADR: ADR-037

## Cel

Zaimplementować kroki 1-2 nowego pipeline'u encyklopedii: deterministyczna
transformacja v1→v2 + wzbogacenie z danych zewnętrznych (marki, frazy klientów).

## Specyfikacja

Pełna specyfikacja: `_docs/TASK-ENC-005_pipeline_v2.md` (371 linii)
Ten task obejmuje TYLKO kroki 1-2. Krok 3 (LLM) będzie osobnym taskiem.

## UWAGA: Stary pipeline (TASK-ENC-001) jest OBSOLETE

TASK-ENC-001/002/003 to stary 4-warstwowy pipeline do wyrzucenia.
NIE bazuj na kodzie z `generate_encyclopedia/`. Pisz od zera w `generate_encyclopedia/v2/`.

## Pliki wejściowe

### Krok 1 (transformacja):
- `data/encyclopedia/raw/*.json` — 46 plików v1 (LLM-generated, fundament)
- `_docs/FAZA1_concept_keys_v2.md` — master lista 106 pojęć (v2.3)

### Krok 2 (lookup):
- `_docs/11_mapa_marek-reviewed.md` — whitelist marek per kategoria
- `data/dataforseo/processed/all_keywords.csv` — 1404 fraz klientów
- `_docs/dane_zewnetrzne_wyszukiwania/luigisbox_*.csv` — frazy wewnętrzne
- `_docs/dane_zewnetrzne_wyszukiwania/GSC-Performance-on-Search-2026-02-23/` — frazy Google

## Pliki wyjściowe

- `generate_encyclopedia/v2/output/skeletons/` — 106 plików JSON (szkielety v2)
- Pojęcia z v1: ~70-85% pól wypełnionych, reszta null
- Pojęcia bez v1: tylko id + nazwa_pl + nazwa_en + marki + kandydaci synonimów

## Co implementować

### 1. `schema_v2.py` — modele Pydantic

Schema v2 z korektami ADR-037 (patrz spec sekcja 4):
- `synonimy_pl.anglicyzmy` — NOWY bucket
- DUMP_VALVE_BCD + DUMP_VALVE_DRYSUIT — osobne pojęcia
- Evidence sidecar schema

### 2. `config.py` — ścieżki i stałe

Ścieżki do wszystkich plików wejściowych/wyjściowych.
Whitelist akronimów które zostają w `exact` mimo ASCII: BCD, LPI, SMB, DSMB, SPG, HP, LP, QD, DIR, EANx.

### 3. `transform_v1_to_v2.py` — krok 1

Mapowanie pól v1→v2 (tabela w spec sekcja 5, krok 1):
- id = concept_key.upper()
- nazwa_pl = canonical_term_pl
- definicja = definition_operational_pl (lub definition_pl jeśli operational < 50 znaków)
- synonimy: filtr po type + lang, rozkład do bucketów
- Anglicyzmy: synonim PL bez diakrytyków + match z EN → bucket anglicyzmy
- DUMP_VALVE split: rozdziel na BCD/DRYSUIT jeśli v1 ma DUMP_VALVE
- Pola bez źródła w v1 → null

### 4. `enrich_from_external.py` — krok 2

a) Parsuj mapę marek → wypełnij marki_w_sklepie
b) Załaduj DataForSEO CSV + Luigi's Box + GSC
c) Fuzzy match fraz → concept_key (keyword overlap lub TF-IDF)
d) Dodaj jako `_candidate_synonyms` (pole tymczasowe z search_volume)

## Kolejność implementacji

1. schema_v2.py + config.py
2. transform_v1_to_v2.py — testuj na 5 plikach v1 z grupy C (JACKET, INFLATOR, BACKPLATE, SKRZYDLO, SIDEMOUNT)
3. enrich_from_external.py — testuj na tych samych 5
4. Pełny run na wszystkich 46 v1
5. Generacja pustych szkieletów dla 60 pojęć bez v1

## Definicja done

- [ ] schema_v2.py: Pydantic model przechodzi walidację na przykładowym JSON z spec sekcja 4
- [ ] transform: 46 plików v1 przetransformowanych, zero utraty synonimów vs v1
- [ ] transform: DUMP_VALVE rozbity na BCD + DRYSUIT
- [ ] transform: anglicyzmy w dedykowanym buckecie
- [ ] enrich: marki_w_sklepie wypełnione z mapy marek (≥80% pojęć ma ≥1 markę)
- [ ] enrich: kandydaci synonimów z DataForSEO zmatchowani do pojęć
- [ ] 106 plików szkieletów w output/skeletons/
- [ ] Raport: ile pól wypełnionych vs null per pojęcie (CSV lub JSON)
- [ ] requirements.txt
- [ ] README z instrukcją uruchomienia

## Blokujące

Nic. Wszystkie dane wejściowe gotowe.
