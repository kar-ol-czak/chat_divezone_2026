# SPECYFIKACJA: Pipeline generacji encyklopedii sprzętu nurkowego
# Projekt: generate_encyclopedia
# Autor: Architekt (Claude Opus, sesja 2026-02-27)
# Status: DO IMPLEMENTACJI przez Claude Code
# Wersja: 1.0

---

## 1. CEL

Zautomatyzować proces generacji i walidacji definicji encyklopedii sprzętu nurkowego,
ktory dotad byl wykonywany recznie (kopiowanie promptow do UI, naprawa JSON, kopiowanie wynikow).

Pipeline obsluguje 11 grup (C-M, lacznie ~81 pojec).
Grupy A (15 pojec) i B (9 pojec) sa juz gotowe i sluza jako wzorzec jakosci.

## 2. ARCHITEKTURA

```
generate_encyclopedia/
  spec.md                  # ta specyfikacja
  .env.encyclopedia        # klucze API (symlink/kopiuje z ../.env)
  config.py                # konfiguracja modeli, sciezek, parametrow
  models.py                # klienty API (OpenAI, Anthropic)
  pipeline.py              # glowna logika: generacja + walidacja + zapis
  prompt_builder.py        # buduje prompty z szablonu + metadanych grupy
  json_sanitizer.py        # naprawia Unicode, cudzysłowy, waliduje schema
  group_metadata.py        # metadane per grupa (pojecia, marki, frazy, reguly)
  run.py                   # CLI: python run.py --group C [--dry-run] [--gen-only] [--val-only]
  templates/
    generation.md.j2       # szablon Jinja2 promptu generacyjnego
    validation.md.j2       # szablon Jinja2 promptu walidacyjnego
  output/
    grupa_{X}_original.json    # surowy output z GPT
    grupa_{X}_validated.md     # wynik walidacji z Claude
    grupa_{X}_final.json       # po auto-patchach (jesli 0 FAIL)
  logs/
    run_{timestamp}.log        # logi z kazdego przebiegu
```

## 3. MODELE AI

### 3.1 Generacja: OpenAI GPT-5.2 thinking
- Model string: sprawdzic w .env -> OPENAI_CHAT_MODEL (obecnie: gpt-5.2)
- WAZNE: uzyc wariantu "thinking" jesli API wymaga innego model string
- reasoning_effort: "high" (NIGDY nie uzywac low/medium dla tego zadania)
- temperature: 0.4 (zachowawczo, definicje musza byc precyzyjne)
- max_output_tokens: 16000 (duze grupy jak C maja 16 pojec)

### 3.2 Walidacja: Anthropic Claude Opus 4.6 extended
- Model string: "claude-opus-4-6" (NIE sonnet! w .env jest sonnet dla czatu)
- Extended thinking: wlaczyc z budget_tokens=16000
- temperature: 1.0 (wymagane przez API przy extended thinking)
- max_tokens: 16000

### 3.3 KRYTYCZNA REGULA
NIGDY nie uzywac modeli starszych niz 6 miesiecy.
NIGDY nie wybierac tanszego modelu "bo wystarczy".
Jesli model z .env jest inny niz powyzsze, uzyc TWARDYCH wartosci ze specyfikacji.

## 4. FLOW PIPELINE

```
[1] Buduj prompt generacyjny
    - Wczytaj szablon templates/generation.md.j2
    - Wczytaj metadane grupy z group_metadata.py
    - Wczytaj znane bledy v1 z ../data/encyclopedia/raw/
    - Wczytaj frazy DataForSEO z ../data/dataforseo/processed/all_keywords.csv
    - Wczytaj marki z ../_docs/11_mapa_marek-reviewed.md
    - Renderuj prompt Jinja2

[2] Wyslij do GPT-5.2 thinking
    - Wyslij wyrenderowany prompt
    - Odbierz odpowiedz

[3] Wyciagnij i napraw JSON
    - Znajdz blok JSON w odpowiedzi (moze byc w markdown code block)
    - Napraw znane problemy Unicode (json_sanitizer.py):
      * Polskie cudzyslowy U+201E/U+201D -> apostrof lub usun
      * Strzalki -> / ->, wielokropki -> ...
      * Pautierki -> -
      * Inne znaki spoza ASCII+polskie -> zamienniki ASCII
    - Sparsuj JSON
    - Waliduj schema: tablica obiektow, wymagane pola, typy

[4] Zapisz original
    - output/grupa_{X}_original.json

[5] Buduj prompt walidacyjny
    - Wczytaj szablon templates/validation.md.j2
    - Dolacz wygenerowany JSON
    - Dolacz marki, frazy, reguly domenowe

[6] Wyslij do Claude Opus 4.6 extended
    - Wyslij prompt walidacyjny + JSON
    - Odbierz odpowiedz z extended thinking

[7] Zapisz walidacje
    - output/grupa_{X}_validated.md

[8] Parsuj werdykty
    - Wyciagnij PASS/PASS z uwagami/FAIL per concept
    - Jesli 0 FAIL: skopiuj original jako final
    - Jesli FAIL > 0: zapisz raport, NIE twórz final (wymaga review)

[9] Raport
    - Wyswietl podsumowanie: X PASS, Y PASS z uwagami, Z FAIL
    - Jesli FAIL: wyswietl ktore pojecia i dlaczego
```

