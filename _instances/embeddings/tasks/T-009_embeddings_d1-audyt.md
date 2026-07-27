# T-009: D1 AUDIT — diagnostyka mapowania kategorii z pr_category vs D2-hybrid

**Instancja:** embeddings
**Powiązany:** ADR-055 (D2-hybrid hotfix), TASK-CHAT-015 (legacy nazwa pełnego D1 ETL, przeniesione na T-009 AUDIT + T-010 ETL osobno)
**Priorytet:** P2 (diagnostyczny, bez zmian w prod)
**Czas estymowany:** ~2.5h CC

## Cel

Audyt diagnostyczny porównujący obecny mapping `parent_category_name` w `divechat_product_embeddings` (po T-002 D2-hybrid hotfix) z hierarchią `pr_category` w MySQL PrestaShop. Wynik: raport markdown z rekomendacjami dla design D1 ETL (osobny task T-010 po decyzji architekta na bazie audytu).

**Bez zmian w produkcji.** Czysty read-only audyt + generowanie raportu.

## Kontekst

ADR-055 wprowadził D2-hybrid (hardcoded SQL UPDATE pseudokategorii w `sql/010_pseudocategory_mapping.sql`) jako hotfix. Trwałe rozwiązanie (D1) to ETL z `pr_category` hierarchii. Przed implementacją D1 musimy wiedzieć:

- Ile produktów obecny D2 mapping pokrywa (po T-002: 2193/2561 = 86%, reszta NULL)
- Ile produktów hierarchia PrestaShop pokryłaby gdyby zastosować ETL
- Gdzie są ROZJAZDY (kategoria w D2 ≠ "naturalny parent" wg drzewa PS)
- Które poziomy hierarchii brać (level_depth=2? pierwszy non-root? najbardziej szczegółowy?)
- Jak obsłużyć produkty wielokategoryjne (jeden produkt w wielu kategoriach PS)

## KROK 0. Read

- `_docs/10_decyzje_projektowe.md` sekcje ADR-027 + ADR-055 (parent_category_name fallback + D2-hybrid)
- `sql/010_pseudocategory_mapping.sql` (lista D2 hardcoded mappingu — 14 pseudokategorii zbiorczych)
- `embeddings/` katalog — wzorzec istniejących skryptów Python (connection do MySQL i PG, parsowanie .env)

## KROK 1. Skrypt audytu

Plik: `embeddings/audit_d1_pr_category.py`. Type hints PEP 8.

### Połączenia:
- MySQL PrestaShop: query do `pr_category`, `pr_category_lang` (id_lang=1 PL), `pr_category_product`, `pr_product_shop` (filter active=1). Connection params z `.env` (sprawdź który istniejący skrypt embeddings ma działający MysqlConnection wrapper i zaadaptuj).
- PostgreSQL: query do `divechat_product_embeddings` (`product_id`, `product_name`, `category_name`, `parent_category_name`). DATABASE_URL z `.env`.

### Budowa drzewa kategorii (pr_category):

```python
def build_category_tree(mysql) -> dict[int, dict]:
    """Returns: id_category -> {name, id_parent, level_depth, is_root, active, ancestors_chain}"""
```

Każdy węzeł ma `ancestors_chain: list[int]` (od korzenia w dół) dla szybkiego wyszukiwania parenta na konkretnym poziomie.

### Heurystyki wyboru "naturalnego parenta":

```python
def get_natural_parent(product_id: int, tree: dict, product_categories: list[int]) -> dict:
    """Returns {strategy_a: parent_name, strategy_b: parent_name, strategy_c: parent_name}"""
```

Strategia A: kategoria najwyższego `level_depth` (najbardziej szczegółowa wśród produkta).
Strategia B: pierwsza kategoria na `level_depth=2` (drugi poziom od korzenia — zwykle grupa nadrzędna typu "Skafandry suche", "Komputery Nurkowe").
Strategia C: pierwszy non-root ancestor (parent najbliższy korzeniowi, ale niebędący korzeniem).

Dla produktów wielokategoryjnych: każda strategia bierze pod uwagę WSZYSTKIE id_category produktu, deduplikuje, wybiera najczęstszy lub pierwszy alfabetycznie (audyt sprawdzi rozkład).

### Porównanie z obecnym mappingiem:

```python
def compare_with_current(natural: dict, current: str | None) -> dict:
    """Returns {match_a: bool, match_b: bool, match_c: bool, current_is_null: bool}"""
```

### Agregaty:

- `% pokrycia` per strategia (ile produktów dostałoby parenta)
- `% zgodności` per strategia (current == natural dla nie-NULL)
- TOP 20 rozjazdów (current_name, natural_name per strategia, liczba produktów)
- TOP 20 produktów które są obecnie NULL ale strategia X znajduje parenta

## KROK 2. Raport markdown

Plik: `_docs/audyt_D1_ETL_pr_category.md` (NIE matchuje `*_audit*.md` w .gitignore, idzie do gita jako dokumentacja architektoniczna).

