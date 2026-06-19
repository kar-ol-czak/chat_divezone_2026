# 40. Diagnoza schematu atrybutów rozmiarów w PrestaShop (CHAT-T-101)

> **Charakter:** diagnoza READ-ONLY z REALNEJ bazy sklepu, pod projekt mini-modułu rozmiarów (ADR-100 krok 2). Cel: NIE stworzyć trzeciego bytu obok natywnych atrybutów Presty. Każdy wniosek poparty zapytaniem + przykładem z danych.
> **Powiązane:** ADR-100 (źródło prawdy → PrestaShop), ADR-099 (long format, algorytm), ADR-098.
> **Data:** 2026-06-19 | **Instancja:** embeddings | **Status:** ZAKOŃCZONA

## Metoda i środowisko (dowód read-only)

- **Baza:** `divezone_2025`, **MariaDB 10.11.18**, prefix `pr_`, `id_lang=1` = Polski (`id_lang=2` = Deutsch **nieaktywny**, `id_lang=3` = English).
- **Konto:** `divezone_chat_reader@localhost` — uprawnienia:
  ```
  GRANT USAGE ON *.* TO `divezone_chat_reader`@`localhost`
  GRANT SELECT, SHOW VIEW ON `divezone_2025`.* TO `divezone_chat_reader`@`localhost`
  ```
  Tylko `SELECT, SHOW VIEW` — **zero write potwierdzone strukturalnie** (nie tylko deklaratywnie).
- **Dostęp:** tunel SSH `localhost:33060` → MySQL sklepu. Połączenie przez `embeddings/diagnose_size_attributes.py` (pymysql).
- **Artefakty odtwarzalne:** skrypt `embeddings/diagnose_size_attributes.py` + surowe zapytania `sql/diag_101_atrybuty_rozmiary.sql`.

---

## Q1. Jak Presta trzyma rozmiar jako atrybut/wariant?

**Mechanizm natywny:** rozmiar = **grupa atrybutów** (`pr_attribute_group` + `_lang`) → **wartości** (`pr_attribute` + `_lang`, czyste etykiety) → **kombinacje produktu** (`pr_product_attribute` = wariant z własnym SKU/ceną, powiązany z etykietą przez `pr_product_attribute_combination`).

### Q1.a — Grupy atrybutów związane z rozmiarem (z realnej bazy)

| gid | Nazwa (PL) | #wartości | #kombinacji (użycie) | Rola |
|----:|------------|----------:|---------------------:|------|
| **27** | ROZMIAR | 243 | **5284** | główna grupa rozmiarów (uniwersalna litera + liczby) |
| **29** | ROZMIAR MĘSKI | 48 | 313 | rozmiary skafandrów męskie (litery + EU 48–60) |
| **30** | ROZMIAR DAMSKI | 43 | 804 | rozmiary skafandrów damskie (litery + EU 36–46 + Bare 4–14) |
| **42** | ROZMIAR BUTÓW | 21 | 222 | rozmiary EU butów (36, 37/38, 39…) |
| **69** | Rozmiar rękawic | 6 | 167 | XS–XXL |
| **46** | ROZMIAR UPRZĘŻY | 6 | 72 | XS–XL, M/L |
| **37** | BUTY | 7 | 1 | generyczne XS–XXL (prawie nieużywane) |
| 65 | Grubość neoprenu | 6 | 0 | nie-rozmiarowa (lycra/2–7 mm), nieużywana jako kombinacja |
| 66 | Długość pianki | 2 | 0 | „Długa/shorty", nieużywana jako kombinacja |
| 74 | GRUBOŚĆ | 5 | 75 | 100–500 (nie body-size; np. denier/grubość) |

> Pozostałe grupy (KOLOR, WERSJA, WYPORNOŚĆ, SZKŁO PRAWE/LEWE itd.) nie dotyczą rozmiaru. Pełna lista 45 grup w wyniku Q1.a skryptu.

### Q1.b — Realne wartości etykiet (próbki dystynktne)

