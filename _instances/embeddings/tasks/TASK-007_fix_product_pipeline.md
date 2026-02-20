# TASK-007: Fix pipeline embeddingów produktów
# Data: 2026-02-20
# Instancja: embeddings
# Priorytet: WYSOKI (wpływa na jakość wyszukiwania)
# Zależności: Railway DB aktywna, SSH tunnel do MySQL

## Kontekst
Review jakości embeddingów wykazał 3 problemy:
1. description_short (CMS śmieci) używany zamiast description (prawdziwy opis)
2. Produkty spoza aktywnych kategorii w indeksie (Niedostępne, Bazowa, vouchery)
3. 267 produktów z bardzo krótkimi document_text (prawdopodobnie odpadną po filtrach)

## Diagnoza (dane z review)
- 233 produktów z CMS cruft w opisie ("Kliknij i przeczytaj nasz poradnik")
- 20+ fałszywych produktów (opłaty, maseczki COVID, vouchery, kategoria Niedostępne)
- "maska do nurkowania" → top-1 to "Maseczka ochronna Divezone.pl" (COVID)
- Similarity 0.51-0.53 w E2E (powinno być 0.65-0.75)

## Co naprawić

### FIX-A: extract_products.py — opis produktu
**Zmiana:** Używaj TYLKO `description` (długi opis). NIE używaj `description_short` w ogóle.

Obecna logika (USUNĄĆ):
```python
desc = strip_html(product.get("description_short") or "")
if len(desc) < 20:
    desc = strip_html(product.get("description") or "")
```

Nowa logika:
```python
desc = strip_html(product.get("description") or "")
```

### FIX-B: extract_products.py — filtrowanie po drzewie kategorii
**Zasady:**
1. Tylko produkty z kategorii będących potomkami kategorii "Główna" (id=2)
2. Kategoria musi być aktywna (pr_category_shop.active = 1)
3. Wykluczone kategorie (i ich podkategorie): ID 484, 458, 485, 486, 468, 368, 413, 451, 406, 409, 445, 447, 110, 396, 366, 448, 397, 482, 168, 461, 59, 457, 436, 462, 490

**Implementacja:** Zmień PRODUCTS_SQL aby filtrował po drzewie kategorii.

Sposób: użyj pr_category (nleft, nright) do sprawdzenia potomków id=2:
```sql
-- Pobierz nleft/nright kategorii Główna (id=2)
-- Filtruj: kategoria produktu musi mieć nleft BETWEEN parent.nleft AND parent.nright
-- Oraz: kategoria musi być aktywna w pr_category_shop
-- Oraz: kategoria NIE jest na liście wykluczeń i NIE jest potomkiem wykluczonej
```

Drzewo PrestaShop: tabela pr_category ma kolumny nleft, nright (nested set).
Kategorię C jest potomkiem kategorii P gdy: C.nleft > P.nleft AND C.nright < P.nright.

**Wykluczone kategorie — stała lista:**
```python
EXCLUDED_CATEGORY_IDS = [
    484, 458, 485, 486, 468, 368, 413, 451, 406, 409,
    445, 447, 110, 396, 366, 448, 397, 482, 168, 461,
    59, 457, 436, 462, 490
]
```

Wykluczenie obejmuje też ich podkategorie (potomków w nested set).

**SQL do pobrania dozwolonych kategorii:**
```sql
SELECT c.id_category
FROM pr_category c
JOIN pr_category_shop cs ON c.id_category = cs.id_category AND cs.id_shop = 1
JOIN pr_category root ON root.id_category = 2
WHERE c.nleft BETWEEN root.nleft AND root.nright
  AND cs.active = 1
  AND c.id_category NOT IN (484,458,485,486,468,368,413,451,406,409,445,447,110,396,366,448,397,482,168,461,59,457,436,462,490)
  AND NOT EXISTS (
    SELECT 1 FROM pr_category excl
    WHERE excl.id_category IN (484,458,485,486,468,368,413,451,406,409,445,447,110,396,366,448,397,482,168,461,59,457,436,462,490)
      AND c.nleft BETWEEN excl.nleft AND excl.nright
  )
```

Następnie PRODUCTS_SQL filtruje: `WHERE p.id_category_default IN (dozwolone_kategorie)`.

### FIX-C: build_document_text — lepsza jakość tekstu
Gdy description jest pusty po strip_html (nie powinno się zdarzać po filtrach, ale safety net):
- NIE dodawaj pola "Opis:" wcale
- Embedding bazuje na: nazwa + marka + kategoria + cechy
- Loguj warning: "Produkt {id} bez opisu"

### FIX-D: Re-embedding
Po wszystkich poprawkach:
1. Wyczyść starą tabelę: `TRUNCATE divechat_product_embeddings`
2. Odpal: `python batch_embed_products.py --full`
3. Zweryfikuj: ile produktów, ile z opisem, ile bez
4. Test similarity na zapytaniach:
   - "maska do nurkowania" (NIE może zwrócić maseczki COVID)
   - "komputer nurkowy Suunto"
   - "automat oddechowy do zimnej wody"
   - "skrzydło do nurkowania technicznego"

## Pliki do modyfikacji
- embeddings/extract_products.py (FIX-A, FIX-B, FIX-C)

## Definition of Done
- [ ] description_short nie jest używany nigdzie w pipeline
- [ ] Produkty z kategorii Niedostępne/Bazowa nie są w bazie
- [ ] Produkty z 25 wykluczonych kategorii (i ich potomków) nie są w bazie
- [ ] "Maseczka ochronna Divezone.pl" nie jest w bazie
- [ ] "Opłata za płatność online" nie jest w bazie
- [ ] Re-embedding wykonany na pełnej bazie
- [ ] Test similarity: top-3 wyniki sensowne dla 4 zapytań testowych
- [ ] Log: ile produktów przed filtrem vs po filtrze
