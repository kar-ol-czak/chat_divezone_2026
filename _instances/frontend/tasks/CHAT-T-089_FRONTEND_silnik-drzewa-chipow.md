# CHAT-T-089 — FRONTEND: silnik drzewa chipów w widgecie (zastąpienie statycznych chipów)

**Instancja:** frontend (vanilla JS). Plik: `modules/divezone_chat/views/js/widget-bundle.js` (+ ewentualnie `widget.css`).
**Powiązane:** ADR-096 (model węzła: hierarchia + hybryda + inline), ADR-071 (zasady drzewa, pierwsza wiadomość 114a, Q231a deterministyczne), CHAT-T-088 (backend: tabela `divechat_chip_nodes` + endpoint GET `/api/chip-tree` WDROŻONY na PROD). Decyzje sesji: 37a (pełne zastąpienie), 38a (static render lokalnie, ZERO LLM), 39a (beacon — osobny task CHAT-T-090, ten task działa BEZ niego).
**Status:** DONE (2026-06-14, commit `4a16ac5`).

---

## CEL
Zastąpić statyczne chipy (`CHIPS_DESKTOP` hardcoded) dynamicznym silnikiem drzewa pobieranym z `/api/chip-tree`. Widget renderuje Level 1, obsługuje nawigację w głąb (podchipy), render tekstu deterministycznego lokalnie (ZERO LLM dla faktów operacyjnych) i mapuje akcje węzłów na istniejące mechanizmy.

## STAN OBECNY (z analizy kodu — NIE szukaj od zera)
- `widget-bundle.js`: `CHIPS_DESKTOP` = 5 statycznych etykiet. Render w `buildWindow()` (sekcja "Chipy", ~l.380). Klik chipa: albo `sendUserMessage(label)` (→ LLM), albo `openOrderModal()` dla "Status zamówienia".
- `state.chipsEl` = kontener `.dz-chips`. Po pierwszej wiadomości chipy są ukrywane (`display:none`) w `sendUserMessage` i `renderHistoryMessages`.
- `renderMarkdown(text)` JUŻ ISTNIEJE (l.~230) — bold/linki/listy/paragrafy, XSS-safe (escape przed tagami). bot_text z drzewa renderuje się przez nią 1:1.
- `appendBotMessage(text)` renderuje bąbel bota z markdown. `appendUserMessage(text)` — bąbel usera (escape).
- Transport: `/api/chip-tree` jest PUBLICZNY (bez tokenu) — pobierz prostym `fetch(BOOT.backendUrl + '/api/chip-tree')`, jak loader pobiera tokenUrl. NIE wymaga nagłówków X-DiveChat-*.
- `BOOT.backendUrl` dostępny w bundle przez `state.boot.backendUrl`.

## KONTRAKT ENDPOINTU (CHAT-T-088, na PROD)
`GET /api/chip-tree` → `{ tree: [ {node_key, bot_text, buttons:[{label,target}], children:[...], context_hint, model_level}, ... ] }`. Cache'owalny (ETag, max-age=300). Korzenie = parent_id NULL (dziś jeden: root). Pola id/parent_id/level NIE wychodzą.
Seed na PROD: root → {zwroty, serwis, wysylka, dobor}. zwroty/serwis mają bot_text ORAZ buttons (hybryda). Wartości `target` w buttons: `link:<klucz>` | `ai` | (przyszłe: `curated:<kat>`, `modal:<typ>`).

## ZAKRES

### 1. Pobranie drzewa przy mount
- Po mount (w `boot()` lub przy `openWindow` pierwszym) pobierz `/api/chip-tree`. Cache wynik w `state.chipTree` (jedna sesja, drzewo małe).
- Graceful fallback: jeśli fetch padnie / pusty / błąd → pokaż obecne statyczne chipy jako fallback (NIE zostawiaj pustego UI). Zachowaj `CHIPS_DESKTOP` jako fallback constant.
- Rozważ prefetch (jak widget-loader prefetchuje bundle) — opcjonalnie, nie wymagane.

