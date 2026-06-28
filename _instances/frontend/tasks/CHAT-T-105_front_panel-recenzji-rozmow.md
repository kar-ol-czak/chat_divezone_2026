# CHAT-T-105 (frontend/panel PS) — System recenzji rozmów: kolumna statusu + notatka + kontrolki

**Instancja:** frontend (panel admina PS, moduł `divezone_chat`)
**ADR:** ADR-102 | **Powiązane:** ADR-070 (panel PS = jedyny front admina)
**Status:** DO WYKONANIA — **START po dostarczeniu kontraktu API z CHAT-T-104.**
**Zależność:** kształt odpowiedzi endpointów `/api/admin/review` (CHAT-T-104, KROK 7).

## Kontekst

UI dla regularnego, delegowalnego przeglądu rozmów. Pracownik PS ma móc: filtrować listę po statusie, otworzyć rozmowę, przeczytać/edytować notatkę z zapisem, zmienić status i nadać werdykt. Tożsamość recenzenta bierzemy z sesji PS (`id_employee`) i wysyłamy w payloadzie zapisu.

Model (ADR-102): dwie osie — `status` (`nowy`/`do_weryfikacji`/`w_trakcie`/`zamkniety`) i `verdict` (`ok`/`problem_do_rozwiazania`/`problem_rozwiazany`). Pracownik nadaje `ok` lub `problem_do_rozwiazania` przy domykaniu; `problem_rozwiazany` ustawia Karol po fixie.

Miejsce: zakładka rozmów panelu PS (ADR-070, struktura 3-zakładkowa: Rozmowy domyślna). Lista rozmów + modal rozmowy już istnieją — rozszerzamy je, nie tworzymy nowego widoku.

## Zakres

1. **Lista rozmów — kolumna statusu + filtr.** Dodaj kolumnę `status` recenzji (badge: `do_weryfikacji` wyróżniony kolorem alarmowym, `zamkniety` neutralny, brak wiersza = puste/"—"). Filtr po statusie nad listą (domyślnie `do_weryfikacji`, pozwól na inne + "wszystkie"). Sort po `updated_at` recenzji malejąco gdy filtr aktywny. Dane z `GET /api/admin/review`.
2. **Modal rozmowy — panel recenzji.** Pod treścią rozmowy sekcja "Recenzja":
   - Pole notatki (textarea), edytowalne, z przyciskiem **Zapisz**. Pokaż `updated_by` (nazwa pracownika — mapowanie `id_employee→nazwa` po stronie PS z `pr_employee`) i `updated_at`.
   - Select `status` (4 wartości).
   - Select `verdict` (3 wartości; `problem_rozwiazany` widoczny ale z adnotacją "ustawia administrator po wdrożeniu fixu" — nie blokuj twardo, decyzja UX po stronie panelu).
   - Zapis: `POST /api/admin/review/:conversationId` z `status`, `verdict`, `note`, `id_employee` (z sesji PS).
3. **Tożsamość:** `id_employee` z sesji admina PS dołączany do każdego POST. NIGDY z inputu użytkownika.

## Kroki

**KROK 0 — pull/read.** `git pull`. Przeczytaj: `_docs/10_decyzje_projektowe.md` (ADR-102, ADR-070), raport CHAT-T-104 (kontrakt API — kształt odpowiedzi `GET /api/admin/review` i `POST /api/admin/review/:id`), istniejący kod listy rozmów i modala w module `divezone_chat` (panel PS). Jeśli kontraktu CHAT-T-104 brak — STOP, zgłoś Karolowi.

**KROK 1 — lista: kolumna + filtr.** Dodaj kolumnę statusu i kontrolkę filtra. Podłącz `GET /api/admin/review`. Obsłuż stan "brak wiersza recenzji".

**KROK 2 — modal: panel recenzji.** Dodaj sekcję Recenzja (notatka + Zapisz + status + verdict + metadane `updated_by`/`updated_at`). Mapowanie `id_employee→nazwa` przez `pr_employee` (klasa `Employee` PS lub zapytanie). Wzorzec zapisu `sendBeacon`/fetch jak istniejące zapisy panelu — uwaga na regułę: `sendBeacon` MUSI używać `Blob application/x-www-form-urlencoded` (LiteSpeed/ModSecurity blokuje `text/plain` 403).

**KROK 3 — walidacja UX.** Pusta notatka dozwolona (status sam bez notatki OK). Po zapisie odśwież badge na liście. Komunikat błędu gdy API zwróci 422 (zły enum) lub 401 (refresh tokenu — TTL 900s, `DIVECHAT_SECRET` klient vs `DIVECHAT_SERVER_SECRET` serwer; nie mieszać).

**KROK 4 — test ręczny.** Oznacz rozmowę → pojawia się na liście `do_weryfikacji`. Edytuj notatkę dwukrotnie → druga wersja nadpisuje, `updated_at` rośnie, `updated_by` = zalogowany pracownik. Zmień status na `zamkniety` + verdict → znika z domyślnego filtra.

