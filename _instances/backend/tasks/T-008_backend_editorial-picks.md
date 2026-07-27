# T-008: Editorial Picks backend (manualny boost rankingu produktów)

**Instancja:** backend
**Powiązany:** ADR-054, ADR-048 (RRF), ADR-052 (admin), TASK-CHAT-009a (legacy nazwa, przeniesione na T-008)
**Priorytet:** P1
**Czas estymowany:** ~5h CC

## Cel

Implementacja backend warstwy Editorial Picks (manualny boost rankingu produktów przez właściciela sklepu) wg ADR-054. Bez UI — frontend admin w osobnym tasku (T-XXX po T-008).

Zakres T-008: migracja PG + EditorialPicksService + RRF integration w ProductSearch + cron auto-expire + API endpoints CRUD pod `chat.divezone.pl/admin`.

Out of scope T-008: frontend UI sekcji Editorial Picks, weekly notifications (email/banner) — osobne taski.

## KROK 0. Read

- `_docs/10_decyzje_projektowe.md` sekcja ADR-054 (pełna specyfikacja)
- `standalone/src/Tools/ProductSearch.php` linie 380-470 (RRF fusion + filter — gdzie wpiąć boost)
- `standalone/src/Controller/AdminController.php` + `Controller/AdminPricingController.php` (wzorzec endpointów admin)
- `standalone/src/Http/AdminAuthMiddleware.php` (autoryzacja basic auth)
- `sql/008_telemetry_extension.sql` (wzorzec migracji idempotentnej)

## KROK 1. Migracja 011 — divechat_editorial_picks

Plik: `sql/011_editorial_picks.sql` + rollback `sql/011_editorial_picks_rollback.sql`.

Spec tabeli i indeksu literalnie wg ADR-054:

```sql
CREATE TABLE IF NOT EXISTS divechat_editorial_picks (
    id SERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL,
    product_name TEXT NOT NULL,
    category_hint TEXT,
    boost_factor NUMERIC(3,2) NOT NULL DEFAULT 1.5 CHECK (boost_factor BETWEEN 1.0 AND 2.5),
    reason TEXT NOT NULL,
    added_by TEXT NOT NULL,
    added_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ,
    last_review_at TIMESTAMPTZ,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE(product_id, category_hint)
);

CREATE INDEX IF NOT EXISTS idx_editorial_picks_active_expires
    ON divechat_editorial_picks(active, expires_at) WHERE active = TRUE;

CREATE INDEX IF NOT EXISTS idx_editorial_picks_product
    ON divechat_editorial_picks(product_id) WHERE active = TRUE;
```

Apply na Railway, weryfikacja struktury.

## KROK 2. EditorialPicksService

Plik: `standalone/src/Editorial/EditorialPicksService.php`. Metody:

- `getActiveBoosts(array $productIds, ?string $category): array<int, float>` — zwraca map `product_id => boost_factor` dla aktywnych picków matchujących produkty + (opcjonalnie) category_hint. WHERE `active=TRUE AND (expires_at IS NULL OR expires_at > NOW())`. Jeśli `category_hint` jest NULL — pasuje zawsze; jeśli ustawione, musi matchować przekazaną kategorię (case-insensitive).
- `list(?bool $active, ?string $orderBy): array` — lista picków dla panelu admin
- `add(int $productId, string $productName, ?string $categoryHint, float $boostFactor, string $reason, string $addedBy, ?int $ttlDays): array` — INSERT z UNIQUE conflict (UPSERT na (product_id, category_hint)). `ttlDays=null → expires_at=NULL` (bezterminowo), inaczej `NOW() + INTERVAL '$ttlDays days'`.
- `update(int $id, array $changes): bool` — zmiany: boost_factor, reason, expires_at (extend TTL), active (deactivate).
- `markReviewed(int $id): bool` — `last_review_at = NOW()`.
- `expireDue(): int` — UPDATE SET active=FALSE WHERE active=TRUE AND expires_at < NOW(). Zwraca liczbę zdezaktywowanych.

Wszystkie metody przez `PostgresConnection` singleton. Bez ORM.

