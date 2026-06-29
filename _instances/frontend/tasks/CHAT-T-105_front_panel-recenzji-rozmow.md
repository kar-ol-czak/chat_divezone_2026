# CHAT-T-105 (frontend/panel PS) — System recenzji rozmów: kolumna statusu + notatka + kontrolki

**Instancja:** frontend (panel admina PS, moduł `divezone_chat`)
**ADR:** ADR-102 | **Powiązane:** ADR-070 (panel PS = jedyny front admina)
**Status:** KOD GOTOWY (commit 86c75de na main, php -l clean), MODUŁ NIE WGRANY — czeka na ręczny upload Karola + test ręczny KROK 4. Kontrakt API z CHAT-T-104 DOSTARCZONY (niżej), backend wdrożony.
**Zależność:** SPEŁNIONA — endpointy `/api/admin/review` działają na produkcji (zweryfikowane 2026-06-29).

## Kontrakt API (CHAT-T-104, zweryfikowany na prod 2026-06-29)

Enumy (muszą pasować do CHECK constraint migracji 037):
- `status` (oś pracy): `nowy` / `do_weryfikacji` / `w_trakcie` / `zamkniety`. DEFAULT = `do_weryfikacji`.
- `verdict` (oś jakości): `ok` / `problem_do_rozwiazania` / `problem_rozwiazany`. NULL dopóki recenzent nie domknie.

Endpointy (kanał uwierzytelniony `DIVECHAT_SERVER_SECRET`, jak reszta admin API):
- `GET /api/admin/review?status=&limit=&offset=` — lista rozmów o danym statusie (default `do_weryfikacji`), sort malejąco po `updated_at`, paginacja. ZAWSZE zwraca też `counts` (liczniki per status, CHAT-T-106) niezależnie od filtra. 422 przy złym enumie statusu.
- `GET /api/admin/review/{conversationId}` — pojedyncza recenzja.
- `POST /api/admin/review/{conversationId}` — upsert (`status?`, `verdict?`, `note?`, `id_employee`). Pola opcjonalne (można zmienić sam status bez notatki).

UWAGA: panel obecnie pokazuje STARY dropdown z CHAT-T-048 (`new/reviewed/knowledge_created/ignored`, endpoint `/api/conversations/{sid}/status`). CHAT-T-105 ma go ZASTĄPIĆ dwuosiowym review wołającym `/api/admin/review`. Stary mechanizm CHAT-T-048 → do usunięcia/zastąpienia (nie zostawiać obok — pomieszanie osi, którego ADR-102 świadomie unika).

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

## Wynik (2026-06-28, iter.2 2026-06-29)

**Status:** KOD GOTOWY, `php -l` clean (commity `86c75de` + `83fbea2` + `75f2940`). Moduł NIE wgrany — czeka na ręczny upload Karola (KROK 5, rsync port 5739 → `~/public_html/newtmp2`, `--exclude config_pl.xml`, bez `--delete`). Granica 116b/ADR-089: CC NIE dotyka żywego docrootu PS bez explicit zgody per plik. Test ręczny (KROK 4) wykonuje Karol po uploadzie (wymaga żywego panelu PS).

**iter.2 (commit `75f2940`) — usunięcie starego jednoosiowego statusu CHAT-T-048** (wg UWAGA w nagłówku tasku, ADR-102 unika mieszania osi): usunięty formularz `admin_status` w modalu (`renderConvStatusForm`) + handler + dispatch + wiersz „Status" w meta + `POST /api/conversations/{sid}/status`; usunięty filtr `admin_status` i badge statusu na liście + martwe `convStatusOptions`/`renderStatusBadge`/CSS `.dz-status-*`. Zostaje: wyszukiwarka + filtr luk wiedzy (sygnał niezależny od osi recenzji) + dwuosiowy panel recenzji. `counts` per status (CHAT-T-106) NIE użyte — to osobny task.

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
