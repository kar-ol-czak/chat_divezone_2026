# T-002: TASK-CHAT-012 kontynuacja — rozszerzony mapping 100% pokrycia bazy (backend)

**Instancja:** backend
**Powiązany:** TASK-CHAT-012 w toku (MAPPING PROPOSAL STOP point), ADR-055
**Powiązany handoff:** _instances/backend/handoff/TASK-CHAT-012_mapping_proposal.md

## Decyzje Karola po review MAPPING PROPOSAL

Pierwotnie zaproponowałeś 9 pseudokategorii pokrywających 1290 produktów (~50% bazy). **Karol wymaga 100% pokrycia bazy poza brand-only (które wymagają osobnego D1 ETL).** Konieczne rozszerzenie mappingu.

### Zaktualizowane decyzje na 5 pytań z mapping_proposal.md:

1. **"Wypornościowe"** → MAPUJ jak zaproponowane.
2. **"KLASYCZNE" (16) + "TURYSTYCZNE, LEKKIE" (4)** → MAPUJ pod **Wypornościowe** (Karol potwierdził: to podkategorie jacketów).
3. **"Ocieplacze do Suchych" (70), "Buty do suchego" (7), "Zawory do suchego skafandra" (8), "Torby na Suche i Ocieplacze" (6)** → MAPUJ pod **Skafandry suche** (zmiana z wcześniejszego NULL — Karol nie chce nic wyłączać).
4. **"Konsole" (12), "Manometry" (40), "Kompasy" (11), "Interfejsy" (7)** → MAPUJ pod **Komputery Nurkowe** (zmiana z wcześniejszego NULL).
5. **Brand-only kategorie (TECLINE 76, SCUBAPRO 29, APEKS 23, POSEIDON 10, MARES 8, AQUALUNG 7, ATOMIC 7, XDEEP 4, SCUBATECH 4 = 168 produktów)** → **WERYFIKACJA HIERARCHII** w MySQL przed decyzją.

   Karol wskazał: tylko 2 główne kategorie w PrestaShop mają strukturę "parent → marka jako subkategoria": Automaty Oddechowe i Komputery. Komputery zostały już zmapowane bo subkategorie mają format "Komputery SHEARWATER" (z prefiksem). Automaty mają subkategorie nazwane tylko marką (bez prefiksu "Automaty"), dlatego CC ich nie złapał.

   **Krok weryfikacji (przed SQL UPDATE):**
   
   ```sql
   -- MySQL: sprawdź parent dla brand-only kategorii
   SELECT c.id_category, cl.name, c.id_parent, plc.name AS parent_name
   FROM pr_category c
   INNER JOIN pr_category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
   INNER JOIN pr_category_lang plc ON plc.id_category = c.id_parent AND plc.id_lang = 1
   WHERE cl.name IN ('TECLINE','SCUBAPRO','APEKS','POSEIDON','MARES','AQUALUNG','ATOMIC','XDEEP','SCUBATECH');
   ```
   
   **Decyzja per kategoria:**
   - Jeśli parent_name = "Automaty Oddechowe" (lub równoważne) → DORZUĆ do mappingu pseudokategorii **Automaty Oddechowe**
   - Jeśli parent_name = inna pseudokategoria z NAZEWNICTWO SKLEPU → DORZUĆ pod ten parent
   - Jeśli różne kategorie lub niejasne → zostaw NULL i zaznacz w handoff jako EDGE CASE
   
   Hipoteza Karola: wszystkie 168 to automaty. Po weryfikacji ~168 produktów dorzucone do "Automaty Oddechowe" zamiast NULL.

### Rozszerzenie mappingu o pozostałe kategorie

Cel: pokryć WSZYSTKO co nie jest brand-only. Po mapping ~2388 produktów ma `parent_category_name`, ~168 NULL (brand-only).

Pseudokategorie z NAZEWNICTWO SKLEPU sekcja "Inne" (i nie tylko) — dorób mapping dla każdej:

**Pseudokategoria "Akcesoria Nurkowe"** (parent z NAZEWNICTWO SKLEPU "Inne"):
- Akcesoria (84)
- Akcesoria Chemiczne (23)
- Akcesoria pływackie (12)
- Akcesoria Nurkowe (12)
- (rozważ czy są inne akcesoria-pochodne kategorie)

**Pseudokategoria "Automaty Oddechowe"** (rozszerzenie):
- Akcesoria do automatów (41) — DODAJ
- Węże do Automatów (68) — DODAJ

**Pseudokategoria "Książki nurkowe"** (parent z NAZEWNICTWO "Inne"):
- Książki nurkowe (41)
- (rozważ Wydawnictwa Nurkowe jeśli istnieje jako osobna kategoria)

**Pseudokategoria "Skrzynie transportowe"** (parent z NAZEWNICTWO):
- Skrzynie transportowe (38)

**Pseudokategoria "Torby na Sprzęt"** (parent z NAZEWNICTWO):
- Torby na Sprzęt (jeśli istnieje literal)
- Torby na Suche i Ocieplacze (już zmapowane pod Skafandry suche — wybierz JEDEN parent zgodnie z ADR-055, rekomendacja: zostaw pod Skafandry suche bo bardziej semantycznie pasuje)
- Inne kategorie torb (sprawdź)

**Pseudokategoria "Odzież nurkowa"** (parent z NAZEWNICTWO):
- Odzież nurkowa (sprawdź count)

