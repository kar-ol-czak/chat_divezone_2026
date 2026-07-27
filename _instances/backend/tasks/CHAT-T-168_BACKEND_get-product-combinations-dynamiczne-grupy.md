# CHAT-T-168 — BACKEND — GetProductCombinations: dynamiczne grupy atrybutów zamiast stałych 23/27

**Instancja:** backend
**Pliki:** `standalone/src/Tools/GetProductCombinations.php` + `standalone/src/Chat/SystemPrompt.php`
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl) — TYLKO te dwa pliki
**ADR:** ADR-135 (architekt zapisze przed deployem)
**Priorytet:** wysoki — dotyczy 180 aktywnych produktów, nie tylko szkieł

---

## 1. Objaw

Rozmowa produkcyjna `id=829` (2026-07-24, po deployu CHAT-T-167). Klient pytał
o szkła korekcyjne +3,5 do maski TUSA Intega. Bot wywołał
`get_product_combinations` trzy razy i za każdym razem dostał:

```json
{"product_id":6994,"liczba_wariantow":0,"warianty":[]}
{"product_id":6577,"liczba_wariantow":0,"warianty":[]}
{"product_id":6573,"liczba_wariantow":0,"warianty":[]}
```

W MySQL te produkty mają odpowiednio 8, 23 i 22 kombinacje. Strona sklepu
renderuje pełny selektor mocy. **Narzędzie zwraca pustkę dla produktów,
które warianty mają.**

Bot zachował się poprawnie — zapytał, dostał zero, nie potwierdził dostępności.
Dane były fałszywe, nie rozumowanie.

## 2. Przyczyna — potwierdzona pomiarem

`GetProductCombinations.php`, INNER JOIN w zapytaniu:

```sql
JOIN pr_attribute a
    ON a.id_attribute = pac.id_attribute
   AND a.id_attribute_group IN (%1$d, %2$d)   -- 23 (KOLOR), 27 (ROZMIAR)
```

Klasa ma zaszyte dwie stałe: `GROUP_COLOR = 23`, `GROUP_SIZE = 27`.
Kombinacja, której atrybuty leżą poza tymi grupami, zostaje odrzucona przez
INNER JOIN — produkt wygląda na bezwariantowy.

Pomiar dla produktów z rozmowy 829:

| id_product | kombinacji w bazie | widocznych dla narzędzia | grupa |
|---|---|---|---|
| 6573 | 22 | 0 | 34 (SZKŁO PRAWE) |
| 6577 | 23 | 0 | 35 (SZKŁO LEWE) |
| 6993 | 8 | 0 | 35 |
| 6994 | 8 | 0 | 34 |

**md5 repo == md5 prod** (`161112790b71b23495a3a5dc4ce5fb8e`) — to NIE jest
rozjazd wdrożenia. Kod jest błędny w obu miejscach.

