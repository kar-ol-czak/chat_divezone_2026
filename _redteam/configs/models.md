# Pin modeli — Faza 0 (T-021 KROK 5)

**STATUS: ZAAKCEPTOWANE 2026-05-26 (T-021).**

## Decyzja Karola (T-021)

| Rola | Model_id | Notatka |
|---|---|---|
| Target | `claude-sonnet-4-6` | matchuje dev `.env` (`ANTHROPIC_MODEL`). |
| Attacker | `gpt-5.4-mini` | $0.75/$4. Spoza rodziny Claude. `gpt-5.5-mini` nie istnieje (websearch 2026-05). |
| Sędzia W1 | `gpt-5.4` | **decyzja 102b (cost-optimization): W1 obsługuje ~80% scenariuszy, gpt-5.5 jest 2× droższy ($5/$30 vs $2.5/$15) → gpt-5.5 zostawiamy tylko w W2 escalation.** `gpt-5.4` jest już w `divechat_model_pricing` → W1 odpalimy bez migracji. |
| Sędzia W2 panel | `claude-opus-4-7` + `gpt-5.5` | 2 rodziny w Faza 0. Anty-bias spełniony (target=Claude → GPT spoza rodziny). gpt-5.5 wymaga migracji `divechat_model_pricing` w T-022 przed T-023. Trzeci głos (Gemini 2.5 Pro) opcjonalny przy meta-eval/golden set w T-024+, klucz Google API już w `.env`. |
| Strategia snapshot ID | alias + log z response | Pin w configu = alias. T-023 loguje pełny snapshot z odpowiedzi providera. Drift w transkrypcie → rebaseline event. |

Każda zmiana pin = rebaseline (3/3 raporty panelu).


Każda zmiana pin = rebaseline (3/3 raporty panelu). Dlatego pinujemy z premedytacją.

## Modele dostępne w projekcie (źródło: `divechat_model_pricing` + `standalone/src/Enum/AIModel.php`)

| `model_id` (alias) | provider | label | tier | input $/1M | output $/1M | uwagi |
|---|---|---|---|---|---|---|
| `claude-opus-4-7` | claude | Claude Opus 4.7 | escalation | 5.00 | 25.00 | drogi, najmocniejszy |
| `claude-sonnet-4-6` | claude | Claude Sonnet 4.6 | primary | 3.00 | 15.00 | **target domyślny** (.env `ANTHROPIC_MODEL`) |
| `claude-haiku-4-5` | claude | Claude Haiku 4.5 | primary | 1.00 | 5.00 | tani attacker |
| `gpt-5.5` | openai | GPT-5.5 | escalation | **5.00** | **30.00** | najnowszy GPT (websearch 2026-05). 2× droższy niż gpt-5.4. **NIE w `divechat_model_pricing` — migracja w T-022.** Używany w W2 panel (escalation S0/S1). |
| `gpt-5.4` | openai | GPT-5.4 | escalation | **2.50** | **15.00** | poprzednia gen (websearch 2026-05). W pricingu. Używany w W1 (default judge ~80% scenariuszy). |
| `gpt-5.4-mini` | openai | GPT-5.4 Mini | primary | 0.75 | 4.00 | attacker (gpt-5.5-mini nie istnieje, zweryfikowane 2026-05). |
| `gpt-5-mini` | openai | GPT-5 Mini | primary | 0.25 | 2.00 | najtańszy attacker |
| `gpt-4.1` | openai | GPT-4.1 | primary | 2.00 | 8.00 | bez reasoning_effort |
| `o3-mini` | openai | o3-mini | primary | — | — | reasoning model |

## UWAGA: alias vs snapshot date

Aliasy w naszej tabeli (`claude-opus-4-7`, `gpt-5.4`) **mogą się ślizgać na minorach po stronie dostawcy** (3/3 raporty). Pełny pin wymaga snapshot-date ID (np. `claude-opus-4-20250514`, `gpt-5.4-2026-04-01`).

