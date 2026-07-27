# CHAT-T-068 — BACKEND: panel PS steruje modelem (provider wynika z modelu, nie z .env)

**Status:** DONE (2026-06-04, commit 96efaea, deploy PROD OK)

**Instancja:** backend (standalone, PHP 8.4). CC WDRAŻA SAM.
**Powiązane:** ChatService, AIProviderFactory, AIModel enum, SettingsStore, panel PS Modele (CHAT-T wcześniejsze).
**Decyzja:** 184a (provider wynika automatycznie z modelu wybranego w panelu; .env tylko fallback gdy panel pusty), 185a (.env AI_PROVIDER zostaje jako fallback, nie ruszać ręcznie).

## Problem (zdiagnozowany na PROD przez SSH)
Panel PS pokazuje modele Claude (Haiku 4.5 primary, Opus 4.7 escalation), ale WSZYSTKIE rozmowy lecą na gpt-4.1. Przyczyna:
- .env PROD: AI_PROVIDER=openai, OPENAI_CHAT_MODEL=gpt-4.1.
- AIProviderFactory wybiera instancję providera z .env (AI_PROVIDER=openai) → wstrzykuje OpenAIProvider do ChatService (1 instancja, konstruktor).
- ChatService::$currentProvider (linia 102) też z .env → 'openai'.
- Panel ustawił model_primary=claude-haiku-4-5 (provider 'claude'). Linia 109: $primaryModel->provider() ('claude') === $currentProvider ('openai') → FALSE → model_override NIE ustawiony.
- Efekt: OpenAIProvider leci swoim gpt-4.1, panel zignorowany.
DWA źródła prawdy (panel vs .env) w sprzeczności, .env wygrywa po cichu. CEL: panel = źródło prawdy.

## Rozwiązanie (184a): provider wynika z modelu wybranego w panelu
Gdy panel ustawił model_primary, provider MA wynikać z tego modelu (AIModel->provider()), a NIE z .env. .env AI_PROVIDER zostaje fallbackiem tylko gdy panel nic nie ustawił. Wtedy:
- $currentProvider zgadza się z modelem panelu → model_override zawsze przejdzie.
- Instancja providera (ClaudeProvider/OpenAIProvider) musi pasować do modelu panelu — INACZEJ request Claude poleci do API OpenAI (błąd).

## DWIE WARSTWY DO NAPRAWY (obie konieczne — sama jedna nie wystarczy)

### Warstwa 1 — instancja providera (AIProviderFactory / DI)
PROBLEM: aiProvider wstrzykiwany jako 1 instancja z .env (routes.php → AIProviderFactory::create() czyta .env). Jeśli model panelu to Claude a instancja to OpenAIProvider — request Claude idzie do OpenAI API.
FIX (wybierz najczystsze, CC ocenia):
- Opcja A: ChatService dostaje provider wybierany na podstawie modelu z SettingsStore. Np. AIProviderFactory::createForModel(?string $modelId) — jeśli modelId podany → provider z AIModel::tryFrom($modelId)->provider(); else .env fallback. ChatService (lub warstwa wyżej) tworzy właściwego providera dla model_primary z settings PRZED wywołaniem chat().
- Opcja B: ChatService dostaje OBIE instancje (ClaudeProvider + OpenAIProvider) lub fabrykę, i wybiera właściwą wg providera modelu z panelu w runtime.
- KLUCZOWE: instancja providera użyta do chat() MUSI odpowiadać providerowi modelu z panelu. Provider z panelu (model), nie z .env.
- Jeśli to wymaga zmiany sygnatury konstruktora ChatService / DI w routes.php — zrób to (to sedno naprawy). Opisz wybór A/B w raporcie.

### Warstwa 2 — $currentProvider w ChatService (linia 102)
Zmień wyprowadzanie $currentProvider:
- Jeśli !empty($settings['model_primary']) i AIModel::tryFrom() rozpoznaje → $currentProvider = $primaryModel->provider() (z PANELU).
- Else → dotychczasowy fallback z .env (Config::get AI_PROVIDER / ANTHROPIC_MODEL).
- Po tym linia 109 (provider===currentProvider) zawsze się zgadza gdy panel ustawił model → model_override przechodzi.
- Linie logowania modelu (170-172, 272-274): $modelForLogging/$modelUsed = override ?? fallback. Po naprawie override będzie ustawiony, więc logowanie pokaże właściwy model. Zweryfikuj że logują model z panelu, nie .env.

## Escalation (model_escalation)
- Sprawdź jak działa escalation (model_escalation z panelu). Jeśli używa tego samego $currentProvider — po naprawie też ma wynikać z modelu escalation (provider modelu escalation może różnić się od primary? Raczej nie — ale sprawdź, by escalation też respektował panel). Jeśli escalation primary/escalation mogą być różnych providerów → instancja providera musi pasować do AKTUALNIE używanego modelu (primary vs escalation w danym wywołaniu). Opisz w raporcie jak rozwiązano.

## Ostrzeżenie w panelu (bezpiecznik na przyszłość — opcjonalne, jeśli proste)
- Po naprawie .env nie przebija panelu. Ale dla pewności: jeśli AIModel::tryFrom(model_primary) zwróci null (panel zapisał model spoza enuma) → log warning + fallback .env (NIE cichy gpt-4.1 bez śladu). Żeby przyszły rozjazd był widoczny w logach.