- **gid 27 ROZMIAR:** `T | 3XS | 2XS | XXS | XS | XS-S | S Short | S | SL | ST | SM | MS | M | M Long | ML | MLS | MLT | MT …` — mieszanka liter, „Short/Long/Tall", łączonych.
- **gid 29 ROZMIAR MĘSKI:** `48 | 48/50 | 50 | 52 | 54 | 56 | 58 | 60 | XS | S | M | ML | L | XL | 2XL | MB | MR | MT | LB | LR | LT …` — EU + litery + sufiksy B/R/T (Body/Regular/Tall).
- **gid 30 ROZMIAR DAMSKI:** `36 | 38 | 40 | 42/44 | 46 | XS | S | M | ML | 4 | 6 | 8 | 8T | 10 Plus | 12 | 14 Plus | 16 | Szycie na miarę …` — w tym **liczby Bare** (4–14) i „**10 Plus**", „**Szycie na miarę**".
- **gid 42 ROZMIAR BUTÓW:** `36 | 37/38 | 39 | 40/41 | 42 | 43/44 | 45 | 46/47 | 28 | 30 | 32 …` — EU, w tym pary.

**Wniosek Q1.b:** etykiety są **czysto handlowe (string)**. Występują dokładnie te formy, które ADR-099 przewidział: litery S/M/L, liczby Bare, „MT", „X Plus". **Brak jakiejkolwiek wartości liczbowej wymiaru ciała** (cm klatki/talii) — etykieta mówi „dostępny w MT", nie „MT = klatka 98".

### Q1.c — Reprezentacja rozmiaru konkretnego produktu (przykład realny)

`BARE CD4 Pro Dry` (id_product **4054**), grupa ROZMIAR MĘSKI (29) — każdy rozmiar to osobny wariant z własnym SKU:

```
pa=1749 ref=11133-S      ROZMIAR MĘSKI = S
pa=1751 ref=11133-10002  ROZMIAR MĘSKI = M
pa=1752 ref=011104-MT    ROZMIAR MĘSKI = MT
pa=1757 ref=011104-L     ROZMIAR MĘSKI = L
pa=1760 ref=011133BLU-XL ROZMIAR MĘSKI = XL
pa=1763 ref=011133-2XL   ROZMIAR MĘSKI = 2XL
pa=1765 ref=11133-10015  ROZMIAR MĘSKI = 3XL
```

Tabela `pr_product_attribute` trzyma per wariant: `reference` (SKU), `price` (dopłata), stan magazynowy. Powiązanie z etykietą rozmiaru przez `pr_product_attribute_combination(id_product_attribute, id_attribute)`. **To jedyna natywna reprezentacja rozmiaru: etykieta + SKU + cena/stan. Zero pola na wymiar.**

---

## Q2. Czy jest gdziekolwiek natywne miejsce na PROGI (liczby)? — **NIE**

To kluczowy punkt diagnozy (przesądza o potrzebie modułu).

### Q2.a/b — `pr_feature` / `pr_feature_value`: cechy NIE przechowują progów rozmiar→wymiar

Sklep używa 65 cech (features). Najczęstsze to skalary **na poziomie produktu**, nie tabele per-rozmiar:
`Długość`, `Waga`, `Wymiary`, `Max. głębokość`, `Grubość neoprenu`, `Materiał`, `Damska/Męska` itd.

Wartości „wymiarowe" znalezione w `pr_feature_value` to **pojedyncze wymiary akcesoriów**, nie zakresy ciała per rozmiar:
```
[Długość] 75cm | 90cm | 120cm | 150cm | 210cm   (np. węże, bojki, noże)
[Głębokość] 30 cm | 23cm | 28cm
[Szerokość] 5,23 cm | 4,11 cm
```
**Nie istnieje cecha** typu „rozmiar L = klatka 101–107, talia 86–92". Feature trzyma jedną wartość na produkt, a nie macierz rozmiar × wymiar. Model cech Presty (1 wartość/produkt) **strukturalnie nie mieści** tabeli progów.

### Q2.c — Opisy produktów: brak interaktywnego kalkulatora, są tylko statyczne tabele/grafiki

