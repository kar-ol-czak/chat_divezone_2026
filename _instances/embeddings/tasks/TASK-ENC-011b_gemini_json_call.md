# TASK-ENC-011b: Gemini Call — JSON Schema, 1 hasło per wywołanie
# Data: 2026-03-06
# Status: CZEKA NA 011a
# Instancja: embeddings
# Zależność: TASK-ENC-011a DONE (evidence registry gotowy)

---

## CEL

Nowy skrypt wywołujący Gemini 3.1 Pro z JSON Schema output,
1 hasło per wywołanie, evidence_ids zamiast swobodnych tagów.

## SKRYPT

Nowy plik: `scripts/generate_encyclopedia_v2.py`

### Input per wywołanie:

1. System prompt (PROMPT z kluczowymi zmianami — patrz niżej)
2. Lista 105 concept keys (do linkowania → KEY)
3. Evidence registry JSON dla tego hasła (z gen_v2/evidence/{KEY}.json)
4. Fragment transkrypcji eksperta (relevantne grupy z expert_mapping.json)
5. Draft z NotebookLM (jeśli istnieje dla tego hasła)
6. Reguły domenowe (17_reguly_domenowe_grupy_C-M.md — jeśli relevantne)
7. Mapa marek (11_mapa_marek-reviewed.md)

NIE wysyłaj surowych plików CSV. Tylko przetworzone evidence z kroku 2.

### System prompt — kluczowe zmiany vs stary prompt

Weź istniejący PROMPT_gemini_encyklopedia_v3.md jako bazę.
Utwórz NOWY plik: `_docs/PROMPT_gemini_encyklopedia_v4_json.md`

DODAJ na początku:
```
KRYTYCZNA ZASADA: FORMAT WYJŚCIA
Odpowiadasz WYŁĄCZNIE w formacie JSON zgodnym z dostarczonym schematem.
NIE generujesz markdownu. NIE dodajesz komentarzy poza JSON-em.
NIE dodajesz pól których nie ma w schemacie.

KRYTYCZNA ZASADA: EVIDENCE IDS
Dostaniesz listę evidence IDs (EV-K-001, EV-P-001, etc.) z frazami.
W polach synonyms, longtail_phrases, faq.evidence_ids, cross_sell 
używasz WYŁĄCZNIE evidence_ids z tej listy.
NIE wymyślasz własnych fraz SEO. NIE tworzysz tagów [GSC], [PAA], [AC].
Jeśli chcesz dodać frazę której NIE MA na liście evidence,
wstaw ją w pole "ungrounded_additions" z wyjaśnieniem skąd pochodzi.

KRYTYCZNA ZASADA: BRAKUJĄCE DANE
Jeśli w kontekście BRAK danych dla jakiejś sekcji,
wstaw w pole "missing_data" opis czego brakuje.
NIE symuluj danych. NIE wymyślaj statystyk sprzedażowych.
```

Zasady #1-#20 ZOSTAJĄ (treść merytoryczna, podtypy, honest params, etc.)
Zasada #17 (cross-sell) — zmień "cytuj %" na "użyj evidence_id z listy EV-S-*"
Zasada #18 (long-tail) — zmień "taguj źródło" na "podaj evidence_id"

### JSON Schema

Użyj Gemini Structured Output: response_mime_type="application/json"
z response_schema.

