# TASK-ENC-011c: Deterministic Validator
# Data: 2026-03-06
# Status: CZEKA NA 011b
# Instancja: embeddings
# Zależność: TASK-ENC-011b DONE (surowe JSON-y z Gemini)

---

## CEL

Walidator sprawdzający KAŻDY surowy JSON z Gemini pod kątem:
fabricated evidence, broken concept keys, reguły domenowe, kompletność.
Output: status GREEN/YELLOW/RED per hasło. RED = blocked.

## SKRYPT

Nowy plik: `scripts/validate_encyclopedia_v2.py`

Wczytuje:
- `gen_v2/raw/{KEY}.json` — output Gemini
- `gen_v2/evidence/{KEY}.json` — evidence registry
- `gen_v2/concept_list.json` — lista 105 haseł

### CHECK 1: Evidence integrity (fail = RED)

Dla KAŻDEGO evidence_id w odpowiedzi Gemini:
- Sprawdź czy istnieje w evidence registry tego hasła
- Jeśli NIE → FABRICATED → status RED, batch BLOCKED

```python
def check_evidence(gemini_output, evidence_registry):
    fabricated = []
    valid_ids = set(evidence_registry["evidence"].keys())
    
    # Sprawdź synonimy
    for category in gemini_output.get("synonyms", {}).values():
        for item in category:
            eid = item.get("evidence_id", "")
            if eid and eid not in valid_ids:
                fabricated.append({"field": "synonyms", "id": eid, "text": item["text"]})
    
    # Sprawdź longtail
    for item in gemini_output.get("longtail_phrases", []):
        eid = item.get("evidence_id", "")
        if eid and eid not in valid_ids:
            fabricated.append({"field": "longtail", "id": eid, "text": item["text"]})
    
    # Sprawdź cross_sell
    for item in gemini_output.get("cross_sell", []):
        eid = item.get("evidence_id", "")
        if eid and eid not in valid_ids:
            fabricated.append({"field": "cross_sell", "id": eid, "text": item["product"]})
    
    # Sprawdź FAQ evidence_ids
    for faq in gemini_output.get("faq", []):
        for eid in faq.get("evidence_ids", []):
            if eid not in valid_ids:
                fabricated.append({"field": "faq", "id": eid, "text": faq["question"]})
    
    return fabricated  # len > 0 → RED
```

### CHECK 2: Concept key integrity (fail = YELLOW)

Sprawdź:
- `related_concept_keys` — każdy musi istnieć w concept_list.json
- `not_to_confuse[].concept_key` — j.w.
- `cross_sell[].concept_key` — j.w.

Broken link → YELLOW (nie RED, bo nie szkodzi w RAG, tylko w renderingu)

### CHECK 3: Domain rules — DIN/INT (fail = YELLOW)

Szukaj w tekście (definition, seller_notes, FAQ answers, purchase_parameters):
- "INT" w kontekście "standard INT", "złącze INT", "yoke"
- Flaguj jeśli INT prezentowane jako opcja/alternatywa
- OK jeśli mówi "archaiczny", "martwy", "odradzamy"

Wzorce do flagowania:
- "INT lub DIN", "DIN/INT do wyboru", "kompatybilny z INT"
- Wzorce OK: "INT jest archaiczny", "INT martwy", "wyłącznie DIN"

### CHECK 4: Completeness (INFO — nie blokuje)

Minimalne progi:
- subtypes_client: ≥3 (WARNING jeśli <3)
- faq: ≥4 (WARNING jeśli <4)
- longtail_phrases: ≥6 (WARNING jeśli <6)
- cross_sell: ≥2 (WARNING jeśli <2)

### CHECK 5: Model self-reporting

- `missing_data` — loguj (model uczciwie raportuje braki)
- `ungrounded_additions` — loguj do human review

### OUTPUT per hasło

`gen_v2/validation/{CONCEPT_KEY}.json`:
```json
{
  "concept_key": "AUTOMAT_ODDECHOWY",
  "status": "GREEN",
  "checks": {
    "evidence_integrity": {"passed": true, "fabricated": []},
    "concept_keys": {"passed": true, "broken": []},
    "domain_din_int": {"passed": true, "warnings": []},
    "completeness": {
      "subtypes_client": 4,
      "faq": 5,
      "longtail": 9,
      "cross_sell": 3,
      "warnings": []
    }
  },
  "model_missing_data": [],
  "model_ungrounded": [],
  "evidence_usage": {
    "total_available": 36,
    "total_used": 22,
    "unused": ["EV-K-008", "EV-A-012", ...]
  }
}
```

Status logic:
- RED: any fabricated evidence_id
- YELLOW: broken concept_key OR domain warning
- GREEN: all passed

### STATYSTYKI ZBIORCZE

Po walidacji wszystkich 105 haseł:

`gen_v2/validation_summary.json`:
```json
{
  "total": 105,
  "green": 98,
  "yellow": 7,
  "red": 0,
  "red_concepts": [],
  "yellow_concepts": [
    {"key": "RASHGUARD", "reasons": ["completeness: faq=2 (<4)"]}
  ],
  "total_fabricated_evidence": 0,
  "total_broken_concept_keys": 3,
  "total_domain_warnings": 0,
  "evidence_usage_rate": "61.2% (2350/3847)"
}
```

## TRYB URUCHOMIENIA

```bash
# Waliduj wszystkie
python3 scripts/validate_encyclopedia_v2.py --all

# Waliduj jedno hasło
python3 scripts/validate_encyclopedia_v2.py --concept AUTOMAT_ODDECHOWY
```

→ STOP po walidacji. Czekaj na review architekta.
RED hasła muszą być naprawione przed renderingiem.

## NIE RÓB

- Nie pisz renderera (to TASK-ENC-011d)
- Nie modyfikuj surowych JSON-ów z Gemini
- Nie wywołuj Gemini ponownie
