# Instrukcja ekstrakcji danych do LLM Product Enrichment
# Data: 2026-02-21
# Cel: zebranie realnych fraz klientów do wzbogacenia embeddingów

## 1. Google Search Console (divezone.pl)

### Co wyciągamy
Zapytania które prowadzą do stron produktowych i kategorii.
NIE potrzebujemy: zapytań brandowych ("divezone"), blogowych, informacyjnych.

### Krok po kroku

1. Wejdź na https://search.google.com/search-console
2. Wybierz właściwość divezone.pl
3. Przejdź do: Skuteczność > Wyniki wyszukiwania
4. Ustaw zakres dat: ostatnie 12 miesięcy (maks dane)
5. Kliknij "+ Nowy" filtr > Strona > Zawierające > wpisz kolejno:
   - `.html` (strony produktowe mają rozszerzenie .html w PrestaShop)
   LUB
   - Zrób eksport BEZ filtra strony (łatwiej filtrować potem)

6. Zakładka "ZAPYTANIA" > upewnij się że widoczne kolumny to:
   Zapytania, Kliknięcia, Wyświetlenia, CTR, Średnia pozycja

7. Kliknij "Eksportuj" (prawy górny róg) > Google Sheets lub CSV

### Co dostaniesz
Plik z ~tysiącami wierszy typu:
| Zapytanie | Kliknięcia | Wyświetlenia |
|---|---|---|
| pianka nurkowa 7mm | 45 | 890 |
| komputer nurkowy shearwater | 32 | 410 |
| skafander suchy | 28 | 650 |
| automat oddechowy din | 15 | 320 |

### Filtrowanie (zrobię to za Ciebie w Pythonie)
- Odrzucimy: zapytania z < 3 kliknięcia (szum)
- Odrzucimy: zapytania brandowe ("divezone", "dive zone")
- Odrzucimy: zapytania informacyjne bez intencji zakupowej
- Grupujemy per kategoria produktowa

### Nazwa pliku do zapisania
`gsc_queries_12m.csv` — wrzuć do katalogu projektu: 
`/Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026/data/search_phrases/`

---

## 2. Luigi's Box (wewnętrzna wyszukiwarka)

### Co wyciągamy
Frazy które klienci wpisują w search box na divezone.pl.
To jest NAJCENNIEJSZE źródło, bo to dokładnie "język klienta".

### Krok po kroku

1. Zaloguj się do panelu Luigi's Box
2. Szukaj sekcji typu: Analytics > Search queries / Search terms / Wyszukiwania
3. Ustaw zakres: ostatnie 12 miesięcy (lub max dostępny)
4. Sortuj po: liczba wyszukiwań (malejąco)
5. Eksportuj do CSV

### Jeśli Luigi's Box ma API
Sprawdź w dokumentacji czy jest endpoint typu:
`GET /analytics/searches` lub `/queries/top`
Jeśli tak, podaj mi link do docs, napiszę skrypt.

### Kluczowe dane do wyciągnięcia
- Fraza wyszukiwania
- Liczba wyszukiwań
- Liczba kliknięć w wyniki (jeśli dostępne)
- "Zero results" queries (zapytania bez wyników = najcenniejsze!)

### Nazwa pliku
`luigis_box_queries_12m.csv`

---

## 3. Google Analytics 4 (site search)

### Co wyciągamy
Frazy z wewnętrznej wyszukiwarki śledzone przez GA4.

### Krok po kroku

1. Wejdź na https://analytics.google.com
2. Wybierz właściwość divezone.pl
3. Przejdź do: Raporty > Zaangażowanie > Zdarzenia
4. Znajdź zdarzenie `view_search_results` lub `search`
   (nazwa zależy od konfiguracji, może być też `site_search`)
5. Kliknij to zdarzenie
6. Szukaj wymiaru "search_term" lub "wyszukiwane hasło"

### Alternatywna ścieżka (Eksploracje)
1. Eksploracje > Nowa eksploracja > Dowolna forma
2. Wymiary: dodaj "Wyszukiwane hasło" (Search term)
3. Metryki: dodaj "Liczba zdarzeń", "Użytkownicy"
4. Zakres dat: 12 miesięcy
5. Eksportuj do CSV

### Nazwa pliku
`ga4_site_search_12m.csv`

---

## 4. Google Keyword Planner (opcjonalne, darmowe)

### Co wyciągamy
Powiązane frazy z wolumenami dla kluczowych kategorii.

### Krok po kroku
1. https://ads.google.com/aw/keywordplanner
2. "Poznaj nowe słowa kluczowe"
3. Wpisz kolejno (osobne wyszukiwania):
   - pianka nurkowa
   - komputer nurkowy
   - automat oddechowy
   - skafander suchy
   - latarka nurkowa
   - płetwy nurkowe
   - maska nurkowa
   - kamizelka nurkowa
   - skrzydło nurkowe
   - butle nurkowe
4. Dla każdego: eksportuj "Propozycje słów kluczowych" do CSV
5. Język: polski, Lokalizacja: Polska

### Nazwa pliku
`gkp_keywords_{kategoria}.csv` (osobny per kategoria)

---

## 5. Google Autocomplete (opcjonalne)

### Ręczna metoda (szybka, 30 min)
1. Otwórz Google.pl w trybie incognito
2. Wpisuj kolejno bazowe frazy i zapisuj podpowiedzi:
   - "pianka nurkowa" → zanotuj 8-10 podpowiedzi
   - "komputer nurkowy" → zanotuj
   - "automat oddechowy" → zanotuj
   - itd. dla ~15 bazowych fraz
3. Zapisz w prostym pliku tekstowym

### Przez API (SerpAPI)
Jeśli chcesz zautomatyzować: SerpAPI ma endpoint Google Autocomplete.
$50/mies za 5000 zapytań, wystarczy na jednorazowe użycie.
Ale ręcznie dla 15 fraz = 30 minut, więc API pewnie nie warte.

### Nazwa pliku
`google_autocomplete.txt`

---

## Podsumowanie: co potrzebuję od Ciebie

| Priorytet | Źródło | Plik | Czas pracy |
|---|---|---|---|
| 🔴 KRYTYCZNE | Luigi's Box (search queries) | luigis_box_queries_12m.csv | 15 min |
| 🔴 KRYTYCZNE | Google Search Console | gsc_queries_12m.csv | 10 min |
| 🟡 WAŻNE | GA4 site search | ga4_site_search_12m.csv | 15 min |
| 🟢 OPCJONALNE | Google Keyword Planner | gkp_keywords_*.csv | 30 min |
| 🟢 OPCJONALNE | Google Autocomplete | google_autocomplete.txt | 30 min |

Gdy będę miał te pliki, napiszę skrypt który:
1. Czyści i deduplikuje frazy
2. Grupuje per kategoria produktowa (matching na podstawie landing page z GSC)
3. Buduje "phrase context" per kategoria do promptu LLM enrichment
4. Generuje raport: które kategorie mają dużo danych, które mało