## KROK 3. RRF integration w ProductSearch

W `ProductSearch::execute()` po RRF fusion (przed in_stock_only filter), zaaplikuj boost:

```php
// Editorial boost — manualny override rankingu (ADR-054)
$editorialBoosts = $this->editorialPicks->getActiveBoosts(
    array_keys($scores),
    $category ?? null
);
foreach ($editorialBoosts as $pid => $boost) {
    if (isset($scores[$pid])) {
        $scores[$pid] *= $boost;
    }
}
arsort($scores);
```

Wstrzykiwać `EditorialPicksService` przez konstruktor (DI), nie new wewnątrz.

Update `Tools/ToolRegistry.php` (lub odpowiedniego DI containera) żeby ProductSearch dostał EditorialPicksService.

Dodać `editorial_boost` per produkt do `search_debug.items` (informacyjnie, do diagnostyki — pamiętaj że search_debug NIE idzie do LLM per ADR-049, ale zostaje w search_diagnostics).

## KROK 4. Cron auto-expire (godzinowy)

Plik: `scripts/cron_editorial_picks_expire.php`. Wywołuje `EditorialPicksService::expireDue()`, loguje wynik do stdout/stderr.

Crontab (do dodania ręcznie przez Karola na serwerze, w handoff podać linijkę):

```
0 * * * * cd /var/www/chat.divezone.pl && php scripts/cron_editorial_picks_expire.php >> /var/log/divechat/editorial_picks_expire.log 2>&1
```

## KROK 5. API endpoints

Plik: `standalone/src/Controller/AdminEditorialPicksController.php`. Pod basic auth (AdminAuthMiddleware).

- `GET /api/admin/editorial-picks?active=1|0|all&order_by=added_at|expires_at|boost_factor` — list
- `POST /api/admin/editorial-picks` — body: `{product_id, product_name, category_hint?, boost_factor, reason, ttl_days?}`. `added_by` z basic auth user (lub stała "admin" na MVP).
- `PUT /api/admin/editorial-picks/{id}` — body: dowolny subset {boost_factor, reason, expires_at, active, ttl_extend_days, mark_reviewed}
- `DELETE /api/admin/editorial-picks/{id}` — twarde DELETE z bazy (alt: tylko deactivate przez PUT active=false; preferuj deactivate, ale endpoint istnieje dla operacji czyszczenia).

Rejestracja routów w `standalone/src/Router.php`.

## KROK 6. Integration tests

Plik: `tests/integration/EditorialPicksTest.php` (lub równoważne miejsce w katalogu tests).

Scenariusze:
1. INSERT pick → `getActiveBoosts([product_id])` zwraca boost
2. INSERT pick z `expires_at = NOW() - INTERVAL '1 day'` (przeszłe) → `getActiveBoosts` zwraca pusty (nie aktywny przez WHERE clause)
3. `expireDue()` deaktywuje wygasłe → kolejne `getActiveBoosts` nadal pusty + `active=FALSE` w bazie
4. INSERT 2 picki same product_id, różne category_hint → oba istnieją (UNIQUE constraint po parze)
5. INSERT pick z `category_hint='Komputery Nurkowe'`, query getActiveBoosts(_, 'Komputery Nurkowe') → boost zwrócony. Query getActiveBoosts(_, 'Skafandry suche') → pusty.
6. ProductSearch::execute na zapytanie matchujące boostowany produkt → ten produkt wyżej w wynikach niż bez boost (porównanie z baseline).

## KROK 7. Smoke test PHP

```bash
php -l standalone/src/Editorial/EditorialPicksService.php
php -l standalone/src/Controller/AdminEditorialPicksController.php
php -l standalone/src/Tools/ProductSearch.php  # po zmianie
php -l scripts/cron_editorial_picks_expire.php
```

## KROK 8. STOP point — diff do review Karol

Status: "READY FOR REVIEW v1". Wklej:
- Lista nowych plików + diff stat
- SQL migracji (treść)
- Wynik integration tests
NIE deploy bez akceptacji.

## KROK 9. Deploy

