# CHAT-T-049 — BACKEND: przełączenie Analityki (cost + conversations/top) na kanał serwerowy, admin-only

**Instancja:** backend (standalone, PHP 8.4)
**Powiązane:** Etap 2 migracji panelu (handoff `_docs/25`), ADR-070, ADR-068, CHAT-T-044 (wzorzec admin-only SettingsController), CHAT-T-046 (wzorzec any-role).
**Decyzje:** 107a/108a (Analityka admin-only), 109a (`conversations/{id}` NIE migrujemy), 110a (Etap 2 = osobny task backend, potem UI), 118b (nowy `AdminAnalyticsController`, stary `AdminController` nietknięty).
**Backend standalone — CC WDRAŻA SAM** (to nie moduł PS, 116b nie dotyczy).

## Cel
Przygotować backend pod zakładkę Analityka w PS: przełączyć 4 endpointy analityki z Basic Auth (`AdminAuthMiddleware`) na kanał serwerowy (`ServerHmacVerifier`, admin-only). UI Analityki = osobny task CHAT-T-050. Bez zmian w logice danych (`CostAnalytics` nietknięty) — tylko warstwa kontrolera/auth.

## Zakres (decyzja 118b — NOWY kontroler, czysty podział)

### 1. Nowy `standalone/src/Controller/AdminAnalyticsController.php`
- Czysto kanał serwerowy, admin-only. Wzorzec auth 1:1 z `SettingsController::requireAdmin()` (skopiować metodę `requireAdmin()`: nagłówki X-DiveChat-Server-*, `filter_var` INT, `serverVerifier->verify`, lookup `divechat_admin_roles`, 401 brak/zły podpis, 403 `no_role`/`admin_only`).
- Konstruktor (DI jak w routes): `CostAnalytics $analytics`, `ServerHmacVerifier $serverVerifier`, `PostgresConnection $pg`.
- 4 metody — przenieść CIAŁO 1:1 z `AdminController` (tylko zamienić `$this->auth->check()` na `$this->requireAdmin()`):
  - `kpi(Request)` → `Response::json($this->analytics->kpi())`
  - `trend(Request)` → walidacja period (daily/weekly/monthly, 400 jak dziś) + days (clamp 1-365), `Response::json($this->analytics->trend(...))`
  - `byModel(Request)` → days clamp, `Response::json(['days'=>.., 'models'=>$this->analytics->byModel(..)])`
  - `topConversations(Request)` → limit clamp 1-100, days clamp, `Response::json(['limit'=>.., 'days'=>.., 'conversations'=>$this->analytics->topConversations(..)])`
- `declare(strict_types=1)`, namespace `DiveChat\Controller`, `final class`. PHP 8.4 (typed props/readonly OK — to standalone, NIE moduł 7.2).

### 2. `standalone/config/routes.php` — przepiąć 4 trasy na nowy kontroler
- Dodać instancję: `$adminAnalyticsController = new AdminAnalyticsController($costAnalytics, $serverVerifier, $db);` (`$costAnalytics`, `$serverVerifier`, `$db` już istnieją w tym scope — patrz linie ~96-99 i AdminPricingController).
- Przepiąć trasy:
  - `/api/admin/cost/kpi` → `$adminAnalyticsController->kpi(...)`
  - `/api/admin/cost/trend` → `$adminAnalyticsController->trend(...)`
  - `/api/admin/cost/by-model` → `$adminAnalyticsController->byModel(...)`
  - `/api/admin/conversations/top` → `$adminAnalyticsController->topConversations(...)`
- ZOSTAWIĆ bez zmian: `/api/admin/conversations/{id}` → `$adminController->conversationDetail(...)` (decyzja 109a — nadal Basic Auth, ginie przy wyłączeniu /admin).
- `$adminController` (stary) ZOSTAJE w routes (obsługuje już tylko `{id}`). `AdminController.php` — NIE edytować (nietknięty, 118b).
- UWAGA na kolejność tras: `/api/admin/conversations/top` (statyczna) MUSI być przed `/api/admin/conversations/{id}` (parametryczna) — Router matchuje po kolejności (komentarz w routes już o tym wspomina przy editorial). Zachować ten porządek.

## Konsekwencje (zanotować, nie blokujące)
- Stary panel `/admin` (JS: admin.js/admin-charts.js/admin-tables.js) przestanie czytać kpi/trend/by-model/top — zacznie dostawać 401 (brak nagłówków serwerowych). To OCZEKIWANE (analogicznie do history.js po CHAT-T-046); `/admin` i tak wygaszamy. Zakładka „koszty" w /admin przestanie działać do czasu UI w PS (CHAT-T-050).
- `conversations/{id}` w /admin nadal działa (Basic Auth) — jedyny żywy endpoint starego AdminController.

## Granice
- NIE ruszać `CostAnalytics`, `ConversationViewer`, `AdminController`, `AdminAuthMiddleware`.
- NIE budować UI (to CHAT-T-050).
- NIE migrować `conversations/{id}` (109a).
- NIE dotykać modułu PS.

## Kryteria akceptacji
1. Nowy `AdminAnalyticsController` istnieje, 4 metody, auth `requireAdmin()` 1:1 z SettingsController (HMAC + rola admin, 401/403).
2. routes.php: 4 trasy analityki wołają nowy kontroler; `conversations/{id}` nadal stary (Basic Auth); kolejność top przed {id} zachowana.
3. `php -l` clean dla nowego kontrolera i routes.php.
4. Test ręczny (CC opisze w raporcie): wywołanie `/api/admin/cost/kpi` z poprawnymi nagłówkami serwerowymi employee z rolą admin → 200; bez nagłówków → 401; employee z rolą operator (nie admin) → 403 admin_only.
5. `AdminController.php` bez zmian (git diff pusty dla tego pliku).
