# TASK-ENC-011a: Completeness Gate + Evidence Builder
# Data: 2026-03-06
# Status: DO ZROBIENIA
# Instancja: embeddings
# Kontekst: Przebudowa pipeline (ADR-046). Ten task = kroki 1-2 z 6.

---

## CEL

Dwa komponenty: (1) sprawdzenie że KAŻDE z 105 haseł ma dane z KAŻDEGO źródła,
(2) budowanie zamkniętego rejestru dowodów (evidence registry) per hasło.

## TAGI ŹRÓDŁOWE — PROBLEM KTÓRY ROZWIĄZUJEMY

Stary pipeline pozwalał Gemini swobodnie pisać tagi [GSC, 140 vol], [PAA], [AC].
Gemini sfabrykował ~80% tagów bo nie dostał plików źródłowych (niekompletne mapowania).
Nowy pipeline: Gemini NIGDY nie pisze tagów. Zwraca tylko evidence_ids (EV-K-001).
Tagi buduje Python deterministycznie z evidence registry.

## KROK 1: KOMPLETNE MAPOWANIA

### 1a. Lista 105 haseł

Wczytaj FAZA1_concept_keys_v2.md, wyciągnij listę 105 concept keys.
Zapisz do: `data/encyclopedia/v3/gen_v2/concept_list.json`

### 1b. Mapowanie keywords (CONCEPT → seed keywords)

Stary CONCEPT_TO_SEEDS miał 17 wpisów. Potrzebujesz 105.

Dla KAŻDEGO concept key:
1. Weź polską nazwę hasła (przed "/")
2. Weź angielską nazwę (po "/")
3. Szukaj matchujących wierszy w all_keywords.csv po tych nazwach
4. Jeśli 0 matchów → użyj synonimów z NotebookLM draftu jako dodatkowych seedów
5. Jeśli nadal 0 → loguj WARNING (dopuszczalne dla niszowych haseł)

Zapisz: `data/encyclopedia/v3/gen_v2/mappings/keywords_mapping.json`
Format: {"AUTOMAT_ODDECHOWY": {"seeds": [...], "matched_rows": 12}, ...}

### 1c. Mapowanie PAA (CONCEPT → grupy PAA)

Stary CONCEPT_TO_PAA_GROUP miał ~30 wpisów. Potrzebujesz 105.

Wczytaj atp_questions_all.csv, zidentyfikuj unikalne grupy.
Dla KAŻDEGO concept key przypisz ≥1 grupę.

Zapisz: `data/encyclopedia/v3/gen_v2/mappings/paa_mapping.json`

### 1d. Mapowanie eksperta (CONCEPT → grupy transkrypcji)

Stary CONCEPT_TO_EXPERT_GROUP miał ~40 wpisów. Potrzebujesz 105.
Transkrypt ma 21 grup (1-17, 19-21, brak 18).

Zapisz: `data/encyclopedia/v3/gen_v2/mappings/expert_mapping.json`

### 1e. Mapowanie crosssell (CONCEPT → kategorie sklepowe)

CONCEPT_TO_SHOP_CATEGORIES ma 95 wpisów (po fixie z TASK-ENC-009b).
Rozszerz do 105.

Zapisz: `data/encyclopedia/v3/gen_v2/mappings/crosssell_mapping.json`

### 1f. Walidacja kompletności

Sprawdź dla KAŻDEGO z 105 haseł:
- keywords: ≥3 wierszy (WARNING jeśli <3, OK jeśli 0 dla niszowych)
- PAA: ≥2 wierszy 
- ekspert: ≥1 fragment
- crosssell: ≥1 linia

Output: `data/encyclopedia/v3/gen_v2/completeness_report.json`
```json
{
  "total_concepts": 105,
  "fully_covered": 87,
  "warnings": [
    {"concept": "RASHGUARD", "missing": ["keywords: 0 rows", "PAA: 0 rows"], "blocking": false},
    ...
  ],
  "blocked": [],
  "summary": "87 full, 18 warnings, 0 blocked"
}
```

Jeśli jakiekolwiek hasło ma 0 we WSZYSTKICH źródłach → ABORT.

## KROK 2: EVIDENCE BUILDER

Dla KAŻDEGO z 105 haseł zbuduj evidence registry.

### 2a. Evidence z keywords CSV

Dla każdego matchującego wiersza z all_keywords.csv:
```json
"EV-K-001": {
  "text": "automat nurkowy",
  "source": "GSC",
  "volume": 140,
  "file": "all_keywords.csv",
  "line": 23
}
```

### 2b. Evidence z PAA/autocomplete CSV

Dla każdego matchującego wiersza z atp_questions_all.csv:
```json
"EV-P-001": {
  "text": "jak działa automat nurkowy",
  "source": "PAA",
  "file": "atp_questions_all.csv",
  "line": 12
}
```
lub source: "autocomplete" jeśli type=autocomplete w CSV.

### 2c. Evidence z crosssell

Dla każdej matchującej linii z danych sprzedażowych:
```json
"EV-S-001": {
  "text": "Akcesoria do automatów → 421 zamówień/rok, #7 kategoria",
  "source": "crosssell",
  "file": "dane_sprzedazowe_crosssell_12m.md",
  "line": 15
}
```

### 2d. ID scheme

- EV-K-NNN — keywords (GSC)
- EV-P-NNN — PAA questions  
- EV-A-NNN — autocomplete
- EV-S-NNN — crosssell/sales
- EV-B-NNN — bestsellers

Numeracja per hasło (EV-K-001 w AUTOMAT i EV-K-001 w JACKET to różne evidence).

### 2e. Output

Per hasło: `data/encyclopedia/v3/gen_v2/evidence/{CONCEPT_KEY}.json`

Statystyki: `data/encyclopedia/v3/gen_v2/evidence_stats.json`
```json
{
  "total_evidence_ids": 3847,
  "avg_per_concept": 36.6,
  "min": {"concept": "RASHGUARD", "count": 3},
  "max": {"concept": "AUTOMAT_ODDECHOWY", "count": 89},
  "by_source": {"GSC": 1404, "PAA": 423, "autocomplete": 637, "crosssell": 312, "bestsellers": 71}
}
```

## WERYFIKACJA (zanim przejdziesz do TASK-ENC-011b)

1. Otwórz `evidence/AUTOMAT_ODDECHOWY.json` i sprawdź:
   - Czy EV-K-* zawierają realne frazy z all_keywords.csv
   - Czy EV-P-* zawierają realne pytania z atp_questions_all.csv
   - Czy EV-S-* zawierają realne dane z crosssell
2. Otwórz `completeness_report.json` i sprawdź:
   - 0 blocked
   - Warnings tylko dla niszowych haseł

→ STOP po tym tasku. Czekaj na review architekta.

## NIE RÓB

- Nie pisz skryptu generacji (to TASK-ENC-011b)
- Nie wywołuj Gemini API
- Nie modyfikuj starego generate_encyclopedia.py
