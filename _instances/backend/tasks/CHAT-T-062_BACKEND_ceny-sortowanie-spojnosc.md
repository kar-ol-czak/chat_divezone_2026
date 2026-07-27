# CHAT-T-062 — BACKEND: ceny w czacie — sortowanie po cenie (E4) + spójność ceny między narzędziami (E5)

**Instancja:** backend (standalone, PHP 8.4). CC WDRAŻA SAM.
**Powiązane:** ADR-048 (live MySQL enrichment), ProductSearch, ProductDetails, MysqlProductEnrichmentService. Źródło: ewaluacja czatu 03.06.2026 (E4 ocena 1, E5 ocena 1 — krytyczne).
**Decyzje:** 150a (sortowanie po cenie przez parametr ProductSearch + nauka modelu w prompcie), 151b (diagnoza zmiany ceny), 154a (E4 zaczyna od diagnozy przepływu enrichment↔limit).

## Problem (z ewaluacji)
- **E4 (krytyczny):** „najtańszy komputer nurkowy" → chat wskazał 2940,70 zł, choć były tańsze (2276 zł, 2674 zł). Przyczyna wstępna: ProductSearch zwraca po podobieństwie wektorowym (RRF, ORDER BY vector), NIE po cenie. Model dostaje top-N semantycznych i zgaduje „najtańszy" z tej puli, nie z całego zbioru.
- **E5 (krytyczny):** cena „tego samego" produktu zmieniła się między turami (2940,70 → 2600,81), a model twierdził „cena jest aktualna". Ustalenie z kodu: są DWIE ścieżki liczenia ceny pojedynczego produktu — ProductSearch+MysqlProductEnrichmentService vs ProductDetails (osobne zapytanie specific_price). Mogą dać różne ceny dla tego samego product_id → niespójność w obrębie rozmowy.

## CZĘŚĆ 1 — E4: sortowanie po cenie

### KROK DIAGNOSTYCZNY (154a — NAJPIERW, przed implementacją)
Zdiagnozuj przepływ w ProductSearch: gdzie MysqlProductEnrichmentService dokłada cenę WZGLĘDEM ograniczenia listy (TRACK_LIMIT=30, RRF merge, final limit)?
- Czy cena jest znana PRZED obcięciem do top-N, czy enrichment wchodzi PO obcięciu?
- Jeśli cena dokładana PO obcięciu: sortowanie po cenie obejmie tylko top-N kandydatów (np. 30), NIE cały pasujący zbiór → „najtańszy" nadal może być błędny dla całej kategorii.
Wynik diagnozy zapisz w raporcie i DOPIERO na jego podstawie wybierz wariant implementacji poniżej.

### Implementacja (wybór zależny od diagnozy)
Cel: gdy user pyta „najtańszy/najdroższy [kategoria]", model ma dostać wyniki FAKTYCZNIE posortowane po cenie z właściwego zbioru.
- Dodać do ProductSearch parametr `sort` (np. 'price_asc'/'price_desc'/null=domyślne RRF) w schemacie narzędzia (function calling) + opis dla modelu.
- WARIANT A (jeśli enrichment obejmuje wystarczający zbiór przed limitem): po enrichment posortować kandydatów po cenie i zwrócić wg sort.
- WARIANT B (jeśli enrichment tylko top-N — sortowanie kandydatów NIE wystarczy dla „najtańszy w kategorii"): dla zapytań z sort=price_asc/desc rozszerzyć pobranie cen na szerszy zbiór kandydatów (np. zwiększyć pulę przed sortowaniem cenowym) LUB dodać ścieżkę: gdy jest sort cenowy + kategoria, pobrać kandydatów z enrichment dla większej puli i posortować. NIE budować całkiem osobnego silnika — bazować na istniejącym enrichment.
- Wybór A vs B UZASADNIĆ w raporcie na podstawie diagnozy. Jeśli B okaże się dużą zmianą — ZGŁOSIĆ przed implementacją (możliwy osobny task), nie rozbudowywać silnika wyszukiwania na siłę.

## CZĘŚĆ 2 — E5: jedno źródło ceny dla pojedynczego produktu

### Diagnoza (151b)
Potwierdź: ProductSearch (przez MysqlProductEnrichmentService) i ProductDetails liczą cenę pojedynczego produktu DWOMA różnymi ścieżkami (osobne SQL, osobna obsługa specific_price/promocji). To prawdopodobna przyczyna 2940→2600 (raz cena bazowa, raz promocyjna, dla tego samego product_id).

### Fix
- Ujednolicić źródło ceny: ProductDetails powinien używać TEJ SAMEJ logiki ceny co MysqlProductEnrichmentService (cena brutto z VAT, specific_price percentage/amount, promocja) — najlepiej wołać enrichment dla pojedynczego product_id zamiast duplikować logikę. Cel: ten sam product_id → ta sama cena niezależnie od narzędzia.
- Jeśli pełne ujednolicenie to duża zmiana: minimum = ProductDetails liczy cenę identycznym algorytmem co enrichment (ta sama obsługa specific_price, VAT, reduction_type). Zero rozbieżności dla tego samego produktu.
- (Disclaimer „cenę potwierdź na karcie" i zakaz „cena na pewno aktualna" = CHAT-T-063, prompt. Tu tylko spójność danych.)

## Granice
- Tylko warstwa danych (ProductSearch, ProductDetails, enrichment). Bez zmian w prompcie (osobny task T-063).
- Nie rozbudowywać silnika wektorowego ponad potrzebę; jeśli E4 wariant B duży — zgłosić.
- Diagnoza E4 PRZED implementacją (154a).

## Kryteria akceptacji
1. Diagnoza E4 (gdzie enrichment vs limit) opisana w raporcie; wybór wariantu uzasadniony.
2. „Najtańszy [kategoria]" zwraca faktycznie najtańszy z właściwego zbioru (test: komputer nurkowy → najniższa realna cena, nie semantycznie pierwszy).
3. Parametr sort w ProductSearch działa (price_asc/desc), domyślnie RRF bez zmian.
4. ProductDetails i ProductSearch zwracają TĘ SAMĄ cenę dla tego samego product_id (test: produkt z promocją + bez).
5. php -l clean; testy na PROD opisane w raporcie.
6. Jeśli E4 wariant B okazał się dużą zmianą — zgłoszone, nie wdrożone na siłę.
