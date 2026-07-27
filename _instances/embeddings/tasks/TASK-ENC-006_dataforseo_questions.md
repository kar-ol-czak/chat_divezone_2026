# TASK-ENC-006: DataForSEO Questions (ATP-style) dla encyklopedii
# Data: 2026-03-03
# Status: DONE (2026-03-04) — 1060 fraz, koszt $0.33
# Instancja: embeddings
# ADR: ADR-042
# Blokuje: generację encyklopedii Gemini (dane FAQ)
# Zależności: konto DataForSEO (aktywne), credentials w .env
# Poprzedni task: TASK-017 (keywords, DONE, $0.45)

---

## 1. CEL

Pobrać pytania klientów (People Also Ask, Google Autocomplete, Related Searches)
dla ~100 seed keywords nurkowych z DataForSEO API. Wyniki zasilą sekcję FAQ
w encyklopedii generowanej przez Gemini.

## 2. DLACZEGO NIE ATP

Answer The Public nie ma API. DataForSEO ma endpointy które dają te same dane
programatycznie, taniej ($2-5 vs $99/mies.) i powtarzalnie.

## 3. ENDPOINTY DataForSEO

### 3a. Google Autocomplete
```
POST https://api.dataforseo.com/v3/serp/google/autocomplete/live
Authorization: Basic {BASE64 z .env}
Content-Type: application/json

[{
    "keyword": "automat nurkowy",
    "language_code": "pl",
    "location_code": 2616
}]
```
Zwraca: autocomplete suggestions (jak "automat nurkowy cena", "automat nurkowy używany")
Koszt: $0.01 per request

### 3b. People Also Ask (PAA)
Nie ma dedykowanego endpointu PAA. PAA jest częścią wyników SERP:
```
POST https://api.dataforseo.com/v3/serp/google/organic/live
Authorization: Basic {BASE64 z .env}
Content-Type: application/json

[{
    "keyword": "automat nurkowy",
    "language_code": "pl",
    "location_code": 2616,
    "depth": 100
}]
```
W odpowiedzi szukaj items z type="people_also_ask".
Koszt: $0.002 per request (najtańszy!)

### 3c. Related Searches
W tym samym SERP response szukaj items z type="related_searches".
Darmowe (część tego samego requestu co PAA).

## 4. SEED KEYWORDS

### Faza 1 — Test (5 seedów)
```python
PHASE1_SEEDS = [
    "automat nurkowy",
    "komputer nurkowy",
    "maska do nurkowania",
    "suchy skafander",
    "skrzydło nurkowe"
]
```

### Faza 2 — Pełny run (~100 seedów)
Pogrupowane wg 20 grup z kwestionariusza eksperta:

```python
PHASE2_SEEDS = {
    "automaty": [
        "automat nurkowy", "regulator nurkowy", "octopus nurkowy",
        "pierwszy stopień automatu", "drugi stopień automatu"
    ],
    "weze": [
        "wąż nurkowy", "wąż LP", "wąż HP", "wąż do inflatora", "wąż miflex"
    ],
    "butle": [
        "butla nurkowa", "butla stalowa nurkowanie", "butla aluminiowa nurkowanie",
        "butla stage", "twinset nurkowy"
    ],
    "zawory": [
        "zawór do butli nurkowej", "manifold nurkowy", "zawór DIN"
    ],
    "bcd": [
        "skrzydło nurkowe", "jacket nurkowy", "kamizelka nurkowa",
        "BCD nurkowanie", "backplate wing"
    ],
    "balast": [
        "balast nurkowy", "kieszenie balastowe", "kieszenie trymujące",
        "ciężarki do nurkowania"
    ],
    "komputery": [
        "komputer nurkowy", "komputer nurkowy zegarkowy", "suunto nurkowanie",
        "shearwater peregrine", "garmin descent"
    ],
    "maski": [
        "maska do nurkowania", "maska nurkowa korekcyjna", "maska panoramiczna",
        "maska frameless", "zestaw maska fajka"
    ],
    "pianki": [
        "pianka do nurkowania", "pianka nurkowa 5mm", "pianka nurkowa 7mm",
        "shorty nurkowanie", "suchy skafander nurkowy"
    ],
    "ochrona_termiczna": [
        "buty nurkowe", "rękawice nurkowe", "kaptur nurkowy",
        "ocieplacz do suchego", "rękawice suche nurkowanie"
    ],
    "pletwy": [
        "płetwy nurkowe", "płetwy do nurkowania", "płetwy jet fin",
        "płetwy paskowe", "płetwy kaloszowe"
    ],
    "bezpieczenstwo": [
        "bojka dekompresyjna", "szpulka nurkowa", "nóż nurkowy",
        "kołowrotek nurkowy", "linka nurkowa"
    ],
    "latarki": [
        "latarka nurkowa", "latarka do nurkowania", "latarka backup nurkowanie"
    ],
    "kompasy_manometry": [
        "kompas nurkowy", "manometr nurkowy", "konsola nurkowa"
    ],
    "akcesoria": [
        "karabinki nurkowe", "retraktor nurkowy", "tabliczka nurkowa",
        "antifog do maski"
    ],
    "fotografia": [
        "obudowa podwodna smartfon", "fotografia podwodna",
        "latarka video nurkowa"
    ],
    "torby": [
        "torba nurkowa", "torba na automat", "torba na sprzęt nurkowy"
    ],
    "ogrzewanie": [
        "ogrzewanie nurkowe", "rękawice grzewcze nurkowanie"
    ],
    "sidemount": [
        "sidemount nurkowanie", "konfiguracja sidemount", "xdeep stealth"
    ],
    "ogolne": [
        "sprzęt do nurkowania", "sprzęt nurkowy sklep", "nurkowanie dla początkujących sprzęt",
        "jaki sprzęt do nurkowania", "kurs nurkowania sprzęt"
    ]
}
```

