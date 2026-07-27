# CHAT-T-071 — BACKEND: auth mobilny (logowanie hasłem PS + sesja cookie)

**Status:** DONE (2026-06-05). Pełny raport: `_instances/backend/handoff/CHAT-T-071_done.md`. Wszystkie 12/12 kryteriów PASS na PROD. Deploy chat.divezone.pl + migracja 023_mobile_sessions.sql (Railway) wykonane. Smoke realne konto k.susicki@divezone.pl (id=5 admin).

**Instancja:** backend (standalone chat.divezone.pl). CC deployuje SAM. ZERO modułu PS.
**Powiązany ADR:** ADR-086. **Powiązane:** CHAT-T-046/048 (ConversationsController, requireAnyRole), ADR-068 (ServerHmacVerifier).
**To pierwszy z 3 tasków mobile admin** (T-071 auth → T-072 front → T-073 PWA). NAJWRAŻLIWSZY: dotyka haseł. STOP przed PROD, test na realnym koncie.

## Cel
Logowanie pracownika do widoku mobilnego TYM SAMYM loginem/hasłem co do panelu PS, bez wchodzenia w panel PS. Po zalogowaniu: sesja cookie HttpOnly, która autoryzuje dostęp do istniejących endpointów `/api/conversations*` (reużycie requireAnyRole, NIE druga ścieżka uprawnień).

## Fakty z PROD (zweryfikowane — NIE zgaduj)
- pr_employee: WSZYSCY pracownicy mają bcrypt (passwd len=60, prefix `$2`). ZERO legacy md5. → rdzeń = password_verify(). Legacy md5(_COOKIE_KEY_.pass) = opcjonalny fallback (przyszły import), NIE blokuje MVP.
- _COOKIE_KEY_ czytelny w ~/public_html/newtmp2/app/config/parameters.php (klucz 'cookie_key', 56 znaków) — dostępny dla użytkownika divezone. Backend MOŻE go odczytać (potrzebny TYLKO dla fallback legacy; bcrypt go nie używa).
- divechat_admin_roles: employee_id→role (admin|operator). Dostęp = rola istnieje AND pr_employee.active=1.
- MysqlConnection::getInstance() (src/Database/MysqlConnection.php) — gotowe read-only połączenie do MySQL PS ($_ENV['DB_*'], ładowane przez Config/Dotenv). Użyj go do odczytu pr_employee. NIGDY zapis do MySQL PS.
- PHP 8.4 (ea-php84). PostgreSQL = PostgresConnection (sesje mobilne tu).

## KROK 1 — Weryfikacja poświadczeń (nowa klasa src/Auth/MobileAuthenticator.php)
- Metoda `authenticate(string $email, string $password): ?array` zwraca `['employee_id'=>int, 'role'=>string, 'email'=>string]` lub null.
- Logika:
  1. SELECT id_employee, email, passwd, active FROM pr_employee WHERE email = ? (MysqlConnection). Brak / active=0 → null.
  2. Weryfikacja hasła:
     - PRIMARY: `password_verify($password, $passwd)` (bcrypt, wszystkie realne konta).
     - FALLBACK legacy (tylko gdy hash nie jest bcrypt, tj. len==32 hex): `hash_equals($passwd, md5($cookieKey . $password))`. $cookieKey z parameters.php PS (KROK 1a). (Defensywne — dziś brak takich kont.)
  3. Hasło niezgodne → null.
  4. SELECT role FROM divechat_admin_roles WHERE employee_id = ? (PostgresConnection). Brak roli → null (hasło OK ale brak uprawnień do czatu = brak dostępu).
  5. Zwróć employee_id+role+email.
- Timing: zawsze wykonuj password_verify nawet gdy email nieznany (porównanie z dummy bcrypt hash), by nie zdradzać istnienia konta różnicą czasu. Komunikat błędu zawsze identyczny ("Nieprawidłowy login lub hasło"), nie rozróżniaj "zły email" vs "złe hasło" vs "brak roli".

### KROK 1a — odczyt cookie_key PS (tylko dla fallback legacy)
- Ścieżka: dirname backendu → ../newtmp2/app/config/parameters.php (potwierdzić realną ścieżkę względną z chat.divezone.pl; jeśli poza open_basedir — odczyt może paść, wtedy legacy fallback nieaktywny, zaloguj warning, bcrypt działa niezależnie).
- Parsuj wartość 'cookie_key' z tablicy parameters (plik zwraca `return [... 'parameters' => ['cookie_key'=>'...']]` w PS 1.7). Bezpiecznie: include + odczyt klucza, NIE regex na surowym pliku jeśli się da.
- NIE logować cookie_key nigdzie.

## KROK 2 — Sesja mobilna (server-side w PostgreSQL)
- Tabela `divechat_mobile_sessions` (migracja): session_token (varchar, losowy 32+ bajtów hex/base64url, UNIQUE), employee_id int, role varchar, created_at, expires_at, last_seen_at. (Notatka: nazwa odrębna od divechat_conversations.session_id — to sesja PRACOWNIKA, nie rozmowy.)
- Klasa src/Auth/MobileSessionStore.php: create(employee_id, role) → token; validate(token) → ['employee_id','role']|null (sprawdza expires_at > now, odświeża last_seen_at i przedłuża expires_at — sliding 12h); destroy(token).
- Token generowany `bin2hex(random_bytes(32))`. TTL 12h sliding.

