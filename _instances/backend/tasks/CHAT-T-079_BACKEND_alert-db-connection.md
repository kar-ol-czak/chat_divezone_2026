# CHAT-T-079 — BACKEND: Alert na awarię połączenia z bazą danych (DbHealthAlert)

**Status:** DO WYKONANIA
**Instancja:** backend (standalone, chat.divezone.pl)
**Priorytet:** P1 (dług po incydencie 1045 — ADR-088)
**Powiązane:** ADR-088 (incydent 1045, ~18h niedostępności produktów niezauważonej), CHAT-T-064 / CostGuard (wzorzec alertu do skopiowania), CHAT-T-067 (odczyt e-mail z SettingsStore + fallback .env)

---

## KONTEKST

2026-06-06 ~16:25 → 2026-06-07 rano czat na KAŻDE pytanie produktowe zwracał komunikat zastępczy, bo `search_products` dostawał z MySQL `SQLSTATE[HY000] [1045] Access denied` (rozjazd hasła `.env` ↔ `parameters.php` po rotacji). Model zachował się poprawnie (nie halucynował cen). PROBLEM: awaria trwała ~18h NIEZAUWAŻONA — nikt nie dostał sygnału, że DB padła. Ten task dokłada alert, który zamienia 18h ciszy w minuty.

Decyzje projektowe (z rozmowy, do realizacji 1:1):
- **245b** — dedup alertu w oknie czasowym **30 minut** (nie dobowy jak CostGuard; awaria infra wymaga szybszego ponowienia po nawrocie).
- **246a** — alert TYLKO przy błędach połączenia/dostępu do bazy (awaria infra), NIE przy błędach logicznych (np. `42703` zła kolumna) ani pustych wynikach.
- **247a** — osobna klasa `DbHealthAlert` (wzorzec 1:1 z `CostGuard`) + osobna tabela `divechat_db_alerts`. NIE rozszerzać CostGuard/divechat_cost_alerts.
- **248a** — kanał: `mail()` na adres z SettingsStore + fallback `.env` (jak reszta). Dodatkowo wyróżniony wpis w error_log z prefiksem `[DB-DOWN]`.

---

## STAN OBECNY (zweryfikowany w kodzie — punkt zaczepienia)

**Pojedynczy punkt przechwycenia** — `src/Chat/ChatService.php`, metoda `executeTool()` (~linia 460):
```php
private function executeTool(string $name, array $arguments): array
{
    try {
        if (!$this->toolRegistry->has($name)) {
            return ['error' => "Nieznane narzędzie: {$name}"];
        }
        return $this->toolRegistry->get($name)->execute($arguments);
    } catch (\Throwable $e) {
        return ['error' => "Błąd narzędzia {$name}: {$e->getMessage()}"];
    }
}
```
To TEN catch produkował `Błąd narzędzia search_products: SQLSTATE...1045` widziany w logach rozmów. Łapie KAŻDE narzędzie (search_products, get_expert_knowledge, get_shipping_info, order status) — czyli pokrywa zarówno MySQL PS, jak i pgvector. Idealne miejsce na detekcję.

**Wzorzec alertu do skopiowania** — `src/Usage/CostGuard.php`, metoda `maybeSendAlert()`:
- dedup race-safe: `INSERT ... ON CONFLICT (klucz) DO NOTHING` + `rowCount() > 0` → tylko jeden worker wysyła mail;
- `mail()` z `From: noreply@divezone.pl`, `Content-Type: text/plain; charset=UTF-8`;
- mail() == false → `error_log` + `mail_ok=FALSE` w wierszu, NIE rzuca (alert to dodatek, nie bramka);
- tabela `divechat_cost_alerts` (PK = alert_date).

**Wzorzec odczytu e-mail** — `src/Controller/ChatController.php` (CHAT-T-067): odczyt z SettingsStore klucz, walidacja `filter_var(..., FILTER_VALIDATE_EMAIL)`, fallback `.env`. Zreużyć ten sam helper/wzorzec (NIE pisać drugiej ścieżki).

**Dostęp do DB w ChatService:** ChatService ma już zależności wstrzykiwane przez kontener (`config/` — sprawdź jak rejestrowane są serwisy). PostgresConnection dostępny (CostGuard go używa). `divechat_db_alerts` ląduje w tym samym PG (Railway) co cost_alerts — NIE w MySQL PS (bo gdy MySQL padnie, alert musi działać; PG to osobna infra).

---

## DETEKCJA: które błędy = awaria infra (246a)

Alert wyzwalany TYLKO gdy `$e` (Throwable z executeTool) to błąd połączenia/dostępu. Detekcja po SQLSTATE/treści:

**MySQL (PDO mysql):**
- `1045` Access denied (ten incydent)
- `2002` Can't connect (socket/host down)
- `2003` Can't connect to MySQL server (TCP)
- `2006` MySQL server has gone away
- `2013` Lost connection during query

**PostgreSQL (PDO pgsql) — gdy padnie pgvector/Railway:**
- SQLSTATE klasa `08...` (connection exception: `08000/08003/08006/08001/08004`)
- `57P01` admin_shutdown, `57P03` cannot_connect_now

