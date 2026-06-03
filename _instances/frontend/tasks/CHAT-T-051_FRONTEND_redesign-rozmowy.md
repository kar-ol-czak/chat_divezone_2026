# CHAT-T-051 — FRONTEND/PS: redesign zakładki „Rozmowy" (master-detail, formatowanie czatu, layout)

**Instancja:** frontend (moduł PrestaShop, render natywny PHP)
**Powiązane:** CHAT-T-048 (zakładka Rozmowy — bazowa wersja), ADR-070, ADR-072, handoff `_docs/25` (117a/118a).
**Decyzje sesji:** 111a (redesign teraz, przed Analityką), + uwagi UX Karola ze zrzutu (master-detail, formatowanie, etykieta Status).
**Plik:** `modules/divezone_chat/controllers/admin/AdminDivezoneChatController.php` (sekcja Rozmów z CHAT-T-048).
**Backend:** GOTOWY (`/api/conversations/*`, any-role). Bez zmian w backendzie — patrz wyjątek w pkt 4 (first_message).

## Cel
Zakładka Rozmowy ma być wygodna do codziennej obsługi: lista rozmów stale widoczna po lewej (wąska kolumna), wybrana rozmowa po prawej (szeroka, przewijana) — bez wchodzenia/wychodzenia jak teraz. Plus: czytelne formatowanie czatu (bold, klikalne linki) i poprawa etykiety „Wyświetlany" → „Status".

## Problemy do rozwiązania (3, z obserwacji na żywym panelu)

### Problem A — układ master-detail (zamiast osobnych ekranów lista/szczegóły)
Dziś: lista → klik „Otwórz" → pełny reload na osobny ekran szczegółów → „wróć do listy" = ciągłe wchodzenie/wychodzenie. Stary `/admin` miał wygodniej: wąska lista po lewej, rozmowa w szerokiej kolumnie po prawej, klik w liście podmieniał prawą kolumnę.

Docelowo: JEDEN ekran zakładki Rozmowy, dwie kolumny:
- **LEWA (wąska, ~320-360px, własny scroll):** lista rozmów. Klik w pozycję = otwiera rozmowę po prawej. Aktywna pozycja podświetlona.
- **PRAWA (elastyczna, szersza, własny scroll):** pełna wybrana rozmowa (meta + koszty + formularz statusu + przebieg).

