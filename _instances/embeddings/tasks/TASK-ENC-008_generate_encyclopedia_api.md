# TASK-ENC-008: Generacja encyklopedii via API (test + batch)
# Data: 2026-03-05
# Status: DO ZROBIENIA
# Instancja: embeddings
# ADR: ADR-038 (Gemini), ADR-044 (max 5 haseł/partia)
# Blokuje: cały downstream (parser, walidacja, pgvector)
# Zależności: API keys w .env, materiały źródłowe, TASK-ENC-007 DONE

---

## 1. CEL

Wygenerować 106 haseł encyklopedii sprzętu nurkowego przez API LLM.
Faza 1: test porównawczy 3 modeli × 3 hasła. Faza 2: batch na zwycięskim modelu.

## 2. FAZA 1: TEST PORÓWNAWCZY

### Modele do testu
```python
MODELS = {
    "gemini": {
        "provider": "google",
        "model": "gemini-3.1-pro-preview",
        "api_key_env": "GOOGLE_GEMINI_API",
        "endpoint": "https://generativelanguage.googleapis.com/v1beta/models/gemini-3.1-pro-preview:generateContent",
    },
    "claude": {
        "provider": "anthropic", 
        "model": "claude-opus-4-6",
        "api_key_env": "ANTHROPIC_API_KEY",
        "endpoint": "https://api.anthropic.com/v1/messages",
    },
    "openai": {
        "provider": "openai",
        "model": "gpt-5.2",
        "api_key_env": "OPENAI_API_KEY",
        "endpoint": "https://api.openai.com/v1/chat/completions",
    }
}
```

### Hasła testowe (3 sztuki)
```python
TEST_CONCEPTS = [
    "AUTOMAT ODDECHOWY / REGULATOR",        # łatwe, mamy wzorzec
    "JACKET (BCD) / JACKET (BCD)",           # średnie, mamy wzorzec  
    "SUCHY SKAFANDER / DRYSUIT",             # trudne, bogaty kwestionariusz
]
```

### Architektura wywołania

Każde wywołanie API = samodzielne, bez historii, z pełnym kontekstem:

```
SYSTEM PROMPT:
  └── PROMPT_gemini_encyklopedia_v3.md (zasady #1-#16)

USER MESSAGE:
  └── "Wygeneruj hasła: [lista 1-3 haseł]"
  └── KONTEKST WSPÓLNY:
      ├── Fragment 11_mapa_marek-reviewed.md (tylko marki dla tych kategorii)
      ├── Fragment 17_reguly_domenowe_grupy_C-M.md (jeśli dotyczy)
      ├── Fragment dane_sprzedazowe_crosssell_12m.md (pary dla tych kategorii)
      └── Fragment dane_sprzedazowe_bestsellery_12m.md (top 5 dla tych kategorii)
  └── KONTEKST PER PARTIA:
      ├── Fragment transkrypt_kwestionariusza_eksperta.md (grupy dla tych haseł)
      ├── Fragment atp_questions_all.csv (PAA pytania dla tych seedów)
      ├── Fragment all_keywords.csv (frazy dla tych kategorii)
      └── Fragment Encyklopedia_Nurkowania_NotebookLM_v2.md (drafty tych haseł)
```

### Mapowanie haseł → grupy kwestionariusza
```python
CONCEPT_TO_EXPERT_GROUP = {
    "AUTOMAT ODDECHOWY / REGULATOR": ["Grupa 1: Automaty oddechowe"],
    "JACKET (BCD) / JACKET (BCD)": ["Grupa 2: BCD, jackety, skrzydła"],
    "SUCHY SKAFANDER / DRYSUIT": ["Grupa 8: Suche skafandry i akcesoria"],
}
```

### Mapowanie haseł → seed keywords (PAA + all_keywords)
```python
CONCEPT_TO_SEEDS = {
    "AUTOMAT ODDECHOWY / REGULATOR": ["automat nurkowy", "regulator nurkowy"],
    "JACKET (BCD) / JACKET (BCD)": ["jacket nurkowy", "kamizelka nurkowa", "BCD nurkowanie"],
    "SUCHY SKAFANDER / DRYSUIT": ["suchy skafander", "suchy skafander nurkowy"],
}
```

### Output fazy 1
```
data/encyclopedia/v3/test/
├── gemini_test_3_concepts.md
├── claude_test_3_concepts.md
├── openai_test_3_concepts.md
└── test_comparison_report.md  (metadane: czas, tokeny, koszt per model)
```

### Kryteria porównania (Karol ocenia)
1. Dokładność techniczna (czy fakty się zgadzają z kwestionariuszem eksperta)
2. Jakość FAQ (język klienta, nie podręcznika)
3. Synonimy (czy trzyma się źródeł, czy halucynuje)
4. Cross-sell (czy sensowne, czy z kosmosu)
5. Czytelność i spójność stylu
6. Zgodność z zasadami #1-#16

## 3. FAZA 2: BATCH GENERATION

Po review fazy 1 i wyborze modelu przez Karola.