**NIE alertować (błędy logiczne, NIE infra):**
- `42703` undefined_column, `42P01` undefined_table, `42601` syntax — to bug w kodzie/migracji, nie awaria infra.
- puste wyniki, walidacja, timeouty zapytań aplikacyjnych nie wynikające z zerwania połączenia.

**Implementacja detekcji (sugIArowana, do oceny CC):** sprawdzić czy `$e instanceof \PDOException`, odczytać `$e->getCode()` (SQLSTATE) i/lub `$e->errorInfo[1]` (driver-specific MySQL code: 1045/2002/...). Dopasować do whitelisty kodów połączeniowych powyżej. Zwróć uwagę: dla MySQL `1045/2002` driver code jest w `errorInfo[1]`, a `getCode()` często zwraca SQLSTATE `HY000`/`08...`. Dla PG SQLSTATE w `getCode()`. Obsłużyć OBA. Pojedyncza metoda `isConnectionFailure(\Throwable $e): bool`.

---

## KROK 0 — PULL / READ (zawsze najpierw)

1. `git pull origin main` (lub aktualna gałąź — sprawdź `git status`).
2. Przeczytaj:
   - `src/Usage/CostGuard.php` (wzorzec maybeSendAlert + dedup + mail).
   - `src/Chat/ChatService.php` metoda `executeTool()` (punkt zaczepienia) oraz konstruktor (jak wstrzykiwane zależności).
   - `src/Controller/ChatController.php` helper odczytu e-mail z SettingsStore + fallback .env (CHAT-T-067).
   - `config/` (rejestracja serwisów w kontenerze — jak dodać DbHealthAlert do ChatService).
   - `_docs/10_decyzje_projektowe.md` → ADR-088 (kontekst incydentu).
3. Sprawdź realny katalog migracji SQL (`sql/` — wzorzec plików `NNN_*.sql`) oraz jak są uruchamiane (czy jest runner, czy ręcznie).

## KROK 1 — Migracja: tabela divechat_db_alerts

Nowy plik migracji w `sql/` (numer kolejny wg istniejącej konwencji). Tabela w PG (Railway), dedup w oknie 30 min (245b):
```sql
CREATE TABLE IF NOT EXISTS divechat_db_alerts (
    id           SERIAL PRIMARY KEY,
    alert_window TIMESTAMPTZ NOT NULL,   -- początek 30-min okna (truncated), klucz dedup
    db_target    VARCHAR(20) NOT NULL,   -- 'mysql' | 'pgsql' (która baza padła)
    sqlstate     VARCHAR(10),
    driver_code  INTEGER,                -- np. 1045, 2002 (MySQL) ; NULL dla PG
    error_excerpt TEXT,                  -- skrócony komunikat (BEZ sekretów — patrz uwaga niżej)
    mail_ok      BOOLEAN DEFAULT TRUE,
    created_at   TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE (alert_window, db_target)     -- dedup: 1 alert / okno / baza
);
```
- **Okno 30 min:** `alert_window = date_trunc('hour', now()) + interval '30 min' * floor(extract(minute from now())/30)` — lub policzyć w PHP i wstawić jako parametr. Dedup przez `INSERT ... ON CONFLICT (alert_window, db_target) DO NOTHING` + `rowCount()`.
- **UWAGA BEZPIECZEŃSTWO:** `error_excerpt` NIE może zawierać hasła ani DSN. Komunikat `1045 Access denied for user 'x'@'localhost'` jest OK (bez hasła), ale przefiltruj/utnij — żadnych wartości z `.env`. Najlepiej zapisywać tylko sqlstate + driver_code + generyczny opis, bez surowego `$e->getMessage()` jeśli mógłby nieść connection string.

## KROK 2 — Klasa DbHealthAlert

Nowy plik `src/Usage/DbHealthAlert.php` (wzorzec 1:1 z CostGuard):
- konstruktor: `PostgresConnection $db` (+ ewentualnie reużyty reader e-maila — patrz KROK 3).
- `isConnectionFailure(\Throwable $e): bool` — detekcja wg whitelisty kodów z sekcji DETEKCJA (MySQL errorInfo[1] + PG SQLSTATE getCode). Zwraca też (przez ref/obiekt) `db_target`, `sqlstate`, `driver_code` do zapisu.
- `maybeAlert(\Throwable $e, string $alertEmail): void` — jeśli `isConnectionFailure`: policz `alert_window`, `INSERT ON CONFLICT DO NOTHING`, gdy `rowCount()>0` → wyślij `mail()` (Subject np. `[DiveChat][DB-DOWN] Baza {db_target} niedostępna ({code})`, body: okno, target, sqlstate, code, link do panelu, info że czat zwraca komunikat zastępczy). mail()==false → error_log + mail_ok=FALSE. Cała metoda owinięta tak, by NIGDY nie rzucała w górę (best-effort, jak CostGuard).
- Wyróżniony log ZAWSZE (niezależnie od dedup maila): `error_log('[DB-DOWN] target=... sqlstate=... code=... tool=...')` — tani sygnał do gridowania i potwierdzenia czasu trwania (248a).

