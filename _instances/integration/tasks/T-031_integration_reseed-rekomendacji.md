# T-031 — INTEGRATION: Re-seed kuratorowanych rekomendacji (automaty + komputery)

**Data:** 2026-06-01
**Instancja:** integration
**Wejscie:** ADR-065 (+uzup.1-4), handoff panel/re-seed 2026-06-01, T-029 (backend gotowy)
**Tabela:** `divechat_curated_recommendations` (sql/016) — ISTNIEJE na Railway, PUSTA
**Narzedzie:** `get_curated_recommendations` (standalone/src/Tools/CuratedRecommendations.php) — enum kategorii z DB

---

## CEL
Wypelnic pusta tabele rekomendacji realnymi bestsellerami dla 2 kategorii: **automaty** i **komputery**. Zrodlo popularnosci = Subiekt (NIE PrestaShop, dane PS niewiarygodne — decyzja 172c). Po seedzie bot zacznie polecac realne produkty przy pytaniach o dobor.

## PODZIAL NA FAZY (bramka decyzyjna Karola posrodku)
- **FAZA A** (ten task, prompt A): diagnoza mapowania SKU + ranking kandydatow -> raport do czatu -> STOP.
- **FAZA B** (prompt B, po wyborze Karola): INSERT do PG + smoke test narzedzia.

CC NIE wykonuje INSERT-u w Fazie A. Wybor produktow i rationale_pl = decyzja Karola (172c: Subiekt to input, nie wyrocznia).

---

## FAZA A — DIAGNOZA + RANKING KANDYDATOW

### A1. Mapowanie SKU Subiekt -> product_id (empiryczne, NIE zgadywane)
Symbol Subiekta ma rozne formaty (AC-012-0, 24.855.810, SS051087000, RQ112111). Nie wiadomo z gory, w ktorej kolumnie pr_product siedzi.
- Wez 5-8 znanych Symboli automatow/komputerow z `reports/sales_subiekt_12mcy.csv`.
- Sprawdz w MySQL PrestaShop, ktora kolumna pasuje: `pr_product.reference`, `pr_product.supplier_reference`, `pr_product.ean13` (sprawdz tez `pr_product_attribute` jesli SKU jest na poziomie wariantu).
- USTAL regule mapowania (ktora kolumna, exact match czy normalizacja). Raport: % trafien dla probki.

### A2. Parsing + filtr Subiekta
- Wczytaj `reports/sales_subiekt_12mcy.csv` (kolumny: Nazwa, Symbol, Grupa, Ilosc, J.M., Netto; Netto z przecinkiem dziesietnym i separatorem tysiecy w cudzyslowie).
- Wyfiltruj grupy odpowiadajace **automatom** i **komputerom** (rozpoznaj realne nazwy grup w CSV — np. "Automaty Oddechowe"; nazwy komputerow do ustalenia z danych).
- ODSIEJ akcesoria/czesci w obrebie grupy (ustniki, o-ringi, zaczepy, weze, LP/HP porty) — zostaw wlasciwe produkty (kompletne automaty, komputery nurkowe).

### A3. Ranking kandydatow + enrich
Dla kazdego kandydata po filtrze:
- Symbol -> product_id (regula z A1).
- Enrich z MySQL: nazwa PS, cena aktualna, dostepnosc, active (uzyj wzorca z `MysqlProductEnrichmentService`, NIE pisz nowego dostepu do MySQL od zera).
- Zsumuj ilosc 12mc per produkt (ten sam model moze byc w kilku wierszach).

### A4. Raport do czatu (STOP)
Dla kazdej z 2 kategorii — tabela posortowana po ilosci malejaco:
`Symbol | Nazwa Subiekt | Ilosc 12mc | product_id | Nazwa PS | Cena | Dostepnosc | active`
Oddzielnie wypisz pozycje, ktorych NIE udalo sie zmapowac (Symbol bez trafienia w pr_product) — Karol oceni czy to istotne produkty.
NIE wstawiaj nic do tabeli. Czekaj na decyzje Karola.

---

## FAZA B — SEED (po wyborze Karola)

### B1. Wejscie od Karola (dostarczy w czacie)
Per kategoria: `category_key`, `category_label_pl` (opis KIEDY bot uzywa kategorii), oraz 1-3 wpisy: `product_id` + `priority` (1-3) + `rationale_pl`.

### B2. INSERT
- Zbuduj INSERT wg wzorca z sql/016 (kolumny: category_key, category_label_pl, product_id, priority, rationale_pl, recheck_interval_days).
- `recheck_interval_days`: komputery=90 (szybka ewolucja modeli), automaty=180 (Karol moze nadpisac).
- Idempotentnie: tabela ma UNIQUE(category_key, product_id) — uzyj ON CONFLICT DO UPDATE lub potwierdz pusta tabele przed insertem.
- Zapisz INSERT jako plik migracyjny `sql/017_seed_curated_automaty_komputery.sql` + rollback `sql/017_..._rollback.sql` (konwencja repo).

### B3. Smoke test narzedzia
- Wywolaj `get_curated_recommendations` z `category` = jedna z zaseedowanych kategorii.
- Sprawdz: status `ok`, zwrocone 1-3 produkty, cena/dostepnosc z MySQL obecne, produkty active=false pominiete.
- Sprawdz enum: nowe kategorie widoczne w `getParametersSchema()` (generowany z DB).

### B4. Swiezy hit recznie (Suunto Nautic, ADR-065 uzup.1)
Jesli Karol zdecyduje — dodac do kategorii komputery mimo braku historii sprzedazy: product_id 7515 (NAUTIC S), rationale na marce ("nowoczesny, Suunto sprawdzony producent"). Decyzja w B1.

---

## OGRANICZENIA
- Cena/dostepnosc ZAWSZE z zywego MySQL przez enrich, nigdy hardkodowane w tabeli (ADR-065 twardy staleness).
- Regula KOLOROWY wyswietlacz dla komputerow rekreacyjnych (Karol potwierdzil — NIE mono jak Zoop Novo). To kryterium doboru Karola, nie filtr CC.
- NIE modyfikuj narzedzia ani schematu tabeli (proof_type to przyszle rozszerzenie — na razie w rationale_pl).

## GIT
- Faza A: brak commitu kodu (tylko diagnoza). Jesli powstanie pomocniczy skrypt rankingu — commit `T-031: skrypt rankingu kandydatow re-seed (Subiekt automaty+komputery)`.
- Faza B: `git add sql/017_seed_curated_automaty_komputery.sql sql/017_seed_curated_automaty_komputery_rollback.sql` -> commit `T-031: seed kuratorowanych rekomendacji (automaty+komputery) wg wyboru Karola` -> push.
- Po deploy: osobny `docs:` commit ze statusem (21_STATUS_PROJEKTU.md). UWAGA: handoff `_instances/*/handoff/` jest ignorowany przez .gitignore (lokalny per maszyna) — NIE commituj, NIE uzywaj `-f`. Napisz go lokalnie.

## RAPORT KONCOWY
Faza A: tabele kandydatow + regula mapowania + lista niezmapowanych.
Faza B: liczba wstawionych wierszy per kategoria, wynik smoke testu, potwierdzenie enum.