## 5. JSON SANITIZER (json_sanitizer.py)

To jest KRYTYCZNY komponent. Modele AI generuja JSON z niedozwolonymi znakami Unicode.

### 5.1 Zanim wyciagniesz JSON
- Szukaj bloku ```json ... ``` w odpowiedzi
- Jesli brak, szukaj pierwszego [ i ostatniego ]
- Usun trailing commas przed ] i }

### 5.2 Zamiana znakow (PRZED parsowaniem)
| Znak | Unicode | Zamiana |
|------|---------|--------|
| otwierajacy cudz. polski | U+201E | ' (apostrof) |
| zamykajacy cudz. polski | U+201D | ' (apostrof) |
| otwierajacy cudz. angielski | U+201C | ' (apostrof) |
| zamykajacy cudz. angielski | U+201D | ' (apostrof) |
| strzalka w prawo | U+2192 | -> |
| strzalka w lewo | U+2190 | <- |
| strzalka gora/dol | U+2191/U+2193 | ^ / v |
| pautierka (em dash) | U+2014 | - |
| en dash | U+2013 | - |
| wielokropek | U+2026 | ... |
| nie-rowne | U+2260 | != |
| spacja nierozerywalna | U+00A0 | spacja |
| apostrof typograficzny | U+2019 | ' |

### 5.3 Po parsowaniu: schema validation
Kazdzy entry musi miec:
- id: string, UPPER_SNAKE_CASE
- nazwa_pl: string, niepusty
- nazwa_en: string, niepusty
- definicja: string, min 50 znakow
- podtypy: array of strings
- synonimy_pl: object z kluczami exact, near, potoczne, archaiczne
- synonimy_pl.bledne_ale_popularne: array (opcjonalny, ale zalecany)
- synonimy_en: object z kluczami exact, near
- nie_mylic_z: array of objects z kluczami concept, dlaczego
- parametry_zakupowe: array of strings, min 1
- marki_w_sklepie: array of strings
- powiazane_produkty: array of strings
- faq: array of objects z kluczami pytanie, odpowiedz
- uwagi_dla_ai: string, niepusty

### 5.4 Walidacja marek
Sprawdz ze kazda marka z marki_w_sklepie jest na whiteliscie
z _docs/11_mapa_marek-reviewed.md (sekcja "Wszystkie aktywne marki").
Jesli znajdziesz zakazana marke, loguj WARNING.

## 6. PROMPT BUILDER (prompt_builder.py)

### 6.1 Szablon generacyjny (templates/generation.md.j2)
Bazuje na promptach A i B, ale jest parametryzowany:
- {{ group_id }}: litera grupy (C, D, ...)
- {{ group_name }}: nazwa grupy ("Kontrola plywalnosci")
- {{ concept_count }}: liczba pojec
- {{ concepts_list }}: lista pojec z opisami
- {{ brands_for_group }}: marki dozwolone dla tej grupy
- {{ forbidden_brands }}: marki zakazane
- {{ dataseo_phrases }}: frazy z DataForSEO
- {{ known_errors_v1 }}: znane bledy z raw/ (auto-wyciagane)
- {{ domain_rules }}: reguly specyficzne dla grupy
- {{ json_formatting_rules }}: STALE instrukcje o Unicode

### 6.2 Sekcja JSON FORMATTING (dolaczana do KAZDEGO promptu)
```
## FORMATOWANIE JSON (KRYTYCZNE)
- W wartosciach JSON NIE uzywaj polskich cudzyslowow
- Zamiast cudzyslowow w tekscie uzywaj apostrofow: 'butla z tlenem'
- Dozwolone znaki w stringach: litery (w tym polskie), cyfry, spacje,
  przecinki, kropki, nawiasy (), myslniki -, ukosniki /, dwukropki :
- ZABRONIONE: typograficzne cudzyslowy, strzalki, pautierki, wielokropki
- Jesli potrzebujesz strzalki, napisz: ->
- Jesli potrzebujesz nie-rowne, napisz: !=
```

