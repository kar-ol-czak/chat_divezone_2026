# judge_prompts/

Placeholder — wypełniany w **T-024** (warstwa W1 sędziego + W2 panel).

Co tu trafi:
- `w1_default_v1.md` — domyślna rubryka sędziego (binarne pass/fail per 7 osi, CoT obowiązkowy).
- `w2_panel_v1.md` — instrukcja dla panelu (3 sędziów + reguły konsensusu).
- `meta_eval_golden_set.md` — instrukcja oceniającego golden set (Cohen κ ≥ 0.7 vs ekspert+eng).

Zasada: każda zmiana promptu sędziego = rebaseline (3 raporty 3/3 zgodne).
Wersjonowanie w git, nazewnictwo `_vN` z notatką w git log co się zmieniło.
