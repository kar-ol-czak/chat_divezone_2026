# CHAT-T-121 — Widget: przycisk `target:ai` = wejście w pisanie (nie wiadomość) + dosłanie strukturalnej ścieżki chipów

**Status:** DONE (kod front); seed 040 PRZYGOTOWANY — czeka na akceptację Karola (STOP na produkcji).
**Instancja:** frontend | **Powiązane:** ADR-110, ADR-097 (dwa światy), CHAT-T-089 (silnik drzewa), CHAT-T-122 (backend utrwala), CHAT-T-123 (panel render). **Kontrakt:** `_instances/frontend/handoff/HANDOFF_chip_path_kontrakt.md`. **Decyzje:** 41a, 8a, 8b, 9a.

## Kontekst
Diagnoza na produkcji: przycisk akcji `{"label":"Napisz czego szukasz","target":"ai"}` na węźle-liściu (np. id 36 „Komputer nurkowy") wysyła swój label jako wiadomość user (`widget-bundle.js` linia ~653, `routeChipButton`, `sendUserMessage(btn.label, ctx)`). Efekt: historia zaśmiecona etykietą, panel pokazuje zły tytuł. `bot_text` liścia już zadaje pytanie, więc przycisk jest zbędny — klik ma odsłonić pole pisania. Dodatkowo utrwalamy ścieżkę chipów: front dosyła strukturalny `chip_path` (kontrakt w handoffie).

## Zakres
1. **`routeChipButton` przy `target==='ai'` (41a):** NIE woła `sendUserMessage`. Zamiast tego: ukryj chipy, fokus na `state.inputEl`, zapamiętaj bieżący `chip_context` (string, dziś z `buildChipContext`) ORAZ strukturalną ścieżkę (patrz pkt 3) w stanie `state.pendingChip = { context, path }`, do zużycia przy pierwszej realnej wiadomości. Etykieta przycisku NIGDY nie trafia do historii ani do backendu.
2. **Konsumpcja `pendingChip` w `sendUserMessage`:** gdy klient napisze pierwszą realną wiadomość, dołącz `state.pendingChip.context` jako `chipContext` i `state.pendingChip.path` jako `chip_path` do wysyłki. One-shot: wyczyść `state.pendingChip` po zużyciu. Kolejne wiadomości tury bez chipów lecą bez `chip_path`.
3. **Builder strukturalnej ścieżki:** analogicznie do `buildChipContext` zbuduj `buildChipPath()` z `state.chipStack` (pomiń `chipStack[0]` root). Każdy element `{ node_key, label, level }` z węzła (`node.node_key`, `deriveChipLabel(node)`, `node.level`). Zwróć `[]` gdy brak ścieżki.
4. **Dosłanie `chip_path` przez transport:** rozszerz `transport.sendMessage` (`transport.js`) o przekazanie `chip_path` w body żądania obok `chip_context`. Kontrakt: handoff §„Nowe pole w body".
5. **Usunięcie zbędnych przycisków `target:ai` z liści w seedzie (8a):** w seedzie drzewa (SQL/skrypt seeda chipów, sprawdź `sql/` i CHAT-T-088f) usuń przyciski `target:ai` z węzłów-liści, gdzie `bot_text` już zaprasza do pisania (36, 39, 41, 51, 52, 53, 54, 55, 58, 34, 35 — zweryfikuj z bazą aktualny zestaw `SELECT id,label,buttons FROM divechat_chip_nodes WHERE buttons::text LIKE '%target%ai%'`). Wejście na taki liść ma od razu odsłonić pole pisania (`bot_text` renderowany, chipy-dzieci brak, input aktywny + `pendingChip` ustawiony ze ścieżki liścia).
6. **STOP** przed zmianą seeda na produkcji (pkt 5 to zmiana danych `divechat_chip_nodes`) — przygotuj skrypt, poczekaj na Karola.

## Uwaga
- NIE ruszać `chip_context` (string) semantyki — dalej efemeryczny dla LLM (ADR-097). `chip_path` to rozłączna, strukturalna reprezentacja do utrwalenia.
- Fokus inputu: uważać na mobile (klawiatura) — jeśli istnieje wzorzec fokusowania z „Nowa rozmowa", użyć tego samego.
- Bez `localStorage`/`sessionStorage` w widgecie poza istniejącym mechanizmem CHAT-T-059.

## Handoff
Kontrakt `chip_path` już wystawiony: `_instances/frontend/handoff/HANDOFF_chip_path_kontrakt.md`. Po implementacji odhacz pozycję „Front" w sekcji Status handoffu.

## Definicja ukończenia
- Klik `target:ai` nie tworzy wiadomości user; pole pisania aktywne, `pendingChip` ustawiony.
- Pierwsza realna wiadomość niesie `chip_context` + `chip_path`; kolejne bez `chip_path`.
- Seed bez zbędnych przycisków `target:ai` na liściach (po STOP i akceptacji).

## Wynik (2026-07-01, CC)
Zakres front zaimplementowany w `modules/divezone_chat/views/js/`:
1. **`routeChipButton` przy `target==='ai'`** → `enterWriteMode(ctx, buildChipPath())`: ukrywa chipy (startowe + wygasza inline), ustawia `state.pendingChip = { context, path }`, fokusuje `state.inputEl`. Etykieta przycisku NIE trafia do historii/backendu. Reguła generyczna dla wszystkich `target:ai` (41a) — dotyczy też hybryd (wysyłka „Koszty…", serwis „Inne pytanie").
2. **Konsumpcja `pendingChip` w `sendUserMessage`** (one-shot): pierwsza realna wiadomość dołącza `context` jako `chipContext` i `path` jako `chip_path`; jawny `chipContext` (liść-ai) ma pierwszeństwo; `chip_path` wyłącznie z `pendingChip`. Po zużyciu `pendingChip=null`.
3. **`buildChipPath()`** — strukturalna ścieżka `[{node_key,label,level}]` z `chipStack` (pomija root). `level` wyprowadzony z głębokości stosu (`i+1`), bo `/api/chip-tree` NIE eksportuje kolumny `level` (ChipTreeService celowo pomija id/parent_id/level). Zwraca `[]` gdy brak ścieżki.
4. **`transport.sendMessage`** rozszerzony o parametr `chipPath` → `body.chip_path` (tylko gdy niepusta tablica). Docblock zaktualizowany.
5. **Liść z `bot_text` bez dzieci/przycisków** (`routeChipNode`): po usunięciu przycisku (seed 040) wejście na liść od razu wchodzi w tryb pisania (liść na stos → `enterWriteMode` ze ścieżką + `ai_prompt` liścia). Zbieżne z dawnym klikiem `target:ai`.
6. **Seed 040 PRZYGOTOWANY (NIE uruchomiony)**: `sql/040_chip_remove_redundant_target_ai.sql` (+rollback) usuwa `[{"label":"Napisz czego szukasz","target":"ai"}]` z 12 liści zweryfikowanych na Railway: `zaczynam, snorkel, komputer, automat, jacket, pianka_rozmiar, suchy_rozmiar, pletwy_rozmiar, buty_rozmiar, kaptur_rekawice, nie_wiem_rozmiar, dostepnosc` (id 34,35,36,39,41,51,52,53,54,55,56,58). Hybrydy `wysylka`(4)/`serwis`(3) nietknięte. Guard `buttons @> '[{"target":"ai"}]'` = idempotentne. **STOP: czeka na Karola** (pkt 6).

Cache-busting automatyczny (`?v=md5_8`, CHAT-T-061) — bez ręcznego bumpa wersji. `node --check` OK dla obu plików. Deploy modułu na VPS = osobny krok (poza OSTATNI KROK tasku).