Struktura raportu:

```
# Audyt D1 ETL: pr_category vs D2-hybrid mapping

**Data:** YYYY-MM-DD
**Skrypt:** embeddings/audit_d1_pr_category.py
**Powiązany ADR:** ADR-055 (D2-hybrid hotfix), kontekst dla T-010 (przyszły D1 ETL)

## Stan wejściowy (po T-002 D2-hybrid)

| Metric | Wartość |
|---|---|
| Produkty total w divechat_product_embeddings | XXX |
| parent_category_name != NULL | XXX (XX%) |
| parent_category_name = NULL | XXX (XX%) |

## Strategia A (najwyższy level_depth — najbardziej szczegółowa)

| Metric | Wartość |
|---|---|
| % pokrycia | XX% |
| % zgodności z D2 (dla nie-NULL) | XX% |
| Liczba nowych przypisań (NULL → coś) | XXX |
| Liczba rozjazdów (current != natural) | XXX |

## Strategia B (level_depth=2)
... (j.w.)

## Strategia C (pierwszy non-root ancestor)
... (j.w.)

## TOP 20 rozjazdów (per najlepsza strategia)

| Pseudokategoria w D2 | "Naturalny parent" wg PS | Produkty | Przykład |
|---|---|---|---|
| Skafandry suche | SUCHE Trylaminat, Cordura | 70 | SANTI E.Lite Plus |
| ...

## TOP 20 produktów obecnie NULL → coś (per najlepsza strategia)

| product_id | product_name | category_name | Sugerowany parent |
|---|---|---|---|

## Rekomendacje design D1 ETL (T-010)

[CC pisze 5-10 zdań wniosków na bazie liczb:
- Która strategia daje najwyższe pokrycie i zgodność
- Czy D2-hybrid jest słuszny czy wymaga rewizji
- Jak obsłużyć wielokategoryjne produkty
- Czy D2 hardcoded override layer jest potrzebny po D1 ETL]
```

## KROK 3. Smoke test skryptu (lokalnie)

```bash
cd /Users/karol/Documents/3_DIVEZONE/Aplikacje/Chat_dla_klientow_2026
python3 embeddings/audit_d1_pr_category.py
# Oczekiwany output: raport zapisany do _docs/audyt_D1_ETL_pr_category.md, log stdout z agregatami
```

Sprawdź że:
- Skrypt zwraca exit 0
- Raport ma niezerowe metryki we wszystkich sekcjach
- TOP 20 list nie są puste

## KROK 4. STOP point — review przez Karol

Status: "READY FOR REVIEW v1". Wklej:
- Krótkie podsumowanie liczbowe (% pokrycia i zgodności per strategia)
- TOP 3 najbardziej zaskakujących rozjazdów
- Główna rekomendacja design D1

NIE commit do gita bez akceptacji raportu.

## KROK 5. Git workflow (po akceptacji)

```bash
git status
git add embeddings/audit_d1_pr_category.py
git add _docs/audyt_D1_ETL_pr_category.md
git commit -m "T-009: D1 AUDIT — diagnostyka pr_category vs D2-hybrid mapping

Skrypt Python czyta hierarchię pr_category z MySQL, dla każdego produktu
generuje 'naturalny parent' wg 3 strategii (najwyższy level_depth, level=2,
non-root ancestor), porównuje z obecnym D2-hybrid mappingiem.

Raport: _docs/audyt_D1_ETL_pr_category.md
Output: % pokrycia/zgodności per strategia, TOP 20 rozjazdów, rekomendacje
design dla T-010 (przyszła implementacja D1 ETL).

Powiązany ADR: ADR-055"
git push origin main
```

## KROK 6. Raport + status update

### Utworz `_instances/embeddings/handoff/T-009_done.md`:
- Liczbowe podsumowanie (5-7 linii z najważniejszymi metrykami)
- Główna rekomendacja design D1 (1 paragraf)
- Czy D2-hybrid wymaga rewizji (krótka opinia)

### Update `_docs/21_STATUS_PROJEKTU.md`:
- "Aktywne instancje CC" → embeddings T-009 DONE
- "Kolejka tasków" → usunąć T-009 audyt, dodać T-010 D1 ETL implementacja (na bazie raportu)
- Dodać sekcję "Decyzje czekające na Karola" z linkiem do raportu audytu

### Osobny commit "docs:":

```bash
git add _docs/21_STATUS_PROJEKTU.md
git commit -m "docs: T-009 DONE — D1 audyt gotowy, decyzje czekają"
git push origin main
```

## Out of scope

- Implementacja D1 ETL (osobny task T-010, decyzja design po review raportu)
- Modyfikacja D2-hybrid mapping (jeśli audyt wskaże potrzeby — osobny task)
- Restrukturyzacja drzewa pr_category w PrestaShop
- Audyt category_name accuracy (literalne nazwy w SystemPrompt vs pr_category)
- Editorial Picks integracja z parent_category_name
