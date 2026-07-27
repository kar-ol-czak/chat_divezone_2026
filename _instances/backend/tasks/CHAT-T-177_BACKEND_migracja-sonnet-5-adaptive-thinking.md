# CHAT-T-177 — BACKEND — Migracja Sonnet 4.6 → Sonnet 5 + budget_tokens → adaptive thinking

**Instancja:** backend
**Pliki:** `standalone/src/AI/ClaudeProvider.php`, `standalone/src/AI/AIModel.php`, `divechat_settings` (model_primary)
**Świat wdrożeniowy:** WORLD 1 (chat.divezone.pl)
**ADR:** ADR-139 (architekt zapisze przed deployem)
**Karta:** (nowa, założę po tasku)

---

## 1. Dlaczego skoordynowana migracja, nie sama zmiana modelu

Przełączenie `model_primary` na `claude-sonnet-5` BEZ zmiany kodu thinking =
**HTTP 400 na każdym żądaniu**. Zweryfikowane w dokumentacji Anthropic
(adaptive-thinking, 2026-07-27):
- Sonnet 5 odrzuca `thinking: {type: "enabled"}` błędem 400. Obecny kod
  (`ClaudeProvider.php` linia 100-102) wysyła dokładnie to + `budget_tokens`.
- Sonnet 5 odrzuca niedomyślne `temperature`/`top_p`/`top_k` błędem 400 na
  KAŻDYM żądaniu. Kod ustawia temperature (linia 108) w gałęzi else — trzeba
  zabezpieczyć.

## 2. Fakty (zweryfikowane 2026-07-27)

- **Cena:** intro $2/$10 (do 31.08.2026), potem $3/$15 = tyle samo co Sonnet 4.6.
  Do końca sierpnia TANIEJ. Model string: `claude-sonnet-5`.
- **Tokenizer:** Sonnet 5 ma NOWY tokenizer, do +35% tokenów na tym samym tekście.
  „Cost-neutral" to uproszczenie — trzeba ZMIERZYĆ realny wzrost na naszych promptach.
- **Adaptive thinking:** domyślnie WŁĄCZONY na Sonnet 5. Sterowanie przez `effort`
  (low/medium/high/max/xhigh), nie budget_tokens. `high`=domyślny (prawie zawsze
  myśli), `low`=pomija dla prostych zadań.
- **Cache (rozwiązuje przyczynę C z T-176):** kolejne żądania adaptive ZACHOWUJĄ
  breakpointy cache. System prompt i definicje narzędzi cache'owane niezależnie od
  trybu. Adaptive NIE tworzy problemu dwóch prefiksów jak obecny budget_tokens.
- **Wyłączenie thinking:** na Sonnet 5 jawnie `thinking: {type: "disabled"}`
  (pominięcie thinking = domyślnie adaptive).

## KROK 0 — pull i lektura
```
git pull --rebase
```
Przeczytaj `ClaudeProvider.php` linie 84-113 (blok thinking+temperature),
`AIModel.php` (mapEffortToProviderValue, supportsReasoningEffort, supportsTemperature).

**NIE RUSZAJ:** tools.php, routes.php, SystemPrompt.php, ChatService.php poza
niezbędnym, ADR-ów. T-176 (rozbicie system promptu) jest niezależne i już w repo.

## KROK 1 — POMIAR PRZED (tokenizer)
Zmierz, ile tokenów Sonnet 5 liczy dla waszego system promptu (44,9k tok na 4.6)
przez token counting API albo Console. Zapisz wzrost %. To warunek oceny, czy
migracja jest cost-neutral, czy tokenizer podnosi koszt mimo tej samej stawki.

## KROK 2 — ClaudeProvider: budget_tokens → adaptive
Zmień budowanie `$body['thinking']`:
- gdy model wspiera adaptive (Sonnet 5, Opus 4.6+, Sonnet 4.6): wyślij
  `thinking: {type: "adaptive"}` + `output_config: {effort: <poziom>}`.
  Mapuj obecny `reasoning_effort` z ustawień: minimal→low, low→low, medium→medium,
  high→high. (Anthropic nie ma "minimal" w adaptive — najbliższe to "low".)
- zachowaj wsteczną kompatybilność: starsze modele (Sonnet 4.5) nadal
  `type: enabled` + budget_tokens. Rozpoznaj po fladze modelu.
- `display`: na Sonnet 5 domyślnie "omitted". Jeśli panel recenzji pokazuje
  thinking, ustaw `display: "summarized"`. Jeśli nie pokazuje — zostaw omitted
  (szybszy time-to-first-token). SPRAWDŹ, czy panel recenzji czyta thinking.

## KROK 3 — ClaudeProvider: temperatura
Na modelach odrzucających niedomyślną temperaturę (Sonnet 5, Opus 4.7+): NIE
wysyłaj `temperature`/`top_p`/`top_k` w ogóle, chyba że dokładnie domyślne.
Dodaj flagę modelu `rejectsNonDefaultTemperature()` albo warunek. Obecna gałąź
else (linia 107-108) nie może wysłać temperature dla Sonnet 5.

## KROK 4 — AIModel: definicja Sonnet 5
Dodaj `claude-sonnet-5` z flagami: supportsAdaptiveThinking=true,
rejectsNonDefaultTemperature=true, supportsReasoningEffort=true. Sprawdź, jak
zdefiniowane inne modele, zachowaj wzorzec.

## KROK 5 — walidacja lokalna
```
ea-php84 -l (wszystkie zmienione pliki)
```
Testy: request dla Sonnet 5 NIE zawiera `type: enabled`, NIE zawiera
`budget_tokens`, NIE zawiera niedomyślnej temperature. Asercje w teście.

## KROK 6 — STOP przed zmianą model_primary i rsync (ADR-089)
Zmiana `model_primary` w `divechat_settings` na `claude-sonnet-5` to przełącznik
produkcji. Karol autoryzuje osobno. NIE zmieniaj settings sam.

## KROK 7 — deploy (po autoryzacji)
1. rsync ClaudeProvider.php + AIModel.php → chat.divezone.pl/src/AI/
2. md5, ea-php84 -l, smoke
3. DOPIERO po smoke: zmień `divechat_settings.model_primary` = claude-sonnet-5
   (sql.py --write)
4. natychmiastowy replay wieloturowej rozmowy — SPRAWDŹ brak 400

## KROK 8 — POMIAR PO + weryfikacja
- replay 3 rozmów, sprawdź: zero 400, thinking działa (adaptive), cache_creation
  NIE rozdwaja się (przyczyna C rozwiązana)
- porównaj koszt: nowy tokenizer (+X% tok) vs intro cena ($2 zamiast $3)
- jakość: replay pytań, które wcześniej sprawiały problem (szkła conv 833,
  klasyfikacja) — czy Sonnet 5 nie regresuje
- docs _docs/21, 2 commity

---

## Kryterium akceptacji (architekt)
1. Zero 400 na produkcji po przełączeniu (najważniejsze — 400 = całkowity zgon czatu)
2. Thinking działa w trybie adaptive, cache_creation nie rozdwaja się (C rozwiązane)
3. Pomiar tokenizera: znany realny wzrost tokenów, decyzja czy cost-neutral
4. Brak regresji jakości na trudnych pytaniach (replay)
5. Rollback gotowy: model_primary z powrotem na claude-sonnet-4-6 jednym settings