## 5. ALGORYTM

```
dla każdego seed_keyword:
    1. POST /v3/serp/google/organic/live (PAA + Related Searches)
    2. POST /v3/serp/google/autocomplete/live (Autocomplete suggestions)
    3. Parsuj odpowiedź:
       - people_also_ask → pytania (kolumna: source=PAA)
       - related_searches → frazy (kolumna: source=related)
       - autocomplete → sugestie (kolumna: source=autocomplete)
    4. Deduplikacja per seed
    5. Rate limit: 1 req/sec (bezpieczny margines)
    6. Zapisz do CSV
```

## 6. FORMAT WYJŚCIOWY

Plik: `data/dataforseo/questions/atp_questions_all.csv`

```csv
seed_keyword,question_or_phrase,source,group
automat nurkowy,"Jaki automat nurkowy dla początkującego?",PAA,automaty
automat nurkowy,"automat nurkowy używany",autocomplete,automaty
automat nurkowy,"automat nurkowy vs regulator",related,automaty
```

Plik podsumowania: `data/dataforseo/questions/atp_summary.md`
```
Seed keywords: 100
Unikalne pytania PAA: N
Unikalne autocomplete: N
Unikalne related: N
Łączny koszt: $X.XX
```

## 7. PROCEDURA WYKONANIA

### Krok 1: Test (5 seedów)
```bash
python3 scripts/dataforseo_questions.py --phase test --output data/dataforseo/questions/
```
- Uruchom na 5 seedach z PHASE1_SEEDS
- Sprawdź czy PAA/autocomplete zwracają sensowne dane dla polskiego rynku
- Sprawdź koszt (powinien być < $0.10)
- Pokaż wyniki Karolowi do review

### Krok 2: Review
Karol sprawdza:
- Czy pytania PAA są sensowne (nie śmieciowe)
- Czy autocomplete daje frazy których nie ma w all_keywords.csv
- Czy warto rozszerzać listę seedów

### Krok 3: Pełny run (~100 seedów)
```bash
python3 scripts/dataforseo_questions.py --phase full --output data/dataforseo/questions/
```
- Szacowany koszt: $2-5
- Czas: ~5 min (100 requestów × 2 endpointy × 1s rate limit)

## 8. WALIDACJA

Po uruchomieniu sprawdź:
- [ ] CSV parsuje się poprawnie (UTF-8, polskie znaki)
- [ ] Brak duplikatów (ten sam pytanie z różnych seedów)
- [ ] Każdy wiersz ma wypełnione: seed, question, source, group
- [ ] Koszt nie przekroczył $10
- [ ] Pytania PAA to faktyczne pytania (nie linki/reklamy)

## 9. CREDENTIALS

Wszystko w `.env`:
```
DATAFORSEO_API_LOGIN=k.susicki@divezone.pl
DATAFORSEO_API_PASSWORD=a71e9ca08d66a03d
DATAFORSEO_API_PASSWORD-BASE64=ay5zdXNpY2tpQGRpdmV6b25lLnBsOmE3MWU5Y2EwOGQ2NmEwM2Q=
```

## 10. PLIKI

- Skrypt: `scripts/dataforseo_questions.py`
- Output test: `data/dataforseo/questions/atp_questions_test.csv`
- Output full: `data/dataforseo/questions/atp_questions_all.csv`
- Summary: `data/dataforseo/questions/atp_summary.md`

## 11. UWAGI

- Istniejący TASK-017 pobrał keywords (Google Ads API via DataForSEO). Ten task pobiera
  PYTANIA (SERP PAA + autocomplete). Inne endpointy, inna wartość.
- Dane z tego taska tagowane jako [DataForSEO-PAA] lub [DataForSEO-AC] w encyklopedii.
- Jeśli PAA nie daje wyników dla niszowych seedów (np. "manifold nurkowy"),
  to OK — zostaw puste, Gemini poradzi sobie bez.