### Problem B — formatowanie czatu (czytelność)
Dziś `renderConvMessages` robi `htmlspecialchars` + `nl2br` → operator widzi surowe `**tekst**` zamiast pogrubienia i pełne URL-e zamiast klikalnych linków. To mocno utrudnia czytanie (granica „bez markdown" z CHAT-T-048 okazała się za restrykcyjna dla operatora).

### Problem C — etykieta „Wyświetlany" → „Status"
Na żywym panelu kolumna/pole statusu wyświetla się jako „Wyświetlany" (dziwna nazwa). W KODZIE jest już `$this->l('Status')` (CHAT-T-048) — więc „Wyświetlany" pochodzi z TŁUMACZENIA PrestaShop na serwerze (baza `pr_translation` lub `translations/pl.php` modułu), które nadpisuje string „Status". Sama zmiana kodu NIE wystarczy.

---

## Specyfikacja listy po lewej (decyzja Karola — TYLKO te pola, w tej kolejności)
Każda pozycja na liście (wąska kolumna) pokazuje wyłącznie:
1. **Pierwsza wiadomość** (pierwsza wiadomość użytkownika, skrócona — np. 2 linie, ellipsis).
2. **Data rozpoczęcia** (`started_at`, format `Y-m-d H:i`).
3. **Klient | Status** — w jednej linii: `#ID` lub „gość", separator `|`, badge statusu (mapowanie PL z `convStatusOptions`).
Znacznik luki wiedzy (⚠) może zostać przy statusie jak w CHAT-T-048. NIE pokazujemy modelu/kosztu/liczby wiadomości na liście (idą tylko do prawej kolumny ze szczegółami).

## Formatowanie czatu (Problem B) — lekki, bezpieczny rendering
Nowa metoda pomocnicza (np. `formatConvBubbleText($raw)`), użyta w `renderConvMessages` zamiast samego `nl2br(htmlspecialchars())`. Kolejność operacji (KRYTYCZNE dla bezpieczeństwa):
1. NAJPIERW `htmlspecialchars($raw, ENT_QUOTES)` — escape całości (zero surowego HTML z treści rozmowy).
2. POTEM na zescapowanym tekście:
   - `**bold**` → `<strong>bold</strong>` (regex na sparowane `**`).
   - URL (http/https) → `<a href="..." target="_blank" rel="noopener noreferrer nofollow">...</a>`. Po escapie URL w tekście to czysty ASCII (`&` już jako `&amp;`), więc budując `href` użyć tej samej zescapowanej formy — NIE odwracać escape.
   - `nl2br` na końcu.
Zakres minimalny: bold + linki. NIE dodawać pełnego markdown-parsera, NIE renderować obrazków, NIE wykonywać żadnego HTML z treści. To ma poprawić czytelność, nie odtworzyć widget. Jeśli regex na linki ma być prosty i pewny — lepszy konserwatywny (czasem nie podlinkuje egzotycznego URL) niż agresywny (ryzyko złego HTML).

## Etykieta „Wyświetlany" → „Status" (Problem C) — DWUTOROWO
1. **Kod:** upewnić się, że wszystkie wystąpienia w sekcji Rozmów używają `$this->l('Status')` (już tak jest po CHAT-T-048 — zweryfikować, nie ma literału „Wyświetlany" w kodzie modułu).
2. **Tłumaczenie PS (źródło problemu):** „Wyświetlany" pochodzi z tłumaczenia modułu na serwerze. CC NIE ma dostępu do produkcyjnej bazy/translations (to żywy sklep). Dlatego:
   - CC w raporcie OPISZE Karolowi DOKŁADNIE, gdzie to poprawić ręcznie w PS: **Międzynarodowy → Tłumaczenia → Tłumaczenia modułów → divezone_chat → język polski**, znaleźć string „Status" (lub bezpośrednio wyświetlane „Wyświetlany") i ustawić tłumaczenie na „Status". Alternatywnie: sprawdzić `translations/pl.php` modułu na serwerze (`~/public_html/newtmp2/modules/divezone_chat/translations/pl.php`) i poprawić mapowanie.
   - Jeśli w repo NIE ma `translations/pl.php` (potwierdzone: nie ma), to znaczy że plik istnieje tylko na serwerze — CC tego pliku NIE tworzy w repo (nie znając jego pełnej zawartości produkcyjnej, nadpisanie zepsułoby inne stringi). Poprawka = ręczna w panelu PS przez Karola.

## Granice (czego NIE robić)
- NIE dodawać markdown-parsera ani renderu HTML z treści rozmowy (tylko bold + linki).
- NIE tworzyć `translations/pl.php` w repo (nieznana pełna zawartość produkcyjna — ryzyko nadpisania innych stringów).
- NIE ruszać backendu poza ewentualnym `first_message` w `ConversationStore::list` (patrz pytanie niżej — rozstrzygnięcie przed implementacją).
- NIE ruszać pozostałych zakładek.
- NIE wdrażać modułu sam (116b — wgrywa Karol).

## Kryteria akceptacji
1. Zakładka Rozmowy = jeden ekran: wąska lista po lewej (własny scroll), szeroka rozmowa po prawej (własny scroll). Klik w liście otwiera rozmowę po prawej, aktywna pozycja podświetlona.
2. Pozycja listy pokazuje DOKŁADNIE: pierwsza wiadomość (skrócona) + data rozpoczęcia + „Klient | Status (badge)". Nic więcej.
3. Filtry i paginacja listy nadal działają (zachowane w URL).
4. Bąble czatu: `**bold**` renderuje się jako pogrubienie, URL-e są klikalne (`target=_blank rel=noopener`), reszta treści zescapowana — zero surowego `**` i zero gołych URL-i.
5. Etykieta statusu w UI to „Status" (kod) + instrukcja ręcznej poprawki tłumaczenia PS w raporcie.
6. Zmiana statusu (POST) nadal działa po redesignie.
7. PHP 7.2 / PS 1.7.6 — bez typed properties, bez `match`, typehints jak reszta pliku. Jeśli użyty JS — wyłącznie w `views/js/` (osobny plik, zasada 117a), bez bibliotek zewnętrznych.

---

## ROZSTRZYGNIĘCIA (decyzje 112a, 113a)

### 112a — `first_message` do `ConversationStore::list` (mini-zmiana backendu, any-role)
Lista `/api/conversations` NIE zwraca dziś treści pierwszej wiadomości. Dodać pole, używając SPRAWDZONEGO wzorca z `CostAnalytics::topConversations()` (ten sam fragment napędza „Pierwsza wiadomość" w starym /admin → /koszty):
- W `ConversationStore::list()`, w głównym SELECT dorzucić skorelowane podzapytanie:
  ```
  (SELECT m.content FROM divechat_messages m
   WHERE m.conversation_id = divechat_conversations.id AND m.role = 'user'
   ORDER BY m.created_at, m.id LIMIT 1) AS first_user_message
  ```
  (dostosować alias tabeli do tego, jak `list()` aliasuje `divechat_conversations` — sprawdzić w pliku; `topConversations` używa `c.`).
- W mapowaniu wiersza dodać: `'first_message' => $row['first_user_message'] ?: null,`.
- To jedyna zmiana backendu w tym tasku. Auth bez zmian (any-role). NIE dotykać innych metod ConversationStore.
- Plik: `standalone/src/Chat/ConversationStore.php`. Standalone backend CC wdraża SAM (to nie moduł PS — 116b dotyczy tylko modułu).

### 113a — master-detail server-side, BEZ JS
Jeden ekran zakładki renderuje OBIE kolumny naraz (CSS flex/grid, każda kolumna własny `overflow:auto`, wysokość ograniczona np. `calc(100vh - offset)` lub stała np. 70vh):
- LEWA: lista (linki `?...&session_id=...` jak w CHAT-T-048). Pozycja odpowiadająca bieżącemu `session_id` z query = klasa `is-active` (podświetlenie).
- PRAWA: jeśli `session_id` w query → render wybranej rozmowy (meta + koszty + formularz statusu + przebieg). Jeśli brak `session_id` → placeholder „Wybierz rozmowę z listy po lewej".
- Klik w pozycję = pełen reload tej samej zakładki z nowym `session_id` (wizualnie: lista zostaje, prawa kolumna się zmienia). Filtry/paginacja listy zachowane w linkach (jak CHAT-T-048).
- BEZ JS, bez fetch, bez bibliotek — spójne z CHAT-T-048. (Płynny wariant AJAX z proxy w PHP = osobny przyszły temat, sekret HMAC zostaje na serwerze — NIE robić teraz.)
- Refaktor: `renderConversationsSection` przestaje być routerem list-XOR-detail; teraz renderuje layout dwukolumnowy (lewa zawsze, prawa warunkowo). Metody `renderConversationsList`/`renderConversationDetail` z CHAT-T-048 przerobić na renderery odpowiednich kolumn (lista = pozycje wg nowej specyfikacji pól; detail = jak było, ale jako zawartość prawej kolumny, bez osobnego „wróć do listy").

### Pola pozycji listy (przypomnienie — TYLKO te, decyzja Karola)
Pierwsza wiadomość (z `first_message`, skrócona ~80 znaków + ellipsis jak stary /admin) · Data rozpoczęcia (`started_at`, `Y-m-d H:i`) · „Klient | Status(badge)". Model/koszt/liczba wiadomości NIE na liście.