### 2. Render Level 1 (zastąpienie CHIPS_DESKTOP)
- Render dzieci węzła root jako chipy w `state.chipsEl` (zamiast statycznej listy).
- Limit mobilny: zachowaj `CHIPS_MOBILE_LIMIT` (4) — pokaż pierwsze N wg sort_order.
- Każdy chip = `<button class="dz-chip">` z etykietą = label przycisku rodzica LUB node_key-derived label. UWAGA: etykieta chipa Level 1 to NIE bot_text dziecka — to nazwa nawigacyjna. Potrzebny `label` na węźle albo w buttons rodzica. PATRZ pytanie otwarte 1.

### 3. Klik chipa → routing wg typu węzła (sedno, decyzja 38a)
Po kliknięciu chipa prowadzącego do węzła N:
- **N ma bot_text (static/hybryda):** render bot_text LOKALNIE jako bąbel bota (`appendBotMessage(N.bot_text)` — przez renderMarkdown). ZERO wywołania backendu/LLM (Q231a). Następnie:
  - jeśli N ma dzieci → wyrenderuj je jako nowy zestaw chipów (nawigacja w głąb, podchipy).
  - jeśli N ma buttons → wyrenderuj je jako akcje (patrz pkt 4).
- **N to liść `ai` (target:ai, bez bot_text):** `sendUserMessage(<czytelny content>)` — wstrzyknięcie do LLM wg decyzji 114a. Content = sensowna fraza (np. label chipa), NIE node_key.
- Po wejściu w głąb: chipy Level 1 chowane, pokazane chipy/akcje poziomu N. Rozważ "wstecz" do root LUB poleganie na "Nowa rozmowa" (PATRZ pytanie otwarte 2).

### 4. Mapowanie `target` w buttons na akcje
- `link:<klucz>` → otwórz URL z drzewa. Backend powinien rozwinąć link:<klucz> do URL w response (PATRZ pytanie otwarte 3 — czy endpoint zwraca rozwinięty URL czy sam klucz). Otwórz w nowej karcie (target=_blank rel=noopener), jak istniejące linki.
- `ai` → `sendUserMessage(label przycisku)`.
- `modal:order` → `openOrderModal()` (istnieje). Inne `modal:<typ>` → na razie no-op/fallback do ai.
- `curated:<kat>` → na razie POZA zakresem (brak w seedzie operacyjnym) — zostaw hook, nie implementuj.

### 5. Zgodność z istniejącym flow
- `startNewConversation()` → przywróć render Level 1 z drzewa (nie statyczne chipy).
- `renderHistoryMessages()` (restore sesji) → chipy ukryte jak dziś (rozmowa trwa).
- NIE zepsuj: nudgeSid (CHAT-T-085), persist sesji, modal zamówienia, streaming.

## CZEGO NIE ROBIĆ
- Beacon klików (39a) = CHAT-T-090 (backend). Ten task działa bez niego. (Zostaw ewentualnie pusty hook `onChipClick(node_key)` do późniejszego podpięcia.)
- Gałąź doboru sprzętu — `dobor` to placeholder-liść ai. Nie buduj ścieżki doboru (po sesji zespołu).
- Panel edycji chipów — osobny task (PS/backend).

## DECYZJE (rozstrzygnięte — patrz CHAT-T-088b backend, idzie PRZED tym taskiem)
1. **Etykieta chipa (42a):** węzeł ma kolumnę `label TEXT` (migracja 030, CHAT-T-088b). Endpoint zwraca `label` per węzeł. Chip Level 1 = `label` (NIE node_key, NIE bot_text). Re-seed labeli: root="W czym mogę pomóc?" (nagłówek), zwroty="Zwroty i wymiana", serwis="Serwis automatu", wysylka="Dostępność i wysyłka", dobor="Dobór sprzętu".
2. **Nawigacja wstecz (44a — Wariant A):** gdy klient zejdzie w głąb (np. w serwis), wśród chipów poziomu dochodzi dodatkowy chip **"← Wróć"** (klasa `.dz-chip--back` do odróżnienia stylem). Klik → chowa obecne chipy, przywraca chipy poziom wyżej (stack `state.chipStack` z node_key poziomów). KLUCZOWE: bąble rozmowy (pokazany bot_text) ZOSTAJĄ w historii — "← Wróć" przywraca tylko ZESTAW CHIPÓW, nie kasuje treści. Na Level 1 nie ma "← Wróć". Breadcrumb odrzucony (przerost przy 2 poziomach; rozważyć dopiero gdy gałąź doboru urośnie do 3 poziomów).
3. **link:<klucz> — rozwijanie URL (43a):** backend rozwija przy budowie drzewa (ChipTreeService JOIN do divechat_shop_config, CHAT-T-088b). Endpoint zwraca w buttonie GOTOWY URL — kontrakt: `{label, target:"link", url:"https://..."}` (target="link" + osobne pole url). Front NIE woła get_shop_links, NIE zna divechat_shop_config — bierze url wprost. Dla target inne niż link pole url=null.

