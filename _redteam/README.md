# _redteam/ — harness red-team dla DiveChat

Faza 0 prerekwizyty (T-021). Pełna architektura: ADR-060, _docs/26_synteza_panelu_redteam.md.

## Struktura

```
_redteam/
├── scenarios/      # YAML scenariusze ataków (T-022, ~50, 10 klas)
├── fixtures/       # ground truth: catalog_snapshot_*.json, synthetic_orders.json
├── domain_rules/   # listy zakazane (forbidden_terms.yaml + .local.yaml gitignored)
├── judge_prompts/  # rubryki sędziego (W1, W2 panel), wersjonowane
├── tools/          # snapshot_catalog.py + skrypty pomocnicze
├── configs/        # models.md (pin), promptfoo.config (T-023)
├── transcripts/    # JSON transkryptów z runów (gitignored — może mieć PII z discovery)
└── README.md
```

## Profil ryzyka (ADR-060)

- Czat NIE jest opublikowany na sklepie → brak realnych klientów w pętli.
- WSZYSTKIE 6 narzędzi (ProductSearch, ProductDetails, ExpertKnowledge, OrderStatus, ShippingInfo, GetShopSchedule) są READ-ONLY.
- → NIE stawiamy chat-test.divezone.pl z klonem bazy. Bijemy w dev endpoint.
- ALE: OrderStatus czyta realną pr_orders + zwraca email/imię/nazwisko klienta. Scenariusze IDOR operują na SYNTETYCZNYCH referencjach (TEST-FAKE-*), nigdy na realnych numerach.

## Warstwy oceny (kaskada)

- **W0** — deterministyczne reguły (regex, domain_rules/forbidden_terms.yaml) → natychmiastowy FAIL, pre-filter.
- **W1** — jeden sędzia (innej rodziny niż target) z rubryką binarną + chain-of-thought + reference answer.
- **W2** — panel 3 sędziów różnych rodzin TYLKO dla S0/S1, sporów, low-confidence, ~10% próbki meta-eval, bramki deploy.

Reguła anty-bias: sędzia NIE z rodziny targetu.

## Ground truth (H1, KONIECZNE)

Sędzia LLM nie wie, co jest w katalogu. Bez snapshotu klasa "halucynacje produktowe" to zgadywanie. Case 90/91 (Crystal Vu, SANTI BZ400) wymagają referencyjnego dumpu:

```bash
python tools/snapshot_catalog.py \
    --output fixtures/catalog_snapshot_$(date +%Y-%m-%d).json
```

Snapshot zawiera tylko katalog produktów + statusy. **Zero danych klientów.**

Walidacja smoke:
```bash
python tools/snapshot_catalog.py --validate fixtures/catalog_snapshot_YYYY-MM-DD.json
```
Sprawdza obecność Crystal Vu (7316/7442/4926) w kategoriach masek panoramicznych/zestawów oraz SANTI BZ400 (ocieplacz).

## Pin modeli

`configs/models.md` — wybrane snapshoty dla attackera/targetu/sędziów. **NIE używać aliasów `-latest`** — dostawcy aktualizują minory bez wiedzy, co psuje powtarzalność regresji.

Wybór pinu konsultowany z Karolem (każda zmiana ↔ rebaseline).

## RODO / PII

- Realne PII (numery zamówień klientów, ich emaile, nazwiska, dane sprzedażowe) **nigdy** w repo.
- Nazwiska pracowników (jak BARTEK z aliasów statusów, T-019) traktujemy jako PII — trzymane wyłącznie w `domain_rules/forbidden_terms.local.yaml` (gitignored).
- Transcripty z runów Discovery (T>0, atakujący stochastyczny) mogą zahaczyć o cudze PII jeśli kiedyś przejdą na produkcję → trafiają do `transcripts/` (gitignored), z auto-scrubberem przed eksportem.
- Wszystkie scenariusze przeciw OrderStatus używają **synthetic_orders.json** — fikcyjne reference TEST-FAKE-* i emaile @example.test.

## Out of scope Fazy 0 (T-022+)

- Scenariusze YAML (~50, 10 klas) — T-022.
- Orchestrator Promptfoo + custom HTTP provider — T-023.
- W0 regex + W1 sędzia + integracja domain_rules — T-024.
- Meta-eval sędziego (golden set 50-100 transkryptów oceniony ręcznie + Cohen κ ≥ 0.7).
- Garak nightly (encoding/DAN single-turn probes).

## Powiązane

- _docs/26_synteza_panelu_redteam.md (synteza panelu 3/3)
- _docs/25_panel_ekspertow_redteam_prompt.md (seed promptu)
- _docs/10_decyzje_projektowe.md (ADR-060)
- _instances/backend/tasks/T-021_backend_redteam-faza0-prerekwizyty.md
