# CHAT-T-089b — FRONTEND: chipy następcze pod odpowiedzią (poprawka pozycji) + link w treści

**Instancja:** frontend (vanilla JS). Plik: `modules/divezone_chat/views/js/widget-bundle.js` (+ ewent. widget.css).
**Powiązane:** CHAT-T-089 (silnik drzewa, na PROD), ADR-096. Decyzje: 51a (chipy następcze pod bąblem), 50a (link inline w treści — backend dosyła w 088c, ten task tylko potwierdza render).
**Status:** DONE (2026-06-14, commit `8ca93d3`). Poprawka UX po teście na żywo.

## PROBLEM (z testu na PROD, zrzuty Karola)
Po kliknięciu chipa z bot_text (np. "Zwroty"): bąbel z tekstem ląduje POD chipami poziomu ("← Wróć", "Formularz i szczegóły", "Inne pytanie"), bo chipy renderują się w STAŁYM kontenerze state.chipsEl (góra messages, pod welcome), a appendBotMessage dokleja bąbel na koniec messagesEl. Efekt: chipy NAD odpowiedzią — klient musi ich szukać, nie są naturalnym przedłużeniem odpowiedzi.

## PRZYCZYNA (z analizy kodu)
- state.chipsEl = jeden stały <div class="dz-chips"> wstawiony w buildWindow() po welcomeRow, przed srLive.
- renderCurrentChipLevel() zawsze clearChips() + wypełnia TEN kontener w jego stałej pozycji (góra).
- appendBotMessage() dokleja .dz-bot-row na koniec messagesEl (dół).
- Stąd kolejność: [welcome][CHIPY stałe][...bąble...][bot_text na końcu] → chipy nad bąblem.

## DECYZJA (51a)
Rozróżnić DWA tryby renderu chipów:
1. **Chipy STARTOWE (Level 1, przed rozmową)** — zostają w state.chipsEl u góry (pod welcome), jak teraz. To naturalne menu startowe. BEZ ZMIAN.
2. **Chipy NASTĘPCZE (po render bot_text, Level 2+)** — doklejane POD świeżym bąblem bota jako część tej odpowiedzi, w strumieniu messagesEl (nie w stałym chipsEl).

## ZAKRES
### Render następczy pod bąblem
- W `routeChipNode`, gdy węzeł ma bot_text: po `appendBotMessage(node.bot_text)`, jeśli węzeł ma dzieci/buttony — wyrenderuj chipy poziomu jako element doklejony PO tym bąblu (nowy `.dz-chips--inline` wewnątrz lub tuż po `.dz-bot-row`), NIE w state.chipsEl.
- "← Wróć" + dzieci + buttony renderowane w tym inline'owym bloku (ta sama logika co renderCurrentChipLevel, ale cel = kontener pod bąblem).
- Ukryj/wyczyść stały state.chipsEl gdy wchodzimy w tryb następczy (rozmowa się zaczęła — startowe menu znika, jak dziś przy sendUserMessage).
- "← Wróć" w trybie inline: wraca poziom wyżej. KLUCZOWE (44a): poprzednie bąble ZOSTAJĄ. "← Wróć" dokleja kolejny zestaw chipów pod ostatnią pozycją (lub re-render poziomu wyżej jako nowy inline blok). NIE kasuje historii bąbli.

### Refaktor (zalecany, by uniknąć duplikacji)
- Wydziel `renderChipLevelInto(containerEl, node)` — wspólna logika (← Wróć + dzieci + buttony), wywoływana raz dla startowego chipsEl, raz dla inline kontenera pod bąblem. renderCurrentChipLevel() = wrapper na startowy.

### Czysto nawigacyjny węzeł (bez bot_text)
- Węzeł bez tekstu, z dziećmi (przyszłe podchipy doboru): zejście może renderować chipy w miejscu (startowy chipsEl) BEZ bąbla — bo nie ma czego "przedłużać". To zachowanie z 089 zostaje dla węzłów bez tekstu. Inline pod bąblem dotyczy TYLKO węzłów z bot_text.

