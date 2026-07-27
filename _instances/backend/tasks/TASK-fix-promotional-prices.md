# TASK: Fix promotional prices in enrichWithMySQLData()

## Problem
`enrichWithMySQLData()` w `ProductSearch.php` pobiera `ps.price` z `pr_product_shop` (cena bazowa netto). Nie uwzględnia tabeli `pr_specific_price`, przez co AI podaje cenę sprzed promocji/obniżki.

## Plik do edycji
`standalone/src/Tools/ProductSearch.php` — metoda `enrichWithMySQLData()`

## Wymagania

### 1. Dołączyć `pr_specific_price` do query MySQL
LEFT JOIN z warunkami:
- `sp.id_shop IN (0, 1)` — promocja ogólna lub dla shop 1
- `sp.id_customer = 0` — cena ogólna (nie per-klient)
- `sp.id_group IN (0, 1)` — goście + domyślna grupa
- `sp.from_quantity <= 1` — cena jednostkowa (nie hurtowa)
- Daty: `(sp.from = '0000-00-00 00:00:00' OR sp.from <= NOW()) AND (sp.to = '0000-00-00 00:00:00' OR sp.to >= NOW())`
- `sp.id_product_attribute = 0` — cena na poziomie produktu

### 2. Logika obliczenia ceny finalnej (netto, przed podatkiem)
PrestaShop specific_price ma dwa mechanizmy:
- **price override:** jeśli `sp.price >= 0` (uwaga: 0.000000 to brak override w PS, ale `-1` też bywa używane — sprawdź `sp.price > 0`), to zastępuje `ps.price`
- **reduction:** `sp.reduction_type = 'percentage'` → mnóż `(1 - sp.reduction)`, lub `'amount'` → odejmij `sp.reduction`

Cena finalna netto:
```
base = IF(sp.price > 0, sp.price, ps.price)
IF reduction_type = 'percentage' THEN base * (1 - reduction)
IF reduction_type = 'amount' THEN base - reduction
```
Potem brutto: `cena_netto * (1 + tax_rate / 100)`

### 3. Obsługa wielu specific_prices na jeden produkt
Może istnieć kilka wpisów `pr_specific_price` dla jednego produktu (np. różne grupy, daty). Użyj subquery z priorytetyzacją: wybierz wiersz z najniższą ceną wynikową, lub zastosuj priorytet PS: `id_shop > 0` wygrywa z `id_shop = 0`, `id_group > 0` wygrywa z `id_group = 0`.

Uproszczone podejście (akceptowalne): weź wiersz z `ORDER BY sp.id_shop DESC, sp.id_group DESC LIMIT 1` per produkt (bardziej specyficzny wygrywa).

### 4. Zachować istniejącą strukturę zwracanego array
Output `enrichWithMySQLData()` nie zmienia się strukturalnie. Pole `price` powinno teraz zawierać cenę po promocji. Pole `quantity` zostaje (używane w diagnostyce).

### 5. Opcjonalnie: dodać pole `price_before_discount`
Jeśli specific_price istnieje, dodaj `price_before_discount` (cena bazowa brutto) do output array, żeby AI mogło powiedzieć "przeceniony z X na Y". Nie jest to wymagane w pierwszej iteracji.

## Ograniczenia (znane, do przyszłej iteracji)
- Ceny kombinacji (`id_product_attribute > 0`) nie są obsługiwane. System operuje na `id_product`. Gdy produkt ma kombinacje z różnymi cenami, zwracamy cenę bazowego produktu. To jest akceptowalne na teraz.
- Group-specific pricing dla zalogowanych klientów to osobny feature (przyszłość).

## Testy
Po wdrożeniu sprawdzić na produkcie z aktywną promocją w sklepie, że:
- Cena w czacie = cena widoczna na karcie produktu divezone.pl
- Produkty bez promocji zwracają normalną cenę bazową brutto
- Produkty z wygasłą promocją (data `to` w przeszłości) zwracają cenę bazową

## Kontekst
- PrestaShop 1.7.6, prefix `pr_`, shop ID = 1, country ID = 14 (Polska, VAT)
- Aktualna metoda: linie ~490-560 w ProductSearch.php
