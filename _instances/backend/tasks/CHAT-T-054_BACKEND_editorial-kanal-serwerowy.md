# CHAT-T-054 — BACKEND: Editorial Picks na kanał serwerowy (any-role)

**Instancja:** backend (standalone, PHP 8.4)
**Powiązane:** Etap 3 migracji panelu, ADR-070/074, CHAT-T-046/049 (wzorce auth), CHAT-T-045 (callBackend GET/POST).
**Decyzje:** 127b (any-role), 128a (callBackend body tylko dla POST → aliasy POST dla update/delete), 129a (Etap 3 = backend osobno, potem UI CHAT-T-055).
**Backend standalone — CC WDRAŻA SAM.**

## Cel
Przygotować backend pod zakładkę Editorial Picks w PS: przełączyć 6 endpointów editorial z Basic Auth (AdminAuthMiddleware) na kanał serwerowy (ServerHmacVerifier, ANY-ROLE — operator+admin). Dodać aliasy POST dla update/delete (PS UI jest POST-owe; callBackend wysyła body tylko dla POST). Logika danych (EditorialPicksService) BEZ ZMIAN — tylko warstwa kontrolera/auth.

## Ustalenia z analizy kodu (potwierdzone)
- AdminEditorialPicksController: 6 metod, wszystkie $this->auth->check() (Basic Auth): list(GET), add(POST), update(PUT), delete(DELETE), pendingReviews(GET), productsSearch(GET).
- Wzorzec ANY-ROLE: AdminRecommendationsController::handle() — HMAC (X-DiveChat-Server-*) + lookup divechat_admin_roles, 401 brak/zły podpis, 403 no_role, BEZ wymogu role==admin. To szablon (różnica vs SettingsController: NIE sprawdzamy admin_only).
- callBackend (moduł PS): ustawia 'content' (body) i Content-Type TYLKO dla method==='POST'. PUT/DELETE wyślą się BEZ body. → update/delete muszą być osiągalne POST-em.

## Zakres (decyzja 128a + 127b)

### 1. Auth → kanał serwerowy any-role
- Dodać do AdminEditorialPicksController DI: ServerHmacVerifier $serverVerifier, PostgresConnection $pg (obok istniejącego EditorialPicksService; AdminAuthMiddleware MOŻNA usunąć z konstruktora po przełączeniu wszystkich metod — sprawdzić, czy nieużywane).
- Nowa prywatna metoda requireAnyRole(): int — zwraca employee_id. Wzorzec 1:1 z AdminRecommendationsController::handle() (część auth): nagłówki, filter_var INT, verify, lookup divechat_admin_roles, 401/403 no_role. BEZ sprawdzania role==='admin' (127b — operator+admin OK).
- W każdej z 6 metod zamienić $this->auth->check() na $employeeId = $this->requireAnyRole();.

### 2. Aliasy POST dla update/delete (128a)
PS UI komunikuje się POST-em (formularze), callBackend body tylko przy POST. Dodać ścieżki POST obok istniejących PUT/DELETE (NIE usuwać PUT/DELETE — zgodność wsteczna):
- POST /api/admin/editorial-picks/{id} → update (to samo ciało co PUT update; rozróżnienie operacji: jeśli body zawiera _action='delete' → wykonać delete; w przeciwnym razie update). LUB prościej i czytelniej:
  - POST /api/admin/editorial-picks/{id}        → update($request)
  - POST /api/admin/editorial-picks/{id}/delete → delete($request)
  Wybrać wariant z osobną ścieżką /delete (czytelniejsze, mniej magii z _action). Obie metody współdzielą walidację/serwis z PUT/DELETE.
- add pozostaje POST (już jest). list/pendingReviews/productsSearch pozostają GET (czytają, działają przez callBackend GET).

### 3. routes.php
- Zmiana instancji kontrolera: dodać $serverVerifier, $db do new AdminEditorialPicksController(...). AdminAuthMiddleware usunąć z tej instancji jeśli niepotrzebny.
- Dodać 2 trasy POST (update + delete aliasy) obok istniejących PUT/DELETE.
- ZACHOWAĆ kolejność statycznych przed parametrycznymi (pending-reviews, products/search PRZED /{id}).
- Sprawdzić, czy AdminAuthMiddleware/.htpasswd są jeszcze używane przez COKOLWIEK innego (jeśli editorial był ostatnim konsumentem poza conversations/{id} — zostawić, bo conversations/{id} nadal go używa wg 109a).

## Konsekwencje (zanotować)
- Stary /admin (editorial-picks zakładka, admin-editorial.js) zacznie dostawać 401 (brak nagłówków serwerowych) — oczekiwane, /admin wygaszamy. Editorial Picks w /admin przestaje działać do czasu UI w PS (CHAT-T-055).
- Po tym etapie w /admin żywy zostaje już tylko conversations/{id} (Basic Auth, 109a) — gotowość do wyłączenia /admin.

## Granice
- NIE ruszać EditorialPicksService (logika danych).
- NIE budować UI (CHAT-T-055).
- NIE usuwać PUT/DELETE (zgodność).
- NIE dotykać modułu PS.

## Kryteria akceptacji
1. 6 metod editorial używa requireAnyRole() (kanał serwerowy), nie auth->check().
2. requireAnyRole akceptuje operator I admin (403 tylko no_role / brak podpisu).
3. Aliasy POST: POST /api/admin/editorial-picks/{id} (update) i POST .../{id}/delete (delete) działają i przyjmują body. PUT/DELETE nadal istnieją.
4. routes.php: instancja z $serverVerifier+$db; kolejność statyczne przed /{id}; php -l clean (kontroler + routes).
5. Test ręczny (CC w raporcie): list z nagłówkami operatora → 200; bez nagłówków → 401; POST update z body operatora → 200; employee bez roli → 403 no_role.
6. EditorialPicksService bez zmian (git diff pusty).
