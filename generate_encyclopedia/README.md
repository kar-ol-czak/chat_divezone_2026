# Pipeline generacji encyklopedii sprzetu nurkowego

Automatyczna generacja i walidacja definicji encyklopedii dla AI czatu divezone.pl.
GPT-5.2 thinking (generacja) + Claude Opus 4.6 extended (walidacja).

## Instalacja

```bash
cd generate_encyclopedia/
pip install -r requirements.txt
```

Klucze API (`OPENAI_API_KEY`, `ANTHROPIC_API_KEY`) musza byc w `../.env`.

## Uzycie

```bash
# Podglad promptu bez wysylania do API
python run.py --group C --dry-run

# Generacja + walidacja
python run.py --group C

# Tylko generacja (bez walidacji)
python run.py --group C --gen-only

# Tylko walidacja (na istniejacym output/grupa_C/grupa_C_original.json)
python run.py --group C --val-only

# Powtorka z nowym seedem
python run.py --group C --retry
```

Grupy: A-M (13 grup, 105 pojec). Tryb `--group all` jest wylaczony — kazda grupa wymaga review.

## Workflow

1. `python run.py --group C` — generacja + walidacja
2. Przegladnij `output/grupa_C/grupa_C_validated.md`
3. Jesli 0 FAIL: pipeline automatycznie kopiuje `_original.json` jako `_final.json`
4. Jesli FAIL > 0: review + decyzja o patchach lub re-generacji
5. Nastepna grupa: `python run.py --group D`

## Dashboard

Przeglad wynikow pipeline w przegladarce:

```bash
cd generate_encyclopedia/
python -m http.server 8080
```

Otworz http://localhost:8080/dashboard.html

Dashboard pokazuje:
- **Grupy** — lista z statusem (PASS/FAIL/pending), klikniecie otwiera szczegoly
- **Logi** — tabela tokenow i kosztow per uruchomienie
- **Koszty** — podsumowanie per grupa i per model

## Struktura plikow

```
generate_encyclopedia/
  config.py              # konfiguracja modeli, sciezek, cennika
  models.py              # klienty API (GPT-5.2, Claude Opus 4.6)
  json_sanitizer.py      # sanityzacja Unicode, walidacja schema
  group_metadata.py      # metadane per grupa (pojecia, marki, reguly)
  prompt_builder.py      # buduje prompty z szablonow Jinja2
  pipeline.py            # orchestracja: generacja -> sanityzacja -> walidacja
  run.py                 # CLI
  dashboard.html         # przeglad wynikow w przegladarce
  templates/
    generation.md.j2     # szablon promptu generacyjnego
    validation.md.j2     # szablon promptu walidacyjnego
  output/
    grupa_{X}/           # pliki per grupa
      prompt_generation.md
      response_generation.md
      grupa_{X}_original.json
      prompt_validation.md
      response_validation.md
      grupa_{X}_validated.md
      grupa_{X}_final.json
    logs/
      grupa_{X}_{timestamp}.json
```
