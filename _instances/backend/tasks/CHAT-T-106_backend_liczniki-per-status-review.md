# CHAT-T-106 (backend) — Liczniki per status dla panelu recenzji (`counts` w GET /api/admin/review)

**Instancja:** backend (PHP, standalone)
**ADR:** ADR-102 (realizacja, bez zmiany ADR) | **Powiązane:** CHAT-T-104 (endpointy review), CHAT-T-107 (frontend lifting — konsument)
**Status:** DONE / ZDEPLOYOWANY (2026-06-28, commit a8bd5f3)
**Decyzje wizualne (kontekst):** 25c — licznik przy KAŻDYM statusie + osobny alarm na do_weryfikacji. Ten task dostarcza dane (liczności), CHAT-T-107 je renderuje.

## Kontekst

Segmentowany przełącznik stanów w panelu recenzji (CHAT-T-107) ma pokazać licznik na każdym segmencie (do_weryfikacji / w_trakcie / zamkniety / nowy). Obecny `GET /api/admin/review` zwraca `total` TYLKO dla aktualnie filtrowanego statusu, więc front nie ma skąd wziąć liczb dla pozostałych segmentów bez N zapytań. Ten task dokłada jedno `GROUP BY status`, zwracane niezależnie od aktywnego filtra.

**Granica semantyczna (ważne, z ADR-102 D3):** stan „nowy" jest implicytny dla rozmów BEZ wiersza recenzji. Liczniki liczą WYŁĄCZNIE wiersze istniejące w `divechat_conversation_review`, więc licznik `nowy` = rozmowy z jawnie nadanym `status='nowy'`, NIE wszystkie nierecenzowane rozmowy w katalogu. To poprawne i spójne z modelem (liczniki = stan KOLEJKI recenzji, nie całego katalogu rozmów). NIE dolicza rozmów bez wiersza. Gdyby w przyszłości potrzebny był „ile w ogóle nierecenzowanych" — to osobna liczba (COUNT rozmów bez wiersza), NIE w tym tasku.

## Zakres

