# TASK-011b: LLM Product Enrichment (generowanie fraz wyszukiwania)
# Instancja: embeddings (Python)
# Zależności: TASK-011a (synonimy), dane z GSC/LB/GA4 (opcjonalne ale zalecane)
# Priorytet: WYSOKI (blokuje TASK-012b reembedding)

## CEL
Dla każdego z ~2556 produktów w divechat_product_embeddings,
wygenerować 5-8 alternatywnych fraz wyszukiwania przez LLM.

## ARCHITEKTURA
- Model generujący: OpenAI GPT-5.2 (lub najnowszy dostępny)
- Model walidujący: Anthropic Claude Opus 4.6
- Dane kontekstowe: frazy z GSC/LB/GA4 pogrupowane per kategoria

## KROK 1: Przygotowanie kontekstu per kategoria

Jeśli dostępne pliki w data/search_phrases/:
- gsc_queries_12m.csv
- luigis_box_queries_12m.csv
- ga4_site_search_12m.csv

Skrypt clean_search_data.py:
1. Wczytaj CSV-y
2. Odrzuć: <3 kliknięcia, frazy brandowe ("divezone"), nienurkowe
3. Pogrupuj per kategoria (na podstawie landing page z GSC)
4. Zapisz: data/search_phrases/phrases_per_category.json

```json
{
  "Skafandry Na ZIMNE wody": [
    "pianka nurkowa 7mm",
    "skafander mokry damski",
    "wetsuit na zimną wodę"
  ],
  "Komputery Nurkowe": [
    "komputer nurkowy shearwater",
    "komputer do nurkowania dla początkującego"
  ]
}
```

Jeśli pliki NIE SĄ dostępne: pomiń ten krok, prompt zadziała bez kontekstu.

## KROK 2: Generowanie fraz (GPT-5.2)

Skrypt: generate_search_phrases.py

Dla każdego produktu w divechat_product_embeddings:

```python
prompt = f"""Jesteś ekspertem nurkowym, instruktorem płetwonurkowania oraz sprzedawcą sprzętu
nurkowego z 20-letnim stażem pracującym dla divezone.pl, największego sklepu
nurkowego w Polsce. Wiesz wszystko o sprzęcie do nurkowania i technice nurkowej.
Znasz realia polskiego rynku nurkowego i wiesz jak klienci szukają sprzętu.

Budujemy czat AI, który odczytuje intencje klientów i mapuje je na produkty w sklepie.
Twoim zadaniem jest podać 5-8 alternatywnych fraz wyszukiwania, którymi POLSKI NUREK
mógłby szukać poniższego produktu.

PRODUKT: "{product_name}"
KATEGORIA: "{category_name}"
MARKA: {brand_name}
CECHY: {features}
OPIS: {description_short}

{f"KONTEKST: Frazy które klienci wpisują szukając produktów z kategorii '{category_name}':" + chr(10) + chr(10).join(f"- {phrase}" for phrase in category_phrases) if category_phrases else ""}

ZASADY:
- Frazy po polsku, potoczne i branżowe (jak nurek mówi do kolegi)
- Uwzględnij synonimy (pianka/skafander/wetsuit), kontekst użycia (zimna woda/Polska/Egipt),
  parametry techniczne (7mm, DIN, nitrox) i przeznaczenie (rekreacja/tech/jaskinie)
- Frazy 2-6 słów, bez powtarzania nazwy produktu ani marki
- Uwzględnij WSZYSTKIE regiony gdzie produkt ma zastosowanie
- Jeśli produkt ma warianty płciowe, uwzględnij frazy z "damski/męski"
- NIE generuj fraz negatywnych (bez "nie jest", "to nie")

FORMAT: jedna fraza per linia, bez numeracji, bez dodatkowych komentarzy."""
```

Parametry API:
- model: "gpt-5.2" (lub aktualny najlepszy)
- temperature: 0.7
- max_tokens: 200

Batch processing:
- Przetwarzaj po 10 produktów równolegle (asyncio)
- Zapisuj wyniki co 50 produktów (checkpoint)
- Retry z exponential backoff przy rate limiting
- Loguj: product_id, czas, koszt tokenów

Zapis wyników: data/enrichment/search_phrases_raw.json
```json
{
  "1234": {
    "product_name": "BARE Velocity Semi-Dry 7mm Lady",
    "phrases": [
      "pianka nurkowa damska na zimną wodę",
      "skafander mokry semidry 7mm",
      "wetsuit zimowy damski",
      "pianka do nurkowania w Polsce",
      "neopren 7mm damski bare"
    ],
    "model": "gpt-5.2",
    "tokens_in": 152,
    "tokens_out": 78,
    "timestamp": "2026-02-23T10:00:00Z"
  }
}
```

## KROK 3: Walidacja (Claude Opus 4.6)

Skrypt: validate_search_phrases.py

Dla każdego produktu, wyślij do Claude Opus:

```
Jesteś ekspertem nurkowym. Oceń frazy wyszukiwania wygenerowane dla produktu.

PRODUKT: "{product_name}"
KATEGORIA: "{category_name}"
MARKA: {brand_name}

WYGENEROWANE FRAZY:
{phrases_joined}

OCEŃ każdą frazę:
- OK: fraza jest trafna, polski nurek mógłby jej użyć
- USUN: fraza jest nietrafna, zbyt ogólna lub myląca
- POPRAW: fraza wymaga korekty (podaj poprawioną wersję)
- DODAJ: brakuje oczywistej frazy (podaj brakujące)

FORMAT odpowiedzi (JSON):
{
  "keep": ["fraza1", "fraza2"],
  "remove": ["fraza3"],
  "fix": [{"old": "fraza4", "new": "poprawiona fraza4"}],
  "add": ["nowa fraza"]
}
```

Zapis: data/enrichment/search_phrases_validated.json

## KROK 4: Zapis do bazy

```sql
ALTER TABLE divechat_product_embeddings
    ADD COLUMN IF NOT EXISTS search_phrases JSONB DEFAULT '[]'::jsonb;
```

Skrypt: load_search_phrases.py
- Wczytaj validated JSON
- UPDATE divechat_product_embeddings SET search_phrases = $1 WHERE ps_product_id = $2

## KROK 5: Test na 30 produktach (ZANIM full batch)

PRZED przetworzeniem 2556 produktów:
1. Wybierz 30 produktów: 10 z "Skafandry", 10 z "Komputery Nurkowe", 10 z "Automaty Oddechowe"
2. Uruchom generowanie + walidację tylko dla nich
3. Wyświetl wyniki do manualnego review
4. STOP i czekaj na akceptację od Karola

## PLIKI WYJŚCIOWE
- embeddings/clean_search_data.py
- embeddings/generate_search_phrases.py
- embeddings/validate_search_phrases.py
- embeddings/load_search_phrases.py
- data/enrichment/search_phrases_raw.json
- data/enrichment/search_phrases_validated.json
- data/enrichment/test_30_products_review.md (do review)

## KOSZTY (szacunkowe, 2556 produktów)
- GPT-5.2: ~150 tok in + ~80 tok out * 2556 = ~$2-6
- Claude Opus 4.6: ~200 tok in + ~100 tok out * 2556 = ~$6-10
- Łącznie: ~$8-16

## KRYTERIA AKCEPTACJI
- [ ] Test 30 produktów zaakceptowany przez Karola
- [ ] Każdy produkt ma 3-8 fraz (po walidacji)
- [ ] Kolumna search_phrases wypełniona w divechat_product_embeddings
- [ ] Zero fraz negatywnych (anti-phrases)
- [ ] Frazy są po polsku, potoczne, trafne
