# T-007: Mini-patch v5 SystemPrompt — 2 patche (płeć + zakaz generalizacji statusów)

**Instancja:** backend
**Plik:** `standalone/src/Chat/SystemPrompt.php`
**Powiązany:** T-003 patch E (regresja), smoke test T-006 14.05 (bugi #3 + #4)
**Priorytet:** P1 (2 ostatnie UX regresje z pakietu post-T-002)
**Czas estymowany:** ~40 min CC

## Cel

Dwa patche w SystemPrompt zamykające pakiet hotfixów post-T-002:

**Patch H (bug #3 płeć):** wzmocnić trigger pytania o płeć żeby bot ZAWSZE pytał przed pierwszą rekomendacją skafandra/pianki/BCD/odzieży, niezależnie od marki w pytaniu i nazw produktów w wynikach.

**Patch I (bug #4 generalizacja statusów):** zakaz pisania w intro "nie mamy żadnego dostępnego" gdy lista produktów którą sam wymienisz zawiera produkt in_stock.

## Kontekst bugów

### Bug #3 (płeć) — smoke T-003 + T-006

Klient: "Szukam suchego skafandra Santi". search_products zwraca SANTI E.Motion Plus Męski + E.Lite Plus damski + Ladies First. Bot widzi w nazwach "Męski"/"Ladies First" → zakłada że może sam dobrać i NIE pyta klienta. Regresja patcha E z T-003.

### Bug #4 (generalizacja) — smoke T-006

Po deploy T-006 to samo zapytanie zwraca 4 produkty: 3× available_to_order + 1× in_stock (Ladies First Powystawowy). Bot napisał w intro: "Aktualnie nie mamy **żadnego** suchego skafandra SANTI dostępnego od ręki" mimo że w swojej własnej liście wymienił Powystawowy ze statusem "dostępny od ręki". Wewnętrzna sprzeczność intro vs lista.

To NIE jest bug toola — T-006 zwrócił poprawne dane. Bug LLM-narracyjny: model generalizuje "większość niedostępna = wszystkie niedostępne" zamiast policzyć statusy.

## KROK 0. Read

- `standalone/src/Chat/SystemPrompt.php` (sekcja PYTANIA DOPRECYZOWUJĄCE — patch E z T-003; sekcja DOSTĘPNOŚĆ PRODUKTÓW I DOSTAWA — patche B/F z T-003)

## KROK 1a. Patch H — PYTANIE O PŁEĆ KRYTYCZNE

Znajdź blok PYTANIA DOPRECYZOWUJĄCE (po patchu E z T-003):

```
Pytaj o płeć przy: skafandrach suchych, piankach mokrych, skafandrach mokrych, ocieplaczach, odzieży termoaktywnej, odzieży nurkowej (wszystkie mają krój damski/męski), BCD (pas biodrowy).
Nie pytaj o płeć przy: maskach, płetwach, automatach, komputerach.
```

Zastąp:

```
PYTANIE O PŁEĆ — KRYTYCZNE:

Pytaj o płeć ZAWSZE przed pierwszą rekomendacją produktów w kategoriach: skafandry suche, pianki mokre, skafandry mokre, ocieplacze, odzież termoaktywna, odzież nurkowa, BCD (pas biodrowy).

Reguła obowiązuje NIEZALEŻNIE od:
- tego co klient powie w pytaniu (nawet jeśli wprost wymienia markę, model lub serię, np. "Szukam Santi suchego")
- tego co zwraca search_products w nazwach produktów (np. "Męski", "Damski", "Ladies First", "Women's", "Men's")
- tego jakie produkty istnieją w sklepie (mix męskie/damskie)

Bug do uniknięcia (smoke 14.05): klient pyta "Szukam suchego skafandra Santi". search_products zwraca SANTI E.Motion Plus Męski + SANTI E.Lite Plus damski + Ladies First. Bot widzi że istnieją modele obu krojów ale NIE pyta klienta — prezentuje wszystkie pomieszane. To bug.

PRAWIDŁOWO: "SANTI ma świetne suche skafandry zarówno w wersji damskiej jak i męskiej. Dla kogo szukasz skafandra? To pozwoli mi dobrać odpowiedni krój."

NIE: rekomendować zarówno męskich jak i damskich w pierwszej odpowiedzi.
NIE: zakładać że "Ladies First" w nazwie znaczy że klient szuka damskiego.
NIE: pomijać pytania bo "klient sam wybierze z listy".

Nie pytaj o płeć przy: maskach, płetwach, automatach, komputerach (te są unisex w sklepie).
```

## KROK 1b. Patch I — ZAKAZ GENERALIZACJI STATUSÓW

W sekcji DOSTĘPNOŚĆ PRODUKTÓW I DOSTAWA (po patchu F z T-003 "available_to_order ZAWSZE = na zamówienie"), dodaj nowy podblok:

```
ZAKAZ GENERALIZACJI STATUSÓW — KRYTYCZNE:

Przed napisaniem WSTĘPU/INTRO do listy produktów policz statusy w wynikach search_products. NIE generalizuj.

NIE PISZ:
- "Aktualnie nie mamy żadnego X dostępnego od ręki" jeśli choćby JEDEN produkt z listy którą zaraz wymienisz ma availability="in_stock"
- "Wszystkie modele są na zamówienie" jeśli choć jeden ma "in_stock"
- "Wszystkie produkty dostępne od ręki" jeśli choć jeden ma "available_to_order" lub "unavailable"
- "Niestety nie mamy" jeśli jakikolwiek z listy jest in_stock lub available_to_order

POPRAWNIE — opisz mix lub pomiń intro:
- "Większość modeli na zamówienie, jeden dostępny od ręki:"
- "Mam jeden dostępny od ręki + 3 na zamówienie:"
- Lub pomiń intro o dostępności i przejdź do listy ze statusami per produkt

Bug do uniknięcia (smoke T-006 14.05): klient pyta "Szukam suchego skafandra Santi". search_products zwraca 4 produkty: 3× available_to_order + 1× in_stock (Ladies First Powystawowy). Bot napisał w intro "Aktualnie nie mamy żadnego suchego skafandra SANTI dostępnego od ręki" — ale jego własna lista zawierała Powystawowy ze statusem "dostępny od ręki". Wewnętrzna sprzeczność.

ZASADA: intro o dostępności MUSI być zgodne z listą którą sam wymienisz. Jeśli nie pewny — pomiń intro o dostępności i pokaż listę z prawdziwymi statusami per produkt.
```

## KROK 2. Smoke test PHP + grep

```bash
php -l standalone/src/Chat/SystemPrompt.php
grep -c "PYTANIE O PŁEĆ — KRYTYCZNE" standalone/src/Chat/SystemPrompt.php             # ≥1 (patch H)
grep -c "NIEZALEŻNIE od" standalone/src/Chat/SystemPrompt.php                         # ≥1 (patch H)
grep -c "ZAKAZ GENERALIZACJI STATUSÓW" standalone/src/Chat/SystemPrompt.php           # ≥1 (patch I)
grep -c "policz statusy w wynikach" standalone/src/Chat/SystemPrompt.php              # ≥1 (patch I)
grep -c "wymienił Powystawowy ze statusem" standalone/src/Chat/SystemPrompt.php       # ≥1 (patch I, marker bug example)
```

## KROK 3. STOP point — diff do review Karol

Status: "READY FOR REVIEW v1". Wklej diff. NIE deploy bez akceptacji.

## KROK 4. Deploy

scp + verify (md5 lokalny=remote, php -l, 5 grepów markerów).

## KROK 5. Git workflow

```bash
git status
git add standalone/src/Chat/SystemPrompt.php
git commit -m "T-007: mini-patch v5 SystemPrompt — bugi #3 (płeć) + #4 (generalizacja statusów)

Patch H (bug #3 płeć): wzmocnienie patcha E z T-003. Bot nie pytał o płeć
przy 'Szukam suchego skafandra Santi' bo widział w wynikach nazwy 'Męski'
i 'Ladies First' i sam wybierał. Patch H: PYTANIE O PŁEĆ — KRYTYCZNE,
NIEZALEŻNIE od nazw w wynikach, z few-shot example dla SANTI.

Patch I (bug #4 generalizacja statusów): bot pisał w intro 'nie mamy
żadnego dostępnego od ręki' mimo że w swojej własnej liście wymieniał
produkt in_stock (Ladies First Powystawowy). Patch I: zakaz generalizacji
statusów, intro musi być zgodne z listą per produkt.

Zamyka pakiet hotfixów post-T-002 (T-001/T-002/T-003/T-006/T-007)."
git push origin main
```

## KROK 6. Smoke test produkcyjny dla Karola

### Patch H (płeć):
1. **"Szukam suchego skafandra Santi"** → bot pyta o płeć ZANIM cokolwiek polec, NIE wymienia produktów
2. **"Szukam suchego skafandra Santi, damski"** → bot rekomenduje TYLKO damskie (5509, 5846, 6865) z prawidłowymi statusami + linki
3. **"Polec mi piankę"** → pyta o płeć
4. **"Szukam BCD"** → pyta o płeć
5. Regression: **"Szukam komputera SHEARWATER"** → NIE pyta o płeć (unisex)
6. Regression: **"Polec maskę"** → NIE pyta o płeć

### Patch I (generalizacja statusów):
7. **"Pokaż mi wszystkie suche skafandry Santi" (lub po doprecyzowaniu płci damskie)** → wyniki mix (3× available_to_order + 1× in_stock). Intro NIE może mówić "żadnego dostępnego od ręki" gdy Powystawowy jest na liście.
8. **Generic test mix:** dowolne zapytanie zwracające mieszane statusy → intro zgodne z listą lub brak intro o dostępności.

### Regression T-006 + T-002:
9. **"Polec komputer SHEARWATER"** → T-002 mapping wszystkie marki, T-003 ceny/statusy bold, T-006 prawidłowe availability
10. **Produkt z out_of_stock=0 (deny orders)** → nadal "unavailable"

## KROK 7. Raport + status update

### Utworz `_instances/backend/handoff/T-007_done.md`:
- Backup md5 przed/po
- Diff stat (+X/-Y linii)
- Git commit hash
- Lista 5 grepów markerów z wynikami
- Lista wykonanych smoke testów (10) z PASS/FAIL

### Update `_docs/21_STATUS_PROJEKTU.md`:
- "Co działa na produkcji" → T-007 DEPLOYED + commit hash (oba patche H + I)
- "Aktywne instancje CC" → backend T-007 DONE
- "Kolejka tasków" → usunąć T-007
- Sekcja follow-up bugów T-003: bug #3 płeć — CLOSED by T-007 patch H, bug #4 generalizacja — CLOSED by T-007 patch I. PAKIET HOTFIXÓW POST-T-002 ZAMKNIĘTY (T-001/T-002/T-003/T-006/T-007).
- Aktualizacja "Kolejka tasków": następny = TASK-CHAT-009 Editorial Picks (odmrożenie, ADR-054)

### Osobny commit "docs:":

```bash
git add _docs/21_STATUS_PROJEKTU.md
git commit -m "docs: T-007 DEPLOYED — pakiet hotfixów post-T-002 zamknięty"
git push origin main
```

## Out of scope

- Inne pytania doprecyzowujące (rozmiar buta, wzrost, doświadczenie) — to inna sekcja, bez zmian
- T-XXX EN strategia (kolejny task, 63b)
- TASK-CHAT-009 Editorial Picks (odmrożenie po T-007, osobny task)
- T-004 refresh_stock cron / T-005 SynonymExpander (P2, osobny sprint)
