# CHAT-T-165 — BACKEND — dobór rozmiaru poza skafandry: rękawice, kaptury, buty, pierścienie

**Data:** 2026-07-24 | **Instancja:** backend | **Karta:** Chat - 54
**Nawiązuje do:** CHAT-T-161, CHAT-T-163, ADR-032 aneks 1, ADR-099, ADR-132
**Świat wdrożeniowy:** WYŁĄCZNIE backend standalone `chat.divezone.pl`.

---

## 1. PROBLEM

Dane rozmiarówek dla rękawic, kapturów, butów i pierścieni są **gotowe w bazie**
(projekt Atrybutów), a bot z nich **nie korzysta**.

### 1.1 Pomiar PROD 2026-07-24 (produkty AKTYWNE)

| chart_type | category_hint | produktów |
|---|---|---:|
| progowy | skafander | 79 |
| progowy | **rekawica** | **28** |
| progowy | **kaptur** | **23** |
| progowy | **but** | **6** |
| tresciowy | **but_suchy** | **6** |
| tresciowy | **but** | **6** |
| tresciowy | **pierscienie** | **2** |

**65 produktów poza zasięgiem bota**, z czego 57 to `progowy` (wymiary mierzalne),
a 14 to `tresciowy` (tabele przeliczeń).

KOREKTA: karta Chat - 54 mówiła o 71 produktach — to była liczba bez filtra
`p.active=1`. Poprawna liczba dla aktywnych to 65.

### 1.2 Wymiary per kategoria (pomiar, `divezone_attr_size_chart_rows`)

| category_hint | wymiary | wierszy |
|---|---|---:|
| skafander | `chest,height,hip,leg,waist,weight` | 516 |
| rekawica | `hand_circ`, `palm_length` | 59 |
| kaptur | `forehead`, `head_circ`, `neck` | 49 |
| but | `foot_length` | 16 |

### 1.3 Trzy przyczyny, nie jedna (zweryfikowane wywołaniem na PROD)

Test: `execute(['product_id'=>5368, 'gender'=>'M', 'foot_length'=>26])` na produkcji.

1. **`MATCH_DIMS` zna tylko 5 wymiarów skafandrowych** (linia 25). `foot_length`,
   `hand_circ`, `palm_length`, `head_circ`, `neck`, `forehead` są odrzucane →
   guard uznaje, że nie podano żadnego wymiaru. Wynik testu:
   `{"error":"Podaj co najmniej jeden wymiar ciała..."}`
2. **`chart_type='tresciowy'` nie ma wierszy w `divezone_attr_size_chart_rows`** —
   treść leży w OSOBNEJ tabeli `divezone_attr_size_chart_content`. Wynik testu dla
   pid 2290: `{"error":"Tabela rozmiarów jest pusta dla wybranego charta."}`
3. **Filtr `category_hint='skafander'`** jest w fallbacku po marce (linie 202-210
   `SizeRecommender`) oraz w OBU zapytaniach `FindWetsuitsByMeasurements` (133, 144).
   Ścieżka `product_id` w `SizeRecommender` filtru NIE ma — dlatego znajduje chart
   butowy i dopiero potem wykłada się na punktach 1-2.

---

## 2. STRUKTURA `tresciowy` — dlaczego NIE parsujemy (decyzja Karola 26a)

Tabela `divezone_attr_size_chart_content`: `id_content, id_chart, content_html, note`.

Zmierzone układy kolumn (8 chartów):

| chart | marka | kategoria | kolumny w `<thead>` | długość HTML |
|---:|---|---|---|---:|
| 7 | Scubapro | but | Rozmiar, USA, UK, EU, cm | 788 |
| 9 | Scubapro | but_suchy | Rozmiar, EU | 315 |
| 10 | Santi Water Trekker | but_suchy | Rozmiar, EU | 297 |
| 11 | Santi Rockboots | but_suchy | Rozmiar (EU), UK, US | 573 |
| 12 | Bare | but_suchy | Rozmiar (EU) | 292 |
| 13 | Tecline | but_suchy | Rozmiar (EU) | 214 |
| 14 | XR | but_suchy | Rozmiar, EU, wkladka_cm(info) | 406 |
| 28 | VDS System | pierscienie | rozmiar, średnica wewnętrzna | 266 |

**Pięć różnych układów, od 1 do 5 kolumn.** Parser wymagałby reguł per marka,
czyli stałej listy rozjeżdżającej się cicho przy każdym nowym charcie.

**Decyzja (Karol, 26a): narzędzie zwraca surowy `content_html`.** Tabele są krótkie
(214-788 znaków), model czyta je bez trudu, dane idą prosto ze źródła.

---

## 3. ZAKRES ZMIAN

### 3.1 `SizeRecommender.php` — słownik wymiarów per kategoria

Zamiast jednej stałej `MATCH_DIMS` (5 wymiarów skafandrowych) — mapa kategoria→wymiary.
**Wymiary bierz DYNAMICZNIE z bazy**, nie z listy w kodzie:

```sql
SELECT DISTINCT dimension FROM divezone_attr_size_chart_rows WHERE id_chart = ?
```

To dynamiczne źródło prawdy (konwencja projektu): nowy wymiar w danych działa
bez zmiany kodu. Stała lista rozjedzie się cicho.

`leg` pozostaje wyłączony dla skafandrów (ADR-032 aneks 1) — filtruj po odczycie.

### 3.2 `SizeRecommender.php` — obsługa `chart_type='tresciowy'`

Gdy chart ma `chart_type='tresciowy'`:
- NIE szukaj wierszy w `divezone_attr_size_chart_rows` (są puste → dzisiejszy błąd)
- pobierz `content_html` i `note` z `divezone_attr_size_chart_content`
- zwróć `['decision' => 'content_table', 'content_html' => ..., 'note' => ..., 'brand' => ..., 'category' => ...]`
- **NIE wymagaj wymiarów** — klient nie musi nic podawać, dostaje tabelę przeliczeń
- gdy `content_html` pusty → dotychczasowy błąd braku tabeli

