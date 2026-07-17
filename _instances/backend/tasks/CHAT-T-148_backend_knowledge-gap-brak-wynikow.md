# CHAT-T-148 — `knowledge_gap` dla `search_products`: luka = brak wyników

**Instancja:** backend
**ADR:** ADR-126 (decyzje Karola 128b, 129b)
**Świat wdrożenia:** BACKEND standalone (`chat.divezone.pl`) + migracja Railway PG.
**Moduł PS: BEZ ZMIAN.** Front już umie — nie dotykać `newtmp2`.

---

## Problem

Filtr „Luki wiedzy" w panelu recenzji PS **nie filtruje niczego**: 237 rozmów z `search_products` na produkcji, **237 z flagą `knowledge_gap = true`**, zero z `false`. Badge świeci przy każdej rozmowie, więc nic nie znaczy.

**Przyczyna** — `ChatService::buildSearchDiagnostic()` (`standalone/src/Chat/ChatService.php:432-447`) stosuje **jeden próg 0,5 do dwóch narzędzi o różnych skalach**:

| narzędzie | co realnie jest w `similarity` | skala | próg 0,5 |
|---|---|---|---|
| `get_expert_knowledge` | prawdziwy cosine (`ExpertKnowledge.php:103,128`) | 0–1 | **działa poprawnie** |
| `search_products` | **`rrf_score`** (`ProductSearch.php:798`) | zmierzony max **0,1230** | **nieosiągalny** |

**Dowód (Railway, 2026-07-16, 1605 pozycji ze wszystkich wywołań `search_products`):** max `0,122951`, min `0,028629`, **pozycji ≥ 0,5: ZERO**.

Do tego flaga jest **sticky** (`ConversationStore.php:189`): `knowledge_gap = (? ::boolean OR COALESCE(knowledge_gap, false))` — raz zapalona nie gaśnie. **To zostaje bez zmian**, przy nowej regule jest pożądane.

---

## KROK 0 — czytaj przed pierwszą zmianą

1. `git pull --rebase origin main`
2. Przeczytaj **ADR-126** w `_docs/10_decyzje_projektowe.md` (na końcu pliku).
3. Przeczytaj `_docs/44_slownik_pol_i_metryk.md`: **sekcja 5.1** (RRF: ranga, nie cosine + pomiar produkcyjny) i **sekcja PUŁAPKI** na końcu.
4. Przeczytaj `standalone/src/Chat/ChatService.php:425-470` (cała `buildSearchDiagnostic`).

**Kluczowe do zrozumienia:** `rrf_score` **nie mierzy jakości dopasowania**, tylko na ilu torach produkt wyszedł wysoko. Dlatego żaden próg na tej skali nie ma sensu — to nie jest „źle dobrana liczba", tylko zła jednostka.

---

## KROK 1 — zmiana kodu (backend)

Plik: `standalone/src/Chat/ChatService.php`, metoda `buildSearchDiagnostic()`.

**Reguła docelowa:**
- `search_products` → `$gap = empty($items);` — **bez progu**
- `get_expert_knowledge` → **BEZ ZMIAN**: `$gap = empty($items) || ($maxSim !== null && $maxSim < $threshold);`

**Wymagania:**
- `max_similarity` / `min_similarity` w diagnostyce **zostają** — są przydatne w recenzji, tylko nie sterują flagą dla produktów.
- **Nie usuwaj** parametru `$threshold` ani wpisu `knowledge_gap_threshold` z `divechat_settings` — nadal używa go `get_expert_knowledge`.
- W komentarzu nad warunkiem: jedno zdanie, dlaczego dwie ścieżki (różne skale) + odsyłacz `ADR-126`.
- PSR-12. PHP 8.4 (`ea-php84`).

## KROK 2 — migracja SQL (decyzja 129b)

Utwórz **dwa** pliki (konwencja `sql/`, ostatnia migracja to 041):
- `sql/042_fix_knowledge_gap_search_products.sql`
- `sql/042_fix_knowledge_gap_search_products_rollback.sql`

**Reguła przeliczenia:** rozmowa ma `knowledge_gap = true` wtedy i tylko wtedy, gdy:
- **istnieje** wywołanie `get_expert_knowledge` z `knowledge_gap = true` w `search_diagnostics` (stara reguła nadal obowiązuje dla encyklopedii), **LUB**
- **istnieje** wywołanie `search_products` z `result_count = 0` (nowa reguła 128b).

**GRANICA — czego migracja NIE RUSZA (krytyczne):**
```
WHERE search_diagnostics IS NOT NULL
  AND jsonb_array_length(search_diagnostics) > 0
```
**94 rozmowy** mają `knowledge_gap = true` i **nie mają `search_diagnostics`** (sprzed wprowadzenia diagnostyki). Nie da się odtworzyć, czy była tam luka. **Zostają nietknięte.** Wyzerowanie na ślepo = fabrykacja danych (zasada projektu: zero fabrykacji).