**KROK 5 — upload modułu.** Moduł PS wgrywa Karol ręcznie (rsync port 5739, `~/public_html/newtmp2`, `--exclude config_pl.xml`, bez `--delete`). **NIE deployuj — przygotuj i STOP, Karol wgra.**

**KROK 6 — git + state.**
- `git status` (untracked).
- `git add` per ścieżka (pliki modułu zmienione) — NIE `git add .`, pomiń `.gitignore` i handoffy.
- Commit wg konwencji (`git log`): `CHAT-T-105 front: panel recenzji rozmow — kolumna statusu + notatka + werdykt (ADR-102)`.
- `git push origin main` (potwierdź branch).
- Osobny commit `docs:` ze statusem + task DONE.

**KROK 7 — raport.** Zwięźle: co dodane na liście i w modalu, jak rozwiązane mapowanie `id_employee→nazwa`, wynik testu ręcznego, info że moduł czeka na ręczny upload Karola.

## Wynik (2026-06-28)

**Status:** KOD GOTOWY, `php -l` clean. Moduł NIE wgrany — czeka na ręczny upload Karola (KROK 5, rsync port 5739 → `~/public_html/newtmp2`, `--exclude config_pl.xml`, bez `--delete`). Test ręczny (KROK 4) wykonuje Karol po uploadzie (wymaga żywego panelu PS).

Wszystko w JEDNYM pliku: `modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php` (zakładka „Rozmowy", render natywny PS, bez JS/iframe — wzorzec server-side full-reload jak reszta panelu; ZERO sendBeacon, więc reguła Blob/x-www-form-urlencoded nie dotyczy).

**Lista (KROK 1):**
- Nowy pasek filtra recenzji nad listą (`review_status`, GET form): 4 statusy + „— wszystkie rozmowy —". **Default = `do_weryfikacji`** (ADR-102 D3 — lista robocza). ⚠️ To zmienia domyślne lądowanie zakładki Rozmowy z „wszystkie czaty" na „kolejka do weryfikacji"; powrót do pełnej listy = 1 klik („wszystkie rozmowy").
- Tryb recenzji (status ≠ „wszystkie"): lista z `GET /api/admin/review?status=&limit=&offset=`, sort po `updated_at` recenzji DESC (backend), pozycje z badgem statusu (`do_weryfikacji` czerwony alarmowy, `zamkniety` szary neutralny, `nowy` niebieski, `w_trakcie` bursztyn) + chip werdyktu + liczba wiadomości. Paginacja przez `total`. Pusty stan: „Brak rozmów w tym statusie recenzji."
- Tryb „wszystkie": niezmieniona klasyczna lista `/api/conversations` (search/admin_status/knowledge_gap zachowane). Sentinel `review_status=wszystkie` przenoszony w linkach/formularzu, żeby klik/filtr nie wracał do domyślnego `do_weryfikacji`.
- Brak bulk-endpointu „recenzja per lista konwersacji" → badge recenzji pokazujemy tylko w trybie recenzji (kontrakt CHAT-T-104 nie ma takiego zapytania).

**Modal/detal (KROK 2):** sekcja „Recenzja" POD treścią rozmowy (oddzielona od starego formularza `admin_status` CHAT-T-046, który jest nad rozmową — dwa różne systemy, świadomie rozdzielone wizualnie):
- `conversation_id` (int) brany z `id` w odpowiedzi `/api/conversations/{sid}` (`getBySessionId` → `SELECT *`). Stan recenzji z dedykowanego `GET /api/admin/review/:convId` (`review=null` → stan „nowy" implicytny, D3).
- Notatka (textarea, pusta dozwolona), select `status` (4), select `werdykt` („— brak —" + 3, `problem_rozwiazany` z adnotacją „ustawia administrator po wdrożeniu fixu", bez twardej blokady), metadane `updated_by` (nazwa) + `updated_at`. Przycisk „Zapisz recenzję".
- Zapis: `POST /api/admin/review/:convId` z `{status, verdict, note, id_employee}`; `verdict=""`→null, `note=""`→czyści (kontrakt). Walidacja enumów lokalnie przed POST.

**Tożsamość (KROK 3):** `id_employee` ZAWSZE z sesji PS (`(int)$this->context->employee->id`, w `handleReviewSave` z param `$employeeId`), NIGDY z inputu. Wysyłany w body POST + w nagłówku HMAC kanału serwerowego (`callBackend`).

**Mapowanie `id_employee→nazwa`:** helper `employeeName($id)` przez klasę PS `Employee` (`firstname + lastname`, `Validate::isLoadedObject`), cache w obrębie requestu (`$employeeNameCache`), fallback `#<id>` gdy konto usunięte. Zgodne z ADR-102 pkt 5 (Railway trzyma tylko liczbę, PS mapuje przy wyświetlaniu).

**Walidacja UX (KROK 3):** `reviewErrorMessage()` mapuje 401 (token kanału, TTL 900s), 403 (no_role), 422 (zły enum, z `reason` z backendu), 400 (złe żądanie) na komunikaty PL we flashu. Po zapisie pełny reload → lista i badge odświeżone automatycznie (np. zmiana na `zamkniety` znika z domyślnego filtra `do_weryfikacji`).
