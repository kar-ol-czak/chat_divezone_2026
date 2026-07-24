# CHAT-T-164 — BACKEND — waluta wg języka rozmowy, B2B po ludzku, zakaz zmyślonych URL

**Data:** 2026-07-24 | **Instancja:** backend | **Nawiązuje do:** ADR-099 (zero fabrykacji)
**Świat wdrożeniowy:** WYŁĄCZNIE backend standalone `chat.divezone.pl`. Jeden plik: `SystemPrompt.php`.
**Recenzje źródłowe:** 391 (conv 779), 376 (conv 795), 335 (conv 755, znalezisko architekta)

---

## 1. ZAKRES — ustalony przez audyt, nie z listy recenzji

Pierwotnie planowano pakiet 7 poprawek. **Audyt promptu na PROD 2026-07-24 wykazał,
że 5 z nich JEST JUŻ WDROŻONYCH.** Nie dopisuj do nich niczego.

| recenzja | czat | stan | linia w prompcie na PROD |
|---|---|---|---|
| 340 raty 0% | 742 | **ZROBIONE** | 805 |
| 339 goły URL | 749 | **ZROBIONE** | 1030 |
| 348 premium bez budżetu | 750 | **ZROBIONE** | — |
| 338 brak produktu w paczce | 752 | **ZROBIONE** | 367-369 |
| 358 wykład o Oceanic+ | 680 | **ZROBIONE** | 721-723 |
| 335 cytat spoza okna historii | 755 | **ZROBIONE** | 265-266 |

Do zrobienia zostają **trzy reguły**, wszystkie zweryfikowane jako NIEOBECNE
(grep na PROD: `EUR|waluta|PLN` w kontekście reguły → 0 trafień).

---

## 2. REGUŁA A — waluta wg języka rozmowy (recenzja 391, czat 779)

**Co się stało.** Klient napisał po polsku: „hej macie [link do /en/ wersji] na magazynie?".
Bot odpowiedział po polsku, ale cenę podał w EUR: „954.20 EUR (promocja, regularna 1 084.32 EUR)".

**Przyczyna.** Narzędzia zwracają `price` (PLN) i `price_eur` w każdym wyniku.
Prompt NIE MA żadnej reguły, które pole wybrać. Model wziął EUR, bo URL był `/en/`.

**Reguła do dopisania** (sekcja o cenach/formatowaniu):

> WALUTA — DECYDUJE JĘZYK ROZMOWY, NIE JĘZYK LINKU (czat 779):
> Rozmowa po polsku → cena w PLN (pole `price`), ZAWSZE. To, że klient wkleił link
> do wersji `/en/` sklepu, NIE oznacza życzenia ceny w euro — ludzie wklejają linki
> z wyszukiwarki i historii przeglądarki. Cenę w EUR (`price_eur`) podawaj tylko gdy
> rozmowa toczy się po angielsku ALBO klient wprost o walutę obcą poprosił.
> Przy linku `/en/` w polskiej rozmowie podaj też link do polskiej wersji produktu (`url`).
> Bug do uniknięcia (czat 779): polska rozmowa, link `/en/`, bot podał 954.20 EUR
> zamiast ceny w złotówkach.

---

## 3. REGUŁA B — B2B po ludzku (recenzja 376, czat 795)

