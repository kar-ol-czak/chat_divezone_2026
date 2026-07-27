# TASK-017: Pobieranie danych DataForSEO dla encyklopedii

## Status: DONE (2026-02-27) — 1404 fraz, koszt $0.45
## Instancja: embeddings
## Blokuje: TASK-014 v2 (generacja encyklopedii), TASK-016 (blog)
## Zależności: konto DataForSEO (aktywne), credentials w .env

## Cel
Pobrać dane keyword z Google Ads API (via DataForSEO) dla ~90 kategorii sprzętowych.
Wyniki wchodzą jako kontekst do promptu generacyjnego encyklopedii (GPT-5.2 thinking).

## Budżet
- Dostępny: $50 (pay-as-you-go, saldo nie wygasa)
- Szacowany koszt tego taska: ~$7
- Keywords for Site: 1 req × $0.075 = $0.075
- Keywords for Keywords: 5 req × $0.075 = $0.375
- Bufor na retries/dodatkowe: ~$6.50

## Credentials (.env)
```
DATAFORSEO_API_LOGIN=k.susicki@divezone.pl
DATAFORSEO_API_PASSWORD=a71e9ca08d66a03d
DATAFORSEO_API_PASSWORD-BASE64=ay5zdXNpY2tpQGRpdmV6b25lLnBsOmE3MWU5Y2EwOGQ2NmEwM2Q=
```

## API Endpoints

### 1. Keywords for Site
```
POST https://api.dataforseo.com/v3/keywords_data/google_ads/keywords_for_site/live
Authorization: Basic {BASE64}
Content-Type: application/json

[{"location_code": 2616, "language_code": "pl", "target": "divezone.pl"}]
```
Zwraca: do 700 fraz powiązanych z domeną, z wolumenami i CPC.

### 2. Keywords for Keywords
```
POST https://api.dataforseo.com/v3/keywords_data/google_ads/keywords_for_keywords/live
Authorization: Basic {BASE64}
Content-Type: application/json

[{"location_code": 2616, "language_code": "pl", "keywords": ["fraza1", "fraza2", ..., "fraza20"]}]
```
Max 20 keywords per request. Zwraca: powiązane frazy z wolumenami.
Koszt: $0.075 per request niezależnie od liczby keywords.

## Seed Keywords (~90, pogrupowane w 5 batchów po 20)

### Batch 1: Oddychanie + butle (20)
1. automat nurkowy
2. automat oddechowy
3. pierwszy stopień automatu
4. drugi stopień automatu
5. octopus nurkowy
6. zestaw automatów nurkowych
7. wąż nurkowy LP
8. wąż HP do manometru
9. wąż do inflatora
10. butla nurkowa
11. butla aluminiowa nurkowa
12. butla stage
13. twinset nurkowy
14. manifold nurkowy
15. zawór butlowy
16. złącze DIN nurkowe
17. złącze INT yoke
18. rebreather nurkowy
19. nitrox nurkowy
20. analizator tlenu nurkowy

### Batch 2: Kontrola pływalności + sidemount (20)
1. jacket nurkowy
2. skrzydło nurkowe wing
3. backplate nurkowy
4. uprząż nurkowa harness
5. system BPW nurkowy
6. inflator nurkowy
7. sidemount nurkowy
8. balast nurkowy ciężarki
9. pas balastowy nurkowy
10. kieszenie balastowe zintegrowane
11. manometr nurkowy
12. konsola nurkowa
13. kompas nurkowy
14. komputer nurkowy
15. transmiter ciśnienia nurkowy
16. boja dekompresyjna DSMB
17. szpulka nurkowa spool
18. kołowrotek nurkowy reel
19. karabinek nurkowy
20. retractor nurkowy

### Batch 3: Ochrona termiczna + ochrona ciała (20)
1. pianka nurkowa mokra
2. pianka nurkowa shorty
3. pianka półsucha nurkowa
4. suchy skafander nurkowy
5. ocieplacz pod skafander
6. kaptur nurkowy
7. rękawice nurkowe
8. suche rękawice nurkowe system
9. buty nurkowe neoprenowe
10. zawory suchego skafandra
11. maska nurkowa
12. maska nurkowa dwuszybowa
13. maska pełnotwarzowa
14. fajka nurkowa
15. nóż nurkowy
16. sekator nurkowy line cutter
17. latarka nurkowa
18. płetwy nurkowe
19. płetwy paskowe nurkowe
20. płetwy jet fins