- Apply migracja 011 na Railway (psql, idempotentna)
- scp nowych plików PHP + zmodyfikowanego ProductSearch.php + scripts/cron
- composer dump-autoload na prod (jeśli nowy namespace `DiveChat\Editorial`)
- php -l na każdym wgranym pliku
- Test endpointu admin: curl GET /api/admin/editorial-picks z basic auth → pusta lista (jeszcze brak picków)
- Test POST: dodaj pick przykładowy → GET zwraca pick

## KROK 10. Git workflow

```bash
git status
git add sql/011_editorial_picks.sql sql/011_editorial_picks_rollback.sql
git add standalone/src/Editorial/EditorialPicksService.php
git add standalone/src/Controller/AdminEditorialPicksController.php
git add standalone/src/Tools/ProductSearch.php
git add standalone/src/Router.php
git add scripts/cron_editorial_picks_expire.php
git add tests/integration/EditorialPicksTest.php
git commit -m "T-008: Editorial Picks backend — migracja + service + RRF integration + cron + API

Tabela divechat_editorial_picks (ADR-054): manualny boost rankingu produktów
z TTL i UNIQUE(product_id, category_hint). EditorialPicksService z metodami
CRUD + getActiveBoosts + expireDue. Integration w ProductSearch::execute()
po RRF fusion (score *= boost_factor). Cron godzinowy auto-expire.
API endpoints pod /api/admin/editorial-picks z AdminAuthMiddleware.

Out of scope: frontend UI, weekly notifications — osobne taski.

Powiązany ADR: ADR-054"
git push origin main
```

## KROK 11. Smoke test produkcyjny dla Karola

Wymaga ręcznego dodania picka przez curl (do czasu UI):

```bash
curl -u admin:HASLO -X POST https://chat.divezone.pl/api/admin/editorial-picks \
  -H "Content-Type: application/json" \
  -d '{"product_id": 6865, "product_name": "SANTI E.Lite Plus Ladies First Powystawowy", "category_hint": "Skafandry suche", "boost_factor": 1.8, "reason": "Smoke test T-008 - powystawowy in_stock", "ttl_days": 7}'
```

Potem przez UI chat:
1. "Szukam suchego skafandra Santi, damski" → SANTI Ladies First Powystawowy (6865) powinien być WYŻEJ w rankingu niż bez boost
2. Sprawdź endpoint listy: `curl -u admin:HASLO https://chat.divezone.pl/api/admin/editorial-picks?active=1` → pick widoczny

Cron auto-expire:
3. Dodaj pick z `ttl_days: -1` (już wygasły) → po godzinie cron deaktywuje (active=FALSE w DB)

Po smoke usuń test pick:
```bash
curl -u admin:HASLO -X DELETE https://chat.divezone.pl/api/admin/editorial-picks/{id}
```

## KROK 12. Raport + status update

### Utworz `_instances/backend/handoff/T-008_done.md`:
- Migracja 011 applied + verify schema
- Lista nowych plików + diff stat per plik
- Wyniki integration tests (6 scenariuszy)
- Smoke prod (curl POST + chat query + cron exec)
- Crontab linijka do ręcznego dodania przez Karola

### Update `_docs/21_STATUS_PROJEKTU.md`:
- "Co działa na produkcji" → T-008 DEPLOYED
- "Aktywne instancje CC" → backend T-008 DONE
- "Kolejka tasków" → usunąć TASK-CHAT-009 (legacy), zostawić TASK-CHAT-009b frontend UI (do refaktoryzacji na T-XXX kolejny)

### Osobny commit "docs:"

```bash
git add _docs/21_STATUS_PROJEKTU.md
git commit -m "docs: T-008 DEPLOYED — Editorial Picks backend"
git push origin main
```

## Out of scope

- Frontend admin UI Editorial Picks (osobny task, ~3h CC)
- Weekly notifications cron (email/banner, osobny task — wymaga decyzji email vs banner-only)
- Statystyki konwersji picków (czy boost przekłada się na sprzedaż) — przyszłe analityki
- A/B testing różnych boost factors
- ML auto-tuning
