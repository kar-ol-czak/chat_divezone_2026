# CHAT-T-149 — `search_diagnostics` akumuluje tury zamiast je nadpisywać

**Instancja:** backend
**ADR:** ADR-127 (decyzje Karola 134a, 135a)
**Świat wdrożenia:** BACKEND standalone (`chat.divezone.pl`). **ZERO migracji PG. Moduł PS bez zmian.**

---

## Problem

`search_diagnostics` trzyma **migawkę ostatniej tury**, nie historię rozmowy. Zmierzone: **86 z 277 rozmów** ma mniej wywołań w diagnostyce, niż faktycznie było — **200 wywołań bez śladu**. To zmusiło do wyłączenia tych 86 z migracji 042 (ADR-126 nota nr 1, decyzja 130b).

**Mechanizm (zweryfikowany w kodzie):**
- `ChatService.php:129` — `$searchDiagnostics = []` startuje **puste przy każdej turze**
- `ChatService.php:276` — dopisuje tylko wywołania bieżącej tury
- `ChatService.php:372` → `ConversationStore.php:188` — `SET search_diagnostics = ?::jsonb` **nadpisuje kolumnę w całości**

Kod **nigdy nie czyta poprzedniej wartości**.

**Asymetria do usunięcia:** `knowledge_gap` w tej samej instrukcji `UPDATE` (`:189`) jest **sticky** — `? OR COALESCE(knowledge_gap, false)`, czyli dokłada. Diagnostyka linijkę wyżej nadpisuje. Robimy je spójnymi.

---

## KROK 0 — czytaj przed pierwszą zmianą

1. `git pull --rebase origin main`
2. **ADR-127** w `_docs/10_decyzje_projektowe.md` (na końcu pliku) — całość.
3. `_docs/44_slownik_pol_i_metryk.md` — sekcja PUŁAPKI (pozycja o `search_diagnostics`).
4. `standalone/src/Chat/ConversationStore.php:168-205` (`save()`).

## KROK 1 — zmiana (jedna linia)

Plik: `standalone/src/Chat/ConversationStore.php`, metoda `save()`, linia ~188.

**Z:**
```sql
search_diagnostics = ?::jsonb,
```
**Na:**
```sql
search_diagnostics = COALESCE(search_diagnostics, '[]'::jsonb) || ?::jsonb,
```

**Wymagania:**
- **Kolejność parametrów w tablicy `$params` bez zmian** — `||` nie zmienia liczby placeholderów. Sprawdź to świadomie: przesunięcie parametru = ciche zepsucie zapisu wszystkich pól.
- **NIE ruszaj** sticky OR dla `knowledge_gap` (`:189`) — jest poprawny i zostaje wzorem.
- **NIE ruszaj** `ChatService.php` — `$searchDiagnostics = []` na `:129` **zostaje**. Ma zawierać tylko bieżącą turę; akumulacja dzieje się w SQL. To jest sedno decyzji 134a.
- Komentarz nad linią: jedno zdanie + `ADR-127`.
- PSR-12, PHP 8.4 (`ea-php84`).

## KROK 2 — test lokalny PRZED deployem

Sprawdź na kopii/testowo, że dwa kolejne `save()` dla tego samego `session_id` dają tablicę **2-elementową**, nie 1. Jeśli nie masz jak — powiedz wprost w raporcie, nie zmyślaj testu.

`ea-php84 -l` na zmienionym pliku.

## KROK 3 — STOP. Czekaj na `deployuj` (ADR-089).

Zaraportuj: diff (powinien być 1-2 linie), wynik `php -l`, wynik testu z KROKU 2 albo informację, że się nie dało.

## KROK 4 — deploy (dopiero po autoryzacji)

**UWAGA — wnioski z CHAT-T-148, przeczytaj zanim ruszysz `rsync`:**
- **NIE rób blanket-rsync `standalone/`.** Repo ma dryf wobec produkcji: `config/tools.php` (rozjazd R-5, wypchnięcie = **fatal 500**) i `config/routes.php` (niezacommitowana zmiana innej sesji).
- **Zakres tasku = 1 plik.** Wypchnij **tylko** `src/Chat/ConversationStore.php`.
- Poprzednio CC słusznie odstąpiło od blanket-rsync — tu jest to **wpisane w task**, nie odstępstwo.