Markery przeszukane w opisach **aktywnych** produktów (`pr_product_lang.description`, id_lang=1):

| Marker | Liczba produktów | Interpretacja |
|--------|-----------------:|---------------|
| `<input>` / `<select>` / `<form>` | **0 / 0 / 0** | brak formularza kalkulatora |
| `getElement` / JS `function(` / `<script>` / `onclick` | **0 / 0 / 0 / 0** | brak logiki JS w opisach |
| słowo „kalkulator" | **0** | — |
| `<table>` (statyczna tabela) | 1203 | tabele specyfikacji/rozmiarów jako HTML |
| tekst „tabela rozmiar…" | 97 | odwołania do tabeli rozmiarów |
| `<img>` (grafika) | 547 | **size charty jako obrazki producenta** |
| słowo „klatk" (klatka piersiowa) | 102 | opis prozą, jak mierzyć |

> **Korekta założenia z ADR-099** („na stronie działa kalkulator — PHP inline w opisie"): w **aktywnych** produktach **nie ma** interaktywnego kalkulatora w polu opisu (0 `<input>/<select>/<form>/<script>/getElement`). Progi rozmiarowe na stronie żyją jako: (a) **grafiki producenta** (`<img>`, niemaszynowalne), (b) **statyczne tabele HTML**, (c) **proza** („porównać wymiary klatki piersiowej, talii, bioder, wzrostu i wagi z tabelą producenta" — realny opis `SCUBAPRO Definition Shorty 2,5mm Męski`, id 3193). Żadna z tych form nie jest strukturalnym, odpytywalnym źródłem progów. *(Ewentualny kalkulator widziany wcześniej mógł być na produkcie nieaktywnym lub w szablonie motywu — poza DB; nie zmienia to wniosku.)*

### Wniosek Q2 (przesądzający)

