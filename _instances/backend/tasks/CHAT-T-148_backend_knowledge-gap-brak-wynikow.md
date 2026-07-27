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

**GRANICA — czego migracja NIE RUSZA (krytyczne, ZAKTUALIZOWANE decyzją 130b):**

Dwa zbiory zostają **nietknięte**, oba z tego samego powodu: **nie da się odtworzyć, czy była tam luka**, a zerowanie na ślepo = fabrykacja danych.

**1. Brak diagnostyki (94 rozmowy):**
```sql
search_diagnostics IS NOT NULL AND jsonb_array_length(search_diagnostics) > 0
```

**2. Diagnostyka NIEPEŁNA (86 rozmów) — ADR-126 nota nr 1, decyzja 130b:**
```sql
jsonb_array_length(search_diagnostics) = (
  SELECT count(*) FROM jsonb_array_elements(messages) m,
         jsonb_array_elements(COALESCE(m->'tool_calls','[]'::jsonb)) tc
  WHERE tc->>'name' IN ('search_products','get_expert_knowledge'))
```

**Dlaczego (przeczytaj, zanim uznasz to za nadmiarowe):** `search_diagnostics` jest **nadpisywany przy każdej turze** — trzyma migawkę ostatniej, nie całą historię. `knowledge_gap` jest **sticky**. Rozmowa, gdzie tura 1 miała `search_products` bez wyników (flaga `true`), a tura 2 tylko encyklopedię z trafieniem, ma dziś w diagnostyce **wyłącznie turę 2**. Przeliczenie z niej **gubi realny sygnał luki**.

Zmierzone na produkcji: **191 rozmów** ma diagnostykę == pełna historia, **86 rozmów** ma uboższą (łącznie **200 wywołań bez śladu**). Bez tego warunku migracja zgasiłaby **80 flag na niepewnych danych**.

**Struktura `messages`** (zweryfikowana na produkcji — NIE jest to format Anthropic `content[].type='tool_use'`):
```json
[ { "role": "user", "content": "Co lepsze: Suunto Ocean czy Garmin Descent?" },
  { "role": "assistant", "content": null,
    "tool_calls": [ { "id": "call_0Hhp…", "name": "get_expert_knowledge", "arguments": {...} } ] },
  { "role": "tool_result", "name": "get_expert_knowledge", "content": "{\"knowledge\":[…]}" } ]
```
Wywołania liczymy z `messages[].tool_calls[].name`. `COALESCE(m->'tool_calls','[]'::jsonb)` jest konieczny — wiadomości `user`/`tool_result` nie mają tego klucza.

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

**Rollback:** przywraca `knowledge_gap = true` **wyłącznie dla zbioru objętego migracją** (scope 191). Po zawężeniu 130b jest to **dokładne**: wszystkie 143 zmienione rozmowy były `true`, a `false → true` = 0, więc rollback nie over-restore'uje niczego. W nagłówku pliku zapisz, że autorytatywnym rollbackiem pozostaje `pg_dump` z KROKU 4.1.

**Oczekiwany wynik migracji (ZMIERZONY na produkcji 2026-07-16 — użyj jako asercji):**

| metryka | wartość |
|---|---|
| scope migracji (pełna diagnostyka) | **191** |
| z tego `true → false` | **143** |
| z tego `false → true` | **0** |
| NIETKNIĘTE: niepełna diagnostyka | **86** |
| NIETKNIĘTE: brak diagnostyki | **94** |
| panel globalnie `true`: przed → po | **339 → 196** |
| kohorta `search_products`: `true` po | **94** |

**Liczby „15 / 237 / 116" z wcześniejszej wersji tasku i z ADR są NIEAKTUALNE** — nie uwzględniały reguły OR ani zawężenia 130b. Obowiązuje tabela powyżej.

Jeśli którakolwiek asercja się nie zgadza → **STOP, zaraportuj, nie wykonuj migracji.**

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

1. Liczby po migracji muszą zgadzać się z tabelą asercji z KROKU 2: globalnie **196** rozmów `true` (było 339), scope 191 → **143** zgaszone, **86 + 94 = 180** nietkniętych.
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

---

## Wynik

**Status: DONE** (2026-07-17, instancja backend). Decyzje wykonane: 128b (reguła), 129b (migracja SQL), 130b (zawężenie do pełnej diagnostyki), 132a (rollback whole-scope + `pg_dump` autorytatywny).

