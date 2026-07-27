# CHAT-T-104 (backend) — System recenzji rozmów: tabela + endpointy `/api/admin/review`

**Instancja:** backend (PHP, standalone)
**ADR:** ADR-102 | **Powiązane:** ADR-070, ADR-088, ADR-089
**Status:** DONE / ZDEPLOYOWANY (2026-06-28, commit 26647dc)
**Kontrakt dla:** CHAT-T-105 (frontend/panel PS) — zależy od kształtu odpowiedzi endpointów z tego taska.

## Kontekst

Narzędzie do regularnego, delegowalnego przeglądu rozmów z botem. Dziś przegląd jest ręczny (kopiowanie ID), więc rzadki, więc błędy bota żyją tygodniami. Ten task buduje warstwę danych i API. Frontend (panel PS) w osobnym tasku.

Model (ADR-102): dwie osie — `status` (praca) i `verdict` (jakość). Stan domyślny = brak wiersza. Wiersz powstaje przy pierwszej akcji recenzenta. Tożsamość recenzenta = `id_employee` z sesji PS, wysyłany w payloadzie.

Istniejące byty do zaczepienia (NIE twórz równoległych):
- Tabela rozmów: `divechat_conversations` (Railway PG).
- `standalone/src/Admin/ConversationViewer.php` — metoda `get(int $conversationId)` zwraca pełną rozmowę do modala. Rozszerz o blok `review`.
- Endpointy admina pod `/api/admin/*`, routing w `standalone/config/routes.php`. Wzór istniejący: `/api/admin/nudge-ctr`, `/api/admin/conversations/:id`.
- Migracje: katalog migracji jak dla 026/027/035/036 (sprawdź faktyczną lokalizację i najwyższy numer w KROK 1).

## Model danych (ADR-102 pkt 1–4)

Tabela `divechat_conversation_review`:
- `id` (PK)
- `conversation_id` (FK → `divechat_conversations.id`, UNIQUE — jeden wiersz recenzji na rozmowę)
- `status` — enum tekstowy: `nowy` / `do_weryfikacji` / `w_trakcie` / `zamkniety`. Default `do_weryfikacji` przy tworzeniu przez flagowanie.
- `verdict` — enum tekstowy nullable: `ok` / `problem_do_rozwiazania` / `problem_rozwiazany`. NULL dopóki recenzent nie domknie.
- `note` — TEXT nullable (pojedyncze pole, nadpisywane).
- `updated_by` — INT nullable (`id_employee` z PS).
- `created_at`, `updated_at` — timestamptz.

Walidacja wartości `status`/`verdict` w warstwie aplikacji (CHECK constraint dozwolony dodatkowo). Brak wiersza = stan "nowy" implicytny (NIE seedować wierszy dla istniejących rozmów).

## Kontrakt API (dla CHAT-T-105)

1. `GET /api/admin/review?status=do_weryfikacji&limit=&offset=` — lista rozmów z wpisem recenzji o danym statusie (default `do_weryfikacji`), sort malejąco po `updated_at`. Zwraca: `conversation_id`, `status`, `verdict`, `updated_by`, `updated_at`, skrót rozmowy (np. `started_at`, `model_used`, liczba wiadomości lub pierwsza wiadomość użytkownika — wybierz lekki zestaw do listy). Paginacja.
2. `GET /api/admin/review/:conversationId` — pełny stan recenzji jednej rozmowy (cały wiersz albo `null` gdy brak). Może być scalony z rozszerzeniem `ConversationViewer::get()` (blok `review`) zamiast osobnego endpointu — wybierz spójniej z istniejącym `/api/admin/conversations/:id` i UDOKUMENTUJ wybór w nagłówku odpowiedzi/PR.
3. `POST /api/admin/review/:conversationId` — upsert recenzji. Body: `status?`, `verdict?`, `note?`, `id_employee` (wymagany). Tworzy wiersz jeśli nie istnieje (status default `do_weryfikacji` gdy nie podano), w przeciwnym razie aktualizuje podane pola. Ustawia `updated_by=id_employee`, `updated_at=now()`. Walidacja enumów — odrzuć nieznane wartości 422.

Uwierzytelnienie: kanał serwerowy `DIVECHAT_SERVER_SECRET` jak pozostałe `/api/admin/*`. `id_employee` z payloadu jest zaufany (D2 — nie weryfikujemy w backendzie, PS go dostarcza z sesji).

## Kroki

**KROK 0 — pull/read.** `git pull`. Przeczytaj: `_docs/10_decyzje_projektowe.md` (ADR-102, ADR-070, ADR-089), `standalone/src/Admin/ConversationViewer.php`, `standalone/config/routes.php`, `standalone/src/Router.php`. Znajdź katalog migracji i najwyższy numer migracji.

**KROK 1 — migracja tabeli.** Utwórz migrację `divechat_conversation_review` wg modelu wyżej (kolejny numer w sekwencji — podaj jaki). **STOP: przedstaw Karolowi numer migracji + DDL + potwierdź target Railway przed uruchomieniem.** Nie uruchamiaj migracji na Railway bez zgody.

**KROK 2 — warstwa dostępu.** Klasa repo (np. `src/Admin/ConversationReviewRepository.php`): `getByConversation(int):?array`, `listByStatus(string,int,int):array`, `upsert(int $conversationId, array $fields, int $idEmployee):array`. Walidacja enumów w jednym miejscu (np. `Enum`).

**KROK 3 — endpointy + routing.** Dodaj trasy w `config/routes.php` i handlery (kontroler admina). Zaimplementuj kontrakt API wyżej. Rozszerz `ConversationViewer::get()` o blok `review` (lub osobny GET — decyzja w KROK 0, udokumentuj).

