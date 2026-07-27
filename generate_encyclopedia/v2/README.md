# Pipeline encyklopedii v2 (TASK-ENC-005)

Deterministyczna transformacja danych v1 → v2 + wzbogacenie z danych zewnętrznych.
ADR-037: Python deterministyczny, minimalny LLM.

## Wymagania

```bash
pip install pydantic
```

Python 3.10+.

## Uruchomienie

```bash
cd generate_encyclopedia/v2/

# Krok 1: Transformacja v1 → v2 (46 plików) + puste szkielety (59 pojęć)
python transform_v1_to_v2.py

# Krok 2: Wzbogacenie o marki i kandydatów synonimów
python enrich_from_external.py
```

## Pliki wyjściowe

```
output/
├── skeletons/             # 105 plików JSON (szkielety v2)
├── encyclopedia_v2_evidence.json  # Evidence sidecar
└── completeness_report.json       # Raport pól wypełnionych vs null
```

## Struktura

| Plik | Opis |
|------|------|
| `schema_v2.py` | Modele Pydantic (ConceptV2, synonimy, relacje) |
| `config.py` | Ścieżki, stałe, mapowania concept→brand |
| `transform_v1_to_v2.py` | Krok 1: deterministyczna transformacja v1→v2 |
| `enrich_from_external.py` | Krok 2: marki + frazy klientów |

## Następne kroki

- Krok 3: `generate_llm_fields.py` — LLM uzupełnia pola null (osobny task)
- Krok 4: `validate_encyclopedia.py` — walidacja automatyczna
- Krok 5: Human review