### 3.3 `SizeRecommender.php` — fallback po marce

Linie 202-210: `category_hint = 'skafander'` → parametr, nie stała.
Zasada z CHAT-T-161 sekcja 2.4 BEZ ZMIAN: filtry `chart_type` + `category_hint` + `gender`
OBOWIĄZKOWE, przy >1 wierszu BŁĄD, nigdy pierwszy z brzegu.

**Kategorię wyprowadź z produktu**, gdy podano `product_id`. Gdy klient poda samą
markę bez produktu — zapytaj o kategorię, NIE zgaduj (marka Scubapro ma charty
skafandra, buta, buta suchego, kaptura i rękawicy jednocześnie).

### 3.4 Nazwa i opis narzędzia

`recommend_wetsuit_size` przestaje pasować. **Zachowaj starą nazwę jako działającą**
(jest w `config/tools.php` na PROD, zmiana nazwy to zmiana kontraktu z modelem),
ale zaktualizuj `getDescription()` i opisy parametrów: narzędzie obsługuje
skafandry, rękawice, kaptury, buty i pierścienie.

Dodaj parametry wymiarów: `foot_length`, `hand_circ`, `palm_length`, `head_circ`,
`neck`, `forehead` (typ number, opcjonalne).

### 3.5 `FindWetsuitsByMeasurements.php` — POZA ZAKRESEM

Zostaje przy skafandrach. Rozszerzenie wyszukiwania alternatyw na inne kategorie
to osobna decyzja (co to „pasująca alternatywa" dla rękawicy?). NIE ruszaj.

### 3.6 SystemPrompt — reguła dla `content_table`

> TABELA PRZELICZEŃ ROZMIARÓW (`decision: content_table`): dla butów suchych,
> butów Scubapro i pierścieni narzędzie zwraca gotową tabelę HTML zamiast wyliczenia.
> Przedstaw ją klientowi czytelnie (lista lub tabela markdown), CYTUJĄC WYŁĄCZNIE
> wiersze, które są w tabeli. NIE interpoluj między wierszami, NIE przeliczaj
> rozmiarów spoza tabeli, NIE dodawaj rozmiarów, których w niej nie ma (ADR-099).
> Gdy klient pyta o rozmiar spoza zakresu tabeli — powiedz, że tego rozmiaru nie ma
> w tabeli producenta, i skieruj na kontakt.

---

## 4. KRYTERIA AKCEPTACJI

| # | wejście | oczekiwane |
|---:|---|---|
| 1 | `product_id` rękawicy + `hand_circ` | rozmiar, dziś: błąd „podaj wymiar" |
| 2 | `product_id` kaptura + `head_circ` | rozmiar |
| 3 | `product_id` buta progowego (5368) + `foot_length=26` | rozmiar, dziś: błąd |
| 4 | `product_id` buta suchego (`tresciowy`, np. 2290) | `decision: content_table` + `content_html`, dziś: „tabela pusta" |
| 5 | to samo BEZ żadnego wymiaru | działa (tresciowy nie wymaga wymiarów) |
| 6 | pierścienie VDS (chart 28) | `content_html` ze średnicami |
| 7 | **regresja skafandra**: `gender=M, height=172` (chart 1) | `multiple [XS, MS]` bez zmian |
| 8 | **regresja**: `chest=93, height=172` | `match [XS]` bez zmian |
| 9 | **regresja**: `gender=M` bez wymiarów, chart progowy | błąd (guard działa) |
| 10 | skafander z `leg` | `leg` nadal wyłączony |
| 11 | fallback `brand='Scubapro'` bez `product_id` i bez kategorii | pytanie o kategorię, NIE zgadywanie |
| 12 | `find_wetsuits_by_measurements` | działa bez zmian (nie ruszany) |

Kryteria 7-10 i 12 to REGRESJA — muszą przejść, inaczej zmiana psuje T-161/T-163.

---

## 5. CZEGO NIE RUSZAĆ

- **`FindWetsuitsByMeasurements.php`** — poza zakresem (§3.5)
- **Nazwa narzędzia `recommend_wetsuit_size`** — kontrakt z modelem, tylko opis
- Wymóg płci, guard pustych wymiarów, logika przecięcia z T-161
- Reguły SystemPrompt z T-161/T-162/T-163/T-164 — tylko dopisek z §3.6
- `config/tools.php` (bez zmian: brak nowych klas), `config/routes.php`
- Moduł `newtmp2`, `_ops/newtmp2_root/purge_litespeed.php` (SEKRET)
- **Uzupełnianie brakujących mapowań** (but TUSA 7484 z recenzji 335 nadal bez
  chartu) — projekt Atrybutów, cudza karta

---

## 6. DEPLOY

```
standalone/src/Tools/SizeRecommender.php  →  ~/public_html/chat.divezone.pl/src/Tools/
standalone/src/Chat/SystemPrompt.php      →  ~/public_html/chat.divezone.pl/src/Chat/
```

**ZAKAZ blanket-rsync `standalone/`.** Backup `_deploy_bak/CHAT-T-165/`, md5 ×2,
`ea-php84 -l` ×2, smoke `/api/health`. Bez `composer dump-autoload` (brak nowych klas).
**STOP przed rsync — czekaj na „deployuj" (ADR-089).** Zero migracji PG, zero cache PS.

---

## 7. RAPORT

`_docs/21_STATUS_PROJEKTU.md` NA GÓRZE. Raport z 12 kryteriami, surowy `tool_result`
dla 1, 3, 4 i 7.