Komentarz w nagłówku klasy ("każda kombinacja ma DWA atrybuty — kolor grupa 23
+ rozmiar grupa 27") opisuje założenie, które było fałszywe już w momencie
pisania: grupy 34/35 istniały wcześniej.

## 3. Skala — dlaczego to nie jest sprawa szkieł

Sklep używa co najmniej **23 grup atrybutów** na aktywnych produktach.
Narzędzie obsługuje **2**.

Grupy z największą liczbą produktów (pomiar 2026-07-24, `p.active=1`):

```
27 ROZMIAR              582 prod. / 5025 komb.   ← widoczne
23 KOLOR                377 prod. / 4432 komb.   ← widoczne
57 KOLOR WORKA           35 prod. /  682 komb.
26 PŁYTA                 34 prod. /  352 komb.
29 ROZMIAR MĘSKI         26 prod. /  311 komb.
30 ROZMIAR DAMSKI        25 prod. /  803 komb.
25 WYPORNOŚĆ             21 prod. /  392 komb.
35 SZKŁO LEWE            13 prod. / 1685 komb.
34 SZKŁO PRAWE           13 prod. / 1688 komb.
42 ROZMIAR BUTÓW         13 prod. /  222 komb.
64 KIESZENIE BALASTOWE   10 prod. /  594 komb.
41 KOLOR SZWÓW            6 prod. / 1325 komb.
```

**180 aktywnych produktów** ma warianty CAŁKOWICIE niewidoczne dla narzędzia
(zero atrybutów w grupach 23/27). Dla każdego z nich bot dostanie
`liczba_wariantow: 0`.

Zwróć uwagę na `ROZMIAR MĘSKI` (29) i `ROZMIAR DAMSKI` (30) — bot nie widzi
rozmiarów w 51 produktach odzieżowych, mimo że reguła "DOSTĘPNOŚĆ PER WARIANT"
w SystemPrompt każe mu je pokazywać.

## 4. Ograniczenie do uwzględnienia

**5976 kombinacji ma więcej niż jedną grupę atrybutów.** Nowe pole `atrybuty`
MUSI być tablicą par (grupa, wartość), nie pojedynczą wartością.

---

## KROK 0 — pull i lektura

```
git pull --rebase
```

Przeczytaj:
- `standalone/src/Tools/GetProductCombinations.php` — całość (150 linii)
- `standalone/src/Chat/SystemPrompt.php` — sekcja "WARIANTY (KOLOR/ROZMIAR)"
  (~502-520) i "DOSTĘPNOŚĆ PER WARIANT" (~524)
- `_docs/10_decyzje_projektowe.md` — ADR-025, ADR-131, ADR-135

**NIE RUSZAJ:** `config/tools.php`, `config/routes.php`, ADR-ów,
`_ops/newtmp2_root/purge_litespeed.php`, plików poza dwoma wymienionymi wyżej.

## KROK 1 — SQL: zdejmij filtr grup

W `execute()`:

1. **Usuń** z INNER JOIN warunek `AND a.id_attribute_group IN (%1$d, %2$d)`.
   Wszystkie atrybuty kombinacji mają wracać.
2. Zachowaj `MAX(CASE WHEN ... = 23 ...)` dla `kolor` / `kod_koloru`
   i `MAX(CASE WHEN ... = 27 ...)` dla `rozmiar` — kontrakt zostaje.
3. Dołóż nazwę grupy z `pr_attribute_group_lang` (`id_lang=1`), potrzebną
   do generycznego pola `atrybuty`.

Stałe `GROUP_COLOR` / `GROUP_SIZE` **zostają** — służą teraz wyłącznie
do mapowania na pola kontraktowe, nie do filtrowania.

## KROK 2 — kontrakt wyjściowy: wstecznie zgodny

Pola `kolor`, `kod_koloru`, `nieznany_kolor`, `rozmiar`, `dostepnosc`,
`domyslny_wariant`, `reference`, `id_product_attribute` — **bez zmian**.
SystemPrompt na nich polega, ADR-025 je definiuje.

**Dodaj** do każdego wariantu:

```php
'atrybuty' => [
    ['grupa' => 'SZKŁO PRAWE', 'wartosc' => '+3,5'],
    // ... wszystkie atrybuty kombinacji, także te z grup 23/27
],
```

Tablica, nie skalar (patrz sekcja 4: 5976 kombinacji wielogrupowych).
Kolejność: wg `id_attribute_group` rosnąco, deterministycznie.

## KROK 3 — SystemPrompt: naucz bota czytać `atrybuty`

W sekcji "WARIANTY (KOLOR/ROZMIAR)" dopisz (sformułowanie dostosuj do stylu
sąsiednich reguł):

> Pole `atrybuty` zawiera WSZYSTKIE cechy wariantu, także te spoza koloru
> i rozmiaru (moc szkła, wyporność, płyta, rozmiar męski/damski, kieszenie).
> Gdy `kolor` i `rozmiar` są puste, a `atrybuty` nie — opisz wariant przez
> `atrybuty`, NIE mów "produkt nie ma wariantów".
>
> Bug do uniknięcia (czat 829): bot dostał `liczba_wariantow: 0` dla szkieł
> BF211 i odesłał klienta na infolinię, choć produkt ma 8 wariantów mocy.

Zmień też nagłówek sekcji z "WARIANTY (KOLOR/ROZMIAR)" na "WARIANTY" —
nazwa utrwala błędne założenie.

## KROK 4 — walidacja lokalna

```
ea-php84 -l standalone/src/Tools/GetProductCombinations.php
ea-php84 -l standalone/src/Chat/SystemPrompt.php
```

Sprawdź zapytanie na produkcyjnym MySQL (read-only) dla:
- **6994** — oczekiwane 8 wariantów, grupa SZKŁO PRAWE, wartości +1…+4,5
- **6993** — oczekiwane 8 wariantów, grupa SZKŁO LEWE
- **3322** (Mares Avanti Superchannel, regresja CHAT-T-129) — kolory PL,
  `domyslny_wariant` na RBL, `nieznany_kolor` działa jak wcześniej
- dowolny produkt z grupy 29/30 — rozmiary męskie/damskie mają być widoczne

**Regresja jest krytyczna:** jeśli 3322 przestanie zwracać nazwy kolorów
po polsku, zmiana jest do odrzucenia.

## KROK 5 — STOP

**STOP przed rsync (ADR-089).** Czekaj na "deployuj".

## KROK 6 — deploy (po autoryzacji)

Świat 1, DWA pliki, osobno:

```
backup → _deploy_bak/CHAT-T-168/
rsync -e 'ssh -p 5739' standalone/src/Tools/GetProductCombinations.php \
  divezone@divezonededyk.smarthost.pl:~/public_html/chat.divezone.pl/src/Tools/
rsync -e 'ssh -p 5739' standalone/src/Chat/SystemPrompt.php \
  divezone@divezonededyk.smarthost.pl:~/public_html/chat.divezone.pl/src/Chat/
md5 local ↔ prod (oba pliki)
ea-php84 -l na produkcji (oba pliki)
smoke: /api/health
```

**Bez `--delete`. Bez blanket-rsync katalogu `standalone/`.**

## KROK 7 — status i raport

Dopisz **NA GÓRZE** `_docs/21_STATUS_PROJEKTU.md`.

```
git status
git add standalone/src/Tools/GetProductCombinations.php
git add standalone/src/Chat/SystemPrompt.php
git commit -m "CHAT-T-168 backend: dynamiczne grupy atrybutow w get_product_combinations (ADR-135)"
git push origin main
```
Po deployu osobny commit `docs(...)`.

W raporcie podaj: md5 obu plików, wynik lintu, wynik smoke, oraz **wyniki
testów z KROKU 4 dla wszystkich czterech produktów** (liczba wariantów przed
i po zmianie).

---

## Kryterium akceptacji (weryfikuje architekt)

1. `get_product_combinations(6994)` → 8 wariantów z `atrybuty[].grupa='SZKŁO PRAWE'`
2. `get_product_combinations(3322)` → kolory po polsku, bez regresji wobec CHAT-T-129
3. Replay pytania z rozmowy 829 → bot potwierdza +3,5 w BF211, nie odsyła na infolinię
4. Zliczenie produktów zwracających `liczba_wariantow: 0` mimo kombinacji w MySQL → **0**