1. Backup `_deploy_bak/CHAT-T-149_<timestamp>/` (plik przed zmianą + md5 prod przed).
2. `rsync` **jednego pliku** → `chat.divezone.pl/src/Chat/ConversationStore.php` (port 5739). **Na serwerze NIE MA prefiksu `standalone/`.**
3. `md5` local ↔ prod.
4. `ea-php84 -l` na **wdrożonym** pliku.
5. Smoke `/api/health`.

**Bez `pg_dump`** — zmiana nie dotyka istniejących danych, tylko sposobu zapisu nowych. Rollback = przywrócenie pliku z `_deploy_bak/`.

## KROK 5 — weryfikacja (to jest sedno tasku, nie formalność)

**Test wieloturowy — obowiązkowy:**
1. Nowa rozmowa, **tura 1**: pytanie produktowe (np. „Shearwater Perdix 3"). Sprawdź: `jsonb_array_length(search_diagnostics)` = 1.
2. **Tura 2** w **tej samej** rozmowie: inne pytanie wyszukujące (np. „latarka nurkowa"). Sprawdź: `jsonb_array_length(search_diagnostics)` = **2**, i że **pierwsze wywołanie nadal tam jest** (`query_text` z tury 1).

**Dziś ten test daje 1 — to jest dowód, że naprawa działa.**

3. Kontrola spójności na nowych rozmowach: `jsonb_array_length(search_diagnostics)` = liczba `messages[].tool_calls[]` o nazwach `search_products`/`get_expert_knowledge`. Dokładnie ten test wykrył dług.
4. Sprawdź, że `knowledge_gap` nadal działa (rozmowa z trafnym produktem → `false`).
5. Rozmowy testowe oznacz `[test CHAT-T-149, nie klient]`, `verdict`/`updated_by` NULL.

**Parser `messages`:** format to `tool_calls[]`, **NIE** `content[].type='tool_use'`. Zły parser zwraca **0 i test przechodzi fałszywie**. Użyj `COALESCE(m->'tool_calls','[]'::jsonb)` — wiadomości `user`/`tool_result` nie mają tego klucza.

## KROK 6 — git

`git pull --rebase origin main` → `git status` (sprawdź, czy w indeksie nie ma cudzych zmian — okna CC pracują równolegle) → `git add` **per ścieżka**, NIGDY `git add .`:
```
standalone/src/Chat/ConversationStore.php
_instances/backend/tasks/CHAT-T-149_backend_akumulacja-search-diagnostics.md
```
Commit: `CHAT-T-149 backend: search_diagnostics akumuluje tury (ADR-127)`
`git push origin main`

## KROK 7 — status + raport

`_docs/21_STATUS_PROJEKTU.md` — dopisz **NA GÓRZE**, nie nadpisuj. Osobny commit `docs:`.

Raport: diff, md5 local↔prod, `php -l`, smoke, **wynik testu wieloturowego z liczbami** (długość tablicy po turze 1 i po turze 2).

---

## NIE RUSZAJ

- **`ChatService.php`** — `$searchDiagnostics = []` na `:129` zostaje. Akumulacja dzieje się w SQL, nie w PHP (decyzja 134a).
- **Sticky OR `knowledge_gap`** (`ConversationStore.php:189`) — poprawny, jest wzorem dla tej zmiany.
- **`modules/`** (świat PS) — panel renderuje `<pre>` z `overflow:auto`, dłuższa tablica po prostu scrolluje. Zero zmian.
- **`config/tools.php`, `config/routes.php`** — dryf/cudze zmiany, wypchnięcie = fatal 500.
- **Historia rozmów** — decyzja 135a: nie odtwarzamy. Żadnych `UPDATE` na istniejących danych.
- ADR-y (pisze architekt), `CLAUDE.md`, `_docs/44`, karty Trello.
- `_ops/newtmp2_root/purge_litespeed.php` (**zawiera sekret**).

---

## Wynik

**Status: DONE** (2026-07-17, instancja backend). Decyzje: 134a (`||` w SQL), 135a (bez odtwarzania historii).

### Zmiana (1 plik, 1 linia + komentarz)
`standalone/src/Chat/ConversationStore.php`, `save()` ~188:
```diff
-                 search_diagnostics = ?::jsonb,
+                 -- Akumulacja tur: dokladamy wywolania biezacej tury zamiast nadpisywac
+                 -- kolumne (ADR-127) — symetrycznie do sticky OR ponizej.
+                 search_diagnostics = COALESCE(search_diagnostics, \'[]\'::jsonb) || ?::jsonb,
```
**Kolejność parametrów bez zmian** — `COALESCE(kolumna, '[]'::jsonb)` nie wnosi placeholderów, `|| ?::jsonb` wnosi ten sam jeden: nadal 7 placeholderów, `search_diagnostics` na pozycji 5. Zweryfikowane empirycznie (kontrola przesunięcia w teście). `ChatService.php` nietknięty (`$searchDiagnostics = []` na `:129` zostaje — akumulacja w SQL, 134a), sticky OR `:189` nietknięty, **zero migracji PG**, moduł PS bez zmian.

**Założenie zweryfikowane przed zmianą:** `save()` ma **jedno** wywołanie w całym `src/` (`ChatService.php:372`), na końcu tury poza pętlą narzędzi — gdyby wołało się kilka razy na turę, `||` dublowałby wpisy.

### Test lokalny (realny `save()`, nie symulacja SQL)
`BEGIN`/`ROLLBACK` na tym samym połączeniu (singleton) → zero śladu w danych (135a):

| krok | długość | oczek. |
|---|---|---|
| start (przed `save`) | NULL | — |
| po turze 1 | 1 | 1 ✅ |
| **po turze 2** | **2** | **2 ✅ ← sedno** |
| po turze 3 (pusta diagnostyka) | 2 | 2 ✅ (nie kasuje) |

Kolejność: `TURA1_Shearwater -> TURA2_latarka`; `knowledge_gap` sticky przetrwał turę 3; `model_used`/`tools_used`/`messages` zapisane poprawnie (brak przesunięcia parametrów); po `ROLLBACK` 0 wierszy testowych.

### Deploy (`chat.divezone.pl`)
- Backup: `_deploy_bak/CHAT-T-149_20260717_100248/src/Chat/ConversationStore.php` (md5 prod przed: `5e3c75d1cfbe8c0b2e1cd8135ae71634`) — **to jest rollback** (bez `pg_dump`, dane nietknięte).
- rsync **jednego pliku** `src/Chat/ConversationStore.php` (NIE `standalone/` — dryf `config/tools.php` = fatal 500, `config/routes.php` = zmiana innej sesji).
- md5 local ↔ prod: **`372fadc7185c6ccdb2e422fd4d0af46c`** zgodne. `ea-php84 -l`: *No syntax errors detected*. Markery `ADR-127` + `COALESCE(search_diagnostics` obecne na prod.
- Smoke `/api/health`: **200** (`php 8.4.23`, postgres+mysql true).

### KROK 5 — test wieloturowy NA PRODUKCJI (sedno)
Rozmowa `eabbe0bb-59e4-400e-9f3b-75ad727daa39`:

| tura | zapytanie | `jsonb_array_length` | `query_texts` |
|---|---|---|---|
| 1 | „Shearwater Perdix 3" | **1** | `Shearwater Perdix 3` |
| 2 (ta sama rozmowa) | „A pokaż mi teraz latarki nurkowe" | **2** ✅ | `Shearwater Perdix 3 -> latarka nurkowa` |

**Wpis z tury 1 nadal obecny.** Przed naprawą ta sama sekwencja dawała 1 — to jest dowód naprawy.

**Kontrola spójności:** `jsonb_array_length` = **2** == liczba `messages[].tool_calls[]` o nazwach `search_products`/`get_expert_knowledge` = **2** → ZGODNE. Sanity: wszystkich `tool_calls` = 3 (w tym `get_popular_products`, nie-wyszukujące) — dowód, że parser nie zwraca fałszywego zera (pułapka z `_docs/44`).

**`knowledge_gap` nadal działa:** trafny produkt → **`false`** (naprawa ADR-126 nietknięta).

**Reguła E:** rozmowa oznaczona `[test CHAT-T-149, nie klient]`, `verdict`/`updated_by` NULL.