### Kod
`standalone/src/Chat/ChatService.php` — `buildSearchDiagnostic()` rozdziela regułę per narzędzie: `search_products` → `$gap = empty($items)` (bez progu, ADR-126); `get_expert_knowledge` → bez zmian (`empty || maxSim < $threshold`). `$threshold`, `knowledge_gap_threshold`, `max_similarity`/`min_similarity` nietknięte.

### Deploy (chat.divezone.pl)
- `pg_dump` przed migracją: `_backups/divechat_conversations_pre042_20260717_085336.sql` — **11 MB, 655 wierszy**. Autorytatywny rollback (decyzja 132a).
- `_deploy_bak/CHAT-T-148_20260717_085424/src/Chat/ChatService.php` (md5 stanu prod: `715d48cea1050850c144db06caee4bd8`).
- **Wdrożono TYLKO `src/Chat/ChatService.php`**, NIE blanket-rsync `standalone/`. Powód: dry-run pokazał 146 plików do wypchnięcia, w tym `config/tools.php` (dryf repo≠prod, potwierdzony `diff` → gwarantowany fatal 500, CLAUDE.md) i `config/routes.php` (niezacommitowana zmiana innej sesji, na liście NIE RUSZAJ). Zakres zmiany taska = 1 plik.
- md5 local ↔ prod: **`6ad5558fc19c992fbed8bc48251b8b39`** (zgodne).
- `ea-php84 -l`: *No syntax errors detected*.
- Smoke `/api/health`: **HTTP 200**, `{"status":"ok","php":"8.4.23","postgres":true,"mysql":true}`.
- Migracja `sql/042` na Railway (`psql -f -`): **`UPDATE 191`, COMMIT**.

### Weryfikacja (dowody)

Wszystkie 7 asercji decyzji 130b zgodne. Panel globalnie **339 → 196**.

| klasa | rozmów | `true` po | status |
|---|---|---|---|
| pełna diagnostyka (migrowane) | 191 | 17 (przed: 160) → **143 zgaszone** | zmienione |
| niepełna diagnostyka (migawka) | 86 | 85 | **NIETKNIĘTE** |
| brak diagnostyki | 378 | **94** | **NIETKNIĘTE** |
| **globalnie** | 655 | **196** | 339 → 196 |

Kohorta `search_products`: 237 → **94 `true`** (14 pełna diagnostyka + 80 nietkniętych niepełnych). Nietknięte łącznie: 86 + 94 = **180**.

**Rozmowa testowa (PROD, realny czat HMAC):** „Shearwater Perdix 3" → `search_products` rc=5, **maxSim = 0,123**, flaga **`false`** ✅. To dokładnie zmierzony sufit RRF — pod starą regułą 0,123 < 0,5 dawało `true`. Test przed naprawą **niemożliwy**.

**Gałąź `rc=0 → true`:** nie udało się wywołać promptem (wyszukiwarka wektorowa zwraca top-N nawet na bzdurę — `Blorptronix 9000` dał rc=5, maxSim 0,046, flaga `false`; to udokumentowane ograniczenie ADR-126 „znalazł, ale bzdurę"). Historyczne zera pochodzą z wieloturowego kontekstu z filtrami. Dowiedzione **deterministycznie na wdrożonym pliku prod** (refleksja, plik tymczasowy usunięty) — 5/5 PASS:

| przypadek | gap | oczek. |
|---|---|---|
| `search_products`, 0 wyników | `true` | `true` ✅ |
| `search_products`, rrf 0,046–0,123 (<0,5) | `false` | `false` ✅ |
| `get_expert_knowledge`, cosine 0,46 (<0,5) | `true` | `true` ✅ |
| `get_expert_knowledge`, cosine 0,72 | `false` | `false` ✅ |
| `get_expert_knowledge`, 0 wyników | `true` | `true` ✅ |

**Reguła E:** 5 rozmów testowych oznaczone w `divechat_conversation_review` markerem `[test CHAT-T-148, nie klient]`, `verdict=NULL`, `updated_by=NULL`.

**Panel PS:** bez zmian w module (zgodnie z taskiem). Filtr „Luki wiedzy" zawęża teraz do 196 z 655 zamiast 339 — klik-test UI po stronie Karola (wymaga sesji admina PS).

### Uwagi
- Rollback `sql/042_..._rollback.sql` over-restore'uje 31 rozmów encyklopedycznych (udokumentowane w nagłówku, decyzja 132a) — autorytatywny jest `pg_dump` powyżej.
- **Dług (poza taskiem, ADR-126 nota nr 1):** `search_diagnostics` jako migawka ostatniej tury = 200 wywołań bez śladu w 86 rozmowach. Jeśli diagnostyka ma służyć recenzji, powinna akumulować tury.