## KRYTERIA AKCEPTACJI
- [ ] Widget pobiera /api/chip-tree przy mount, fallback do statycznych przy błędzie.
- [ ] Level 1 renderowany z drzewa (root children), limit mobilny zachowany.
- [ ] Klik "Zwroty"/"Serwis" → bot_text pokazany LOKALNIE (bez requestu LLM — sprawdź w Network: zero /api/chat/stream), pod spodem przyciski.
- [ ] Klik "link:" → otwiera właściwy URL w nowej karcie.
- [ ] Klik liścia "ai" → sendUserMessage (idzie do LLM).
- [ ] startNewConversation przywraca chipy z drzewa.
- [ ] Nie zepsute: streaming, persist, modal zamówienia, nudgeSid.

## DEPLOY
Moduł PS = Karol deployuje ręcznie (ADR-089: rsync port 5739, ~/public_html/newtmp2, --exclude config_pl.xml, no --delete). CC przygotowuje kod + pokazuje diff, NIE deployuje sam.

## RAPORT
Handoff w _instances/frontend/handoff/. Aktualizacja _docs/21_STATUS + ADR-071/096. Commit per ścieżka (modules/divezone_chat/views/js/widget-bundle.js, ewent. widget.css), wg konwencji, push.

## WYNIK (CC, 2026-06-14)
Silnik drzewa chipów zaimplementowany w `widget-bundle.js` (+212/-1) + styl `.dz-chip--back` w `widget.css` (+10). Commit kodu `4a16ac5`, push origin main. Handoff: `_instances/frontend/handoff/CHAT-T-089_done.md` (zawiera kontrakt dla CHAT-T-090: hook `onChipClick(node_key)`).

Co dostarczone:
- `fetchChipTree()` przy mount (`GET /api/chip-tree`, public, `mode:cors`, `cache:default` → ETag/max-age=300), cache w `state.chipTree`. Graceful fallback do statycznych `CHIPS_DESKTOP` przy błędzie/pustym (render początkowy = static, podmiana po sukcesie → zero pustego UI).
- Level 1 z `root.children` (label, 42a), limit mobilny 4.
- `routeChipNode`: `bot_text` → `appendBotMessage` LOKALNIE (ZERO LLM, 38a/Q231a) → zejście do dzieci/przycisków; liść `ai` → `sendUserMessage(label)` (114a).
- `routeChipButton`: `link`+url → `window.open(_blank,noopener)` (43a); `ai` → sendUserMessage; `modal:order` → openOrderModal; curated/inne → fallback ai.
- Nawigacja wstecz Wariant A (44a): `state.chipStack`, chip „← Wróć" od Level 2, bąble ZOSTAJĄ.
- Zgodność: `startNewConversation` → reset Level 1 z drzewa; restore chowa chipy jak dziś; streaming/persist/nudgeSid/modal nietknięte. Hook `onChipClick(node_key)` pusty pod CHAT-T-090.

Weryfikacja: `node --check` OK; kontrakt `/api/chip-tree` i CORS/ETag sprawdzone curl-em na PROD. Render w przeglądarce — po ręcznym deployu modułu PS przez Karola (ADR-089).

**Follow-up (poza zakresem):** gałąź doboru sprzętu (`dobor` = placeholder-liść ai), panel edycji chipów, beacon klików (CHAT-T-090).
