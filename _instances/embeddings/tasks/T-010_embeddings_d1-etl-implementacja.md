# T-010: D1 ETL implementacja — pr_category + alias table + override

**Instancja:** embeddings
**Powiązany:** ADR-057 (decyzje design), ADR-055 (D2-hybrid deprecated), T-009 (audyt)
**Priorytet:** P1
**Czas estymowany:** ~5-6h CC

## Cel

Implementacja D1 ETL zastępującego D2-hybrid (`sql/010_pseudocategory_mapping.sql`). Skrypt Python czyta hierarchię `pr_category` z MySQL PrestaShop, dla każdego produktu generuje `parent_category_name` wg Strategii B (level=2) z lookup w tabeli aliasów PG (`divechat_category_aliases`) oraz hardcoded override edge cases.

Pełna specyfikacja decyzji: ADR-057.

## KROK 0. Read

- `_docs/10_decyzje_projektowe.md` sekcja ADR-057 (źródło prawdy dla decyzji design)
- `_docs/audyt_D1_ETL_pr_category.md` (kontekst rozjazdów + TOP 20)
- `embeddings/audit_d1_pr_category.py` (wzorzec połączeń MySQL+PG z T-009)
- `sql/010_pseudocategory_mapping.sql` (jakich pseudokategorii używamy obecnie — input dla seed aliasów)
- `sql/006_parent_category.sql` (struktura kolumny `parent_category_name` w `divechat_product_embeddings`)

## KROK 1. Migracja 012 — tabela aliasów + seed

Plik: `sql/012_category_aliases.sql` + rollback `sql/012_category_aliases_rollback.sql`.

```sql
CREATE TABLE IF NOT EXISTS divechat_category_aliases (
    id SERIAL PRIMARY KEY,
    ps_name_normalized TEXT UNIQUE NOT NULL,
    ps_name_original TEXT NOT NULL,
    model_facing_name TEXT NOT NULL,
    note TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_category_aliases_normalized
    ON divechat_category_aliases(ps_name_normalized);
```

`ps_name_normalized` powstaje przez `lower(unaccent(ps_name))` — wymaga rozszerzenia `unaccent` (sprawdź czy jest na Railway: `CREATE EXTENSION IF NOT EXISTS unaccent;` na początku migracji).

### Seed aliasów (14 wpisów z TOP rozjazdów audytu B):

| ps_name_original | model_facing_name | note |
|---|---|---|
| Skrzydła i jackety | Wypornościowe | T-009 TOP1 rozjazd, 289 produktów |
| Maski i Fajki | Maski i fajki | case-only diff, 205 produktów |
| Latarki nurkowe | Oświetlenie | 147 produktów |
| Butle nurkowe | Butle | 76 produktów |
| Węże | Automaty Oddechowe | 68 produktów (węże = część automatów) |
| Instrumenty pomiarowe | Komputery Nurkowe | 63 produktów |
| Bojki i kołowrotki | Bezpieczeństwo | 59 produktów |
| Noże | Bezpieczeństwo | 47 produktów |
| Akcesoria | Akcesoria Nurkowe | 84 produktów (level=2 oficjalna nazwa PS) |
| Skafandry Na ZIMNE wody | Skafandry mokre | 56 produktów |
| Skafandry Na CIEPŁE wody | Skafandry mokre | 47 produktów |
| Płetwy Gumowe JET | Płetwy | 39 produktów |
| Płetwy Paskowe na Buta | Płetwy | 38 produktów |
| Side Mount | Wypornościowe | 34 produktów |

Wstaw przez `INSERT ... ON CONFLICT (ps_name_normalized) DO UPDATE` (idempotentne).

Apply migracja na Railway, weryfikacja struktury + COUNT rows == 14.

## KROK 2. Skrypt ETL

Plik: `embeddings/etl_d1_parent_category.py`. Type hints PEP 8. Idempotentny.

### Architektura