## Granice
- NIE wymuszać konkretnego modelu/providera (NIE "ustaw Claude"). Cel: panel STERUJE, jakikolwiek model w panelu = ten model leci. Wybór modelu należy do Karola (panel).
- .env AI_PROVIDER zostaje (185a) — fallback gdy panel pusty. NIE edytować .env na PROD.
- AIModel enum zna już haiku-4-5/opus-4-7/sonnet-4-6/gpt-* — NIE zmieniać enuma.
- Zmiana może dotknąć konstruktora ChatService + routes.php DI — dozwolone (sedno fixu).

## Kryteria akceptacji
1. Panel ustawia model_primary=claude-haiku-4-5 → rozmowy faktycznie lecą na Claude Haiku 4.5 (provider Claude wynika z modelu, instancja ClaudeProvider). Zweryfikuj na PROD: nowa rozmowa → divechat_message_usage / panel Rozmowy pokazuje claude-haiku-4-5.
2. Panel ustawia model_primary=gpt-4.1 → rozmowy lecą na GPT-4.1 (provider OpenAI). Panel steruje w OBIE strony.
3. .env AI_PROVIDER NIE przebija panelu (panel wygrywa). .env użyty tylko gdy panel pusty.
4. Instancja providera odpowiada modelowi z panelu (request Claude → ClaudeProvider → API Anthropic; nie do OpenAI).
5. model_escalation też respektuje panel (escalation leci właściwym modelem).
6. Model logowany (divechat_message_usage) = model faktycznie użyty z panelu, nie .env.
7. AIModel::tryFrom null (model spoza enuma) → log warning + fallback, nie cichy błąd.
8. php -l clean; test PROD opisany (Haiku z panelu → Haiku w rozmowie; zmiana na GPT → GPT). NIE zmieniać .env PROD.

## Wynik (CC, 2026-06-04)

**Wybór warstwy 1: Opcja A (factory injected, lazy resolve).** Najczystszy split odpowiedzialności:
- `AIProviderFactory` z `static create()` → instancja klasy z `createForModel(?string)`. Wewnętrzny cache `?ClaudeProvider` i `?OpenAIProvider` (lazy, tylko ten provider który faktycznie jest potrzebny). Nazwa providera z `AIModel::tryFrom($modelId)?->provider()`; fallback .env gdy null/spoza enuma.
- `ChatService` konstruktor: `AIProviderInterface $aiProvider` → `AIProviderFactory $providerFactory`. W `handle()` po `loadSettings()` resolve `$aiProvider = $this->providerFactory->createForModel($settings['model_primary'] ?? null)`.
- `public/index.php`: `AIProviderFactory::create()` → `new AIProviderFactory()` przekazane do ChatService.

Opcja B (obie instancje wstrzykiwane) odrzucona — wymuszałaby konstrukcję OBU providerów na każdym requeście (każdy z własnym Guzzle Client + odczytem ENV), nawet gdy używany tylko jeden.

**Warstwa 2:** `$currentProvider` derive z `AIModel::tryFrom($settings['model_primary'])?->provider()` (panel); fallback .env tylko gdy panel pusty albo model spoza enuma. Po naprawie linia provider===currentProvider zawsze true dla rozpoznawalnego modelu z panelu — `model_override` zawsze przechodzi.

**Escalation:** `model_escalation` jest ładowany do `$settings`, ale obecny tool loop NIE używa go (sprawdzone grep'em — żadnego użycia poza loadSettings). Fabryka jest gotowa na przyszły kod escalation: wystarczy zawołać `$this->providerFactory->createForModel($settings['model_escalation'])` w punkcie eskalacji i ustawić `$aiOptions['model_override']` na model escalation. Cache fabryki sprawi, że przy primary→escalation w obrębie tego samego providera nie powstanie druga instancja Guzzle.

**Bezpiecznik (kryterium 7):** w `AIProviderFactory::resolveProviderName()` — `AIModel::tryFrom() === null` → `error_log('[DiveChat] AIProviderFactory: model "X" spoza AIModel enuma — fallback na .env')`, dopiero potem .env. Identycznie w ChatService — w warstwie 2 też wpada w fallback .env (bo `$primaryModel === null`), więc panel-stale zostaje widoczne w logach, nie cicho.

**Test PROD (kryteria 1+2):**
1. Panel `model_primary=claude-haiku-4-5` → POST /api/chat → `diagnostics.model_used = claude-haiku-4-5` i `divechat_message_usage.model_id = claude-haiku-4-5`. PRZED naprawą leciało `gpt-4.1` mimo Haiku w panelu.
2. Tymczasowo `model_primary=gpt-4.1` → `model_used = gpt-4.1`, log = `gpt-4.1`. Przywrócone `claude-haiku-4-5`.
3. `.env` nie ruszane (`AI_PROVIDER=openai` zostało). Panel steruje w obie strony.

**php -l** clean (lokalnie + na PROD przez ea-php84) dla 3 zmodyfikowanych plików.

**Commit:** `96efaea` (3 pliki, +86/-25). Push do `main`. Deploy PROD: `scp` 3 plików do `~/public_html/chat.divezone.pl/` (AIProviderFactory.php, ChatService.php, public/index.php). Pliki testowe usunięte z PROD.