**Pseudokategoria "Odzież Termoaktywna"**:
- (sprawdź count)

**Pseudokategoria "Ogrzewanie nurkowe"**:
- (sprawdź count)

**Pseudokategoria "Morsowanie"**:
- (sprawdź count)

**Pseudokategoria "Logbooki"** (parent z mini-patch v2):
- Logbooki (7 produktów z TASK-CHAT-010 — sprawdź literal w PG)

**Pseudokategoria "Tabliczki"** (parent z mini-patch v2):
- Tabliczki (sprawdź count) — wet notes

**Pseudokategoria "Vouchery prezentowe"** (parent z mini-patch v2):
- Vouchery prezentowe (5 produktów z TASK-CHAT-010)

## KROK 4. Rozszerzony mapping proposal

Wyciągnij z PG wszystkie kategorie które nie mają jeszcze przypisanego parent (pełna lista 129 distinct kategorii w `/tmp/categories_pg.txt`). Dla każdej zdecyduj:

a) Pasuje pod jedną z pseudokategorii z NAZEWNICTWO SKLEPU → zmapuj
b) Brand-only (nazwa = marka) → zostaw NULL (osobny task)
c) Nie pasuje pod żadną → zaznacz jako "EDGE CASE — czeka na decyzję Karola"

Zaktualizuj handoff `_instances/backend/handoff/TASK-CHAT-012_mapping_proposal_v2.md` z pełnym mappingiem (~15-17 pseudokategorii).

## KROK 5. STOP point — review v2

Status: "MAPPING PROPOSAL V2 — review Karol"
Przed wykonaniem SQL UPDATE Karol akceptuje rozszerzony mapping LUB modyfikuje.

## KROK 6. SQL UPDATE (po akceptacji v2)

`sql/010_pseudocategory_mapping.sql` — idempotentny, jeden plik z wszystkimi UPDATE statements (po jednym per pseudokategoria, format: `UPDATE divechat_product_embeddings SET parent_category_name = 'X' WHERE category_name IN (...);`).

`sql/010_pseudocategory_mapping_rollback.sql` — revert na NULL.

## KROK 7. Backup + apply na prod

Backup zaktualizowanych wierszy:
```
CREATE TABLE divechat_product_embeddings_backup_20260514 AS 
SELECT id, parent_category_name 
FROM divechat_product_embeddings;
```

Apply migration na Railway PG.

Verify:
- SELECT count(*) FROM divechat_product_embeddings WHERE parent_category_name IS NOT NULL → expected ~2388 (93% bazy)
- SELECT count(*) FROM divechat_product_embeddings WHERE parent_category_name IS NULL → expected ~168 (brand-only)

## KROK 8. Test integracyjny

- ProductSearch::execute(brand="SANTI", category="Skafandry suche", in_stock_only=false) → expected ≥10 produktów
- ProductSearch::execute(category="Komputery Nurkowe") → produkty wszystkich marek + konsole/manometry/kompasy
- ProductSearch::execute(category="Akcesoria Nurkowe") → 131+ akcesoria
- ProductSearch::execute(category="Wypornościowe") → 317+ skrzydeł/jacketów/balastów + 20 (KLASYCZNE/TURYSTYCZNE)
- ProductSearch::execute(category="Książki nurkowe") → 41+ książek

## KROK 9. Smoke test produkcyjny

Karol testuje przez UI chat.divezone.pl:
- "Szukam suchego skafandra SANTI" → top wyniki SANTI + ocieplacze cross-sell w wynikach
- "Polec mi komputer nurkowy SHEARWATER" → top wyniki SHEARWATER
- "Macie akcesoria nurkowe?" → akcesoria
- "Szukam jacketu BCD" → BCD różnych marek (włącznie z KLASYCZNE/TURYSTYCZNE)
- "Latarka backup nurkowa" → top wyniki latarki backup
Plus regresja: "Szukam Maski jednoszybowej Tecline" → nadal działa.

## KROK 10. Git + push

```
git status
git add sql/010_pseudocategory_mapping.sql sql/010_pseudocategory_mapping_rollback.sql
git commit -m "T-002: D2-hybrid mapping pseudokategorii rozszerzony do 100% pokrycia (ADR-055)

- SQL UPDATE: ~17 pseudokategorii z NAZEWNICTWO SKLEPU mapowanych na faktyczne kategorie w PG
- Zaktualizowano parent_category_name dla ~2388 produktów (93% bazy)
- Brand-only kategorie (168 prod) zostają NULL — osobny task D1 ETL
- ADR-027 fallback aktywuje się dla pseudokategorii zbiorczych
- Bez re-embed"
git push origin main
```

## KROK 11. Raport + status

- `_instances/backend/handoff/T-002_done.md` (deploy info, hash commit, mapping summary v2, smoke test results)
- Update `_docs/21_STATUS_PROJEKTU.md` → T-002 DEPLOYED + komentarz że TASK-CHAT-012 zamknięty po tym fix
- git add + commit "docs:" + push

## Powiązany przyszły task

Po deploy T-002, planowany **T-XXX: D1 ETL z pr_category** — trwałe rozwiązanie dla brand-only kategorii (168 prod) + zautomatyzowane utrzymanie parent_category_name przez pipeline embeddings. Out of scope tego tasku.