**Strategia Faza 0:**
1. Wybieramy ALIASY w tej tabeli (target/attacker/judge).
2. W T-023 (Promptfoo orchestrator) każdy call zapisuje pełne `id` snapshotu zwrócone w response → log w `_redteam/transcripts/*.json` (gitignored). Drift wykryty diff'em.
3. Gdy dostawca opublikuje stabilny snapshot-string (np. `-20260601`), aktualizujemy ten plik i traktujemy jako rebaseline event (canary scenariusze + S0 muszą przejść).

## Propozycja przypisania ról (do akceptacji)

### Target (model bota DiveChat — `ANTHROPIC_MODEL` w `.env`)

- Obecnie: `claude-sonnet-4-6` (z `.env` line 70).
- Pin Faza 0: **`claude-sonnet-4-6`** — testujemy realną konfigurację dev.
- Alternatywa do potwierdzenia z Karolem: jeśli chcemy testować TEŻ `claude-opus-4-7` lub `gpt-5.4` (bot per-conversation może przełączać via panel), trzeba dodać runy per-target.

### Attacker (generator ataków w Discovery / multi-turn)

- Wybór konsensus 3/3: Attacker innej rodziny niż target NIE jest wymagany (różnorodność ataku daje baza scenariuszy), ale tani i sprytny model wystarcza.
- **Decyzja: `gpt-5.4-mini`** ($0.75/$4, spoza rodziny Claude). Wariant `gpt-5.5-mini` NIE istnieje (zweryfikowane przez Karola websearch 2026-05).

### Sędzia W1 (default, ~80% scenariuszy)

- Reguła anty-bias (3/3): NIE z rodziny targetu. Skoro target = Claude → W1 = OpenAI.
- **Decyzja 102b (cost-optimization): W1 = `gpt-5.4`**, NIE `gpt-5.5`. Powód:
  - W1 obsługuje ~80% scenariuszy w kaskadzie (W2 panel wchodzi tylko dla S0/S1 + ~10% meta-eval).
  - `gpt-5.5` = $5/$30 per 1M, `gpt-5.4` = $2.5/$15 per 1M — różnica 2×. Przy 500 scenariuszy × 5 tur × W1 to znacząca pozycja w budżecie (~$100-200 per pełen scan vs ~$200-400).
  - `gpt-5.5` zostaje jako escalation w W2 (S0/S1 hard blockers, low-confidence, meta-eval) — tam jakość ważniejsza niż koszt.
- `gpt-5.4` jest już w `divechat_model_pricing` → W1 odpalimy bez migracji.

### Sędzia W2 panel (S0/S1, spory, low-confidence, ~10% meta-eval)

- Faza 0: 2 modele 2 rodzin (Anthropic + OpenAI). Anty-bias spełniony bo target = Claude.
  - **`claude-opus-4-7`** (Anthropic) — "soft" judge tej samej rodziny co target (sanity check).
  - **`gpt-5.5`** (OpenAI) — "hard" judge spoza rodziny targetu, najnowsza generacja ($5/$30) zarezerwowana dla escalation (decyzja 102b). **Wymaga dodania do `divechat_model_pricing` w T-022 przed T-023.**
- Gemini 2.5 Pro NIE w Faza 0 — bot DiveChat nie używa Google, panel 2-rodzinowy wystarcza dla MVP. Klucz Google API już jest w `.env` projektu (decyzja Karola w T-021), więc dodanie Gemini w T-024 (meta-eval golden set) wymaga tylko konfiguracji Promptfoo bez ruchów na infra.

## Otwarte przed T-023

- Migracja `divechat_model_pricing` o `gpt-5.5` (input $5 / output $30 per 1M, websearch 2026-05). Osobny task **T-022** (decyzja Karola w T-021: NIE w commicie Faza 0).
- Ew. rozszerzenie `standalone/src/Enum/AIModel.php` o `GPT_55 = 'gpt-5.5'` jeśli bot ma sam korzystać z tego modelu (red-team harness nie wymaga — Promptfoo akceptuje dowolny model_id niezależnie od enum).

## Powiązane

- ADR-051 / ADR-052 (roster modeli, `divechat_model_pricing`)
- ADR-060 (architektura red-team)
- _docs/26 sekcja "Pin model version w request (snapshot, nie -latest)"
