# TASK-ENC-013 — EMBEDDINGS: Automatyczny update encyklopedii (--mode changed + --mode check, hash w metadata)

**Status:** DO WYKONANIA
**Instancja:** embeddings (Python, scripts/embed_encyclopedia.py)
**Priorytet:** P2 (usprawnienie procesu, przed planowanym update'em encyklopedii przez Karola)
**Powiązane:** TASK-ENC-012 (istniejący embed_encyclopedia.py, tryby full/single/test-query), ADR-088 (kontekst sesji), encyclopedia_chunks (Railway PG)

---

## KONTEKST

Karol będzie regularnie aktualizował encyklopedię: DODAWANIE nowych haseł, czasem POPRAWKI istniejących (gdy znajdzie błąd). NIE usuwa haseł. Potrzebuje pewności, że po edycji czat ma aktualną wiedzę, BEZ ręcznego pamiętania które pliki ruszył i bez zbędnego re-embeddingu całości (koszt API OpenAI).

Decyzje (z rozmowy):
- **251 Poziom 2 + runbook** — nowe tryby `--mode changed` (auto-wykrycie i embed tylko nowych/zmienionych) i `--mode check` (read-only raport rozjazdu plik↔baza), PLUS krótki runbook w `_docs/`.
- **252a** — hash treści pliku raw zapisywany w `metadata` (jsonb) chunku w `encyclopedia_chunks`. ZERO nowych tabel. Porównanie plik↔baza w jednym miejscu.

Model danych (zweryfikowany): `data/encyclopedia/v3/gen_v2/raw/*.json` — JEDEN plik = JEDNO hasło (concept_key = nazwa pliku, 104 pliki). Każde hasło → 5 chunków (definition/synonyms/purchase/faq/seller), każdy osobno embedowany. UPSERT po `(concept_key, chunk_type)` z updated_at=NOW(). Czat (`standalone/src/Tools/ExpertKnowledge.php`) czyta `encyclopedia_chunks` na żywo, per-request — gdy chunk zaktualizowany, czat widzi nową treść natychmiast.

WAŻNE — JEDYNE źródło wiedzy eksperckiej czatu to `encyclopedia_chunks`. Czat NIE czyta bloga ani CMS. Aktualność encyklopedii = aktualność wiedzy czatu. Stąd ten task.

---

## STAN OBECNY (zweryfikowany w scripts/embed_encyclopedia.py)

- Tryby: `--mode full` (wszystkie 104 pliki), `--mode single --concept NAZWA` (jedno hasło), `--mode test-query --query "..."`.
- `load_entries(concept_key=None)` — czyta jeden plik (gdy concept_key) lub wszystkie (glob *.json, sorted).
- `build_chunks(entries)` — buduje 5 chunków/hasło; `metadata` (dict wspólny dla 5 chunków) zawiera dziś: concept_number, name_pl, name_en, related_keys, pipeline_version. **TU dochodzi hash.**
- `upsert_chunks(conn, chunks)` — `INSERT ... ON CONFLICT (concept_key, chunk_type) DO UPDATE SET content, embedding, metadata, updated_at=NOW()`.
- Model: text-embedding-3-large, EMBEDDING_DIM=3072 (MUSI pozostać zgodny z ExpertKnowledge.php — NIE zmieniać).
- Brak indeksu wektorowego (3072 > limit 2000 dla IVFFlat/HNSW; exact scan przy ~520 wierszach <1ms) — bez zmian.

---

## KROK 0 — PULL / READ

1. `git pull origin main` (sprawdź `git status` i aktualną gałąź).
2. Przeczytaj `scripts/embed_encyclopedia.py` w całości — szczególnie: `main()` (argparse, tryby), `load_entries()`, `build_chunks()` (gdzie składany jest `metadata`), `upsert_chunks()`, `verify()`.
3. Zobacz strukturę 1-2 plików raw, np. `data/encyclopedia/v3/gen_v2/raw/MASKA_JEDNOSZYBOWA.json` i `OGRZEWANIE_NURKOWE.json` — żeby wiedzieć co dokładnie hashować.
4. Sprawdź `git log` pod kątem konwencji commitów używanej dla zmian w scripts/embeddings — dopasuj prefiks commita do tego, co już w repo (jeśli brak wzorca ENC, użyj `TASK-ENC-013 embeddings: ...`).

## KROK 1 — Hash źródła w metadata

- W `build_chunks()`: policz `source_hash` = sha256 z **kanonicznej** zawartości pliku raw danego hasła. Kanoniczność WAŻNA, żeby nieistotne różnice formatowania nie wywoływały re-embeddingu: wczytaj JSON i policz hash z `json.dumps(entry, sort_keys=True, ensure_ascii=False, separators=(',',':'))` (NIE z surowego tekstu pliku — to odporne na zmianę wcięć/kolejności kluczy).
- Dodaj do `metadata`: `"source_hash": <sha256_hex>`. Hash jest per-hasło (taki sam w 5 chunkach hasła) — to OK, porównujemy po dowolnym chunku hasła.
- UWAGA spójność: hash liczony z `entry` PRZED budową chunków. Ten sam algorytm użyty w `--mode changed` i `--mode check` do porównania.

## KROK 2 — Tryb --mode check (read-only, najpierw — to fundament)

Nowy tryb, NIC nie zmienia w bazie. Raportuje rozjazd plik↔baza:
1. Wczytaj wszystkie pliki raw → policz `source_hash` każdego hasła (jak KROK 1).
2. Pobierz z bazy: `SELECT concept_key, metadata->>'source_hash' AS h, MAX(updated_at) FROM encyclopedia_chunks GROUP BY concept_key, metadata->>'source_hash'`. (Uwaga: jeśli hasło ma <5 chunków albo niespójny hash między chunkami — zaraportuj jako anomalię.)
3. Wypisz cztery kategorie:
   - **NOWE** (plik istnieje, brak concept_key w bazie),
   - **ZMIENIONE** (hash pliku ≠ hash w bazie),
   - **AKTUALNE** (hash zgodny),
   - **SIEROTY** (concept_key w bazie, brak pliku — Karol NIE usuwa, więc nie powinno wystąpić; zaraportuj ostrzegawczo, NIE usuwaj).
4. Exit code 0 gdy wszystko AKTUALNE, !=0 gdy są NOWE/ZMIENIONE (przydatne do skryptów/CI w przyszłości). Zero zapisu do DB, zero kosztu API (hash liczony lokalnie, embeddingów NIE generujemy w check).

## KROK 3 — Tryb --mode changed (embed tylko nowych/zmienionych)

1. Użyj logiki z `--mode check` do wyznaczenia listy haseł NOWE + ZMIENIONE.
2. Jeśli pusto → wypisz "Brak zmian, baza aktualna." i zakończ (zero kosztu).
3. Dla listy zmienionych: `load_entries` per concept_key (reużyj istniejącej obsługi single w pętli LUB rozszerz load_entries o listę kluczy), `build_chunks` (z source_hash z KROK 1), `embed_chunks` (TYLKO te chunki — nie całość!), `upsert_chunks`.
4. Po upsert: krótka weryfikacja (ile haseł zaktualizowano, nowy MAX(updated_at)).
5. Koszt: wypisz ile chunków/tokenów/USD (jak robi to `embed_chunks`) — żeby Karol widział że płaci tylko za zmiany.

## KROK 4 — Drobne, bezpieczne uzupełnienia

- `--mode full` musi też zapisywać `source_hash` (inaczej po pełnym re-embeddingu `check` pokaże wszystko jako "zmienione"). Czyli hash z KROK 1 wchodzi do `build_chunks` używanego przez WSZYSTKIE tryby — to naturalnie pokrywa full/single/changed.
- Zachowaj wsteczną zgodność: istniejące tryby full/single/test-query działają jak dotąd (tylko zyskują source_hash w metadata).
- NIE zmieniaj modelu embeddingów ani wymiaru (3072) — zgodność z ExpertKnowledge.php.
- NIE dodawaj usuwania chunków (Karol nie usuwa haseł; sieroty tylko raportować w check).

## KROK 5 — Runbook w _docs/

Krótki plik `_docs/23_runbook_update_encyklopedii.md` (numer kolejny — sprawdź najwyższy w _docs/):
- Jak dodać/poprawić hasło: edytuj/dodaj plik w `data/encyclopedia/v3/gen_v2/raw/{CONCEPT_KEY}.json`.
- Po edycji: `python scripts/embed_encyclopedia.py --mode changed` (embeduje tylko to co ruszone).
- Weryfikacja świeżości: `python scripts/embed_encyclopedia.py --mode check` (powinno pokazać "wszystko AKTUALNE").
- Test na żywym czacie: `--mode test-query --query "..."` ALBO zapytanie do produkcyjnego czatu o zmienione hasło.
- Uwaga: czat czyta encyclopedia_chunks na żywo — po `changed` zmiana widoczna od razu, bez deployu/restartu.
- Uwaga kosztowa: `changed` płaci tylko za zmienione hasła; `full` re-embeduje całość (~525 chunków) — używać tylko przy zmianie modelu/struktury.

## KROK 6 — Test

- `--mode check` na niezmienionej bazie → wszystko AKTUALNE, exit 0.
- Edytuj testowo jeden plik raw (drobna zmiana treści) → `--mode check` pokazuje to hasło jako ZMIENIONE → `--mode changed` embeduje TYLKO je (potwierdź: koszt ~1 hasła, nie 104) → ponowny `--mode check` → AKTUALNE.
- Dodaj testowy plik-hasło → `--mode changed` robi INSERT 5 chunków → check AKTUALNE. (Posprzątaj testowe hasło po teście, skoro to tylko test.)
- Potwierdź że `--mode full` nadal działa i zapisuje source_hash (po full → check = wszystko AKTUALNE).

## KROK 7 — STOP (przed uruchomieniem na produkcyjnym PG)

>>> STOP — RAPORT DLA KAROLA <<<
Zatrzymaj się przed jakimkolwiek uruchomieniem zapisującym na produkcyjnym Railway PG. Przedstaw:
- diff `embed_encyclopedia.py` (nowe tryby + hash),
- treść runbooka,
- wynik testów (check/changed na testowej zmianie, koszt),
- potwierdzenie wstecznej zgodności full/single.
Czekaj na akceptację. UWAGA: `--mode changed`/`--full` PISZE do produkcyjnej encyclopedia_chunks (ta sama baza, z której czyta żywy czat). `--mode check` jest bezpieczny (read-only) — można go pokazać jako pierwszy dowód działania.

## KROK 8 — Git (po akceptacji)

1. `git status` (wylistuj untracked).
2. `git add` per ścieżka: `scripts/embed_encyclopedia.py`, `_docs/23_runbook_update_encyklopedii.md`. NIE `git add .`. Pomiń pliki z `.gitignore`.
3. Commit wg konwencji (sprawdzonej w KROK 0): `TASK-ENC-013 embeddings: auto-update encyklopedii (--mode changed + check, hash w metadata)`
4. `git push origin main`.

## KROK 9 — STATE UPDATE + RAPORT (ostatni krok)

1. Dopisz do `_docs/21_STATUS_PROJEKTU.md`: TASK-ENC-013 wykonany, automatyczny update encyklopedii dostępny (changed/check), runbook w _docs/23.
2. Osobny commit: `docs: TASK-ENC-013 (auto-update encyklopedii changed/check + runbook) — status vX.XX`
3. `git push origin main`.
4. Raport dla Karola: jak teraz wygląda jego workflow update (edytuj plik → --mode changed → --mode check), gdzie runbook.

---

## GRANICE / UWAGI

- ZERO nowych tabel — hash w istniejącej kolumnie `metadata` (jsonb).
- NIE usuwać chunków (sieroty tylko raportować). Karol nie usuwa haseł.
- NIE zmieniać modelu/wymiaru embeddingów (3072, text-embedding-3-large) — zgodność z czatem.
- Hash kanoniczny (json sort_keys), nie z surowego tekstu — odporność na reformatowanie.
- `--mode check` read-only (bezpieczny dowód), `--mode changed`/`full` piszą do produkcyjnego PG (STOP przed uruchomieniem).
- Czat czyta na żywo — brak deployu/restartu po update encyklopedii.