### Batch 4: Akcesoria + konserwacja (20)
1. płetwy kaloszowe nurkowe
2. sprężyny do płetw
3. trymówka nurkowa
4. szelki do butli stage
5. worek wypornościowy lift bag
6. światło chemiczne nurkowe
7. klej neoprenowy
8. antifog do maski nurkowej
9. prep do maski nurkowej
10. pasek do maski nurkowej
11. zestaw serwisowy automatu
12. smar silikonowy nurkowy
13. torba nurkowa
14. skrzynia nurkowa
15. kamizelka do snorkelingu
16. kamizelka ocieplająca nurkowa
17. suszarka do skafandra
18. wieszak nurkowy
19. maska wieloszybowa nurkowa
20. nożyce nurkowe

### Batch 5: Frazy ogólne + marki + intencje zakupowe (20)
1. sprzęt nurkowy sklep
2. sprzęt do nurkowania
3. wyposażenie nurka
4. nurkowanie sprzęt dla początkujących
5. sklep nurkowy online
6. sprzęt nurkowy używany
7. serwis automatów nurkowych
8. kurs nurkowania OWD
9. nurkowanie techniczne sprzęt
10. nurkowanie w suchym skafandrze
11. freediving sprzęt
12. snorkeling zestaw
13. aparat oddechowy nurkowy
14. akwalung
15. sprzęt nurkowy Mares
16. sprzęt nurkowy Scubapro
17. sprzęt nurkowy Apeks
18. sprzęt nurkowy Aqualung
19. sprzęt nurkowy Cressi
20. zestaw do nurkowania kompletny

## Skrypt Python — specyfikacja

### Plik: scripts/dataforseo_keywords.py

### Krok 1: Setup
- Czyta .env (python-dotenv)
- Buduje Authorization header z base64
- Tworzy session z retry logic (max 3 retries, backoff)

### Krok 2: Keywords for Site
- POST /v3/keywords_data/google_ads/keywords_for_site/live
- Body: [{"location_code": 2616, "language_code": "pl", "target": "divezone.pl"}]
- Zapisz surowy JSON → data/dataforseo/raw/keywords_for_site_divezone.json
- Log: ile fraz, koszt

### Krok 3: Keywords for Keywords (5 batchów)
- Dla każdego batcha z listy seed keywords:
  - POST /v3/keywords_data/google_ads/keywords_for_keywords/live
  - Body: [{"location_code": 2616, "language_code": "pl", "keywords": [batch]}]
  - Zapisz surowy JSON → data/dataforseo/raw/keywords_for_keywords_batch_{N}.json
  - Sleep 1s między requestami
  - Log: ile fraz per batch, koszt kumulowany

### Krok 4: Konsolidacja
- Parsuj wszystkie surowe JSON-y
- Deduplikacja po keyword
- Zapisz do CSV: data/dataforseo/processed/all_keywords.csv
  Kolumny: keyword, search_volume, cpc, competition, competition_index,
  peak_month, peak_volume, source (site|batch_N)
- Zapisz do JSON: data/dataforseo/processed/all_keywords.json

### Krok 5: Raport
- Top 50 fraz wg wolumenu
- Grupowanie fraz per kategoria sprzętowa (heurystyka: szukaj nazw kategorii we frazie)
- Frazy bez dopasowania do kategorii (mogą wskazywać brakujące kategorie)
- Sezonowość: które kategorie mają wyraźny peak letni
- Zapisz do: data/dataforseo/processed/raport_keywords.md

### Wymagane pakiety
- requests
- python-dotenv
- csv (stdlib)
- json (stdlib)

### Obsługa błędów
- HTTP != 200: retry z backoff
- status_code != 20000: loguj i kontynuuj
- Puste result: loguj ostrzeżenie
- Na koniec: podsumowanie kosztów ($X z $50 zużyto)