## KROK 3 — Endpointy (routes.php + nowy MobileAuthController)
- POST /m/api/login {email, password} → authenticate; sukces: create session, ustaw cookie, zwróć {ok:true, role}. Błąd: 401 {ok:false, error:"Nieprawidłowy login lub hasło"}.
- POST /m/api/logout → destroy session + skasuj cookie. {ok:true}.
- GET /m/api/whoami → z cookie: {employee_id, role} lub 401.
- Cookie: nazwa np. `dz_madmin`, wartość=token, HttpOnly, Secure, SameSite=Lax, Path=/m, Max-Age=12h. (Secure OK — PROD na HTTPS.)
- Rate-limit logowania: prosty licznik prób per IP (reużyj RateLimiter jeśli pasuje, lub lekki token-bucket) — np. 10 prób/15min, by utrudnić brute-force. Po przekroczeniu 429.

## KROK 4 — Most do istniejących endpointów rozmów
- Istniejące /api/conversations* używają ServerHmacVerifier (nagłówki serwerowe z modułu PS). Mobilny widok NIE ma tych nagłówków — ma cookie sesji.
- Decyzja implementacyjna (wybierz prostsze, opisz w raporcie):
  a) Nowe endpointy pod /m/api/conversations* w MobileAuthController/dedykowanym kontrolerze, które: walidują cookie (MobileSessionStore) → mają employee_id+role → wołają TEN SAM ConversationStore (list/getBySessionId/updateAdminStatus) bezpośrednio. Reużycie logiki store, własna cienka warstwa auth (cookie zamiast HMAC). NIE duplikować SQL — wołać ConversationStore.
  b) Współdzielony "guard" akceptujący ALBO nagłówki serwerowe ALBO cookie mobilną w ConversationsController.
- REKOMENDACJA: a. Czyste rozdzielenie kanałów (serwerowy HMAC dla panelu PS, cookie dla mobile), zero ryzyka regresji w istniejącym kanale panelu PS. Te same metody ConversationStore, inna brama auth. Endpointy: GET /m/api/conversations (list z query: page, search, knowledge_gap, admin_status — default filtr "wymagające uwagi" ustala FRONT, nie backend), GET /m/api/conversations/{session_id}, POST /m/api/conversations/{session_id}/status {status, notes}.
- Autoryzacja roli: operator+admin (any-role, jak requireAnyRole) — operator obsługuje rozmowy, to jego praca (spójne z CHAT-T-046).

## Granice
- ZERO zapisu do MySQL PS (tylko SELECT pr_employee).
- Sekret serwerowy / cookie_key NIGDY do przeglądarki ani do logów.
- NIE duplikować SQL rozmów — wołać ConversationStore.
- NIE dotykać istniejącego kanału serwerowego (panel PS działa bez zmian).
- Komunikaty błędów logowania nierozróżnialne (anti-enumeration).
- PHP 8.4, PSR-12, strict_types, type hints.

## Kryteria akceptacji
1. POST /m/api/login z poprawnym emailem+hasłem aktywnego pracownika z rolą → 200 {ok:true, role}, ustawia cookie HttpOnly+Secure+SameSite=Lax.
2. Złe hasło / nieznany email / pracownik bez roli / active=0 → 401 z IDENTYCZNYM komunikatem (nierozróżnialne).
3. Czas odpowiedzi dla nieznanego emaila ≈ jak dla złego hasła (dummy verify — brak timing leak).
4. GET /m/api/whoami z ważną cookie → {employee_id, role}; bez/zły token → 401.
5. Sesja: ważna 12h, sliding (last_seen przedłuża); po expirze → 401.
6. POST /m/api/logout → cookie skasowana, token usunięty, kolejne whoami → 401.
7. GET /m/api/conversations z ważną cookie → lista (te same dane co /api/conversations); bez cookie → 401.
8. GET /m/api/conversations/{sid} + POST .../status działają z cookie, updateAdminStatus zapisuje.
9. Rate-limit: >10 prób logowania/15min z IP → 429.
10. Istniejący panel PS (kanał serwerowy /api/conversations) działa BEZ ZMIAN (brak regresji).
11. php -l clean. Migracja divechat_mobile_sessions wykonana na PROD PG.
12. SMOKE: realne konto (np. Karol id=2 lub 46) loguje się, widzi listę, zmienia status testowej rozmowy, wylogowuje.

## KROK FINALNY — deploy + raport + status + git
- Migracja PG (divechat_mobile_sessions) — wykonać na PROD (sposób jak poprzednie migracje, sprawdź jak robione były wcześniej).
- Deploy standalone (CC sam, wzorzec jak CHAT-T-070). STOP przed deployem: raport php -l + kryteria 1-11 lokalnie/na realnym koncie, potem deploy, potem smoke 12.
- Raport: _instances/backend/handoff/CHAT-T-071_done.md.
- Status: dopisać CHAT-T-071 do _docs/21_STATUS_PROJEKTU.md.
- Git: git status; git add per ścieżka (nowe pliki Auth/*, kontroler, routes.php, migracja, task, handoff, _docs/10 jeśli trzeba); commit wg konwencji (git log); git push origin main. Osobny commit "docs:" dla statusu.
- NIE zaczynać T-072 (front) w tym tasku — STOP po T-071.
