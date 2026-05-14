# TASK-CHAT-010: Synonimy logbook + voucher w embeddings (P1)

**Instancja:** embeddings
**Powiązany:** Test CSV 14.05 wiersz #14 (logbook → książki), wiersz #8 (prezent → voucher)
**Priorytet:** P1
**Czas estymowany:** ~1-2h

## Cel

Naprawić wyszukiwanie semantyczne dla 2 popularnych zapytań:
- "logbook nurkowy" / "logbook" → klient szuka dziennika nurkowań (klasyczna książeczka z wpisami i pieczątkami), bot zwraca książki tematyczne LUB **wet notes** (mokre notesy podwodne, które NIE są logbookami)
- "voucher" / "prezent" → klient szuka karty podarunkowej

GSC dane: logbook 126 kliknięć/rok, "prezent" wysoki ruch sezonowy.

**WAŻNE wyróżnienie produktowe:**
- **Logbook nurkowy = klasyczna papierowa książeczka** do wpisywania nurkowań, zbierania pieczątek instruktorskich, z polami głębokość/czas/lokalizacja
- **Wet notes / mokry notes = podwodny notatnik** z wodoodpornego papieru do robienia notatek pod wodą (cena, kompresja, akcesoria do BCD)
- To **NIE są synonimy**. Klient pytający o logbook NIE chce wet notes.
- Test 14.05 wiersz #5: bot polecił TECLINE Wkład do mokrego notesu i OMS Mokry notes na zapytanie "Macie logbook nurkowy?" — to bug.

## Zadanie

### Krok 1. Znalezienie produktów docelowych

W MySQL produkcji znajdź:
- Produkty logbook/dziennik w kategorii "Akcesoria nurkowe" (Karol potwierdził lokalizację)
- Produkty vouchery prezentowe w kategorii "Vouchery prezentowe" pod parent "Prezenty"

```sql
-- logbook
SELECT p.id_product, p.reference, pl.name, c.id_category, cl.name AS category
FROM pr_product p
JOIN pr_product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = 1
JOIN pr_category_product cp ON cp.id_product = p.id_product
JOIN pr_category c ON c.id_category = cp.id_category
JOIN pr_category_lang cl ON cl.id_category = c.id_category AND cl.id_lang = 1
WHERE pl.name LIKE '%log%' OR pl.name LIKE '%dziennik%' OR pl.name LIKE '%książeczk%';

-- vouchery
SELECT p.id_product, p.reference, pl.name
FROM pr_product p
JOIN pr_product_lang pl ON pl.id_product = p.id_product AND pl.id_lang = 1
JOIN pr_category_product cp ON cp.id_product = p.id_product
JOIN pr_category_lang cl ON cl.id_category = cp.id_category AND cl.id_lang = 1
WHERE cl.name LIKE '%voucher%' OR cl.name LIKE '%prezent%' OR pl.name LIKE '%voucher%';
```

Zapisz wynik do `_instances/embeddings/handoff/TASK-CHAT-010_products.md`.

### Krok 2. Diagnoza obecnych synonimów

Sprawdź w `_docs/synonyms/synonyms_review_v3.csv` (lub w produkcyjnej tabeli PG `divechat_synonyms` jeśli istnieje) czy znalezione produkty mają już synonimy.

Jeśli tak — wskaż jakie. Jeśli nie — pusta sekcja w handoffie.

### Krok 3. Aktualizacja synonimów

Dla każdego znalezionego produktu logbook dodać:
- PL: `logbook, log book, dziennik nurkowań, dziennik nurkowy, książeczka nurkowa, książeczka do wpisywania nurkowań, karta nurkowań`
- EN: `logbook, log book, dive logbook, diving log, dive journal, scuba log`

Dla każdego vouchera:
- PL: `voucher, voucher prezentowy, bon, bon prezentowy, kupon, karta podarunkowa, prezent, prezent dla nurka, upominek`
- EN: `voucher, gift voucher, gift card, gift certificate`

Format aktualizacji zależy od projektowego pipeline:
- Jeśli synonimy są w CSV → dodaj wiersze do `synonyms_review_v3.csv` (lub nowa wersja v4)
- Jeśli w PG `divechat_synonyms` → UPDATE/INSERT
- Sprawdź w kodzie: gdzie `embeddings/load_synonyms.py` ładuje dane

### Krok 4. Re-embed produktów docelowych

Po aktualizacji synonimów wywołaj pipeline re-embed dla znalezionych produktów:
- Skrypt prawdopodobnie `embeddings/batch_embed_products.py` lub `embeddings/generate_embeddings.py`
- Zakres: tylko zmienione produkty (incremental), nie cała baza (~2600 produktów = niepotrzebnie kosztownie)

### Krok 5. Weryfikacja

Po re-embed, test smoke:

```
Test 1 (logbook):
- Wywołaj search_products(query="logbook nurkowy") lub przez czat na chat.divezone.pl
- Expected: w top 5 wyników są znalezione produkty logbook, NIE książki tematyczne (Ratownictwo Nurkowe etc.)

Test 2 (voucher):
- Wywołaj search_products(query="prezent dla nurka")
- Expected: w top 5 wyników jest co najmniej jeden voucher prezentowy
- Wywołaj search_products(query="voucher")
- Expected: w top 5 wyników są wyłącznie vouchery z kategorii "Vouchery prezentowe"
```

## Acceptance criteria

1. Lista produktów docelowych zidentyfikowana i zapisana
2. Synonimy zaktualizowane w odpowiednim źródle (CSV/PG)
3. Re-embed wykonany na produktach docelowych
4. Test 1: zapytanie "logbook nurkowy" zwraca dziennik(i) nurkowy/e w top 3
5. Test 2: zapytanie "voucher" zwraca vouchery prezentowe w top 3

## STOP point

Po wykonaniu Kroku 5 (weryfikacja smoke) — STOP, raport do Karola.
NIE wdrażaj na produkcję bez review jeśli zmiana dotyczy więcej niż znalezionych produktów docelowych.

## Out of scope

- Inne synonimy poza logbook i voucher
- Reembed całej bazy produktów
- Zmiana algorytmu wyszukiwania
- Walidacja synonimów dla innych testów z CSV (np. komputer popularny, manometr Thermo 2K) — to osobne taski
