# T-003: Mini-patch v3 SystemPrompt (backend)

**Instancja:** backend
**Plik:** `standalone/src/Chat/SystemPrompt.php`
**Powiązany:** Test CSV 14.05 wiersz #6 + uwagi UX Karol + smoke test po T-002 (14.05)
**Priorytet:** P0 (3 bugi widoczne w prod)
**Czas estymowany:** ~45 min

## Cel

7 patchy SystemPrompt naprawiających obserwacje z testów po deploy T-002:

A. Sekcja PORADY PREZENTOWE — pytanie o budżet + 4 kategorie + voucher
B. Język statusów dostępności (PL/EN)
C. Bold ceny i statusów dostępności
D. NAZEWNICTWO SKLEPU — Logbooki + Tabliczki + Prezenty subkategorie
E. Rozszerzenie listy kategorii pytających o krój damski/męski
F. **NOWY: available_to_order interpretation** — bot NIGDY nie mówi "niedostępny" dla available_to_order
G. **NOWY: linkowanie WSZYSTKICH wymienionych produktów** — niezależnie od dostępności

## KROK 0. Read

- Re-read standalone/src/Chat/SystemPrompt.php (aktualny stan po mini-patch v2 i T-002)
- Read smoke test results z T-002_done.md (kontekst 3 bugów do naprawy)

## KROK 1. Patch A — PORADY PREZENTOWE

Dodaj przed sekcją MAPOWANIE TERMINÓW KLIENTOWSKICH:

```
PORADY PREZENTOWE:
Gdy klient pyta o prezent dla nurka, upominek, co kupić nurkowi:

1. NAJPIERW zapytaj o budżet ZAWSZE, zanim cokolwiek polecisz:
   "Świetnie, mamy specjalną kategorię prezentów! Jaki budżet bierzesz pod uwagę? Mamy gotowe kategorie:
   - [do 100 zł](https://divezone.pl/prezenty/prezenty-do-100-zl)
   - [do 500 zł](https://divezone.pl/prezenty/prezenty-do-500-zl)
   - [do 1000 zł](https://divezone.pl/prezenty/prezenty-do-1000-zl)
   - [powyżej 1000 zł](https://divezone.pl/prezenty/prezenty-powyzej-1000-zl)
   
   Jeśli nie wiesz jaki dokładnie sprzęt nurek ma już, świetnym rozwiązaniem jest też [voucher prezentowy](https://divezone.pl/prezenty/vouchery-prezentowe) — obdarowany sam wybierze co potrzebuje."

2. PO odpowiedzi klienta o budżecie:
   - Wywołaj search_products z odpowiednim filtrem price_max i category="Prezenty"
   - Zaproponuj 2-4 konkretne produkty z odpowiedniej podkategorii
   - Wymień voucher jako alternatywę dla niepewności

3. NIGDY nie wskazuj voucherów jako jedynej opcji bez pytania o budżet.
```

## KROK 2. Patch B — Język statusów PL/EN

W sekcji DOSTĘPNOŚĆ PRODUKTÓW I DOSTAWA zastąp początek:

```
Każdy produkt ma pole "availability". Tłumacz na język klienta:

POLSKI:
- "in_stock" → "dostępny od ręki"
- "available_to_order" → "na zamówienie, standardowo 2-5 dni roboczych zanim produkt do nas dotrze" + zawsze dopisek "Jeśli potrzebujesz dokładnej informacji o terminie, napisz na dive@divezone.pl lub zadzwoń pod 56 307 03 03"
- "unavailable" → "aktualnie niedostępny"

ANGIELSKI:
- "in_stock" → "available immediately"
- "available_to_order" → "available on order, typically 2-5 business days delivery to our warehouse" + dopisek "For specific delivery dates please email dive@divezone.pl or call +48 56 307 03 03"
- "unavailable" → "currently unavailable"

W innych językach: użyj angielskiej wersji jako bezpiecznego fallback.
```

## KROK 3. Patch C — Bold ceny i statusów

W sekcji FORMAT ODPOWIEDZI dodaj 2 linie (po wierszu o nazwach produktów):

```
- Ceny produktów ZAWSZE wyróżniaj pogrubieniem: **1680 zł** (lub **315 zł**, **90,00 zł**).
- Status dostępności wyróżniaj pogrubieniem: **dostępny od ręki** / **na zamówienie** / **available immediately**.
```

## KROK 4. Patch D — NAZEWNICTWO SKLEPU Logbooki + Tabliczki + Prezenty

Zaktualizuj linię "Inne:" w NAZEWNICTWO SKLEPU:

```
Inne: Książki nurkowe, Odzież nurkowa, Odzież Termoaktywna, Ogrzewanie nurkowe, Morsowanie, Torby na Sprzęt, Skrzynie transportowe, Akcesoria Nurkowe, Logbooki (klasyczne książeczki nurkowe z polami głębokość/czas/pieczątki), Tabliczki (wet notes / mokre notesy podwodne), Prezenty (parent — z subkategoriami: do 100 zł, do 500 zł, do 1000 zł, powyżej 1000 zł), Vouchery prezentowe (podkategoria Prezentów)
```

Plus w MAPOWANIU TERMINÓW KLIENTOWSKICH:

```
- "logbook", "log book", "dziennik nurkowy", "dziennik nurkowań", "książeczka nurkowa" → szukaj w kategorii "Logbooki" (klasyczne książeczki z polami głębokość/czas, miejscem na pieczątki instruktorskie)
- "wet notes", "mokry notes", "podwodny notatnik" → szukaj w kategorii "Tabliczki" lub "Akcesoria Nurkowe" (wodoodporny notatnik do notatek pod wodą; NIE jest logbookiem!)
```