```
1. Połącz MySQL + PG (wzorzec z audit_d1_pr_category.py)
2. Wczytaj alias table do dict: normalized_ps_name -> model_facing_name
3. Wczytaj drzewo pr_category (level_depth, id_parent, name)
4. Dla każdego produktu w divechat_product_embeddings:
   a. Pobierz list[id_category] z MySQL (pr_category_product, filter active=1)
   b. Wybierz "kandydata na parent" wg Strategii B (pierwszy cat z level=2)
   c. Sprawdź czy zachodzi edge case override (level=3 w cat z whitelist override) -> użyj level=3 name
   d. Aplikuj alias lookup (normalized name -> model_facing_name); jeśli brak aliasu, użyj surowej PS name
   e. Sprawdź czy nazwa jest na blacklist intencjonalnych NULL -> ustaw NULL
   f. UPDATE divechat_product_embeddings SET parent_category_name = ? WHERE product_id = ?
5. Loguj statystyki: ile produktów updated, ile zostało NULL, ile użyło override, ile użyło aliasów
```

### Hardcoded override edge cases (wg ADR-057 pkt 2):

```python
EDGE_CASE_OVERRIDES = {
    # Gdy Strategia B wskazuje na ten parent + produkt jest na level=3 w jednym z child cats,
    # użyj level=3 name zamiast parent.
    'Skafandry mokre': {'Kaptury', 'Rękawice', 'Buty'},
    'Skafandry suche': {'Ocieplacze do Suchych'},
    # dodawane gdy testy pokażą potrzebę
}
```

### Blacklist intencjonalnych NULL (wg ADR-057 pkt 3):

```python
INTENTIONAL_NULL_CATEGORIES = {
    'vouchery prezentowe', 'voucher',
    'do 100 pln', 'od 100 do 500 pln', 'od 500 do 1000 pln', 'powyżej 1000 pln',
    'prezenty', 'prezent',
    'wyprzedaże', 'wyprzedaż', 'outlet',
    'morsowanie',
    'dla dzieci i juniorów',
    # normalized lower() - matchowanie po lower(name)
}
```

Jeśli kandydat na parent (po aliasie) jest na tej liście → SET NULL.

### Whitelist NULL→coś wpuszczanych (informacyjnie, dla logów):

Produkty obecnie NULL w divechat_product_embeddings które po ETL dostaną parent — log per grupa: ile produktów + przykład. Oczekiwane ~270 (ADR-057 pkt 3).

### Idempotentność

Re-run skryptu na tych samych danych daje identyczny wynik. UPDATE warunkowy: tylko gdy `parent_category_name IS DISTINCT FROM new_value` (PostgreSQL idiom). Liczba updated rows w secondryun powinna być 0.

## KROK 3. Smoke test skryptu (lokalnie, na PG Railway)

```bash
cd /Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026
python3 embeddings/etl_d1_parent_category.py --dry-run    # opcjonalny flag, tylko SELECT count diffs
python3 embeddings/etl_d1_parent_category.py              # full ETL update
# Re-run - sprawdź idempotentność
python3 embeddings/etl_d1_parent_category.py              # 0 updates expected
```

Oczekiwane statystyki po pierwszym run:

- Total products: 2561
- Updated parent_category_name: ~2470 (z czego ~270 NULL→coś, reszta D2 → D1+alias)
- Final NULL: ~90 (intencjonalne — vouchery, price buckets, segmenty)
- Alias hits: 14 (każdy alias seed użyty co najmniej raz oczekiwane, sprawdź TOP 5 najczęściej używanych)
- Override hits: 60-100 (Kaptury+Rękawice+Buty+Ocieplacze; konkretną liczbę poda log)

## KROK 4. Walidacja przez query SQL na PG

Po ETL:

```sql
SELECT
    COUNT(*) AS total,
    COUNT(parent_category_name) AS with_parent,
    COUNT(*) - COUNT(parent_category_name) AS null_count
FROM divechat_product_embeddings;

SELECT parent_category_name, COUNT(*)
FROM divechat_product_embeddings
WHERE parent_category_name IS NOT NULL
GROUP BY parent_category_name
ORDER BY COUNT(*) DESC LIMIT 30;
```

Oczekiwany top 30 powinien zawierać wszystkie pseudokategorie z NAZEWNICTWO SKLEPU SystemPrompt + nowo wpuszczone (Książki nurkowe, Buty, Kaptury, Rękawice, Odzież Termoaktywna).