**Co się stało.** Klient: „Czy macie zniżki dla nowych centrów nurkowych lub instruktorskie?"
Bot: „Oferujemy program współpracy B2B — szczegóły na [stronie B2B](https://divezone.pl/b2b)."

**Przyczyna.** Prompt (linia 315, JAIL-002) każe podać link i nie negocjować warunków.
To działa. Brakuje wyłącznie tego, żeby odpowiedzieć NA ZADANE PYTANIE, po ludzku.
Karol: „nie każdy musi wiedzieć, co oznacza B2B".

**Reguła do dopisania** (przy JAIL-002, NIE zastępuj jej):

> JĘZYK ODPOWIEDZI O B2B (czat 795): gdy klient pyta o zniżki dla centrów nurkowych,
> instruktorów lub profesjonalistów — ZACZNIJ od potwierdzenia po ludzku:
> „Tak, mamy zniżki dla centrów nurkowych i instruktorów", dopiero potem link.
> Nazwa linku też po ludzku: `[program współpracy dla centrów i instruktorów](https://divezone.pl/b2b)`,
> NIE `[program B2B]`. Skrót „B2B" jako jedyna odpowiedź na pytanie o zniżki jest
> odpowiedzią żargonem — klient nie musi wiedzieć, co znaczy.
> Zakaz negocjowania warunków w czacie (JAIL-002) BEZ ZMIAN.

---

## 4. REGUŁA C — zakaz zmyślonych URL (znalezisko architekta, czat 755)

**Co się stało.** W ostatniej odpowiedzi czatu 755 bot podał link
`https://divezone.pl/tusa-serene-uc-1625.html`. **Tego URL nie było w ŻADNYM `tool_result`
w całej rozmowie.** W wynikach `search_products` był
`https://divezone.pl/tusa-serene-adult-combo-maska-i-fajka.html`.
Bot skleił URL z nazwy produktu i kodu referencyjnego `UC-1625QB`.

**To nie było w nocie recenzji** — wykryte przy audycie 2026-07-24.
Naruszenie zasady zero fabrykacji (ADR-099): zmyślony link prowadzi do 404
albo, gorzej, do przypadkowego innego produktu.

**Reguła do dopisania** (przy regule o linkach Markdown, linia ~1030):

> URL WYŁĄCZNIE Z `tool_result` (czat 755) — ZERO FABRYKACJI:
> Każdy link produktowy kopiuj DOSŁOWNIE z pola `url` (lub `url_en`) w wyniku narzędzia.
> NIGDY nie buduj URL samodzielnie: nie skracaj, nie sklejaj z nazwy produktu,
> nie doklejaj kodu referencyjnego, nie zgaduj wzorca po innych adresach.
> Jeśli produkt nie ma `url` w wyniku — podaj nazwę BEZ linku.
> Bug do uniknięcia (czat 755): bot podał `tusa-serene-uc-1625.html`, sklejone
> z nazwy i referencji `UC-1625QB`. W `tool_result` było
> `tusa-serene-adult-combo-maska-i-fajka.html`. Zmyślony link = 404 dla klienta.
> Wyjątek: adresy stałe podane w tym prompcie (b2b, kontakt, serwis) są dozwolone.

---

## 5. KRYTERIA AKCEPTACJI

| # | wejście | oczekiwane |
|---:|---|---|
| 1 | polska rozmowa + link `/en/` do produktu, pytanie o dostępność | cena w **PLN**, nie EUR |
| 2 | to samo | podany też link do polskiej wersji |
| 3 | rozmowa po angielsku | cena w EUR bez zmian (regresja) |
| 4 | „czy macie zniżki dla instruktorów?" | odpowiedź zaczyna się od „Tak, mamy zniżki dla centrów i instruktorów" |
| 5 | to samo | link nazwany po ludzku, nie „program B2B" |
| 6 | to samo | nadal BEZ negocjowania warunków (JAIL-002 nienaruszone) |
| 7 | dowolne pytanie produktowe | każdy link produktowy identyczny z `url` z `tool_result` |
| 8 | produkt bez `url` w wyniku | nazwa bez linku, nie zmyślony URL |

Kryteria 1-8 realnym czatem na PROD, oznaczone `[test CHAT-T-164, nie klient]`.

---

## 6. CZEGO NIE RUSZAĆ

- **Sześć reguł z tabeli w §1** — już wdrożone, nie duplikuj, nie przepisuj
- JAIL-002 (linia 315), reguła gołych URL (1030), reguła cytatu spoza okna (265-266)
- Reguły z T-161/T-162/T-163 (dobór rozmiaru, `size_chart`, wyszukiwanie po wymiarach)
- `SizeRecommender.php`, `ProductDetails.php`, `FindWetsuitsByMeasurements.php`
- `config/tools.php`, `config/routes.php`, moduł `newtmp2`
- `_ops/newtmp2_root/purge_litespeed.php` (SEKRET), cudze ADR-y

---

## 7. DEPLOY

Jeden plik:
```
standalone/src/Chat/SystemPrompt.php  →  ~/public_html/chat.divezone.pl/src/Chat/SystemPrompt.php
```

**ZAKAZ blanket-rsync `standalone/`.** Backup `_deploy_bak/CHAT-T-164/`, md5 local↔prod,
`ea-php84 -l`, smoke `/api/health`.
**STOP przed rsync — czekaj na „deployuj" (ADR-089).**
Zero migracji PG, zero cache PS, bez `composer dump-autoload`.

---

## 8. RAPORT

`_docs/21_STATUS_PROJEKTU.md` NA GÓRZE. Raport z 8 kryteriami, surowe przebiegi dla 1, 4 i 7.
