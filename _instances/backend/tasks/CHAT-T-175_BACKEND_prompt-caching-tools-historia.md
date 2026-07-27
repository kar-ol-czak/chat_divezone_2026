# CHAT-T-175 — BACKEND — Rozszerzenie prompt cachingu: narzędzia + historia

**Instancja:** backend
**Plik:** `standalone/src/AI/ClaudeProvider.php`
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl)
**ADR:** ADR-138 (architekt zapisze przed deployem — decyzja o strategii cache)
**Karta:** Chat-70

---

## 1. Cel i oszczędność

Dziś cache'owany jest TYLKO system prompt. Historia rozmowy i definicje narzędzi
idą po cenie cache-miss ($3/M) w KAŻDEJ turze. Cache Sonnet 4.6: odczyt 0.10×
(90% taniej), zapis 1.25×. Rozszerzenie cache o narzędzia i historię obniża koszt
każdej wieloturowej rozmowy — oszczędność rzędu wielokrotnie większego niż wybór
KEEP z T-159 (który dotyczył tylko 35 najdłuższych rozmów; caching dotyczy
WSZYSTKICH wieloturowych).

## 2. Stan obecny — zweryfikowany w kodzie 2026-07-26

`ClaudeProvider.php`:
- **system**: JUŻ ma `cache_control: ephemeral` (linia 111). Zostaje.
- **tools**: `formatTools()` (linia 138) buduje bez `cache_control`.
- **messages**: `$claudeMessages` budowane w pętli (linie ~50-60), trafiają do
  `$body['messages']` (linia 75) bez `cache_control`.
- **POMIAR JUŻ ISTNIEJE**: `parseResponse()` czyta `cache_read_input_tokens`
  i `cache_creation_input_tokens` (linie 246-247) do `usage`. NIE trzeba dodawać
  odczytu — trzeba go WYKORZYSTAĆ w KROKU 1 (pomiar przed).

## 3. Fakty Anthropic (potwierdzone web 2026-07-26)

- do **4 breakpointów** `cache_control` na żądanie, LICZONE ŁĄCZNIE przez
  system + tools + messages
- działają na PREFIKSACH: cache'owana część musi być na początku kontekstu,
  przed dynamicznymi. Kolejność: system → tools → historia → świeży input
- zapis 1.25×, odczyt 0.10×, minimum 1024 tokeny

## 4. DWIE PUŁAPKI (bez nich wdrożenie szkodzi)

1. **TWARDA — limit 4.** Przekroczenie = HTTP 400 "maximum of 4 blocks with
   cache_control". Dziś mamy 1 (system). Dodanie tools = 2. Dodanie 1 breakpointu
   na końcu historii = 3. Zapas do 4. NIE dawać breakpointu na wielu wiadomościach
   historii naraz — łatwo przekroczyć 4 przy dłuższej rozmowie.
2. **Breakpoint po treści zmiennej co turę** = nowe hashe bez przerwy, zero
   reużycia. Dashboard cache ON, rachunek zły. Cache historii ma sens TYLKO na
   STABILNYM prefiksie. Stub z T-159 pomaga (starsze tool_result stałe).

## KROK 0 — pull i lektura
```
git pull --rebase
```
Przeczytaj `ClaudeProvider.php` całość metody budującej request (linie ~45-135),
`parseResponse` (~221-250). Zrozum, jak `$claudeMessages` narasta w pętli.

**NIE RUSZAJ:** `config/tools.php`, `config/routes.php`, `SystemPrompt.php`,
ChatService.php, ADR-ów, migracji. TYLKO ClaudeProvider.php.

## KROK 1 — POMIAR PRZED (obowiązkowy, decyzja Karola 55)

Przed jakąkolwiek zmianą zmierz realny udział cache-miss. Użyj istniejących pól
`cache_read_tokens` / `cache_creation_tokens` z `usage`. Odtwórz 3 wieloturowe
rozmowy przez replay.py, zbierz z bazy (albo z logu) sumę input_tokens vs
cache_read. Zapisz baseline: ile % wejścia dziś idzie po cache-miss.
**Bez tego pomiaru nie ma jak udowodnić oszczędności — to warunek z decyzji 55.**

## KROK 2 — cache_control na definicjach narzędzi

W `formatTools()` (linia 138) dodaj `cache_control: ephemeral` do OSTATNIEGO
narzędzia w tablicy (breakpoint po całym bloku tools — cache obejmuje wszystkie
narzędzia przed nim). NIE na każdym narzędziu (marnuje breakpointy).

To breakpoint #2 (po system #1).

## KROK 3 — cache_control na końcu historii

Na OSTATNIM bloku treści OSTATNIEJ wiadomości `$claudeMessages` PRZED świeżym
inputem użytkownika dodaj `cache_control: ephemeral`. To cache'uje całą narastającą
historię jako prefiks. Świeży input klienta zostaje POZA cache (i tak się zmienia).

To breakpoint #3. Zostaje zapas #4.

**UWAGA struktura:** wiadomości mają różne formaty (text, tool_use, tool_result).
`cache_control` idzie na ostatnim BLOKU treści, nie na całej wiadomości. Sprawdź,
jak `formatAssistantMessage` i `appendToolResult` budują bloki — cache_control
musi trafić na ostatni element `content[]`.

## KROK 4 — rozważ tryb automatyczny (alternatywa dla KROK 3)

Jeśli ręczne wstawianie na końcu historii jest kruche (bo struktura wiadomości
się zmienia), rozważ: jeden breakpoint na końcu bloku tools wystarcza, by
cache objął system+tools (stały prefiks), a historia niech idzie bez cache.
To PROSTSZE i bezpieczniejsze, choć oszczędność mniejsza (nie cache'uje historii).
**Decyzja w raporcie:** jeśli KROK 3 wychodzi kruchy → zrób sam system+tools
(KROK 2), zgłoś że historia wymaga osobnego podejścia. Nie ryzykuj 400 na produkcji.

## KROK 5 — walidacja
```
ea-php84 -l standalone/src/AI/ClaudeProvider.php
```
Test lokalny: request z 3+ wiadomościami NIE może mieć >4 bloków cache_control.
Policz je programowo przed wysłaniem (asercja w teście).

## KROK 6 — STOP
STOP przed rsync (ADR-089). Czekaj na "deployuj".

## KROK 7 — deploy (po autoryzacji)
Świat 1, jeden plik:
```
backup → _deploy_bak/CHAT-T-175/
rsync ClaudeProvider.php → ~/public_html/chat.divezone.pl/src/AI/
md5 ↔ prod, ea-php84 -l, smoke /api/health
```

## KROK 8 — POMIAR PO + status
Odtwórz TE SAME 3 rozmowy co w KROKU 1. Porównaj cache_read vs cache_miss.
Udowodnij, że udział cache_read wzrósł. Zapisz do raportu liczby przed/po.
Dopisz NA GÓRZE `_docs/21`. Commit kod + osobny commit docs.

---

## Kryterium akceptacji (architekt, pomiar)
1. Request nigdy nie ma >4 bloków cache_control (zero 400 na produkcji)
2. Pomiar po: udział cache_read_tokens wyraźnie wyższy niż baseline z KROKU 1
3. Zero regresji: replay wieloturowej rozmowy działa, brak 400
4. Jeśli historia okazała się zbyt krucha → system+tools same, z jawną notą