## KROK 3 — Podpięcie w ChatService::executeTool()

W catch (`\Throwable $e`) PRZED `return ['error' => ...]`:
```php
} catch (\Throwable $e) {
    // CHAT-T-079: alert na awarię połączenia DB (best-effort, nie zmienia zachowania czatu)
    try {
        $this->dbHealthAlert->maybeAlert($e, $this->resolveAlertEmail());
    } catch (\Throwable $ignore) {
        error_log('[DbHealthAlert] alert path failed: ' . $ignore->getMessage());
    }
    return ['error' => "Błąd narzędzia {$name}: {$e->getMessage()}"];
}
```
- Wstrzyknąć `DbHealthAlert` do ChatService przez kontener (`config/`), wzorem CostGuard w ChatController.
- `resolveAlertEmail()` — reużyć helper/wzorzec z ChatController (SettingsStore klucz np. `protect_alert_email` lub ten sam co CostGuard używa, fallback `.env` `DIVECHAT_ALERT_EMAIL`/istniejący). NIE duplikować logiki — jeśli helper jest prywatny w ChatController, wydzielić go do wspólnego miejsca albo powtórzyć minimalnie wg tego samego wzorca i odnotować w raporcie.
- **KLUCZOWE:** zachowanie czatu BEZ ZMIAN. Alert to dodatek w catch; nadal zwracamy `['error' => ...]` i model dostaje to samo co dziś. Zero zmian w treści odpowiedzi do klienta.

## KROK 4 — Test

- Jednostkowo (jeśli jest harness jak `tests/ExpertKnowledgeTest.php`): `isConnectionFailure()` zwraca TRUE dla zasymulowanych PDOException z kodami 1045/2002/2006 i PG 08006/57P01; FALSE dla 42703/42P01/syntax.
- Dedup: dwa wywołania `maybeAlert` w tym samym oknie 30 min → tylko 1 wstawienie / 1 mail (drugie `rowCount()==0`).
- Bezpieczeństwo: potwierdź, że `error_excerpt` w tabeli NIE zawiera hasła/DSN.
- Smoke (ostrożnie, NIE na produkcyjnym ruchu): rozważ test detekcji bez realnego wywalania DB.

## KROK 5 — STOP (przed deployem)

>>> STOP — RAPORT DLA KAROLA PRZED DEPLOYEM <<<
Zatrzymaj się. Przedstaw:
- diff klas (DbHealthAlert, ChatService catch, migracja),
- potwierdzenie że zachowanie czatu niezmienione,
- jak rozwiązano odczyt alert_email (klucz SettingsStore + fallback .env),
- wynik testów detekcji + dedup.
Czekaj na akceptację Karola. Backend standalone deployuje się autonomicznie (konwencja 116b), ALE migracja na PG produkcyjnym (Railway) + nowy alert = wymaga świadomej zgody. NIE deployuj przed akceptacją.

## KROK 6 — Deploy + git (po akceptacji)

1. `git status` (wylistuj untracked do dodania).
2. `git add` per ścieżka: `src/Usage/DbHealthAlert.php`, `src/Chat/ChatService.php`, `sql/NNN_divechat_db_alerts.sql`, ewentualne zmiany `config/`, testy. NIE `git add .`. Pomiń pliki z `.gitignore` (handoff/log/.env).
3. Commit wg konwencji: `CHAT-T-079 backend: alert na awarię połączenia DB (DbHealthAlert, okno 30min, ADR-088)`
4. `git push origin main`.
5. Uruchom migrację na PG produkcyjnym (wg runnera projektu).
6. Deploy backendu standalone (autonomicznie, 116b).

## KROK 7 — STATE UPDATE + RAPORT (ostatni krok, zawsze)

1. Dopisz do `_docs/21_STATUS_PROJEKTU.md`: CHAT-T-079 wykonany, alert DB aktywny, domyka dług z ADR-088.
2. Osobny commit: `docs: CHAT-T-079 (alert awarii DB — DbHealthAlert okno 30min, dług ADR-088 zamknięty) — status vX.XX`
3. `git push origin main`.
4. Raport końcowy dla Karola: co wdrożone, jak zweryfikować że alert działa (np. jak bezpiecznie wywołać testowy alert), jaki adres e-mail odbiera.

---

## GRANICE / UWAGI

- Tabela alertu w PG (Railway), NIE w MySQL PS — gdy MySQL padnie, alert MUSI działać.
- Alert NIGDY nie zmienia zachowania czatu (best-effort w catch, nie rzuca, nie blokuje).
- error_excerpt bez sekretów (hasło/DSN).
- Reużyć wzorce: CostGuard (dedup+mail), ChatController (odczyt e-mail). NIE pisać trzeciej ścieżki maila ani drugiej ścieżki odczytu e-mail.
- To NIE jest fallback do pgvector (osobna, jeszcze niepodjęta decyzja z dyskusji o pyt. 231). Ten task = wyłącznie ALERT (wiedza o awarii), nie zmiana UX przy awarii.