Schema (uproszczona, pełna w TASK-ENC-011 głównym):
```python
SCHEMA = {
    "type": "object",
    "properties": {
        "concept_number": {"type": "integer"},
        "concept_key": {"type": "string"},
        "name_pl": {"type": "string"},
        "name_en": {"type": "string"},
        "definition": {"type": "string"},
        "subtypes_client": {"type": "array", "items": {
            "type": "object",
            "properties": {"name": {"type": "string"}, "description": {"type": "string"}}
        }},
        "subtypes_technical": {"type": "array", "items": {
            "type": "object",
            "properties": {"name": {"type": "string"}, "description": {"type": "string"}}
        }},
        "synonyms": {"type": "object", "properties": {
            "official": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"}
            }}},
            "close": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"}
            }}},
            "slang": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"}
            }}},
            "anglicisms": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"}
            }}},
            "misspelled": {"type": "array", "items": {"type": "object", "properties": {
                "text": {"type": "string"}, "evidence_id": {"type": "string"}
            }}}
        }},
        "longtail_phrases": {"type": "array", "items": {
            "type": "object",
            "properties": {"text": {"type": "string"}, "evidence_id": {"type": "string"}}
        }},
        "not_to_confuse": {"type": "array", "items": {
            "type": "object",
            "properties": {"concept_key": {"type": "string"}, "explanation": {"type": "string"}}
        }},
        "purchase_parameters": {"type": "array", "items": {
            "type": "object",
            "properties": {"name": {"type": "string"}, "description": {"type": "string"}}
        }},
        "cross_sell": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "product": {"type": "string"},
                "concept_key": {"type": "string"},
                "description": {"type": "string"},
                "evidence_id": {"type": "string"}
            }
        }},
        "faq": {"type": "array", "items": {
            "type": "object",
            "properties": {
                "question": {"type": "string"},
                "answer": {"type": "string"},
                "evidence_ids": {"type": "array", "items": {"type": "string"}}
            }
        }},
        "seller_notes": {"type": "string"},
        "related_concept_keys": {"type": "array", "items": {"type": "string"}},
        "missing_data": {"type": "array", "items": {"type": "string"}},
        "ungrounded_additions": {"type": "array", "items": {
            "type": "object",
            "properties": {"text": {"type": "string"}, "reason": {"type": "string"}}
        }}
    },
    "required": ["concept_key", "name_pl", "definition", "subtypes_client",
                 "synonyms", "longtail_phrases", "purchase_parameters",
                 "cross_sell", "faq", "seller_notes"]
}
```

### Wywołanie

```python
for concept in all_105_concepts:
    evidence = load_json(f"gen_v2/evidence/{concept_key}.json")
    context = build_context(concept, evidence)
    
    result = call_gemini_structured(
        system_prompt=SYSTEM_PROMPT_V4,
        user_content=context,
        response_schema=SCHEMA,
        model="gemini-3.1-pro-preview"
    )
    
    save_json(f"gen_v2/raw/{concept_key}.json", result)
    
    # Zapisz manifest (co dostał Gemini)
    save_manifest(concept_key, evidence, context)
    
    time.sleep(2)
```

### Manifest per hasło

`gen_v2/manifests/{concept_key}.json`:
```json
{
  "concept_key": "AUTOMAT_ODDECHOWY",
  "timestamp": "2026-03-06T14:23:00Z",
  "model": "gemini-3.1-pro-preview",
  "prompt_version_hash": "sha256:abc123...",
  "evidence_count": {"K": 12, "P": 8, "A": 15, "S": 3, "B": 1},
  "context_sources": ["expert_grupa_1", "notebooklm_draft", "domain_rules"],
  "input_tokens": 4521,
  "output_tokens": 2890,
  "duration_s": 78.3,
  "source_hashes": {
    "all_keywords.csv": "sha256:...",
    "atp_questions_all.csv": "sha256:...",
    "transkrypt_eksperta.md": "sha256:...",
    "crosssell_12m.md": "sha256:..."
  }
}
```

### Tryby uruchomienia

```bash
# Test 3 haseł
python3 scripts/generate_encyclopedia_v2.py --mode test

# Pełna generacja
python3 scripts/generate_encyclopedia_v2.py --mode full

# Pojedyncze hasło (debug)
python3 scripts/generate_encyclopedia_v2.py --mode single --concept AUTOMAT_ODDECHOWY
```

Test mode generuje: AUTOMAT_ODDECHOWY, JACKET, SUCHY_SKAFANDER

## KROK TESTOWY (OBOWIĄZKOWY)

Uruchom --mode test. Sprawdź:
1. Czy output to valid JSON (nie markdown)
2. Czy evidence_ids w odpowiedzi istnieją w evidence registry
3. Czy missing_data raportuje braki (a nie symuluje dane)
4. Czy ungrounded_additions jest używane zamiast halucynowania

→ STOP po teście. Czekaj na review architekta.
Dopiero po OK → --mode full (105 haseł, ~2.5h, ~$15).

## NIE RÓB

- Nie pisz validatora (to TASK-ENC-011c)
- Nie pisz renderera markdown (to TASK-ENC-011d)
- Nie modyfikuj starego generate_encyclopedia.py
- Nie uruchamiaj full run bez testu i OK architekta