### Partycjonowanie 106 haseł
```python
# Max 5 haseł na partię (ADR-044)
# Grupowanie: hasła z tej samej grupy tematycznej razem
# (dzielą kontekst kwestionariusza + PAA)
BATCHES = [
    # Batch 1: Automaty (grupa 1)
    ["AUTOMAT ODDECHOWY / REGULATOR", "PIERWSZY STOPIEŃ AUTOMATU / FIRST STAGE",
     "DRUGI STOPIEŃ AUTOMATU / SECOND STAGE", "OCTOPUS / OCTOPUS",
     "ZESTAW AUTOMATÓW REKREACYJNY / RECREATIONAL REGULATOR SET"],
    # Batch 2: Automaty cd. (grupa 1)
    ["ZESTAW AUTOMATÓW DO TWINSETU / TWINSET REGULATOR SET", ...],
    # ... itd. ~22 batchy
]
```

UWAGA: Pełną listę batchy wygeneruj z `_docs/FAZA1_concept_keys_v2.md`,
grupując po kategoriach tematycznych. Nie musisz mieć tego z góry,
zbuduj dynamicznie.

### Algorytm batch
```
for batch in BATCHES:
    1. Zbuduj kontekst: wyciągnij fragmenty źródeł relevantne dla TYCH haseł
    2. Zbuduj prompt: system + kontekst + "Wygeneruj hasła: [batch]"
    3. Wyślij do API zwycięskiego modelu
    4. Parsuj response, zapisz do pliku
    5. Rate limit: pauza 5s między wywołaniami
    6. Log: koszt, tokeny, czas per batch
    7. Append output do master file
```

### Output fazy 2
```
data/encyclopedia/v3/
├── batch_001_automaty.md
├── batch_002_automaty_cd.md
├── ...
├── batch_022_lifestyle.md
├── encyclopedia_v3_all.md          # scalony master file
└── generation_report.md            # koszt, tokeny, czas, błędy
```

## 4. BUDOWANIE KONTEKSTU PER PARTIA

Kluczowa logika: NIE wrzucaj wszystkich materiałów do każdego wywołania.
Wyciągaj FRAGMENTY relevantne dla danej partii haseł.

### Źródła i jak je fragmentować:

| Źródło | Metoda fragmentacji |
|--------|-------------------|
| transkrypt_kwestionariusza_eksperta.md | Grep po "## Grupa N:" — wyciągnij tylko sekcje matching |
| atp_questions_all.csv | Filter po kolumnie `group` matching |
| all_keywords.csv | Filter po seed keywords matching |
| dane_sprzedazowe_crosssell_12m.md | Grep po nazwach kategorii matching |
| dane_sprzedazowe_bestsellery_12m.md | Grep po nazwach kategorii matching |
| Encyklopedia_Nurkowania_NotebookLM_v2.md | Grep po nazwie hasła, wyciągnij draft |
| 11_mapa_marek-reviewed.md | Grep po kategorii, wyciągnij marki |
| 17_reguly_domenowe_grupy_C-M.md | Grep po grupie, wyciągnij reguły |

### Stały kontekst (w każdym wywołaniu):
- PROMPT_gemini_encyklopedia_v3.md (pełny, ~170 linii)
- Lista wszystkich 106 pojęć ze spisu (do linkowania wewnętrznego)

## 5. PLIKI WEJŚCIOWE

Wszystkie w katalogu projektu:
```
_docs/PROMPT_gemini_encyklopedia_v3.md
_docs/FAZA1_concept_keys_v2.md
_docs/11_mapa_marek-reviewed.md
_docs/17_reguly_domenowe_grupy_C-M.md
_docs/dane_sprzedazowe_crosssell_12m.md
_docs/dane_sprzedazowe_bestsellery_12m.md
_docs/wiedza_nurkowa/transkrypt_kwestionariusza_eksperta.md
_docs/wiedza_nurkowa/Encyklopedia_Nurkowania_NotebookLM_v2.md
data/dataforseo/questions/atp_questions_all.csv
data/dataforseo/processed/all_keywords.csv
_docs/dane_zewnetrzne_wyszukiwania/  (Luigi's Box jeśli dostępne)
.env (API keys)
```

## 6. CREDENTIALS

Z `.env`:
```
GOOGLE_GEMINI_API=AIzaSy... (Google AI Studio)
ANTHROPIC_API_KEY=sk-ant-...
OPENAI_API_KEY=sk-...
```

## 7. PROCEDURA

### Krok 1: Zbuduj skrypt
```bash
python3 scripts/generate_encyclopedia.py --phase test
```
- Generuje 3 hasła × 3 modele
- Output: `data/encyclopedia/v3/test/`

### Krok 2: Karol review
Karol porównuje 3 pliki, wybiera model. Feedback w chacie.

### Krok 3: Batch
```bash
python3 scripts/generate_encyclopedia.py --phase batch --model gemini
```
- Generuje 106 haseł na wybranym modelu
- Output: `data/encyclopedia/v3/encyclopedia_v3_all.md`

## 8. BUDŻET

Szacunki per 106 haseł (zakładając ~3K input + ~2K output tokens per hasło):
| Model | Input cost | Output cost | Total |
|-------|-----------|-------------|-------|
| Gemini 3.1 Pro | ~$0.60 | ~$2.50 | ~$3 |
| Claude Opus 4.6 | ~$4.50 | ~$15 | ~$20 |
| GPT-5.2 | TBD | TBD | TBD |

Test fazy 1: < $1 łącznie.
