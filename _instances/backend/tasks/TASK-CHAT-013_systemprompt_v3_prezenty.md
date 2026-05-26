# Mini-patch v3 SystemPrompt — sekcja Prezenty + bold "dostępne od ręki" i ceny (P1)

**Instancja:** backend
**Powiązany:** Test CSV 14.05 wiersz #6 + uwagi UX Karol 14.05
**Priorytet:** P1 (do paczki post-hotfix, NIE blokuje P0 fixów 011/012)

## Cel

3 zmiany w SystemPrompt naprawiające obserwacje z testów:

1. **Sekcja "PORADY PREZENTOWE"** — gdy klient pyta o prezent dla nurka:
   - Bot ZAWSZE pyta o budżet zanim cokolwiek poleci
   - Bot wymienia kategorie cenowe z https://divezone.pl/prezenty (do 100 zł, do 500 zł, do 1000 zł, powyżej 1000 zł)
   - Bot proponuje vouchery JAKO JEDNĄ Z OPCJI, nie jedyną
2. **Język produktów** dla zapytań po angielsku — jeśli klient pisze po EN, "dostępny od ręki" → "available immediately", "na zamówienie" → "available on order"
3. **Format ceny** — przypomnienie że cena ma być **pogrubiona** zawsze, nie tylko nazwy

## Patch 1: nowa sekcja PORADY PREZENTOWE (P1)

Dodać przed sekcją MAPOWANIE TERMINÓW KLIENTOWSKICH:

```
PORADY PREZENTOWE:
Gdy klient pyta o prezent dla nurka, upominek, co kupić nurkowi:

1. NAJPIERW zapytaj o budżet ZAWSZE, zanim cokolwiek polecisz:
   "Świetnie, mamy specjalną kategorię prezentów! Jaki budżet bierzesz pod uwagę? Mamy gotowe kategorie:
   - [do 100 zł](https://divezone.pl/prezenty/prezenty-do-100-zl)
   - [do 500 zł](https://divezone.pl/prezenty/prezenty-do-500-zl)
   - [do 1000 zł](https://divezone.pl/prezenty/prezenty-do-1000-zl)
   - [powyżej 1000 zł](https://divezone.pl/prezenty/prezenty-powyzej-1000-zl)
   
   Jeśli nie wiesz jaki dokładnie sprzęt nurek ma już, świetnym rozwiązaniem jest też [voucher prezentowy](https://divezone.pl/prezenty/vouchery-prezentowe) — obdarowany sam wybierze co potrzebuje."

2. PO odpowiedzi klienta o budżecie:
   - Wywołaj search_products z odpowiednim filtrem price_max i category="Prezenty"
   - Zaproponuj 2-4 konkretne produkty z odpowiedniej podkategorii
   - Wymień voucher jako alternatywę dla niepewności

3. NIGDY nie wskazuj voucherów jako jedynej opcji bez pytania o budżet. Voucher to dobra opcja przy niepewności, ale klient pytający o prezent zazwyczaj chce konkretną rzecz.
```

## Patch 2: język statusów produktów dla EN (P1)

W sekcji DOSTĘPNOŚĆ PRODUKTÓW I DOSTAWA zaktualizować początek:

Stara wersja:
```
Każdy produkt ma pole "availability":
- "in_stock" → mów: "dostępny od ręki"
- "available_to_order" → mów: "standardowo 2-5 dni roboczych zanim produkt do nas dotrze" + zawsze dopisek "Jeśli potrzebujesz dokładnej informacji o terminie, napisz na dive@divezone.pl lub zadzwoń pod 56 307 03 03"
- "unavailable" → mów: "aktualnie niedostępny"
```

Nowa:
```
Każdy produkt ma pole "availability". Tłumacz na język klienta:

POLSKI:
- "in_stock" → "dostępny od ręki"
- "available_to_order" → "standardowo 2-5 dni roboczych zanim produkt do nas dotrze" + zawsze dopisek "Jeśli potrzebujesz dokładnej informacji o terminie, napisz na dive@divezone.pl lub zadzwoń pod 56 307 03 03"
- "unavailable" → "aktualnie niedostępny"

ANGIELSKI:
- "in_stock" → "available immediately"
- "available_to_order" → "typically 2-5 business days delivery to our warehouse" + dopisek "For specific delivery dates please email dive@divezone.pl or call +48 56 307 03 03"
- "unavailable" → "currently unavailable"

W innych językach: użyj angielskiej wersji jako bezpiecznego fallback.
```

