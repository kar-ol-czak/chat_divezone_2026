# CHAT-T-136 MODUL PS — atrybucja deterministyczna: cookie + hook + tabela

**Instancja:** integration (moduł PS) — ewentualnie frontend dla cookie w widgecie.
**Swiat:** MODUL PS (newtmp2). ZERO zmian w backendzie standalone.
**ADR:** ADR-119 (architektura atrybucji; ostatni w pliku ADR-118 — bierz 119).
**Spec:** `_docs/12_atrybucja_czatu.md` — przeczytaj CALY przed praca.
**Karta Trello:** "Chat - dodanie sledzenia konwersji do podawanych linkow"
(https://trello.com/c/g1Dg88kS). Na start → "W trakcie" (boardId=6a55e07bc2193b7dfc53297e).

## Cel
Strumien deterministyczny (zrodlo prawdy, odporny na Consent Mode): powiazac rozmowe
czatu z zamowieniem. Cookie w domenie sklepu → hook zamowienia → tabela.

## Stan zastany (zweryfikowany)
- Widget ma `sessionId` (localStorage), przekazywany do transportu (transport.js).
- Modul ma `hookDisplayFooter` (divezone_chat.php ~543) — tam widget sie laduje.
- Modul NIE rejestruje hooka zamowienia. `install()` (~97) nie ma registerHook dla order.
- Tabela `pr_divechat_order_attribution` NIE istnieje.

## Zakres

### A. Cookie w widgecie (domena sklepu)
Widget przy starcie rozmowy (pierwsza wymiana, nie samo otwarcie) zapisuje cookie
`divechat_session_id` = sessionId, w domenie sklepu, **persistent 30 dni** (decyzja 34b).
Dodaj tez znacznik czasu rozmowy (np. cookie `divechat_last_at` = ISO timestamp lub epoch),
by hook mogl rozroznic last_touch/assist.
Miejsce: JS widgetu (modules/divezone_chat/views/js/), tam gdzie ustala sie sessionId.
Cookie: `path=/`, `max-age=2592000` (30 dni), `SameSite=Lax`. NIE Secure-only jesli sklep
miewa http (sprawdz — divezone.pl jest https, wiec Secure OK).

### B. Tabela `pr_divechat_order_attribution` (MySQL PrestaShop)
Utworz w install() modulu (idempotentnie, IF NOT EXISTS) + osobny plik sql do recznego
uruchomienia na wypadek juz-zainstalowanego modulu. Schema wg specu sekcja 4:
id_attribution PK, id_order (idx), chat_session_id VARCHAR(64) (idx),
attribution_type ENUM('last_touch','assist'), conversation_last_at DATETIME NULL,
date_add DATETIME. Prefix `pr_` przez `_DB_PREFIX_`.

### C. Hook `actionValidateOrder`
1. Zarejestruj: `registerHook('actionValidateOrder')` w install() ORAZ wykonaj rejestracje
   na juz zainstalowanym module (modul jest zainstalowany — install() sie nie wykona
   ponownie; dodaj jednorazowy krok rejestracji hooka, np. przez skrypt lub reinstall
   kontrolowany — OPISZ Karolowi, bo to dotyka produkcji).
2. `hookActionValidateOrder($params)`: odczytaj cookie `divechat_session_id` (przez
   `$this->context->cookie` lub `$_COOKIE`). Jesli jest → zapisz rekord do tabeli:
   id_order z `$params['order']->id`, chat_session_id z cookie, attribution_type
   (last_touch jesli divechat_last_at z tej samej sesji/swieze, inaczej assist),
   conversation_last_at z cookie znacznika, date_add = NOW().
3. Brak cookie → nic nie zapisuj (zamowienie bez czatu).

## Kryteria akceptacji
1. Zamowienie testowe z aktywna rozmowa (cookie ustawione) → rekord w
   pr_divechat_order_attribution z poprawnym id_order + chat_session_id.
2. Zamowienie bez rozmowy (brak cookie) → brak rekordu (nie smiecimy).
3. attribution_type poprawny: rozmowa w tej samej wizycie → last_touch; cookie z wczesniej
   → assist.
4. Tabela zakladana idempotentnie (ponowny install nie wywala bledu).
5. Cookie 30 dni, SameSite=Lax, widoczne w domenie sklepu po starcie rozmowy.

## Deploy (MODUL PS, newtmp2 — DEPLOYUJE CC po jawnym "deployuj" Karola)

CC deployuje sam, jak przy CHAT-T-133 czesc B (zweryfikowane: CC zrobil backup + rsync
kontrolera + md5 + cache — dzialalo). Karol NIE rsyncuje recznie. Bramka to ADR-089
(STOP + jawne "deployuj"), backup, md5, lint, smoke — nie ludzkie rece na klawiaturze.

KOLEJNOSC NA PRODUKCJI (wazna — hook NA KONCU):
1. backup + rsync do newtmp2 (port 5739, --exclude config_pl.xml, bez --delete):
   divezone_chat.php, views/js/widget-bundle.js, sql/. NIE deployowac config/tools.php.
2. cache: skasuj var/cache/prod + flush LSCache (patrz CLAUDE.md CACHE).
3. tabela: uruchom sql/pr_divechat_order_attribution.sql na MySQL sklepu.
4. DOPIERO TERAZ rejestracja hooka (INSERT do pr_hook_module).
Powod kolejnosci: hook zaczyna odpalac natychmiast po rejestracji. Gdyby tabeli lub kodu
jeszcze nie bylo, kazde zamowienie logowaloby blad (nieszkodliwie przez try/catch, ale
smiecilo). Rejestracja na koncu = wszystko gotowe, zanim zacznie dzialac.

Weryfikacja: md5 divezone_chat.php i widget-bundle.js prod==local + grep markera
CHAT-T-136 na newtmp2 + tabela istnieje + wpis w pr_hook_module.

ROLLBACK (lagodny): `DELETE FROM pr_hook_module WHERE id_module=204 AND id_hook=1;`
— hook przestaje odpalac, sklep dziala jak przedtem, tabela zostaje nietknieta.

## Git
`git add` per sciezka (divezone_chat.php + JS widgetu + sql + spec + ADR); commit
`CHAT-T-136 modul: atrybucja deterministyczna cookie+hook+tabela (ADR-119)`; push.
NIE `git add .` (drzewo ma cudze pliki). Po deployu osobny docs: commit.

## Domkniecie
Po zweryfikowanym zamowieniu testowym z rekordem w tabeli: karta → "Do weryfikacji"
(Karol potwierdza na realnym zamowieniu), potem "Zrobione". T-137 (GA4) osobno.

## Wynik (CC, 2026-07-14)

**Status: KOD GOTOWY — commit 350f116, push OK. STOP przed dotknięciem produkcji.**

Zaimplementowany strumień deterministyczny (świat MODUŁ PS, zero zmian w backendzie standalone):

### A. Cookie w widgecie — `views/js/widget-bundle.js`
`setAttributionCookies(sessionId)` wołane w `onDone` tuż po `persistSession()`, tj. po
KAŻDEJ realnej wymianie (nie przy otwarciu widgetu, nie przy restore rozmowy). Trzy cookie
w domenie sklepu (`path=/; SameSite=Lax; Secure` na https):
- `divechat_session_id` — persistent 30 dni (max-age=2592000) → chat_session_id.
- `divechat_last_at` — persistent 30 dni, `Date.now()` (epoch ms) → conversation_last_at.
- `divechat_visit` — cookie sesyjne (bez max-age) → sygnał „ta sama wizyta".

### B. Tabela — `divezone_chat.php::createAttributionTable()` + `sql/pr_divechat_order_attribution.sql`
`pr_divechat_order_attribution` (schema wg spec sekcja 4), idempotentna (IF NOT EXISTS).
Zakładana w install() + bliźniaczy plik SQL do ręcznego uruchomienia.

### C. Hook — `divezone_chat.php::hookActionValidateOrder()`
Czyta cookie z `$_COOKIE` (raw JS cookie, NIE szyfrowany `$this->context->cookie`).
Brak `divechat_session_id` → nic (zamówienie bez czatu). last_touch gdy `divechat_visit=1`
obecne, inaczej assist. Cały handler w try/catch (atrybucja nigdy nie wywraca zamówienia).
`registerHook('actionValidateOrder')` dodany do install().

Weryfikacja lokalna: `php -l` czysto (PHP 7.2-safe), `node --check` widget-bundle.js OK,
markery CHAT-T-136 obecne (JS 2×, PHP 6×). ADR-119 dopisany.

### ⚠️ KROKI NA PRODUKCJI — do zrobienia przez Karola (NIE wykonane przez CC)

1. **rsync modułu → newtmp2** (port 5739, `--exclude config_pl.xml`, bez `--delete`):
   `divezone_chat.php`, `views/js/widget-bundle.js`, `sql/pr_divechat_order_attribution.sql`.
   NIE deployować `config/tools.php`.
2. **Cache po rsync:** skasować `var/cache/prod` + flush LSCache (LiteSpeed).
3. **Założyć tabelę na prod** (install() się nie wykona ponownie — moduł zainstalowany):
   uruchomić `sql/pr_divechat_order_attribution.sql` na bazie MySQL sklepu
   (phpMyAdmin lub `mysql < plik`). Idempotentne.
4. **Zarejestrować hook `actionValidateOrder` na żywym module** (dotyka produkcji):
   - Wariant A (najczystszy): panel PS → Moduły → DiveZone Chat → odinstaluj+zainstaluj
     — ryzyko: usuwa tab/config, wymaga ponownego wpisania sekretów. NIE zalecane.
   - Wariant B (zalecany): ręczny INSERT do `pr_hook_module` wiążący id_module modułu
     z id_hook `actionValidateOrder` (+ ewentualnie utworzyć wiersz w `pr_hook`, jeśli
     hooka nie ma). Do przygotowania jako osobny skrypt SQL, gdy Karol da zielone światło
     (potrzebne id_module z `pr_module WHERE name='divezone_chat'`).
   - Weryfikacja: `SELECT * FROM pr_hook_module hm JOIN pr_hook h ON h.id_hook=hm.id_hook
     WHERE h.name='actionValidateOrder' AND hm.id_module=(SELECT id_module FROM pr_module
     WHERE name='divezone_chat');` zwraca wiersz.
5. **Test:** zamówienie testowe z aktywną rozmową → rekord w `pr_divechat_order_attribution`
   (poprawny id_order + chat_session_id + typ); zamówienie bez czatu → brak rekordu.

Po zweryfikowanym zamówieniu testowym: karta Trello → „Do weryfikacji" → „Zrobione".

## Wynik — POPRAWKI + DEPLOY (CC, 2026-07-15)

**Status: WDROŻONE NA PROD. Karta → „Do weryfikacji" (Karol robi zamówienie testowe).**

### Poprawki (zatwierdzone przez Karola: 36a + 37a) — commit `a68ccc3`, push OK
- **36a** `hookActionValidateOrder`: `catch (Exception)` → `catch (Throwable)`. W PHP 7+
  `catch(Exception)` NIE łapie `Error`/`TypeError`; hook jest w ścieżce zakupu — Error mógłby
  wywrócić zamówienie. `Throwable` (PHP 7.0+, 7.2-safe) łapie oba. `catch(Exception)` w
  `createAttributionTable()` (~164) zostawiony (inna stawka).
- **37a** `pr_divechat_order_attribution`: `KEY idx_id_order` → `UNIQUE KEY uniq_id_order (id_order)`
  w `createAttributionTable()` ORAZ w `sql/pr_divechat_order_attribution.sql`. Jedno zamówienie =
  jeden rekord; retry płatności nie zduplikuje (hook loguje `insert failed` i idzie dalej — try/catch).
- Lint: `php -l` czysto, `node --check` widget-bundle.js OK.

### Deploy (CC — świat MODUŁ PS / newtmp2, port 5739), kolejność wg ADR-089, hook NA KOŃCU:
1. **Backup** prod → `_deploy_bak/CHAT-T-136_20260715_073909/` (divezone_chat.php + widget-bundle.js).
2. **rsync** (bez `--delete`, bez config/tools.php): `divezone_chat.php`, `views/js/widget-bundle.js`,
   `sql/` (2 pliki). md5 prod==local potwierdzone:
   - divezone_chat.php `5af1daae050844f0cf2f1300490d05bf`
   - widget-bundle.js `eb26320d8a0b8035ea425879b8272553`
   - markery: PHP CHAT-T-136 ×6, JS ×2, `catch (Throwable)` ×1, `uniq_id_order` ×1. `php -l` czysto.
3. **Cache**: `var/cache/prod` skasowany (×2 — drugi raz PO rejestracji hooka, by PS przeładował
   mapę hook→moduł). Shop 200 po warmupie.
4. **Tabela**: `pr_divechat_order_attribution` utworzona (PDO na parametrach PS — `mysql` CLI
   odrzucał hasło). `UNIQUE uniq_id_order` potwierdzony (Non_unique=0).
5. **Rejestracja hooka** (NA KOŃCU): idempotentny INSERT do `pr_hook_module`
   (id_module=204, id_hook=1, id_shop=1, position=10). 1 wiersz wstawiony, weryfikacja zwraca
   `id_module=204 | id_hook=1 | id_shop=1 | position=10 | name=actionValidateOrder`.

**Pre-checki na żywym PROD potwierdziły wartości Karola:** module 204 = divezone_chat (active=1),
hook 1 = actionValidateOrder, hook wcześniej NIE zarejestrowany, MAX(position na hooku 1)=9
→ position=10 wchodzi PO przelewy24/stripe/supercheckout/kurierach (celowo, bezpiecznie).

**Skrypt rejestracji** zapisany jako `sql/register_hook_actionvalidateorder.sql` (idempotentny,
z zapytaniem weryfikacyjnym i rollbackiem w komentarzu).

**ROLLBACK** (gdyby): `DELETE FROM pr_hook_module WHERE id_module=204 AND id_hook=1;` — hook
przestaje odpalać, tabela nietknięta.

### Do weryfikacji przez Karola (realne zamówienie):
- Zamówienie z aktywną rozmową (cookie `divechat_session_id`) → rekord w
  `pr_divechat_order_attribution` (id_order + chat_session_id + typ). Log PS: `actionValidateOrder:
  atrybucja zapisana`.
- Zamówienie bez czatu → brak rekordu.
