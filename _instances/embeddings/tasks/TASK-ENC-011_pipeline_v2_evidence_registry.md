# TASK-ENC-011: Przebudowa pipeline generacji — Evidence Registry + JSON + Validator
# Data: 2026-03-06
# Status: DO ZROBIENIA
# Instancja: embeddings
# ADR: ADR-046

---

## OVERVIEW

Ten task jest podzielony na 4 pod-taski wykonywane SEKWENCYJNIE.
Każdy pod-task ma STOP point — czekaj na review architekta przed następnym.

## KOLEJNOŚĆ

```
011a: Completeness Gate + Evidence Builder
  ↓ STOP → review architekta
011b: Gemini Call (test 3 haseł → review → full 105)
  ↓ STOP → review architekta
011c: Deterministic Validator
  ↓ STOP → review architekta (0 RED wymagane)
011d: Markdown Renderer + Master Report
  ↓ STOP → review architekta → human review Karol
```

## PLIKI TASKÓW

1. `TASK-ENC-011a_completeness_evidence.md` — mapowania + evidence registry
2. `TASK-ENC-011b_gemini_json_call.md` — wywołania Gemini z JSON Schema
3. `TASK-ENC-011c_validator.md` — deterministyczna walidacja
4. `TASK-ENC-011d_renderer_report.md` — markdown renderer + master report

## HANDOFF DLA CC

Każdy pod-task uruchamiaj osobną sesją CC:

```
Sesja 1: "Przeczytaj i wykonaj TASK-ENC-011a_completeness_evidence.md"
→ STOP → review

Sesja 2: "Przeczytaj i wykonaj TASK-ENC-011b_gemini_json_call.md"
→ STOP po teście 3 haseł → review → "Uruchom --mode full"

Sesja 3: "Przeczytaj i wykonaj TASK-ENC-011c_validator.md"
→ STOP → review

Sesja 4: "Przeczytaj i wykonaj TASK-ENC-011d_renderer_report.md"
→ STOP → review → human review Karol
```

## STARA SPECYFIKACJA

Pełna architektura jest opisana w tym pliku (pipeline_v2_evidence_registry.md)
ale CC pracuje z pod-taskami, nie z tym plikiem bezpośrednio.