## 7. GROUP METADATA (group_metadata.py)

Plik definiuje metadane dla kazdej grupy. Zrodla:
- Pojecia: _docs/FAZA1_concept_keys_v2.md
- Marki: _docs/11_mapa_marek-reviewed.md (sekcja "Rekomendowane marki wg kategorii")
- Frazy: data/dataforseo/processed/all_keywords.csv (filtrowane per grupa)
- Znane bledy v1: data/encyclopedia/raw/*.json (auto-ekstrakcja)
- Reguly domenowe: per grupa (hardcoded w metadanych)

### 7.1 Struktura metadanych per grupa
```python
GROUP_C = {
    "id": "C",
    "name": "Kontrola plywalnosci",
    "concept_count": 16,
    "concepts": [
        {"key": "JACKET", "name_pl": "jacket (BCD)", "description": "..."},
        # ...
    ],
    "brands": {
        "allowed": {"Jackety/skrzydla": ["HOLLIS", "MARES", "SCUBAPRO", "TECLINE", "TUSA", "xDEEP"]},
        "forbidden": ["Cressi", "Sherwood Scuba", ...]
    },
    "dataforseo_keywords": ["jacket nurkowy", "skrzydlo nurkowe", ...],
    "domain_rules": [
        "Inflator to glowica z przyciskami. Waz inflatora to waz LP z QD. Waz karbowany to przewod BCD-inflator. To 3 ROZNE SKU.",
        "Sidemount to konfiguracja, nie produkt. Zestaw sidemount to konkretny produkt.",
    ],
    "v1_known_errors": {
        "INFLATOR": "Definicja laczyla mechanizm inflatora z wezem karbowanym - to osobne SKU",
        # auto-wyciagane z raw/
    }
}
```

### 7.2 Auto-ekstrakcja bledow v1
Dla kazdego pojecia ktore ma plik w raw/:
1. Wczytaj raw JSON
2. Wyciagnij: definicje, synonimy, nie_mylic_z
3. Dolacz jako "kontekst v1" do promptu (zeby model widzial co wygenerowano wczesniej)
4. NIE dolaczaj calego JSON, tylko kluczowe pola

### 7.3 Filtrowanie fraz DataForSEO
Z all_keywords.csv wyciagnij frazy pasujace do grupy:
- Po slowach kluczowych (np. "jacket", "skrzydlo", "wing", "bcd", "inflator" dla grupy C)
- Zapisz z wolumenem
- Sortuj malejaco po wolumenie

## 8. REGULY DOMENOWE

### 8.1 Globalne (dolaczane do KAZDEGO promptu)

1. DIN to JEDYNY aktualny standard przylacza automatu do butli.
   INT/yoke to martwy standard, nie produkowany od ~10 lat.
   W Europie nigdy nie byl powszechny.
   Nie traktowac INT jako rownorzednej opcji zakupowej.

2. Jedna definicja = jeden typ SKU. Nigdy nie laczyc roznych produktow.

3. Synonimy potoczne sa WAZNIEJSZE niz techniczne.

4. 'Nie mylic z' musi zawierac tylko pary ktore klienci REALNIE myla.

5. Marki w marki_w_sklepie tylko z whitelisty.

6. FAQ oparte na realnych frazach klientow (DataForSEO).

7. Jesli pomylka miedzy produktami moze byc niebezpieczna, MUSI byc ostrzezenie.

### 8.2 Per grupa (z pliku _docs/17_reguly_domenowe_grupy_C-M.md)

Plik zawiera 21 ponumerowanych regul domenowych.
Pipeline MUSI wczytac ten plik i dolaczyc reguly odpowiedniej grupy do promptu.

UWAGA: Litery grup w pliku regul NIE pokrywaja sie z literami grup encyklopedii!
Przyklad: 'Grupa E' w regulach = BCD/Wing/Jacket, ale 'Grupa E' w encyklopedii = Maski i fajki.
Mapowanie ponizej jest jedynym zrodlem prawdy.

Mapowanie grup encyklopedii na reguly domenowe:
- Grupa C (Kontrola plywalnosci) -> reguly z sekcji E (BCD/Wing/Jacket): 6, 7
- Grupa D (Instrumenty i nawigacja) -> reguly z sekcji I (Instrumenty/Komputery): 13, 14, 15
- Grupa E (Maski i fajki) -> reguly z sekcji D (Maski i fajki): 3, 4, 5
- Grupa F (Ochrona termiczna mokra) -> reguly z sekcji C (Pianki): 1, 2
- Grupa G (Ochrona termiczna sucha) -> reguly z sekcji C (Pianki): 1, 2
- Grupa H (Obuwie i pletwy) -> reguly z sekcji H (Pletwy): 11, 12
- Grupa I (Bezpieczenstwo i sygnalizacja) -> reguly z sekcji K (Akcesoria tech.): 18, 19, 20
- Grupa J (Oswietlenie i foto/video) -> reguly z sekcji J (Latarki): 16, 17 + sekcji L (Foto): 21
- Grupa K (Klipsy, mocowania, transport) -> brak specyficznych
- Grupa L (Konserwacja i akcesoria) -> brak specyficznych
- Grupa M (Lifestyle, edukacja, inne) -> brak specyficznych

REGULY KRYTYCZNE (bezpieczenstwo, musza byc w KAZDYM prompcie niezaleznie od grupy):
- 'Butla z tlenem' = blad terminologiczny, korygowac (regula 8)
- Szpulka != kolowrotek (regula 18)
- Komputer nurkowy != zegarek sportowy (regula 13)
- Pianka polsucha != suchy skafander (regula 1)
- 'Maska z tlenem' = blad terminologiczny (regula 3)

## 9. CLI (run.py)

```
python run.py --group C                # generacja + walidacja grupy C
python run.py --group C --gen-only     # tylko generacja (bez walidacji)
python run.py --group C --val-only     # tylko walidacja (uzyj istniejacego original)
python run.py --group all              # wszystkie grupy C-M sekwencyjnie
python run.py --group C --dry-run      # pokaz prompt bez wysylania
python run.py --group C --retry        # powtorz z nowym seedem
```

### 9.1 Tryb --group all: WYLACZONY
NIE implementowac trybu all. Workflow z gate'ami wymaga review Karola po kazdej grupie.
Kazda grupa jest uruchamiana osobno: python run.py --group C, potem review, potem D itd.

### 9.2 Gate workflow
Po kazdej grupie:
1. Pipeline generuje + waliduje
2. Karol przegląda output/grupa_{X}_validated.md
3. Jesli OK: pipeline kopiuje original jako final
4. Jesli FAIL: Karol decyduje o patchach lub re-generacji
5. Dopiero potem nastepna grupa

## 10. WZORCE JAKOSCI (pliki referencyjne)

Pipeline ma dostep do gotowych grup A i B jako wzorca:
- data/encyclopedia/grupa_A_oddychanie.json (15 pojec, spatchowany)
- data/encyclopedia/grupa_B_butle_zawory.json (9 pojec, spatchowany)

Szablon promptu generacyjnego MUSI byc zgodny z formatem tych plikow.
Przy schema validation porownuj z tymi plikami.

## 11. ZALEZNOSCI

```
openai>=1.40.0        # GPT-5.2 thinking API
anthropic>=0.40.0     # Claude Opus 4.6 extended thinking
jinja2>=3.1.0         # szablony promptow
python-dotenv>=1.0.0  # ladowanie .env
```

requirements.txt w katalogu generate_encyclopedia/.

## 12. OBSLUGA BLEDOW

- Jesli API zwroci blad (rate limit, timeout): retry 3x z exponential backoff
- Jesli JSON nie parsuje sie po sanityzacji: zapisz surowy output do logs/, rzuc blad
- Jesli schema validation nie przejdzie: zapisz z suffixem _invalid.json, kontynuuj
- Jesli walidator nie zwroci PASS/FAIL: zapisz surowy output, oznacz jako MANUAL_REVIEW

## 12.5 LOGOWANIE I KOSZTY

### 12.5.1 Struktura logow
Kazdy przebieg grupy generuje plik: logs/grupa_{X}_{timestamp}.json

```json
{
  "group": "C",
  "timestamp_start": "2026-02-27T16:00:00Z",
  "timestamp_end": "2026-02-27T16:03:45Z",
  "duration_seconds": 225,
  "steps": [
    {
      "step": "generation",
      "model": "gpt-5.2",
      "reasoning_effort": "high",
      "tokens_input": 12500,
      "tokens_output": 8700,
      "tokens_reasoning": 3200,
      "cost_usd": 0.85,
      "duration_seconds": 95,
      "status": "ok"
    },
    {
      "step": "json_sanitize",
      "replacements": {"U+201E": 14, "U+2192": 3},
      "json_valid": true,
      "schema_valid": true,
      "entries_count": 16,
      "status": "ok"
    },
    {
      "step": "validation",
      "model": "claude-opus-4-6",
      "extended_thinking": true,
      "budget_tokens": 16000,
      "tokens_input": 18200,
      "tokens_output": 4500,
      "tokens_thinking": 8900,
      "cost_usd": 1.20,
      "duration_seconds": 120,
      "status": "ok"
    },
    {
      "step": "verdict_parse",
      "pass": 12,
      "pass_with_notes": 3,
      "fail": 1,
      "fail_concepts": ["INFLATOR"],
      "status": "needs_review"
    }
  ],
  "total_cost_usd": 2.05,
  "total_tokens": {"input": 30700, "output": 13200, "reasoning": 12100},
  "result": "needs_review"
}
```

### 12.5.2 Cennik (do config.py, aktualizowac jesli sie zmieni)
Pipeline musi obliczac koszt na podstawie aktualnych cen per token.
Sprawdzic aktualne ceny przy implementacji (web search).
Logowac: tokens_input, tokens_output, tokens_reasoning/thinking osobno.

### 12.5.3 Raport zbiorczy
Po kazdym uzyciu wyswietl na stdout:
```
=== GRUPA C: Kontrola plywalnosci ===
Generacja: gpt-5.2 | 12500 in / 8700 out / 3200 reasoning | $0.85 | 95s
Walidacja: claude-opus-4-6 | 18200 in / 4500 out / 8900 thinking | $1.20 | 120s
Sanitizer: 17 zamian Unicode, JSON valid, schema valid, 16 entries
Werdykt: 12 PASS, 3 PASS z uwagami, 1 FAIL (INFLATOR)
Koszt laczny: $2.05 | Czas: 3m 45s
```

## 12.6 STRUKTURA PLIKOW WYJSCIOWYCH

Wszystko w generate_encyclopedia/output/:

```
output/
  grupa_C/
    prompt_generation.md       # pelny prompt wyslany do GPT
    response_generation.md     # surowa odpowiedz GPT (przed sanityzacja)
    grupa_C_original.json      # JSON po sanityzacji i schema check
    prompt_validation.md       # pelny prompt wyslany do Claude
    response_validation.md     # surowa odpowiedz Claude
    grupa_C_validated.md       # sparsowany wynik walidacji
    grupa_C_final.json         # finalny JSON (po patchach lub jesli 0 FAIL)
  grupa_D/
    ...
  logs/
    grupa_C_2026-02-27T160000.json   # strukturalny log z tokenami i kosztami
    grupa_D_2026-02-27T163000.json
```

Kazdy krok zapisuje swoj input i output. Jesli cos pojdzie nie tak,
mozna odtworzyc caly przebieg z plikow w output/grupa_{X}/.

## 13. BEZPIECZENSTWO

- Klucze API TYLKO z .env (NIE hardcodowane)
- Logi NIE zawieraja kluczy API
- Output NIE jest commitowany do git (dodaj do .gitignore)

## 14. TESTOWANIE

Przed uruchomieniem na grupach C-M:
1. Uruchom --dry-run na grupie A i porownaj wygenerowany prompt z _docs/prompts/PROMPT_encyklopedia_grupa_A.md
2. Uruchom --group B --gen-only i porownaj output z grupa_B_original.json (nie musi byc identyczny, ale schema musi sie zgadzac)
3. Uruchom json_sanitizer.py na surowym uzytkowniku z problemami Unicode (test case z grupa_B)

## 15. PLIKI WEJSCIOWE (sciezki wzgledem katalogu projektu)

| Plik | Cel | Wymagany |
|------|-----|----------|
| .env | klucze API (OPENAI_API_KEY, ANTHROPIC_API_KEY) | TAK |
| _docs/FAZA1_concept_keys_v2.md | lista pojec per grupa | TAK |
| _docs/11_mapa_marek-reviewed.md | whitelist/blacklist marek | TAK |
| _docs/17_reguly_domenowe_grupy_C-M.md | reguly domenowe per grupa | TAK |
| data/dataforseo/processed/all_keywords.csv | frazy klientow z Google | TAK |
| data/encyclopedia/raw/*.json | definicje v1 (znane bledy) | TAK |
| data/encyclopedia/grupa_A_oddychanie.json | wzorzec jakosci A | TAK |
| data/encyclopedia/grupa_B_butle_zawory.json | wzorzec jakosci B | TAK |
| _docs/prompts/PROMPT_encyklopedia_grupa_A.md | wzorzec promptu gen. | TAK |
| _docs/prompts/PROMPT_walidacja_grupa_A.md | wzorzec promptu wal. | TAK |
| _docs/prompts/PROMPT_encyklopedia_grupa_B.md | wzorzec promptu gen. | TAK |
| _docs/prompts/PROMPT_walidacja_grupa_B.md | wzorzec promptu wal. | TAK |