**Struktura `search_diagnostics`** (jsonb, tablica wywołań) — zweryfikowana na produkcji:
```json
[ { "tool": "search_products",
    "query_text": "Aqualung Pro HD Compact",
    "result_count": 5,
    "max_similarity": 0.116,
    "knowledge_gap": true,
    "search_debug": { "items": [ { "rrf_score": 0.116218, "product_id": 6737, ... } ] } } ]
```
Rozbijaj przez `jsonb_array_elements(search_diagnostics)`. **Nie ruszaj** `search_debug` — do reguły wystarczy `tool` + `result_count`.

**Rollback:** przywraca `knowledge_gap = true` dla wszystkich rozmów objętych migracją (stan przed = wszystkie true). Zapisz w nagłówku pliku, że to stan zastany, nie „poprawny".

**Oczekiwany wynik migracji (zmierzony, użyj jako asercji):**
- rozmów z `search_products`: **237**
- z nich z `result_count = 0` w którymkolwiek wywołaniu: **15**
- rozmów `true` bez `search_diagnostics` (nietkniętych): **94**

## KROK 3 — STOP. Nie wykonuj migracji.

Zaraportuj: treść obu plików SQL + wynik `SELECT` **symulującego** migrację (ile rozmów zmieni flagę, ile zostanie `true`, ile nietkniętych). **Czekaj na `deployuj`** (ADR-089).

## KROK 4 — deploy backendu (dopiero po autoryzacji)

1. `pg_dump` **tabeli** `divechat_conversations` → `_backups/`. Ścieżka + rozmiar w raporcie.
2. Backup `_deploy_bak/`.
3. `rsync` `standalone/` → `chat.divezone.pl/` (port 5739, `--exclude config_pl.xml`, **bez** `--delete`). **Na serwerze NIE MA prefiksu `standalone/`.**
4. `md5` local ↔ prod dla `src/Chat/ChatService.php`.
5. `ea-php84 -l` na wdrożonym pliku.
6. Smoke `/api/health`.
7. Migracja `042` na Railway (heredoc `psql -f -`, nie wiele `-c`).

## KROK 5 — weryfikacja (dowody, nie deklaracje)

1. `SELECT count(*) FILTER (WHERE knowledge_gap) ...` — oczekiwane: **15** z 237 rozmów z `search_products`, **94** nietknięte bez diagnostyki.
2. Nowa rozmoma testowa z trafnym zapytaniem produktowym (np. „Shearwater Perdix 3") → flaga **`false`**. To jest test, który przed naprawą był niemożliwy.
3. Rozmowa z zapytaniem bez wyników → flaga **`true`**.
4. Panel PS: checkbox „Luki wiedzy" zawęża listę. **Bez zmian w module** — tylko sprawdź, że działa.

## KROK 6 — git

`git pull --rebase origin main` → `git status` (sprawdź, czy w indeksie nie ma cudzych zmian — okna CC pracują równolegle) → `git add` **per ścieżka**, NIGDY `git add .`:
```
standalone/src/Chat/ChatService.php
sql/042_fix_knowledge_gap_search_products.sql
sql/042_fix_knowledge_gap_search_products_rollback.sql
_instances/backend/tasks/CHAT-T-148_backend_knowledge-gap-brak-wynikow.md
```
Commit wg konwencji (sprawdź `git log`): `CHAT-T-148 backend: knowledge_gap = brak wynikow dla search_products (ADR-126)`
`git push origin main`

## KROK 7 — status + raport

`_docs/21_STATUS_PROJEKTU.md` — dopisz **NA GÓRZE**, nie nadpisuj. Osobny commit `docs:`.

Raport: ścieżka dumpa, md5 local↔prod, wynik `php -l`, smoke, liczby z weryfikacji (przed/po), wynik rozmowy testowej.

---

## NIE RUSZAJ

- **`modules/` (świat PS, `newtmp2`)** — front już umie, UI kompletne i wdrożone (md5 prod == repo `472701c6…`). Ten task **nie ma części PS**.
- `knowledge_gap_threshold` w `divechat_settings` — nadal używa go `get_expert_knowledge`.
- Sticky OR w `ConversationStore.php:189` — zamierzone.
- `search_debug`, `max_similarity`, `min_similarity` — zostają.
- ADR-y (pisze architekt), `CLAUDE.md`, `_docs/44`, karty Trello.
- Niezacommitowane zmiany innych sesji: `_ops/newtmp2_root/purge_litespeed.php` (**zawiera sekret**), `standalone/config/routes.php`.
