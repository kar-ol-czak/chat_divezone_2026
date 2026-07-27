# TASK-ENC-001: Implementacja pipeline generacji encyklopedii
# Instancja: generate_encyklopedia (Python)
# Priorytet: WYSOKI
# Status: DO ZROBIENIA
# Data: 2026-02-27

## Cel

Zaimplementowac pipeline Python ktory automatyzuje generacje i walidacje
definicji encyklopedii sprzetu nurkowego via API (OpenAI + Anthropic).

## Specyfikacja

Pelna specyfikacja techniczna: generate_encyclopedia/spec.md (362 linie)
Handoff z kontekstem: _instances/generate_encyklopedia/handoff/HANDOFF_from_architect.md

## Kolejnosc implementacji

1. config.py + models.py (klienty API, test polaczenia)
2. json_sanitizer.py (KRYTYCZNE, testuj na danych z grupy B)
3. group_metadata.py (parsowanie FAZA1, marek, fraz, regul domenowych)
4. prompt_builder.py + templates/ (Jinja2, bazuj na promptach A/B)
5. pipeline.py (orchestracja)
6. run.py (CLI)
7. Testy: --dry-run na A, porownanie z recznym promptem

## Definicja done

- [ ] run.py --group C --dry-run generuje sensowny prompt
- [ ] run.py --group A --dry-run generuje prompt zblizona do recznego
- [ ] json_sanitizer.py parsuje JSON z polskimi cudzyslowami
- [ ] Schema validation przechodzi na grupa_A i grupa_B
- [ ] Logi z tokenami, modelami, kosztami USD per krok (spec sekcja 12.5)
- [ ] Pelne prompty i odpowiedzi zapisywane w output/grupa_{X}/ (spec sekcja 12.6)
- [ ] requirements.txt z zaleznosciami
- [ ] README.md z instrukcja uzycia

## Blokujace

Nic. Wszystkie dane wejsciowe sa gotowe.

## Uwagi

- Tryb --group all jest WYLACZONY (gate workflow, review Karola po kazdej grupie)
- Modele: GPT-5.2 thinking (reasoning_effort=high), Claude Opus 4.6 extended (budget_tokens=16000)
- Model w .env (claude-sonnet-4-6) to model CZATU, dla encyklopedii uzyc claude-opus-4-6
- Reguly domenowe per grupa: _docs/17_reguly_domenowe_grupy_C-M.md
- UWAGA: litery grup w regulach != litery grup encyklopedii, mapowanie w spec.md sekcja 8.2
