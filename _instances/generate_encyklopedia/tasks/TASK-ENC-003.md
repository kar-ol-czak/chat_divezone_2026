# TASK-ENC-003: Poprawki po review architekta
# Instancja: generate_encyklopedia (Python)
# Priorytet: WYSOKI (blokuje uruchomienie pipeline)
# Status: DONE
# Data: 2026-02-27

## Poprawki

### 1. models.py: temperature usuniety ✅ DONE
GPT-5.2 z reasoning nie obsluguje parametru temperature.
Usunieto z _call() w generate().

### 2. models.py: thinking tokeny ✅ DONE
Naprawiono ekstrakcje thinking tokenow.
Liczy z content blokow (przyblizenie ~4 znaki/token), nie z cache_creation_input_tokens.

### 3. config.py: komentarze o reasoning_effort (DO ZROBIENIA)
Dodaj:
```python
# UWAGA: GPT-5.2 default = none. Jawne ustawienie jest KRYTYCZNE.
# Opcje: none, low, medium, high, xhigh
GENERATION_REASONING_EFFORT = "high"
```

### 4. Sub-batche: grupy >8 pojec dzielone na czesci (DO ZROBIENIA)

PROBLEM: Grupa C (16 pojec) = 26k znakow output, trafia w limit 16k tokenow.

ROZWIAZANIE: Pipeline automatycznie dzieli grupy >8 pojec na sub-batche.

Prog: MAX_CONCEPTS_PER_BATCH = 8 (dodac w config.py)

Logika w pipeline.py _run_generation():
1. Jesli metadata.concept_count <= 8: jeden call (bez zmian)
2. Jesli > 8: podziel concepts na sub-batche po max 8
3. Dla kazdego sub-batcha:
   - Zbuduj prompt z PELNA lista pojec grupy (zeby model widzial kontekst)
   - Ale w sekcji "POJECIA DO WYGENEROWANIA" tylko sub-batch
   - Wyslij do API
   - Sanityzuj JSON
4. Polacz entries ze wszystkich sub-batchy w jeden JSON
5. Zapisz polaczony wynik jako grupa_X_original.json

Wazne szczegoly:
- Prompt musi zawierac info: "Generujesz batch X/Y. Pelna lista pojec grupy
  (do kontekstu nie_mylic_z i powiazane_produkty): [lista]. Wygeneruj TYLKO: [sub-batch]."
- Kazdy sub-batch ma osobny log krokow (generation_batch_1, generation_batch_2)
- Koszty sumowane ze wszystkich sub-batchy
- Prompt i response zapisywane osobno: prompt_generation_1.md, response_generation_1.md
- Schema validation na polaczonym JSON (nie per batch)
- Brand validation na polaczonym JSON

Grupy do podzialu (A i B juz gotowe):
- C: 16 -> 8+8
- G: 10 -> 5+5

Grupy w jednym batchu: D(6), E(8), F(8), H(6), I(7), J(4), K(4), L(7), M(5)

Zmiana w templates/generation.md.j2:
Dodaj opcjonalna sekcje Jinja2:
```
{% if is_sub_batch %}
## KONTEKST: Batch {{ batch_number }}/{{ total_batches }}
Pelna lista pojec grupy (uzywaj do nie_mylic_z i powiazane_produkty):
{% for c in all_concepts %}{{ c.key }}{% if not loop.last %}, {% endif %}{% endfor %}

Wygeneruj TYLKO ponizsze {{ concept_count }} pojec:
{% endif %}
```

### 5. run.py: --gen-only z walidacja JSON (DO ZROBIENIA)
Po --gen-only sprawdz czy JSON jest valid i kompletny (wszystkie pojecia obecne).
Wyswietl na stdout ile pojec wygenerowano vs oczekiwano.

## Definicja done

- [x] temperature usuniety z generate()
- [x] thinking tokeny liczone z content blokow
- [x] Komentarz o reasoning_effort w config.py
- [x] MAX_CONCEPTS_PER_BATCH = 8 w config.py
- [x] Sub-batch logika w pipeline.py
- [x] Template generation.md.j2 z opcjonalnym kontekstem batcha
- [x] Pliki output: prompt/response z numerem batcha
- [x] Logi per sub-batch + sumaryczny
- [x] --gen-only wyswietla kompletnosc JSON
- [x] Test: python run.py --group C --dry-run generuje 2 prompty
