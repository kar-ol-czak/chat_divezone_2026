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

## Deploy (MODUL PS, newtmp2 — reczny rsync Karola)
Reczny rsync (port 5739, --exclude config_pl.xml, bez --delete). Po deployu: skasuj
var/cache/prod + flush LSCache (patrz CLAUDE.md CACHE). Rejestracja hooka na zywym
module — STOP, opisz Karolowi krok (to dotyka produkcji). Weryfikacja: md5 kontrolera/JS
prod==local + grep markera CHAT-T-136 + sprawdzenie ze hook jest w ps_hook_module.

## Git
`git add` per sciezka (divezone_chat.php + JS widgetu + sql + spec + ADR); commit
`CHAT-T-136 modul: atrybucja deterministyczna cookie+hook+tabela (ADR-119)`; push.
NIE `git add .` (drzewo ma cudze pliki). Po deployu osobny docs: commit.

## Domkniecie
Po zweryfikowanym zamowieniu testowym z rekordem w tabeli: karta → "Do weryfikacji"
(Karol potwierdza na realnym zamowieniu), potem "Zrobione". T-137 (GA4) osobno.