**Progi liczbowe (rozmiar → zakres wymiaru ciała) NIE mają żadnego natywnego miejsca w PrestaShop.** Atrybuty trzymają etykietę („L"), cechy trzymają skalar na produkt, opisy trzymają grafikę/prozę. Informacja „L = klatka 101–107" to **inny typ danych** niż cokolwiek, co Presta przechowuje natywnie. **Potwierdza to konieczność modułu z własnymi tabelami progów** (ADR-100 pkt 2) — nie da się tego „dowiesić" do natywnego atrybutu bez nadużycia schematu.

---

## Q3. Mapowanie marka → rozmiary

### Q3.a — Marki w kategoriach rozmiarowych (skafandry mokre 337+367)

```
SCUBAPRO 33 | BARE 26 | MARES 15 | AQUALUNG 14 | TUSA 5 | TECLINE 4 |
TUSA SPORT 4 | Outlet 4 | FOURTH ELEMENT 2 | Typhoon 2 | Aqua Zone 2 | …
```
Iteracja 1 (ADR-099 pkt 8) słusznie ogranicza się do Scubapro + Bare (najwięcej produktów + dostarczone charty). Mares/Aqualung to naturalne następne marki.

### Q3.b — „System wspólny per marka" potwierdzony danymi

Etykiety atrybutów w Preście są **globalne i współdzielone** — jeden `id_attribute` „L" jest podpięty pod dziesiątki produktów:

```
Scubapro (mid=18): 'L' współdzielone przez 69 produktów, 'M' przez 68, 'XL' przez 67, 'S' przez 65, 'MT' przez 18 …
Bare    (mid=11): 'M' współdzielone przez 44 produkty, 'L' przez 43, 'S' przez 42, 'XL' przez 41, '2XL' przez 24 …
```

**Konsekwencja dla projektu modułu:** natywna etykieta „L" jest **agnostyczna względem marki i wymiaru** — to ten sam `id_attribute` dla Scubapro i Bare, choć „L" znaczy u nich inne centymetry. Dlatego mapowanie **marka(+płeć) → chart progów musi mieszkać w module**; Presta nie ma gdzie tego zapisać. To dokładnie uzasadnia model z ADR-099/100: chart per marka+płeć, produkt→chart osobno.

---

## Q4. Identyfikacja kategorii rozmiarowych (realne id + nazwy)

| cid | Nazwa | parent | #produktów | Uwaga |
|----:|-------|-------:|-----------:|-------|
| 211 | Skafandry mokre | 2 | 208 | kategoria-matka mokrych |
| └ **337** | Skafandry Na CIEPŁE wody | 211 | 54 | ✅ potwierdzone (ADR-099) |
| └ **367** | Skafandry Na ZIMNE wody | 211 | 62 | ✅ potwierdzone (ADR-099) |
| **205** | Skafandry suche | 2 | 164 | ✅ główna kategoria suchych |
| └ 425 | SUCHE Trylaminat, Cordura | 205 | 38 | podkategoria suchych |
| └ 424 | SUCHE Neoprenowe | 205 | 10 | podkategoria suchych |
| 477 | Skafandry suche i ocieplacze | 467 | 16 | wtórne drzewo (nie główne — handoff mylił 205/477; **główne = 205**) |
| **212** | Buty | 211 | 35 | buty mokre |
| 208 | Buty do suchego | 205 | 17 | buty suche |
| **218** | Rękawice | 211 | 31 | rękawice mokre |
| 210 | Rękawice i Pierścienie | 205 | 25 | rękawice/pierścienie do suchego |
| 213 | Kaptury | 211 | 31 | (rozmiary jakościowe S/M/L) |

**Rozstrzygnięcie 205 vs 477:** suche skafandry żyją głównie pod **205** (164 prod., z podkat. 425/424); `477` to mniejsze, wtórne listowanie pod innym rodzicem (467). Do inwentaryzacji/modułu kategorią suchych jest **205**.

---

## Q5. Rekomendacja integracyjna

### 5a. Moduł z WŁASNYMI tabelami, luźno powiązany z `id_product` / marką — NIE rozszerzanie natywnych atrybutów

**Rekomendacja: wariant (a) — własne tabele modułu** (`divezone_size_*`), zgodnie z kierunkiem ADR-100 pkt 2. Uzasadnienie wprost z danych:

1. **Brak natywnego miejsca na progi (Q2).** Atrybut trzyma etykietę, cecha skalar, opis grafikę. Próg „L = klatka 101–107" nie ma żadnego natywnego nośnika — rozszerzanie `pr_attribute` polem wymiaru byłoby nadużyciem schematu (i tak nieobsługiwanym przez admin Presty) → **trzeci byt, którego unikamy**.
2. **Etykiety są globalne i agnostyczne marki (Q3.b).** Ten sam `id_attribute` „L" dla Scubapro i Bare. Progi są per marka+płeć, więc **nie da się ich powiesić na współdzielonym `id_attribute`** bez rozsadzenia go per marka. Mapowanie marka→chart musi być osobną relacją modułu.
3. **System wspólny per marka (Q3.b) pasuje 1:1 do modelu long-format ADR-099** (chart per marka+płeć, `divezone_product_size_chart` jako mapowanie). Diagnoza potwierdza ten model — nie wymaga rewizji.

**Powiązanie z katalogiem:** moduł trzyma własny chart + wiersze progów (long format), a styk z Prestą to:
- `divezone_product_size_chart(id_product → chart_id)` lub mapowanie po **marce+płci** (`id_manufacturer` + płeć), bo system wspólny per marka (mniej wierszy, mniej długu — preferowane jako klucz główny mapowania, `id_product` jako override).
- **Etykieta rozmiaru = klucz łączący** wiersz progu modułu z natywnym wariantem: `divezone_size_chart_rows.size_label` ↔ `pr_attribute_lang.name` (np. „MT"). Stąd potrzeba tabeli aliasów (ADR-099) — etykieta handlowa modułu musi mapować na realną etykietę wariantu w `pr_attribute`.

**Świadomie NIE robimy:** nie dodajemy kolumn do `pr_*`, nie tworzymy nowej grupy atrybutów „progi", nie duplikujemy wariantów. Moduł stoi OBOK natywnych atrybutów i czyta je tylko po to, by spiąć etykietę z progiem.

### 5b. Render tabeli na stronie produktu — gdzie wstrzykiwać

Presta udostępnia naturalne haki (moduł rejestruje się na hooku, zero edycji rdzenia):
- **`displayProductExtraContent`** (PS 1.7+) — natywny mechanizm zakładek/sekcji pod opisem produktu (idealne na „Tabela rozmiarów / Dobierz rozmiar").
- alternatywnie `displayFooterProduct` / `displayProductAdditionalInfo` (zależnie od motywu).
- **Hybryda progi/treść (ADR-100 pkt 4):** gdzie są progi → tabela **generowana** z `divezone_size_chart_rows`; gdzie progów brak (kaptur S/M/L, maski) → moduł renderuje tabelę-treść zarządzaną ręcznie. Oba w jednym hooku.
- Panel zarządzania: zakładka w adminie SKLEPU pod „Katalog" (obok „Atrybuty i Cechy") — naturalne miejsce domenowe (ADR-100 pkt 2).

### 5c. Jak czat (read-only) czyta progi z modułu

Czat pozostaje **konsumentem read-only** (symetria z ceną/wagą — ADR-100 pkt 3). Rekomendacja:
- **Bezpośredni `SELECT` na tabele modułu** (`divezone_size_charts`, `_chart_rows`, `_product_size_chart`, `_size_label_alias`) przez istniejący kanał read-only `divezone_chat_reader` — **ten sam mechanizm, którym czat już czyta katalog**. Najmniej ruchomych części, zero nowego API.
  - Wymaga rozszerzenia GRANT-ów `divezone_chat_reader` o **SELECT na nowe tabele modułu** (obecnie ma `SELECT` na całą `divezone_2025.*`, więc nowe tabele w tej bazie **będą automatycznie czytelne** — bez zmiany uprawnień, o ile moduł tworzy tabele w `divezone_2025`).
- Endpoint modułu (REST) tylko jeśli front sklepu i kalkulator (warstwa 2) i tak będą go potrzebować — wtedy czat może go współdzielić. Ale dla samego czatu bezpośredni SELECT jest prostszy i spójny z resztą jego dostępu do danych.
- **Logika algorytmu `match_size`/`match_pointwise` bez zmian** (ADR-100 pkt 6) — zmienia się tylko warstwa dostępu: PG Railway → MySQL PS (te same kolumny long-format).

---

## Wnioski kluczowe (pkt 2 i 5 — przesądzają projekt modułu)

1. **(pkt 2) Progi nie mają natywnego miejsca w PrestaShop — potwierdzone danymi.** Atrybuty = etykiety (Q1), cechy = skalar/produkt (Q2.a/b), opisy = grafika/proza, brak interaktywnego kalkulatora w DB (Q2.c). → **Moduł z własnymi tabelami progów jest konieczny, nie opcjonalny.**
2. **(pkt 5) Kierunek ADR-100 (własne tabele modułu, OBOK natywnych atrybutów) jest poprawny.** Diagnoza nie znalazła żadnego natywnego nośnika, na którym dałoby się oprzeć progi bez tworzenia trzeciego bytu. Etykiety atrybutów są globalne/agnostyczne marki (Q3.b), więc mapowanie marka+płeć→chart i aliasy etykiet muszą żyć w module. Model long-format z ADR-099 nie wymaga rewizji.
3. **Styk modułu z katalogiem:** klucz mapowania = **marka+płeć** (system wspólny per marka), `id_product` jako override; **etykieta rozmiaru** (`size_label` ↔ `pr_attribute_lang.name`) jako spinacz progu z wariantem (stąd tabela aliasów). Render na stronie przez hook `displayProductExtraContent`. Czat czyta przez bezpośredni read-only SELECT (nowe tabele w `divezone_2025` → automatycznie w zasięgu `divezone_chat_reader`).
4. **Kategorie do modułu/inwentaryzacji:** mokre 211 (337+367), suche **205** (425/424; nie 477), buty 212/208, rękawice 218/210, kaptury 213.

---

*Diagnoza READ-ONLY, zero write. Następny krok (osobny task): projekt schematu modułu MySQL + ADR.*
