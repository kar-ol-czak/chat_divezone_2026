# Prompt dla Claude Code — TASK-017 DataForSEO Keywords

Przeczytaj handoff i specyfikację taska:

```
cat _instances/embeddings/handoff/HANDOFF_task017_dataforseo.md
cat _instances/embeddings/tasks/TASK-017_dataforseo_keywords.md
```

Na ich podstawie napisz skrypt Python `scripts/dataforseo/fetch_keywords.py` oraz `scripts/dataforseo/requirements.txt`.

Skrypt pobiera dane keyword z DataForSEO API (Google Ads Keyword Planner) dla ~90 seed keywords sprzętu nurkowego. Credentials w `.env`. Szczegóły API, seed keywords (5 batchów po 20), format output i obsługa błędów — wszystko jest w TASK-017.

Po napisaniu skryptu uruchom go: `cd scripts/dataforseo && pip install -r requirements.txt && python fetch_keywords.py`

Pokaż mi wynik: ile fraz pobranych, koszt, top 20 fraz wg wolumenu.
