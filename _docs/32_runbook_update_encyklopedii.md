# Runbook: aktualizacja encyklopedii (auto-update przez source_hash)

**Cel:** krótka procedura dla Karola po dodaniu/poprawce hasła w encyklopedii. Czat (`ExpertKnowledge.php`) czyta `encyclopedia_chunks` z Railway PG na żywo — zmiana widoczna w czacie od razu po embedowaniu, bez deployu/restartu.

**Powiązane:** TASK-ENC-013 (skrypt `scripts/embed_encyclopedia.py`, tryby `--mode changed` / `--mode check`).

---

## 1. Dodanie/poprawka hasła

1. Edytuj lub utwórz plik w:
   ```
   data/encyclopedia/v3/gen_v2/raw/{CONCEPT_KEY}.json
   ```
   - DODANIE: nowy plik z polami jak w istniejących (`concept_key`, `name_pl`, `definition`, `synonyms`, `purchase_parameters`, `faq`, `seller_notes`, `related_concept_keys`, …).
   - POPRAWKA: edytuj istniejący plik. Drobne zmiany formatowania (kolejność kluczy, wcięcia) NIE powodują re-embeddingu — hash jest kanoniczny (`sort_keys`).

2. Embeduj TYLKO ruszone hasła:
   ```bash
   python scripts/embed_encyclopedia.py --mode changed
   ```
   - Skrypt sam wykrywa NOWE/ZMIENIONE po porównaniu `source_hash` z plików raw z `metadata->>'source_hash'` w bazie.
   - Pisze do produkcyjnej `encyclopedia_chunks` (UPSERT po `(concept_key, chunk_type)`).
   - Wypisuje koszt — płacisz tylko za zmienione hasła.

3. Weryfikacja świeżości:
   ```bash
   python scripts/embed_encyclopedia.py --mode check
   ```
   - Read-only, zero kosztu API.
   - Po `changed` powinno pokazać "Wszystko AKTUALNE" (exit 0).
   - Exit 2 = są jeszcze NOWE/ZMIENIONE/ANOMALIE.

4. (Opcjonalnie) test semantyczny:
   ```bash
   python scripts/embed_encyclopedia.py --mode test-query --query "twoje pytanie"
   ```
   lub po prostu zadaj pytanie produkcyjnemu czatowi o zmienione hasło.

---

## 2. Tryby skryptu — szybka ściąga

| tryb | co robi | DB | API |
|---|---|---|---|
| `--mode check` | raport rozjazdu plik↔baza (NOWE/ZMIENIONE/AKTUALNE/SIEROTY/ANOMALIE), exit 0=czysto, 2=są zmiany | read-only | 0 |
| `--mode changed` | embeduje TYLKO NOWE+ZMIENIONE wykryte przez hash | WRITE (UPSERT) | tylko zmienione |
| `--mode single --concept KEY` | re-embed jednego hasła wprost | WRITE | 1 hasło |
| `--mode full` | re-embed WSZYSTKICH 105 haseł (~525 chunków) | WRITE | całość |
| `--mode test-query --query "..."` | top-10 semantic search | read-only | 1 query |

---

## 3. Kiedy `--mode full` (a nie `changed`)

`full` używamy TYLKO przy:
- zmianie modelu embeddingów (np. `text-embedding-3-large` → coś innego),
- zmianie wymiaru (3072 → coś innego),
- zmianie struktury chunków (logika w `build_chunk_*`),
- zmianie algorytmu hashowania (musimy odświeżyć hashe w bazie żeby `check` przestał krzyczeć "ZMIENIONE" na wszystko).

W codziennym update encyklopedii — ZAWSZE `changed`.

---

## 4. Jak działa hash (krótko)

- `build_chunks()` liczy `source_hash` z **kanonicznej** serializacji JSON hasła (sort_keys, ensure_ascii=False, separators=(',',':')).
- Hash trafia do `metadata->>'source_hash'` na każdym z 5 chunków hasła (definition/synonyms/purchase/faq/seller) — identyczny dla wszystkich pięciu.
- `check`/`changed` porównują hash z pliku z hashem z bazy. Reformatowanie pliku nie wywołuje re-embeddingu. Zmiana TREŚCI hasła — wywołuje.

---

## 5. Granice / czego skrypt NIE robi

- **NIE usuwa chunków** — sieroty (concept_key w bazie, brak pliku raw) są tylko RAPORTOWANE w `check`/`changed`. Karol nie usuwa haseł; jeśli pojawi się sierota, rozwiązujemy ręcznie.
- **NIE zmienia modelu/wymiaru** — `text-embedding-3-large` + 3072 dim trzymane sztywno, zgodność z `standalone/src/Tools/ExpertKnowledge.php`.
- **NIE czyta bloga ani CMS** — JEDYNE źródło wiedzy eksperckiej czatu to `encyclopedia_chunks`. Aktualność encyklopedii = aktualność wiedzy czatu.

---

## 6. Szybki diagnostyczny przepływ

Wątpliwości "czy czat ma świeżą wiedzę o haśle X"?
```bash
# 1. Czy pliki i baza się zgadzają?
python scripts/embed_encyclopedia.py --mode check

# 2. Jeśli "AKTUALNE" — czat ma świeżą wiedzę. Koniec.

# 3. Jeśli "ZMIENIONE: X" — embeduj:
python scripts/embed_encyclopedia.py --mode changed

# 4. Re-check:
python scripts/embed_encyclopedia.py --mode check
```
