# CHAT-T-077 — BACKEND: hotfix promptu — zwroty 30 dni + reguła półki cenowej

**Instancja:** backend (standalone chat.divezone.pl). CC deployuje SAM. ZERO modułu PS, ZERO frontu.
**Priorytet:** P1 — dwa defekty klientowskie z rozmowy 0b0eefe4a1150f37f5d3392978e7e130.
**Powiązane:** ADR-085, Q229 (struktura półek — backlog), ADR-071/Q231a (docelowo zwroty na chip deterministyczny; ten task = mitygacja promptowa TERAZ).
**Plik:** standalone/src/Chat/SystemPrompt.php. TYLKO ten plik.

## Defekt 1 — zwroty: bot podał 14 dni zamiast 30
Klient "ile dni mam na zwrot" → bot "14 dni" (ustawa). Sekcja "ZWROTY i brak wymian" (~linie 81-84) ma szczegóły, ale BRAK TERMINU. Polityka divezone = 30 dni bez podania przyczyny (towar nieużywany).

### Fix 1 (Q225a) — w sekcji "ZWROTY i brak wymian" DOPISAĆ na początku:
- Termin: **30 dni od zakupu na zwrot bez podania przyczyny** (towar NIEUŻYWANY). Standard sklepu, więcej niż ustawowe 14 dni. GŁÓWNA odpowiedź = 30 dni (nie 14). 14 dni wspomnieć jako ustawowe minimum.
- Warunek "towar nieużywany" ZAWSZE z terminem.
- LINK OBOWIĄZKOWY (Q230a): przy KAŻDEJ odpowiedzi o terminie/procedurze zwrotu bot MUSI podać https://divezone.pl/zwroty-produktow (nakaz, nie sugestia). Zakres: odpowiedzi O ZWROTACH — nie każda wzmianka "zwrot środków" w innym kontekście.
- Brzmienie: "Jesteśmy pewni jakości sprzętu, dlatego możesz zwrócić towar aż przez 30 dni od zakupu bez podania przyczyny."

NIE zmieniać istniejących punktów (brak wymian, zwrot środków 24h, formularz).

## Defekt 2 — półka cenowa: bot dał najtańszy gdy klient prosił średnio-wysoką
Klient "automat ze średnio-wysokiej półki" → bot wziął priority:1 z curated (ATX40, najtańszy) jako główny. Bot NIE MA deterministycznego sygnału o pozycji cenowej w PEŁNYM asortymencie; 3 curated to za mało by wyliczyć "półkę".

### Fix 2 (Q228a) — DODAĆ regułę przy doborze/curated:
- priority = ranking zespołu dla pytań OGÓLNYCH, NIE nakaz. Gdy klient poda kryterium (półka/budżet/"najlepszy"/"budżetowy") — NIE oddawaj automatycznie priority:1.
- Półka cenowa BEZ kwoty: NIE udawaj znajomości pozycji w asortymencie, NIE wyliczaj półki z kilku rekomendacji (za mała próbka).
- Zamiast tego: pokaż opcje z cenami jako ZAKRES ("rekomendacje zespołu od X do Y zł"), zaznacz że to wybór zespołu, DOPYTAJ o budżet.
- Klient PODA kwotę → dopasuj do budżetu używając cen, nie priority.
- NIE wymyślaj etykiet "to średnia półka" bez podstawy w danych.

## Granice
- TYLKO SystemPrompt.php. ZERO narzędzi/curated/logiki backendu. ZERO frontu/modułu PS.
- NIE budować struktury półek (Q229 osobno). Hotfix behawioralny promptu.
- Nie ruszać innych sekcji (daty T-070, zwroty istniejące, serwis). PHP 8.4.

## Kryteria akceptacji
1. "ile dni na zwrot?" → **30 dni** (towar nieużywany), opcjonalnie 14 jako ustawowe min. NIE samym "14".
2. Odpowiedź o zwrotach: warunek "nieużywany" + link zwroty-produktow ZAWSZE (Q230a).
3. Pozostałe fakty zwrotów niezmienione.
4. SMOKE: "automat średnio-wysoka półka" (bez kwoty) → NIE najtańszy jako główny; zakres cen i/lub dopytanie o budżet.
5. Po podaniu budżetu → dopasowanie do kwoty, nie priority:1.
6. php -l clean.

## KROK FINALNY — deploy + raport + status + git
- Deploy standalone (CC sam, wzorzec T-070/T-076). STOP przed: raport php -l + kryteria, deploy, smoke oba scenariusze z 0b0eefe4.
- Raport: _instances/backend/handoff/CHAT-T-077_done.md. Status: dopisać CHAT-T-077 do 21_STATUS_PROJEKTU.md.
- Git: add per ścieżka (SystemPrompt.php, task); commit wg konwencji; push. Osobny "docs:" dla statusu.
