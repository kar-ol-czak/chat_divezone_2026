# CHAT-T-058 — FRONTEND/PS: polish dymka nudge (CSS + emoji fix)

**Instancja:** frontend (widget, moduł PrestaShop)
**Plik:** modules/divezone_chat/views/js/widget-loader.js (jedyny edytowany).
**Powiązane:** CHAT-T-056 (nudge), ADR-078.
**Decyzje:** 140a (emoji jako prefiks z loadera, NIE z configu), 141a (treść configu bez emoji, zawijanie płynne wg szerokości). Karol wgrywa moduł ręcznie (116b).

## Cel
Dopracowanie wyglądu dymka nudge wg uwag Karola + naprawa emoji (renderuje się jako „????").

## Fix 1 — emoji „????" → 🤿 (decyzja 140a, przyczyna: NIE CSS)
PRZYCZYNA (zdiagnozowana): emoji 🤿 (4-bajtowy UTF-8) ginie w bazie PrestaShop (pr_configuration prawdopodobnie utf8, nie utf8mb4) → zapisywane jako „????". Kod modułu jest poprawny (default ma 🤿, JSON z JSON_UNESCAPED_UNICODE), ale wartość z bazy jest już zepsuta.
ROZWIĄZANIE (140a): emoji NIE pochodzi z configu, tylko z loadera (plik .js w repo = UTF-8, nie przechodzi przez bazę).
- W widget-loader.js, render tekstu nudge (ok. linia 249, `textEl.textContent = text`): poprzedzić tekst stałym prefiksem emoji z kodu. Np. zdefiniować stałą `var NUDGE_EMOJI = '🤿';` (na górze loadera, obok TEAL) i renderować `textEl.textContent = NUDGE_EMOJI + ' ' + text;`.
- WAŻNE: plik widget-loader.js MUSI być zapisany w UTF-8 (emoji w źródle). Po edycji zweryfikować, że emoji jest poprawnym bajtem UTF-8 (nie ucieczką, nie ????).
- Tekst z configu pozostaje BEZ emoji. Karol zaktualizuje pole configu na: „Hej! Nie wiesz, jaki sprzęt wybrać? Zapytaj naszych specjalistów." (bez 🤿). Wynik wizualny: „🤿 Hej! Nie wiesz, jaki sprzęt wybrać? Zapytaj naszych specjalistów."
- (NIE ruszać DEFAULT_NUDGE_TEXT w module ani bazy/charsetu — to osobny, większy temat utf8mb4, poza zakresem.)

## Fix 2 — CSS dymka (.dz-nudge) — wartości od Karola
W baseStyle (loader), reguła `.dz-nudge`:
- background: #f2feff (było #ffffff)
- font-size: 16px (było 14px)
- width: 320px (było 280px); zachować max-width:calc(100vw - 40px) dla mobile
- padding: 20px 38px 20px 20px (było 14px 38px 14px 14px) — prawy 38px zostaje (miejsce na ×)
- pozostałe (position, right, bottom:88px, border-radius, box-shadow, font-family, line-height, cursor, animation) BEZ zmian
Tekst (.dz-nudge__text): zawijanie płynne wg szerokości (141a) — NIE wymuszać <br>. Zostawić white-space:pre-line/word-break jak jest; przy 320px + 16px tekst zawinie się naturalnie.

## Fix 3 — CSS przycisku CTA (.dz-nudge__cta) — wartości od Karola
- padding: 12px 14px (było 8px 14px)
- font-size: 15px (było 13px)
- background: #f7b427 (było TEAL) — UWAGA: to zmienia logikę :hover. Obecnie hover = TEAL_DARK. Ustawić tło #f7b427 na stałe; hover może być nieco ciemniejszy odcień żółtego (np. #e0a31f) zamiast TEAL_DARK (żeby nie wracał do zielonego). Focus-visible outline może zostać TEAL (kontrast OK).
- color: #0b3b3d (było #fff)
- font-weight zostawić 600; border-radius zostawić

## Fix 4 — CSS przycisku × (.dz-nudge__close) — wartości od Karola
- font-size: 36px (było 18px)
- color: #555555 (było #888); hover color: #1e6363 (TEAL) — zmienić obecny hover color:#333 na #1e6363
- right: 10px (było 6px); top zostawić ok. 6px lub dopasować by × ładnie siedział przy font-size 36px (sprawdzić wizualnie — przy 36px może trzeba top nieco w górę/dół; szerokość/wysokość przycisku 24px może być za mała dla glifu 36px — zwiększyć width/height do ~40px lub usunąć sztywne wymiary i dać line-height:1)
- font-family: cieńszy, elegancki — zamiast inherit dać np. font-family: "Helvetica Neue", Arial, sans-serif z font-weight:300 (cieńszy × ). Cel: × ma być smukły, nie pogrubiony.
- hover background:#f0f0f0 — można zostawić lub usunąć (przy większym × tło hover może wyglądać dziwnie; ocenić). Zachować focus-visible.

## Granice
- Tylko widget-loader.js. Bez backendu, bez modułu PHP (config), bez bazy/charsetu.
- Emoji wyłącznie jako prefiks z kodu (140a). Bez markdown, bez innerHTML (textContent zostaje — anty-XSS).
- Plik zapisać w UTF-8 (emoji w źródle).
- Bez zmiany logiki nudge (timer, sessionStorage, klik/otwieranie) — tylko wygląd + prefiks emoji.

## Kryteria akceptacji
1. Dymek pokazuje 🤿 (nie „????") niezależnie od wartości w configu.
2. Dymek: tło #f2feff, font-size 16px, width 320px, padding 20px 38px 20px 20px.
3. Tekst zawija się płynnie (bez sztywnego <br>); przy domyślnej treści wygląda jak ~2 linie.
4. CTA: padding 12px 14px, font-size 15px, tło #f7b427, tekst #0b3b3d, hover ciemniejszy żółty (nie zielony).
5. ×: font-size 36px, color #555, hover #1e6363, right 10px, cieńszy font (font-weight 300), glif mieści się w przycisku.
6. Logika nudge bez regresji (timer, raz na sesję, klik otwiera czat, × zamyka).
7. Plik UTF-8; widget-bundle/transport nietknięte.