**KROK 4 — testy.** Testy jednostkowe repo (upsert tworzy/aktualizuje, walidacja enumów odrzuca śmieci, brak wiersza → null). Real-path: potwierdź połączenie przez `Config::load()→$_ENV→PDO` na Railway (ADR-088 — nigdy przez CLI/odczyt pliku). Smoke `/api/health`.

**KROK 5 — deploy.** Wg ADR-089: rsync `standalone/` → serwer, backup do `_deploy_bak/`, md5, `php -l`, smoke `/api/health`. **STOP przed rsync — czekaj na zgodę Karola.**

**KROK 6 — git + state.**
- `git status` (wylistuj untracked).
- `git add` per ścieżka (migracja, repo, kontroler, routes, viewer, testy) — NIE `git add .`, pomiń `.gitignore`.
- Commit wg konwencji (sprawdź `git log`): `CHAT-T-104 backend: system recenzji rozmow — tabela divechat_conversation_review + endpointy /api/admin/review (ADR-102)`.
- `git push origin main` (potwierdź branch w `git log`).
- Po deploy: osobny commit `docs:` ze statusem projektu (bump wersji statusu) + oznacz task jako DONE/zdeployowany.

**KROK 7 — raport.** Zwięźle: numer migracji, lista endpointów z kształtem odpowiedzi (kontrakt dla CHAT-T-105), decyzja osobny GET vs blok w ConversationViewer, wynik real-path, status deploy.

## Wynik (2026-06-28)

**Migracja:** `037` (`sql/037_conversation_review.sql` + rollback). Zastosowana na Railway real-path (Config→$_ENV→PDO, ADR-088). Tabela `divechat_conversation_review`: `id` PK, `conversation_id` INTEGER FK→`divechat_conversations` ON DELETE CASCADE + UNIQUE, `status` TEXT default `do_weryfikacji`, `verdict` TEXT NULL, `note` TEXT NULL, `updated_by` INT NULL, `created_at`/`updated_at` timestamptz; CHECK na status/verdict; indeks `(status, updated_at DESC)`. 0 wierszy seedu (D3).

**Endpointy** (kanał serwerowy `DIVECHAT_SERVER_SECRET` + DOWOLNA rola operator+admin), kontrakt dla CHAT-T-105:
- `GET /api/admin/review?status=&limit=&offset=` → `{ items: [{conversation_id, status, verdict, updated_by, updated_at, session_id, started_at, model_used, message_count, first_user_message}], total, limit, offset }`. Default `status=do_weryfikacji`, sort `updated_at DESC`, limit 1..200 (default 50). Nieznany status filtra → 422.
- `GET /api/admin/review/:conversationId` → `{ conversation_id, review: {id, conversation_id, status, verdict, note, updated_by, created_at, updated_at} | null }`. `review=null` = brak wiersza (stan „nowy" implicytny, D3) — NIE 404.
- `POST /api/admin/review/:conversationId` body `{ status?, verdict?, note?, id_employee }` → `{ conversation_id, review: {...} }`. Tworzy wiersz (status default `do_weryfikacji`) lub aktualizuje TYLKO podane pola; zawsze `updated_by=id_employee`, `updated_at=now()`. `id_employee` wymagany (400 gdy brak/nie-int, zaufany D2). Nieznana wartość enum status/verdict → 422. `verdict:null`/`note:null`/`note:""` → czyści pole.

Enumy: `status` ∈ {nowy, do_weryfikacji, w_trakcie, zamkniety}; `verdict` ∈ {ok, problem_do_rozwiazania, problem_rozwiazany} | null.

**Decyzja osobny GET vs blok w ConversationViewer:** dedykowany `GET /api/admin/review/:id` = kanoniczny kontrakt (spójny z rodziną `/api/admin/review/*`, odsprzęgnięty od wygaszanego Basic-Auth `/api/admin/conversations/:id`, decyzja 109a). DODATKOWO `ConversationViewer::get()` zwraca pole `review` (mirror — modal pełnej rozmowy też ma stan recenzji).

**Pliki:** `sql/037_conversation_review.sql`(+rollback), `standalone/src/Enum/ReviewStatus.php`, `ReviewVerdict.php`, `standalone/src/Admin/ConversationReviewRepository.php`, `InvalidReviewValueException.php`, `standalone/src/Admin/ConversationViewer.php`(mod), `standalone/src/Controller/AdminConversationReviewController.php`, `standalone/config/routes.php`(mod), `standalone/tests/Admin/ConversationReviewRepositoryTest.php`.

**Real-path / testy:** 30/30 PASS na żywych danych Railway (PostgresConnection, ADR-088). Test tworzy własną jednorazową rozmowę i sprząta przez cascade.

**Deploy (ADR-089):** backup `_deploy_bak/CHAT-T-104/` → rsync per ścieżka 7 plików runtime (testy+sql NIE na prod) → md5 prod==local 7/7 → `php -l` ea-php84 clean 7/7 → `/api/health` 200 → smoke `/api/admin/review` + `/review/:id` → 401 (route+auth OK), `/api/chip-event` → 404 (CHAT-T-090 świadomie niewdrożony). Commit `26647dc` push origin main.

**Numeracja migracji (dot. CHAT-T-090, nie blokuje):** `034_chip_events` NIE zastosowana na Railway (`divechat_chip_events` nieobecna) — to LUKA (CHAT-T-090 niedokończony), NIE kolizja numeracji. Sekwencja monotoniczna 034(luka) < 035/036 < 037.