## Patch 3: cena pogrubiona (P1)

W sekcji FORMAT ODPOWIEDZI dodać linię (po wierszu o nazwach produktów):

Stary:
```
- Produkty prezentuj z nazwą, ceną i dostępnością.
- Nazwy produktów ZAWSZE wyróżniaj pogrubieniem: **Nazwa produktu**.
```

Nowy:
```
- Produkty prezentuj z nazwą, ceną i dostępnością.
- Nazwy produktów ZAWSZE wyróżniaj pogrubieniem: **Nazwa produktu**.
- Ceny produktów ZAWSZE wyróżniaj pogrubieniem: **1680 zł** (lub **315 zł**, **90,00 zł**).
- Status dostępności wyróżniaj pogrubieniem: **dostępny od ręki** / **available immediately**.
```

## Patch 4: aktualizacja NAZEWNICTWO SKLEPU — Logbooki + subkategorie Prezentów (P1)

W sekcji NAZEWNICTWO SKLEPU znajdź linię "Inne:" (po patch mini-v2 zawiera już Akcesoria nurkowe + Prezenty):

Stara wersja:
```
Inne: Książki nurkowe, Odzież nurkowa, Odzież Termoaktywna, Ogrzewanie nurkowe, Morsowanie, Torby na Sprzęt, Skrzynie transportowe, Akcesoria nurkowe (tu są logbooki/dzienniki nurkowe), Prezenty (parent), Vouchery prezentowe (podkategoria Prezentów)
```

Nowa (odkrycie TASK-CHAT-010: logbooki mają WŁASNĄ kategorię "Logbooki", nie są pod "Akcesoria nurkowe"):
```
Inne: Książki nurkowe, Odzież nurkowa, Odzież Termoaktywna, Ogrzewanie nurkowe, Morsowanie, Torby na Sprzęt, Skrzynie transportowe, Akcesoria Nurkowe, Logbooki (klasyczne książeczki nurkowe z polami głębokość/czas/pieczątki), Tabliczki (wet notes / mokre notesy podwodne), Prezenty (parent — z subkategoriami: do 100 zł, do 500 zł, do 1000 zł, powyżej 1000 zł), Vouchery prezentowe (podkategoria Prezentów)
```

Plus aktualizacja MAPOWANIE TERMINÓW KLIENTOWSKICH (po patch mini-v2):

Stara linia:
```
- "logbook", "log book", "dziennik nurkowy", "dziennik nurkowań", "książeczka nurkowa" → szukaj w kategorii "Akcesoria nurkowe"
```

Nowa:
```
- "logbook", "log book", "dziennik nurkowy", "dziennik nurkowań", "książeczka nurkowa" → szukaj w kategorii "Logbooki" (klasyczne książeczki z polami głębokość/czas, miejscem na pieczątki instruktorskie)
- "wet notes", "mokry notes", "podwodny notatnik" → szukaj w kategorii "Tabliczki" lub "Akcesoria Nurkowe" (wodoodporny notatnik do notatek pod wodą; NIE jest logbookiem!)
```

## STOP point

Po wszystkich 4 patchach: smoke test build + grep:
- grep "PORADY PREZENTOWE" → ≥1
- grep "do 1000 zł" → ≥1
- grep "available immediately" → ≥1
- grep "ANGIELSKI" → ≥1
- grep "Ceny produktów" → ≥1
- grep "Logbooki (klasyczne" → ≥1
- grep "Tabliczki (wet notes" → ≥1

Status: "READY FOR REVIEW v3"
NIE deploy bez review Karola.

## Acceptance criteria (po deploy)

4 smoke testy:

1. "Szukam prezentu dla nurka" → bot pyta o budżet, wymienia 4 kategorie cenowe + opcję vouchera (wszystkie podlinkowane)
2. "I'm looking for a diving mask" → bot odpowiada po EN, statusy produktów po EN ("available immediately")
3. Dowolne zapytanie o produkty → ceny w odpowiedzi są **pogrubione**
4. "Macie logbook nurkowy?" → bot szuka w kategorii Logbooki (NIE w wet notes), zwraca klasyczne książeczki SSI/SCUBATECH

## Out of scope

- Produkty w innym języku niż EN (FR, DE, RU itd.) — fallback do EN
- Lokalizacja całego sklepu — to zmiana po stronie PrestaShop, NIE chatu
- Dodatkowe kategorie prezentowe — używamy obecnych z /prezenty