## KROK 5. STOP point — review przez Karol

Status: "READY FOR REVIEW v1". Wklej:

- Statystyki z KROK 3 (updated/NULL/alias hits/override hits)
- TOP 30 z KROK 4 (lista parent_category_name + COUNT)
- Diff vs stan przed ETL: ile produktów dostało INNY parent niż przed (osobny SELECT przed run vs po — porównanie)
- Pełny raport `_instances/embeddings/handoff/T-010_v1_stats.md` (lokalnie, gitignored)

NIE commit do gita ani nie cofaj D2-hybrid przed akceptacją Karola.

## KROK 6. Deploy

T-010 jest read-write na bazie PG (UPDATE divechat_product_embeddings). Nie ma kodu PHP do scp.

Po akceptacji Karola w KROK 5:

- Apply migracja 012 na Railway (jeśli nie zaaplikowana w KROK 1)
- Re-run ETL na świeżej bazie (idempotentność)
- Sprawdź `divechat_product_embeddings` zawiera nowe wartości

Brak rollback poza migracją 012 — gdyby trzeba cofnąć, uruchom `sql/010_pseudocategory_mapping.sql` ponownie żeby wrócić do D2-hybrid wartości. ETL nie zmienia struktury embeddingów ani innych kolumn — bezpieczny.

## KROK 7. Git workflow

```bash
git status
git add sql/012_category_aliases.sql sql/012_category_aliases_rollback.sql
git add embeddings/etl_d1_parent_category.py
git commit -m "T-010: D1 ETL implementacja — Strategia B + alias table + override

Skrypt Python czyta pr_category z MySQL, dla każdego produktu wybiera
parent na level=2 (Strategia B z audytu T-009), aplikuje lookup w
divechat_category_aliases (PS name -> NAZEWNICTWO SKLEPU), edge case
override dla Kaptury/Rękawice/Buty/Ocieplacze, blacklist intencjonalnych
NULL (price buckets, vouchery, segmenty).

Migracja 012 tworzy tabelę aliasów + seed 14 wpisów z TOP rozjazdów
audytu T-009. ETL idempotentny.

D2-hybrid (sql/010_pseudocategory_mapping.sql) deprecated — zostaje
w repo jako rollback.

Powiązany ADR: ADR-057"
git push origin main
```

## KROK 8. Raport + status update

### Utworz `_instances/embeddings/handoff/T-010_done.md`:

- Migracja 012 apply + verify count == 14 aliasów
- Statystyki ETL final (per ADR-057 oczekiwanych liczb)
- TOP 30 parent_category_name po ETL
- Lista override hits (ile + przykłady per kategoria)
- Komentarz o idempotentności (run #2 = 0 updates)
- Git commit hash

### Update `_docs/21_STATUS_PROJEKTU.md`:

- "Co działa na produkcji" → T-010 DEPLOYED (D1 ETL + alias table)
- "Aktywne instancje CC" → embeddings T-010 DONE
- "Kolejka tasków" → usunąć T-010, dodać do backlogu: subcategory_name (z insight Strategii A), multi-value parents (gdy będzie use case)
- Sekcja "Co działa" → dodać że alias table jest edytowalna online (URL/curl jeśli istnieje API; w razie braku — manual SQL UPDATE na Railway)

### Osobny commit "docs:"

```bash
git add _docs/21_STATUS_PROJEKTU.md _docs/10_decyzje_projektowe.md
git commit -m "docs: T-010 DEPLOYED — D1 ETL + ADR-057"
git push origin main
```

## Out of scope

- API/UI do edycji aliasów (na razie SQL/curl manual)
- Subcategory_name jako osobne pole (backlog z T-009)
- Multi-value parent_categories TEXT[] (przyszłość gdy use case)
- Restrukturyzacja drzewa pr_category w PS admin
- Re-embedding produktów (ETL zmienia tylko parent_category_name, embeddingi zostają)
- Audyt SystemPrompt NAZEWNICTWO SKLEPU vs faktyczne wartości po ETL (osobny task gdy potrzeba)