### Link w treści (50a — weryfikacja, nie implementacja)
- Backend (CHAT-T-088c) dosyła link inline w bot_text jako Markdown [label](url). renderMarkdown JUŻ parsuje [..](..) na <a target=_blank>. Ten task: POTWIERDŹ że link w bąblu bot_text renderuje się klikalnie (test po wdrożeniu 088c). Jeśli renderMarkdown wymaga poprawki dla linków w liście/treści — zgłoś.

## CZEGO NIE PSUĆ
- nudgeSid, persist, restore (renderHistoryMessages chowa chipy — startowe, zostaje), modal zamówienia, streaming.
- startNewConversation → resetChipsToLevel1 (startowy, góra) — bez zmian.
- Mobile limit dzieci (CHIPS_MOBILE_LIMIT) — zachowaj w obu trybach.

## KRYTERIA AKCEPTACJI
- [ ] Klik "Zwroty" → bąbel tekstu, a POD nim chipy ("← Wróć", "Formularz...", "Inne pytanie"). Chipy = naturalne przedłużenie odpowiedzi.
- [ ] Level 1 startowy (przed rozmową) BEZ zmian — u góry pod welcome.
- [ ] "← Wróć" wraca poziom wyżej, poprzednie bąble ZOSTAJĄ w historii.
- [ ] Po wdrożeniu 088c: link w treści zwrotów/serwisu klikalny (otwiera URL).
- [ ] Nie zepsute: streaming/persist/restore/modal/nudgeSid.

## DEPLOY
Moduł PS = Karol deployuje ręcznie (ADR-089: rsync port 5739, ścieżka /home/divezone/public_html/newtmp2/modules/divezone_chat/views/, --exclude config_pl.xml, bez --delete). UWAGA: w komendach rsync używać PEŁNEJ ścieżki /home/divezone/... NIE ~/ w zmiennej (tylda w $BASE = błąd "unexpected end of file", potwierdzone 2026-06-14).

## RAPORT
Commit per ścieżka (widget-bundle.js, ewent. widget.css), wg konwencji, push, docs: status. Handoff jeśli trzeba.

## WYNIK (CC, 2026-06-14)
Chipy następcze pod bąblem + refaktor renderu. Commit kodu `8ca93d3` (push origin main): `widget-bundle.js` +150/-46, `widget.css` +17.

Co dostarczone:
- **Refaktor `renderChipLevelInto(container, node, backHandler)`** — wspólna logika poziomu (← Wróć + dzieci + buttony + limit mobilny), wołana dla startowego `chipsEl` i dla inline bloku. `renderCurrentChipLevel` = wrapper startowy.
- **Tryb następczy (51a)** — `state.chipsInline`. Klik węzła z `bot_text`: `appendBotMessage` → `enterInlineMode()` (chowa startowe menu) → `appendInlineChips(node)` dokleja `.dz-chips--inline` POD bąblem w `messagesEl`. Węzeł nawigacyjny bez tekstu: inline gdy rozmowa trwa, inaczej startowy chipsEl (089 zachowane).
- **← Wróć inline (44a)** — `goBackInline()` pop + nowy inline blok poziomu wyżej pod ostatnią pozycją; bąble ZOSTAJĄ.
- **Spójność stosu** — `spendPriorInlineChips()` wygasza starsze inline bloki (disabled + `.dz-chips--spent`), interaktywny tylko najnowszy; wołane też w `sendUserMessage`. Link w treści bąbla pozostaje klikalny.
- **Zgodność** — `clearMessagesView` usuwa inline bloki; `resetChipsToLevel1` resetuje `chipsInline=false`; restore/persist/nudgeSid/modal/streaming nietknięte.

**KROK 2 (link w treści, 50a):** potwierdzone z analizy — `renderMarkdown` podmienia `[label](url)` na całym tekście PRZED podziałem na linie, więc link działa też w listach/paragrafach; `bot_text` idzie przez `renderMarkdown` w `appendBotMessage`. Seed 031 na PROD. Live-weryfikacja po deployu modułu PS.

Weryfikacja: `node --check` OK, wszystkie referencje funkcji silnika rozwiązane (brak `clearChips`/`popChipLevel`). Render w przeglądarce po ręcznym deployu modułu PS przez Karola (ADR-089, pełna ścieżka `/home/divezone/...`).
