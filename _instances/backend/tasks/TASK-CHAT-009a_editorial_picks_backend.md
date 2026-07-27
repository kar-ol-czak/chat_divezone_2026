# TASK-CHAT-009a: Editorial Picks — backend (P1)

**Instancja:** backend
**Powiązany ADR:** ADR-054
**Priorytet:** P1 (po deploy paczki post-007b i 007c)
**Czas estymowany:** ~6h

## Cel

Manualny mechanizm boostowania produktów w rankingu RRF, niezależnie od danych sprzedażowych. Rozwiązuje problem "wiemy że to dobry produkt zanim ma sprzedaż" (test #2 CSV, gdzie bot pokazał tylko Suunto Eon Core mimo że Nautic/Ocean są flagowymi produktami).

## Komponenty

### 1. Migracja DB

Plik: `sql/00X_editorial_picks.sql` (kolejny numer, sprawdź ostatni)

```sql
CREATE TABLE divechat_editorial_picks (
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

CREATE INDEX idx_editorial_picks_active_expires
    ON divechat_editorial_picks(active, expires_at) WHERE active = TRUE;

COMMENT ON TABLE divechat_editorial_picks IS
    'Manualne boosty rankingu produktów dla AI search. ADR-054.';
COMMENT ON COLUMN divechat_editorial_picks.expires_at IS
    'NULL = bezterminowo. Wygasłe picki są auto-dezaktywowane przez cron.';
COMMENT ON COLUMN divechat_editorial_picks.category_hint IS
    'NULL = boost we wszystkich kategoriach. Inna wartość = boost tylko w tej kategorii.';
```

Rollback w osobnym pliku `00X_editorial_picks_rollback.sql`.

### 2. EditorialPicksService

Lokalizacja: `standalone/src/Search/EditorialPicksService.php`

Kontrakt publiczny:

```php
namespace DiveChat\Search;

final class EditorialPicksService
{
    /** @return array<int, float> mapping product_id => boost_factor dla aktywnych picków */
    public function getActiveBoosts(?string $categoryHint = null): array;

    /** @return EditorialPick lookup pick'a po id */
    public function get(int $id): ?EditorialPick;

    /** @return EditorialPick[] lista z filtrem */
    public function list(string $filter = 'active'): array; // active|expired|all

    public function create(EditorialPick $pick): int; // zwraca id
    public function update(int $id, array $changes): void;
    public function deactivate(int $id): void;
    public function markReviewed(int $id): void; // ustawia last_review_at = NOW()
    public function extendTtl(int $id, int $daysOrNull): void; // null = bezterminowo

    public function expireOldPicks(): int; // cron: deaktywuje wygasłe, zwraca liczbę
    public function getPendingReviews(): array; // dla raportu tygodniowego
}
```

`EditorialPick` value object z polami z tabeli.

### 3. Integracja z RRF

Plik: zmodyfikować `standalone/src/Search/RrfSearch.php` (lub odpowiednik).

Po fuzion RRF, przed sortowaniem finalnym:

```
boosts = editorialPicksService.getActiveBoosts(categoryHint)
for each candidate in results:
    if candidate.product_id in boosts:
        candidate.score = candidate.score * boosts[candidate.product_id]
sort results by score desc
```

**Uwaga:** boost stosujemy PO MySQL enrichment (ADR-048), nie przed. Wymusza ranking po cenie/dostępności + manual boost na końcu.

### 4. API endpoints

W `standalone/admin/` lub równoważnym, pod prefiksem `/api/admin/editorial-picks`:

- `GET /api/admin/editorial-picks?filter=active|expired|all` — lista
- `GET /api/admin/editorial-picks/{id}` — pojedynczy
- `POST /api/admin/editorial-picks` — create (body: product_id, category_hint, boost_factor, reason, ttl_days)
  * ttl_days: 15 | 30 | 60 | 90 | null (= bezterminowo)
  * walidacja: product_id istnieje w `pr_product`, boost_factor w zakresie
- `PATCH /api/admin/editorial-picks/{id}` — update (boost_factor, reason, ttl_days)
- `POST /api/admin/editorial-picks/{id}/mark-reviewed` — ustawia last_review_at
- `POST /api/admin/editorial-picks/{id}/extend` — przedłuża TTL
- `DELETE /api/admin/editorial-picks/{id}` — soft delete (active=false)
- `GET /api/admin/editorial-picks/product-search?q=...` — autocomplete produktów z `pr_product` (LIKE name lub reference)
- `GET /api/admin/editorial-picks/pending-reviews` — dla banneru w dashboard

Autoryzacja: ta sama co inne `/api/admin/*` (HTTP Basic Auth + auth header, zgodnie z TASK-052).

### 5. Cron jobs

**Cron 1: Auto-expire** (co godzinę)
- Skrypt `standalone/scripts/cron_expire_editorial_picks.php`
- Wywołuje `EditorialPicksService::expireOldPicks()`
- Loguje liczbę zdeaktywowanych do `_docs/cron_logs/` (lub gdzie projekt loguje)

Crontab entry (do dodania do dokumentacji deployu):
```
0 * * * * /usr/local/bin/php /home/divezone/public_html/chat.divezone.pl/scripts/cron_expire_editorial_picks.php >> /var/log/divechat/cron_expire.log 2>&1
```

**Cron 2: Tygodniowe przypomnienia** (poniedziałek 9:00 CEST)
- Skrypt `standalone/scripts/cron_weekly_editorial_review.php`
- Generuje raport (HTML) z sekcjami:
  * Wygasłe w ostatnim tygodniu
  * Wygasające w tym tygodniu
  * Bezterminowe bez review > 30 dni
  * Aktywne bez sprzedaży 60+ dni (JOIN z `pr_orders` przez MySQL bridge)
- Wysyła mail na dive@divezone.pl (użyj istniejącego mailera z TASK-055 admin lub PHPMailer)
- Zapisuje payload raportu w tabeli `divechat_pending_reviews` dla wyświetlenia w banner UI

```
0 9 * * 1 /usr/local/bin/php /home/divezone/public_html/chat.divezone.pl/scripts/cron_weekly_editorial_review.php
```

## STOP point 1 (po komponentach 1-3)

Wyprodukować:
- Migracja + rollback
- EditorialPicksService z testami jednostkowymi (min. 6 przypadków: create, update, getActiveBoosts, getActiveBoosts(category), expireOldPicks, getPendingReviews)
- Integracja RRF z testem: produkt z boost 2.0 powinien być wyżej w wynikach niż identyczny produkt bez boost

Status w handoff: "STOP 1 — backend services + RRF integration done, awaiting review przed buildem API".

## STOP point 2 (po komponentach 4-5)

Wyprodukować:
- API endpoints z dokumentacją (OpenAPI/Markdown)
- Cron jobs z dokumentacją w `_docs/deploy_setup.md` (lub gdzie projekt trzyma deploy notes)
- Test integracyjny: pełny lifecycle picka (create → boost w wynikach → expire po TTL → auto-deactivate)

Status w handoff: "STOP 2 — ready for deploy + frontend 009b".

## Acceptance criteria

1. Migracja przechodzi bez błędów na produkcji
2. Pick z boost_factor=2.0 powoduje że produkt jest wyżej w rankingu RRF (test integracyjny przed/po)
3. Cron expire wykonuje się co godzinę, deaktywuje wygasłe picki
4. Cron weekly wysyła email do dive@divezone.pl w poniedziałek 9:00
5. API endpoints autoryzowane Basic Auth, zwracają poprawne kody HTTP (200/400/401/404)

## Out of scope

- UI panel admina → TASK-CHAT-009b
- A/B testing różnych boost_factor — przyszły ADR
- Boost per segment klienta — przyszły ADR
- Integracja z embeddings (preboost przed RRF) — obecna decyzja: boost po MySQL enrichment, nie przed
