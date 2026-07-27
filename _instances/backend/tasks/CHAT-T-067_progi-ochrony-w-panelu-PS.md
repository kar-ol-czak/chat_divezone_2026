# CHAT-T-067 — progi ochrony (cap/rate-limit/input) w panelu PrestaShop (admin-only)

**Instancje:** backend (standalone) + frontend (moduł PS). DWA obszary.
**Powiązane:** CHAT-T-064 (cap kosztów), CHAT-T-066 (rate-limit), SettingsController + SettingsStore (istniejący kanał settings admin-only), ADR-082.
**Decyzje:** 171 (jeden panel — TYLKO PrestaShop, żaden drugi panel), 173a (moduł PS pushuje progi do backendu przez kanał serwerowy), 174a (admin-only), 175a (po T-066), 176a (SettingsStore wygrywa, .env fallback), 177a (wszystkie progi w panelu).

## Cel
Przenieść strojenie progów ochrony z .env do panelu Konfiguracja w PrestaShop (admin-only). Backend czyta progi z SettingsStore (PG) z fallbackiem do .env. Karol stroi wszystko z jednego panelu (PS), bez SSH.

## Stan (zweryfikowane)
- Backend ma SettingsController (admin-only: requireAdmin, HMAC+rola admin) + SettingsStore (PG, divechat_settings, get(key,default) z JSON, setMany). Kanał POST /api/settings JUŻ przyjmuje bulk {settings:{...}} admin-only. NIE trzeba nowego endpointu.
- Progi dziś czytane w ChatController z .env (Config::get) i przekazywane jako argumenty do CostGuard::maybeSendAlert i RateLimiter::check. CostGuard/RateLimiter NIE czytają progów same — dostają jako argumenty. → zmieniamy TYLKO skąd ChatController bierze progi.
- Panel Konfiguracja (AdminDivezoneChatController): woła whoami, ma $isAdmin = ($role==='admin') (linia ~332), blok if($isAdmin) — tam admin-only pola.
- SettingsStore->get(key, default) ma wbudowany fallback — idealne do 176a.

## CZĘŚĆ A — BACKEND (CC wdraża SAM)

### A1. ChatController czyta progi z SettingsStore z fallbackiem .env (176a)
- DI ChatController: dodać SettingsStore (jeśli jeszcze nie ma).
- Tam gdzie dziś Config::get('DIVECHAT_DAILY_CAP_USD') itp. → zastąpić: $settingsStore->get('protect_daily_cap_usd', (float) Config::get('DIVECHAT_DAILY_CAP_USD', 10)).
- Klucze SettingsStore (spójna konwencja, np. prefiks protect_):
  - protect_daily_cap_usd (float, .env DIVECHAT_DAILY_CAP_USD default 10)
  - protect_cost_alert_usd (float, .env DIVECHAT_COST_ALERT_USD default 5)
  - protect_cost_alert_email (string, .env DIVECHAT_COST_ALERT_EMAIL default k.susicki@divezone.pl)
  - protect_max_input_chars (int, .env DIVECHAT_MAX_INPUT_CHARS default 2000)
  - protect_rl_session_max (int, .env DIVECHAT_RL_SESSION_MAX default 10)
  - protect_rl_session_window (int, .env DIVECHAT_RL_SESSION_WINDOW default 300)
  - protect_rl_ip_max (int, .env DIVECHAT_RL_IP_MAX default 40)
  - protect_rl_ip_window (int, .env DIVECHAT_RL_IP_WINDOW default 300)
- Rzutowanie typów: SettingsStore zwraca zdekodowany JSON — wymusić (float)/(int) na odczycie (panel może zapisać string). Walidacja sanity: cap>0, window>0, max>=1; jeśli wartość z panelu bezsensowna (0/ujemna/nie-liczba) → użyć .env default (NIE wyłączyć ochrony przez błędny wpis w panelu — bezpiecznik).
- CostGuard/RateLimiter BEZ ZMIAN (dostają progi jako argumenty).
- .env ZOSTAJE jako fallback (176a) — nie usuwać.

