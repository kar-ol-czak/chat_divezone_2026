# T-001: Diagnoza regresji logbook → wet notes (embeddings)

**Instancja:** embeddings
**Powiązany commit:** 6171157 (TASK-CHAT-010, DONE)
**Powiązane:** _docs/synonyms/diving_synonyms_curated.json, divechat_synonyms (PG), embeddings/update_synonyms_logbook_voucher.py

## Problem

Karol smoke test produkcyjny na chat.divezone.pl po deploy 6171157:
- "Macie logbook?" → ZWRACA WET NOTES (regresja)
- "Macie mokry notes?" → wet notes (OK)
- "Macie vouchery prezentowe?" → działa poprawnie (OK)

Embeddings smoke test (3 tory semantic) dał 6/8 logbooków bez wet notes. Prod pipeline (5 torów: semantic + FTS + trigram + SynonymExpander PHP) krzyżuje wyniki.

Cel: zdiagnozować root cause i zaproponować fix.

## KROK 0. Sanity check bazy wektorowej

Karol pyta wprost: czy produkty które bot ma polecać są w bazie wektorowej?

```sql
-- MySQL prod
SELECT count(*) FROM pr_product WHERE active = 1;

-- PG
SELECT count(*) FROM divechat_product_embeddings;
```

Zapisz oba w handoff. Diff > 5% sygnalizuje systemowy problem (osobny temat, zaznacz w raporcie).

## KROK 1. Diagnoza search_phrases dla wet notes

Czy któryś wet notes ma "logbook" w search_phrases (AI crossover w generatorze)?

```sql
SELECT id, product_data->>'name' AS name, 
       product_data->>'search_phrases' AS phrases
FROM divechat_product_embeddings 
WHERE id IN (1868, 5260, 6241, 6263);
```

Jeśli któryś zawiera "logbook" / "dziennik" / "książeczka" → root cause. Fix: re-generate search_phrases dla wet notes bez tych terminów.

## KROK 2. Diagnoza divechat_synonyms

Czy grupa wet notes zawiera "logbook" jako alias?

```sql
SELECT * FROM divechat_synonyms 
WHERE term ILIKE '%logbook%' 
   OR term ILIKE '%wet%' 
   OR term ILIKE '%mokry notes%'
   OR canonical_term ILIKE '%logbook%'
   OR canonical_term ILIKE '%mokry%';
```

Jeśli "logbook" jest w grupie wet notes (lub odwrotnie) → bug w update_synonyms_logbook_voucher.py. Fix: rozdzielenie grup, re-load.

## KROK 3. Diagnoza cache PHP

Cache po stronie produkcyjnego PHP może serwować starsze synonimy.

```bash
ssh -p 5739 divezone@divezonededyk.smarthost.pl
# sprawdź czy opcache aktywne
php -r 'var_dump(opcache_get_status());'
# reset opcache
php -r 'opcache_reset();'
# lub restart php-fpm jeśli dostępne
```

## KROK 4. Diagnoza FTS index

Czy tsvector dla wet notes zawiera "logbook"?

```sql
SELECT id, 
       product_data->>'name' AS name,
       to_tsvector('polish', 
         coalesce(product_data->>'name','') || ' ' || 
         coalesce(product_data->>'search_phrases','')
       ) AS tsv
FROM divechat_product_embeddings 
WHERE id IN (1868, 5260, 6241, 6263);
```

Sprawdź czy w tsv dla wet notes pojawia się lexem 'logbook'. Jeśli tak → idzie razem z search_phrases (KROK 1).

## KROK 5. Diagnoza wywołania produkcyjnego z debug

Włącz tymczasowo search_debug w prod (lub odpal lokalnie z analogiczną konfiguracją):
- Query: "logbook nurkowy"
- Zaloguj per tor RRF: semantic score, FTS score, trigram score
- Zaloguj wynik SynonymExpander dla "logbook" (jakie terminy zostały rozszerzone)
- Zaloguj final ranking przed stripping i top 8 wyników

To pokazuje który tor RRF "wygrywa" dla zapytania "logbook" i odpowiada za wyświetlenie wet notes na czele.

## KROK 6. STOP point — diagnoza

Raport w `_instances/embeddings/handoff/T-001_diagnoza.md`:
- Sanity check (KROK 0): pr_product count vs embeddings count + diff
- Wyniki KROK 1-5
- Wskazany root cause
- Rekomendowany fix (najpewniej jedna z: re-generate search_phrases / fix synonyms / opcache reset / FTS reindex)

Status: "DIAGNOZA DONE — propozycja fix"
Karol akceptuje lub modyfikuje.

## KROK 7. Implementacja fixa

Zależnie od root cause:
- **search_phrases bug:** re-generate dla 4 wet notes (1868, 5260, 6241, 6263) bez "logbook" / "dziennik" / "książeczka", re-embed te 4 produkty
- **synonyms bug:** fix w divechat_synonyms (rozdziel grupy), update load_synonyms.py, re-run loader
- **opcache:** reset + dodać `opcache_reset()` do procedury deploy (handoff)
- **FTS:** zazwyczaj fixowane przez KROK 1 (jeśli search_phrases zawierał logbook, tsv był stale)

## KROK 8. Smoke test po fix

Karol weryfikuje przez UI chat.divezone.pl:
- "Macie logbook?" → top wyniki to logbooki (id 3574, 5261, 5262, 5263, 6645, 6646, 6805), NIE wet notes
- "Macie mokry notes?" → top wyniki to wet notes (id 1868, 5260, 6241, 6263), NIE logbooki
- "Macie wet notes?" → wet notes
- Regresja: voucher prezentowy 500 zł → nadal #1 (id 4652)

## KROK 9. Git + push

```bash
git status
# add konkretne ścieżki zależnie od fixa:
git add data/synonyms/diving_synonyms_curated.json  # jeśli synonyms fix
git add embeddings/update_synonyms_logbook_voucher.py  # jeśli logika
# lub inne ścieżki jeśli inny scope

git commit -m "T-001: regression fix logbook → wet notes po 6171157

- Root cause: <konkretny>
- Fix: <co zrobione>
- Re-embed: <jeśli było, ile produktów>
- Smoke test prod: 4/4 OK"

git push origin main
```

## KROK 10. Status update

- _instances/embeddings/handoff/T-001_done.md (deploy info, hash commit, root cause, fix summary, smoke test)
- _docs/21_STATUS_PROJEKTU.md → T-001 DEPLOYED + komentarz że TASK-CHAT-010 jest zamknięty po tym fix
- git add _docs/21_STATUS_PROJEKTU.md
- git commit -m "docs: T-001 deployed, TASK-CHAT-010 zamknięty"
- git push

## Out of scope

- Audyt EXCLUDED_CATEGORY_IDS → TASK-CHAT-014 (osobny task)
- Sanity check szerszy niż count (np. które konkretne produkty brakują) → wniosek z KROK 0 może uruchomić osobny task
- Zmiany w RRF weights / ranking logic
