# CHAT-T-159 BACKEND — okno historii: 10 → 20 wiadomości + przycinanie starych tool_result (decyzja 196a)

**Świat:** BACKEND `chat.divezone.pl`. Plik: `src/Chat/ChatService.php` (+ testy).
ZERO tools.php/routes.php/promptu/migracji.

## Problem (conv 755, review 335)
`MAX_HISTORY_MESSAGES = 10` (ChatService.php:25, trim w trimHistory() ~l.500).
W długiej rozmowie sprzedażowej wcześniejsza wiadomość bota (podsumowanie zestawu
487 zł) wypadła z okna; gdy klientka ją wkleiła, model jej realnie nie widział
i dwukrotnie zaprzeczył własnemu autorstwu („to z innego czatu") — poważna utrata
zaufania. Osłona tekstowa (nie zaprzeczaj cytatom spoza okna) idzie w CHAT-T-157;
ten task naprawia przyczynę.

## Decyzja Karola 196a
1. `MAX_HISTORY_MESSAGES` = 20.
2. Starsze wpisy z rolą tool/tool_result (poza N najnowszych — zaproponuj próg,
   np. poza ostatnimi 6 wpisami) NIE wypadają z okna, tylko są SKRACANE do
   krótkiego stuba, np. `{"trimmed":true,"tool":"search_products","note":"wynik
   przycięty — starsza część rozmowy"}` — struktura tool_use/tool_result musi
   pozostać poprawna dla API (para tool_call ↔ result nienaruszona; jeśli
   przycinasz result, przycinaj TREŚĆ, nie usuwaj wpisu).
3. Wiadomości user i assistant (tekstowe) zostają w całości.
4. Zachowaj istniejący warunek: okno zaczyna się od wiadomości user.

## Uzasadnienie kosztowe (do zweryfikowania w tasku)
tool_resulty bywają ogromne (pełne opisy produktów, kilkanaście KB) — to one,
nie tekst rozmowy, dominują koszt. Po zmianie policz na próbce realnych rozmów
(divechat_conversations.messages, np. conv 755 i 751) rozmiar wejścia przed/po
i wpisz liczby do raportu. Jeśli wzrost kosztu > ~30% względem stanu obecnego,
STOP i raport z liczbami zamiast deployu.

## Kroki
- KROK 0: `git pull --rebase`; przeczytaj ChatService.php (cała ścieżka budowy
  historii: skąd $history, co zawiera, jak liczone są wpisy), ten plik.
- KROK 1: implementacja + testy jednostkowe trimHistory (okno 20, stub starych
  tool_result, para tool_call/result spójna, start od user).
- KROK 2: pomiar kosztowy na realnych rozmowach (odczyt z Railway PG read-only).
- KROK 3: `ea-php84 -l`; commit per ścieżka `CHAT-T-159 backend: okno historii 20
  + trim tool_result (196a)`, push.
- KROK 4: **STOP przed rsync (ADR-089)** — czekaj na „deployuj". Deploy: backup
  `_deploy_bak/CHAT-T-159/`, rsync per ścieżka, md5 prod==local, lint, `/api/health`.
- KROK 5: Test PROD realny czat (`[test CHAT-T-159, nie klient]`): rozmowa 12+
  wymian, potem wklej wcześniejszą wiadomość bota — bot ma ją rozpoznać/przyjąć,
  nie zaprzeczać.
- KROK 6: Status `_docs/21` NA GÓRZE + raport; osobny commit `docs:`.

## NIE RUSZAJ
SystemPrompt.php (reguła 13 idzie w T-157), tools.php, routes.php,
purge_litespeed.php (SEKRET), ADR-y, pliki innych sesji.

## Wynik (DEPLOYED 2026-07-26, commit kodu 3e83b78)
Jeden plik: `standalone/src/Chat/ChatService.php`. `MAX_HISTORY_MESSAGES` 10→20,
nowa stała `KEEP_FULL_TOOL_RESULTS=6`. `trimHistory()` skraca TREŚĆ `tool_result`
starszych niż 6 najnowszych wpisów okna do stuba `{"trimmed":true,"tool":...,
"note":"wynik przycięty — starsza część rozmowy"}` — wpis nie znika, para
tool_use↔tool_result spójna; user/assistant tekst i tool_calls w całości; „start
od user" zachowany; tool_result bieżącej tury (tool-loop) zawsze pełny.
Test jednostkowy `tests/Chat/TrimHistoryTest.php`: 23/23.

**Pomiar kosztowy (Railway read-only, conv 755/751/335):** wejście **+54,1%** vs
okno10 (okno20 bez stuba: +109%). **Powyżej bramki 30%** — Karol świadomie
zaakceptował KEEP=6 (tekst nigdy nie stubowany = sedno 196a).

**Deploy:** backup `_deploy_bak/CHAT-T-159/`, rsync 1 pliku, md5 prod==local
`426a1fe5cfc4f0a3efdedb59b97d4d4c`, `ea-php84 -l` clean, `/api/health` 200.
**Test PROD (HMAC, reguła E, conv 867, review 410):** 12 wymian + wklejenia; bot
nie zaprzeczył autorstwu — cytat z okna-20/poza-oknem-10 (wpis 34) → „Tak, to moja
wiadomość z tej rozmowy". Sentinel: deklaracja 1 pliku (blok dla operatora w raporcie).