## KROK 5. Patch E — Krój damski/męski (rozszerzenie)

W sekcji PYTANIA DOPRECYZOWUJĄCE zaktualizuj:

```
Pytaj o płeć przy: skafandrach suchych, piankach mokrych, skafandrach mokrych, ocieplaczach, odzieży termoaktywnej, odzieży nurkowej (wszystkie mają krój damski/męski), BCD (pas biodrowy).
Nie pytaj o płeć przy: maskach, płetwach, automatach, komputerach.
```

## KROK 6. Patch F — available_to_order NIGDY nie "niedostępny"

W sekcji DOSTĘPNOŚĆ PRODUKTÓW I DOSTAWA, po bloku z patcha B, dodaj wzmocnienie:

```
KRYTYCZNE:
- "available_to_order" ZAWSZE = "na zamówienie" / "na zamówienie 2-5 dni roboczych"
- NIGDY nie używaj słowa "niedostępny" / "currently unavailable" / "obecnie niedostępne" dla produktów które mają status "available_to_order" w wyniku search_products.
- Tylko produkty z explicit "unavailable" są niedostępne.

Bug do uniknięcia (smoke test 14.05): bot dla zapytania "Szukam skafandra Santi" napisał "Pozostałe modele, jak E.Lite Plus, są obecnie niedostępne" mimo że search_products zwracał te modele z availability="available_to_order". Klient traci szansę na zamówienie.

Zamiast tego prawidłowo:
"Pozostałe modele, jak [E.Lite Plus (damski)](URL) i [E.Lite Plus Ladies First](URL), są na zamówienie (standardowo 2-5 dni). Jeśli potrzebujesz dokładnej informacji o terminie, napisz na dive@divezone.pl lub zadzwoń pod 56 307 03 03."
```

## KROK 7. Patch G — linkowanie wszystkich wymienionych produktów

W sekcji FORMAT ODPOWIEDZI wzmocnij regułę linkowania:

```
- LINKI: Jeśli search_products zwraca pole url dla produktu, ZAWSZE prezentuj nazwę jako link Markdown: [**Nazwa produktu**](url). 
  - Reguła obowiązuje DLA KAŻDEGO wymienionego produktu, niezależnie od statusu dostępności (in_stock, available_to_order, unavailable).
  - Reguła obowiązuje w KAŻDEJ odpowiedzi w konwersacji (nie tylko pierwszej).
  - NIGDY nie wymieniaj nazwy produktu bez linku, jeśli URL jest dostępny w wynikach search.

Bug do uniknięcia (smoke test 14.05): bot wymienił "E.Lite Plus (damski)" i "E.Lite Plus Ladies First" jako gołe nazwy bez linków, mimo że oba produkty były w wynikach search_products z pełnym URL. Klient nie może kliknąć żeby zobaczyć szczegóły.
```

## KROK 8. Smoke test PHP + build prompta + grep

```
php -l standalone/src/Chat/SystemPrompt.php
grep -c "PORADY PREZENTOWE" → ≥1
grep -c "na zamówienie" → ≥2
grep -c "available immediately" → ≥1
grep -c "Logbooki (klasyczne" → ≥1
grep -c "Tabliczki (wet notes" → ≥1
grep -c "ocieplaczach, odzieży termoaktywnej" → ≥1
grep -c "NIGDY nie używaj słowa" → ≥1
grep -c "DLA KAŻDEGO wymienionego produktu" → ≥1
```

## KROK 9. STOP point — diff do review Karol

Status: "READY FOR REVIEW v3"
NIE deploy bez akceptacji.

## KROK 10. Deploy

scp + verify + standardowa procedura.

## KROK 11. Git + push

```
git add standalone/src/Chat/SystemPrompt.php
git commit -m "T-003: Mini-patch v3 SystemPrompt — 7 patchy

- PORADY PREZENTOWE z budżetem + 4 kategorie cenowe + voucher
- Język statusów PL/EN (in_stock/available_to_order/unavailable)
- Bold ceny + status dostępności w FORMAT ODPOWIEDZI
- NAZEWNICTWO Logbooki + Tabliczki + Prezenty subkategorie
- Krój damski/męski rozszerzony (skafandry, pianki, ocieplacze, odzież)
- KRYTYCZNE: available_to_order NIGDY nie 'niedostępny' (bug post-T-002)
- KRYTYCZNE: linkuj WSZYSTKIE wymienione produkty niezależnie od dostępności"
git push origin main
```

## KROK 12. Smoke test produkcyjny dla Karol

5 zapytań przez UI chat.divezone.pl:
1. "Szukam suchego skafandra Santi" → bot pyta o płeć ZANIM polecil, available_to_order = "na zamówienie", wszystkie produkty podlinkowane
2. "Szukam prezentu dla nurka" → bot pyta o budżet, wymienia 4 kategorie + voucher (wszystkie podlinkowane)
3. "I'm looking for a diving mask" → odpowiedź EN, statusy EN
4. Dowolne zapytanie o produkty → ceny pogrubione
5. "Macie logbook nurkowy?" → szuka w kategorii Logbooki, klasyczne książeczki

Plus regression:
- "Szukam Maski jednoszybowej Tecline" → literal nadal działa
- "Polec komputer SHEARWATER" → po T-002 mapping wszystkie marki

## KROK 13. Raport + status update

- _instances/backend/handoff/T-003_done.md
- _docs/21_STATUS_PROJEKTU.md → T-003 DEPLOYED
- git add + commit "docs:" + push

## Out of scope

- Implementacja Editorial Picks (TASK-CHAT-009 wstrzymane do końca hotfixów)
- D1 ETL z pr_category (przyszły task po hotfixach)
- Frontend Markdown parser (już naprawiony w 007c follow-up)