Rozszerz `GET /api/admin/review` o pole `counts` w odpowiedzi:
```
{ items:[...], total, limit, offset, counts: { do_weryfikacji:N, w_trakcie:N, zamkniety:N, nowy:N } }
```
- `counts` liczone JEDNYM zapytaniem `SELECT status, COUNT(*) FROM divechat_conversation_review GROUP BY status`.
- Zwracane ZAWSZE, niezależnie od parametru `status` (segmenty pokazują pełny obraz, nawet gdy patrzysz na jeden).
- Statusy bez wierszy zwracają 0 (nie pomijaj klucza — front oczekuje wszystkich czterech, żeby segment pokazał „0", a nie zniknął).
- `total` zostaje bez zmian (liczność aktualnie filtrowanego widoku, dla paginacji).

## Kroki

**KROK 0 — pull/read.** `git pull`. Przeczytaj: raport CHAT-T-104 (kontrakt `GET /api/admin/review`), `src/Admin/ConversationReviewRepository.php` (metoda `listByStatus`), kontroler admina review, `config/routes.php`. ADR-102 D3 (stan implicytny „nowy").

**KROK 1 — repo: metoda counts.** Dodaj `countsByStatus(): array` w `ConversationReviewRepository` — jedno `GROUP BY status`, zwraca mapę z gwarantowanymi czterema kluczami (brakujące → 0). Walidacja: klucze ograniczone do dozwolonej listy statusów (gdyby w bazie pojawił się nieznany status, nie przeciekaj go do API — albo loguj, albo pomiń wg istniejącej walidacji enumów).

**KROK 2 — kontroler: dołącz counts.** W handlerze `GET /api/admin/review` dołóż `counts` do odpowiedzi obok `items/total/limit/offset`. Jedno dodatkowe wywołanie repo, bez zmiany istniejącej logiki listy/filtra/sortu.

**KROK 3 — test.** Test jednostkowy `countsByStatus` (suma counts == liczba wierszy w tabeli; brakujący status → 0; nieznany status nie przecieka). Real-path: potwierdź przez `Config::load()→$_ENV→PDO` na Railway (ADR-088 + UZUPEŁNIENIE — nazwy kluczy `.env` bez myślników, weryfikacja tylko realną ścieżką). Smoke: `GET /api/admin/review` zwraca `counts` z czterema kluczami.

**KROK 4 — deploy.** ADR-089: backup zmienianych plików do `_deploy_bak/CHAT-T-106/`, rsync per ścieżka (repo + kontroler; testy nie idą na prod), md5 match, `php -l` ea-php84, smoke `/api/health` 200 + `GET /api/admin/review` zawiera `counts`. **STOP przed rsync — czekaj na zgodę Karola.**

**KROK 5 — git + state.**
- `git status` (untracked).
- `git add` per ścieżka (repo, kontroler, test) — NIE `git add .`, pomiń `.gitignore`.
- Commit wg konwencji (`git log`): `CHAT-T-106 backend: liczniki per status w GET /api/admin/review (counts, GROUP BY) — dane dla segmentowanego przelacznika CHAT-T-107 (ADR-102)`.
- `git push origin main`.
- Po deploy: osobny commit `docs:` ze statusem (bump wersji) + task DONE.

**KROK 6 — raport.** Zwięźle: kształt `counts` w odpowiedzi (kontrakt dla CHAT-T-107), potwierdzenie granicy semantycznej (nowy = jawny, nie implicytny), wynik real-path, status deploy.

## Wynik (2026-06-28)

**Kontrakt dla CHAT-T-107** — `GET /api/admin/review` zwraca dodatkowe pole `counts`:
```
{ items:[...], total, limit, offset, counts: { nowy:N, do_weryfikacji:N, w_trakcie:N, zamkniety:N } }
```
- `counts` ZAWSZE (niezależnie od parametru `status`), zawsze KOMPLET 4 kluczy (status bez wierszy → 0).
- `total` bez zmian (liczność aktualnie filtrowanego widoku, paginacja).

**Granica semantyczna (ADR-102 D3) — potwierdzona:** licznik `nowy` = rozmowy z JAWNYM `status='nowy'`, NIE rozmowy bez wiersza recenzji. Liczniki = stan KOLEJKI recenzji (`COUNT` istniejących wierszy `divechat_conversation_review`), nie całego katalogu rozmów. „Ile w ogóle nierecenzowanych" = osobna, przyszła liczba (poza tym taskiem).

**Implementacja:** `ConversationReviewRepository::countsByStatus()` — jedno `GROUP BY status`; klucze seedowane z `ReviewStatus::cases()` (jedno źródło prawdy z enumem); nieznany status w bazie logowany (`error_log`) i pomijany (nie przecieka do API). Kontroler: jedno dodatkowe wywołanie repo w `list`, bez zmiany logiki listy/filtra/sortu.

**Real-path / testy:** `tests/Admin/ConversationReviewCountsTest.php` **11/11** PASS na żywej Railway przez `Config::load()` (po naprawie nazwy klucza `.env` — `DATAFORSEO_API_PASSWORD-BASE64`→`_BASE64`; real-path znów działa bez ręcznego obejścia). Regresja CHAT-T-104 30/30.

**Deploy (ADR-089):** pre-check istnienia plików CHAT-T-104 na serwerze (oba `-s`, niepuste) → backup `_deploy_bak/CHAT-T-106/` (md5 .bak==źródło) → rsync 2 pliki runtime (test NIE na prod) → md5 prod==local 2/2 → `php -l` clean 2/2 → `/api/health` 200 → uwierzytelniony smoke: `counts` z 4 kluczami (`{nowy:0,do_weryfikacji:0,w_trakcie:0,zamkniety:0}`, total=0). Commit `a8bd5f3` push origin main.

**⚠️ Incydent infra (niezależny od tego taska):** w trakcie smoke serwer↔Railway migocze — endpointy bijące Railway PG zwracają intermittent 500/503 (`SQLSTATE[08006] could not connect … :14368`, crash w `requireAnyRole`). Dotyczy WSZYSTKICH Railway-endpointów (nudge-ctr 500, chip-tree 503, review 500/200 naprzemiennie), nie tylko nowego kodu; lokalny dostęp do Railway OK. Rollback NIE wykonany (nic by nie naprawił — stary kod też bije Railway w auth). Do osobnej diagnozy stabilności połączenia.
