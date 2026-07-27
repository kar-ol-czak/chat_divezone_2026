# CHAT-T-088e — BACKEND+FRONTEND: ai_prompt na liściu + kontekst ścieżki dla AI ("dwa światy")

**Instancja:** backend (schemat+endpoint) + frontend (serializacja ścieżki, wysyłka kontekstu). DWIE instancje — backend pierwszy (kontrakt), front drugi.
**Powiązane:** ADR-096 (model węzła), CHAT-T-088* (drzewo na PROD), CHAT-T-089/089b (silnik widgetu). Decyzje: 63c (hybryda: ścieżka nawigacji + opcjonalny ai_prompt liścia), 61a (fundament PRZED Level 2). Fundament pod Level 2 (088f) i panel (D).
**Status:** DONE (2026-06-14). Migracja 033 (ai_prompt) zaaplikowana na Railway przez Karola; backend (ChipTreeService+ChatService+ChatController) wdrożony ADR-089 (md5 3/3, /api/health 200, /api/chip-tree pokazuje ai_prompt). Front (widget-bundle.js+transport.js) zacommitowany — moduł PS deployuje Karol ręcznie. ADR-097. Status v3.65.

## CEL ("dwa światy" — co klient widzi ≠ co dostaje AI)
Dziś (089) liść `ai` wysyła do LLM samą etykietę chipa ("Kaptur") — za płytko. Klient schodzący "Dobór rozmiaru → Kaptur" powinien dać AI bogatszy kontekst: skąd przyszedł (ścieżka) + intencję. Rozdzielić:
- **Co klient widzi/klika:** label (krótkie, np. "Kaptur").
- **Co dostaje AI:** kontekst ścieżki (Dobór rozmiaru › Kaptur) + opcjonalny ai_prompt liścia.

## MODEL (decyzja 63c — HYBRYDA)
Kontekst dla AI = automatyczna ścieżka nawigacji (składana przez FRONT z chipStack) + OPCJONALNY ai_prompt węzła-liścia (instrukcja od pracownika, gdy potrzeba czegoś więcej).
- Prosty przypadek: pracownik NIC nie wpisuje → AI dostaje samą ścieżkę ("Klient wybrał: Dobór rozmiaru › Kaptur. Pomóż zgodnie z tą intencją.").
- Złożony: pracownik wpisuje ai_prompt liścia ("Zapytaj o obwód głowy w cm, dopytaj o model i grubość") → doklejony do ścieżki.

## ZAKRES BACKEND (pierwszy)
### 1. Migracja sql/033_chip_ai_prompt.sql (+rollback)
- ADD COLUMN ai_prompt TEXT NULL do divechat_chip_nodes. COMMENT: opcjonalna instrukcja dla AI gdy węzeł jest liściem (target ai) — doklejana do automatycznej ścieżki nawigacji. NULL = sama ścieżka.
- Rollback: DROP COLUMN ai_prompt.

### 2. ChipTreeService — ai_prompt w kontrakcie
- getTree() SELECT + ai_prompt; buildTree() mapuje `'ai_prompt' => $row['ai_prompt'] ?? null`.
- Kontrakt węzła rozszerza się o `ai_prompt` (string|null).

### 3. Endpoint chat — przyjęcie kontekstu ścieżki (DECYZJA 65b)
Kontekst ścieżki idzie OSOBNYM parametrem (NIE w treści user message — historia ma zostać czysta).
- `sendMessage` (transport.js) dostaje nowy opcjonalny param `chipContext` (string|null) obok `text`, `sessionId`, `nudgeSid`.
- Endpoint chat (Controller + ChatService) przyjmuje `chip_context`: gdy niepuste, wstrzykuje jako KONTEKST (prefiks do promptu / dodatkowa instrukcja systemowa dla tej tury), NIE jako wiadomość user. Wiadomość user = to, co klient faktycznie napisał (lub label liścia jeśli kliknął bez pisania).
- Historia (divechat_messages / messages JSONB): user message = realna treść klienta. chip_context może być zapisany w metadanych tury (opcjonalnie) lub tylko użyty ulotnie do promptu — NIE jako osobny user turn. Zgodne z 114a.
- ADR krótki: zmiana kontraktu sendMessage + ChatService o chip_context (osobny od treści).

## ZAKRES FRONTEND (drugi, po kontrakcie backendu)
### 4. Serializacja ścieżki (widget-bundle.js)
- chipStack już śledzi zejście (CHAT-T-089). Zbuduj z niego czytelną ścieżkę: labele węzłów po drodze, np. "Dobór rozmiaru › Kaptur".
- Przy wejściu w liść ai (routeChipNode → sendUserMessage): wiadomość user = label liścia (lub realny tekst klienta); kontekst ścieżki + ai_prompt liścia idą OSOBNYM parametrem chipContext (decyzja 65b). Dołącz ai_prompt liścia do chipContext jeśli niepuste.
### 5. Limit mobilny 6 (58a — przy okazji)
- var CHIPS_MOBILE_LIMIT = 6; (było 4). Level 1 ma teraz 5 chipów (po 088d).

## PYTANIA OTWARTE
66. Format ścieżki dla AI: "Klient wybrał ścieżką chipów: Dobór rozmiaru › Kaptur. [ai_prompt liścia]" — do ustalenia przy implementacji (drobne).

## KRYTERIA AKCEPTACJI
- [ ] Kolumna ai_prompt + w kontrakcie endpointu.
- [ ] Liść ai z ścieżką: AI dostaje kontekst "skąd klient przyszedł", nie samą etykietę.
- [ ] ai_prompt liścia (gdy ustawiony) doklejony do kontekstu.
- [ ] Historia rozmowy czysta (jeśli 65b) — bez sztucznych user messages.
- [ ] Limit mobilny 6.
- [ ] Testy: ChipTreeService (ai_prompt w wyjściu); front (serializacja ścieżki).

## DEPLOY
Backend: migracja 033 (Karol) + rsync ChipTreeService + ChatService (jeśli 65b). Front: rsync widget-bundle.js (Karol, pełna ścieżka). Sekwencyjnie: backend kontrakt → front.

## RAPORT
Per instancja osobno. ADR krótki jeśli 65b zmienia kontrakt sendMessage. Commit per ścieżka, push, docs:.