### A2. (opcjonalnie) walidacja po stronie SettingsController
- POST /api/settings przyjmuje bulk — działa bez zmian. Ewentualnie dodać walidację kluczy protect_* (typy/zakresy) przy zapisie, żeby panel nie wstrzyknął śmieci. Jeśli proste — dodać; jeśli komplikuje, walidacja przy ODCZYCIE w ChatController (A1) wystarcza jako bezpiecznik.

## CZĘŚĆ B — MODUŁ PS (Karol wgrywa ręcznie 116b)

### B1. Sekcja "Ochrona i limity" w zakładce Konfiguracja — ADMIN-ONLY (174a)
- W renderConfigSection(): nowa sekcja pól WEWNĄTRZ bloku if($isAdmin) (operator NIE widzi — decyzja 174a, Karol: operator nie ma dostępu do Konfiguracji, a te pola tym bardziej).
- Pola (wszystkie, 177a):
  - Dzienny cap kosztów (USD) — number, step 0.5
  - Próg alertu kosztów (USD) — number, step 0.5
  - Email alertu — text/email
  - Limit długości wiadomości (znaki) — number
  - Rate-limit sesji: max wiadomości — number; okno (sekundy) — number
  - Rate-limit IP: max wiadomości — number; okno (sekundy) — number
- Prefill wartościami AKTUALNYMI z backendu: na wejściu sekcji wywołać GET /api/settings (callBackend, admin-only) i wypełnić pola bieżącymi wartościami (lub defaultami gdy brak). Żeby admin widział co jest ustawione, nie puste pola.
- Krótkie opisy pod polami (co robi, ostrzeżenie "to bezpieczniki — zmieniaj świadomie").

### B2. Push progów do backendu przy zapisie
- Submit sekcji (np. submitDivezoneChatProtect): zebrać pola, walidacja lokalna (liczby dodatnie, cap>0, sensowne zakresy), wysłać do backendu POST /api/settings (callBackend POST, kanał serwerowy admin-only — JUŻ istnieje) jako bulk {settings:{protect_daily_cap_usd:..., protect_rl_session_max:..., ...}}.
- Flash sukces/błąd (jak inne sekcje configu). 403 → komunikat admin-only.
- NIE zapisywać progów w Configuration PS (one żyją w backendzie SettingsStore — panel PS to tylko UI edycji; źródło prawdy = backend). Panel czyta (GET) i zapisuje (POST) do backendu, nie trzyma własnej kopii. (Inaczej niż nudge/TTL, które są konsumowane przez front — progi konsumuje backend.)

## Granice
- Backend: ChatController czyta z SettingsStore+fallback. CostGuard/RateLimiter nietknięte. .env zostaje (fallback).
- Walidacja sanity przy odczycie (bezsensowna wartość z panelu → .env default, NIE wyłączenie ochrony).
- Panel: sekcja admin-only (if $isAdmin), push przez istniejący /api/settings. Bez nowego endpointu. Bez trzymania progów w Configuration PS.
- Deploy: A (backend) CC sam; B (moduł) Karol ręcznie (rsync).
- PHP 8.4 backend / PHP 7.2 PS 1.7.6 moduł.

## Kryteria akceptacji
1. ChatController czyta 8 progów z SettingsStore; brak klucza → .env default; bezsensowna wartość → .env default (ochrona nigdy nie wyłączona błędnym wpisem).
2. Panel Konfiguracja: sekcja "Ochrona i limity" widoczna TYLKO dla admina (operator nie widzi).
3. Pola prefillowane aktualnymi wartościami z backendu (GET /api/settings).
4. Zapis w panelu → POST /api/settings (admin-only HMAC) → backend zapisuje w SettingsStore → kolejny request czatu używa nowych progów (test: zmień cap w panelu, zweryfikuj że backend egzekwuje nowy).
5. Operator (nie admin) nie ma dostępu do sekcji (403/niewidoczne).
6. .env nadal działa jako fallback (gdy SettingsStore pusty).
7. php -l backend; PHP 7.2 moduł; testy PROD opisane.
