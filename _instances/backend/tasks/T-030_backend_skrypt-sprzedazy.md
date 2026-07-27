# T-030: Skrypt analityczny sprzedazy — top N produktow per kategoria

Instancja: backend
Powiazane: ADR-065 uzup.1 (dane sprzedazowe jako fundament, decyzja 148a/150a), fundament pod re-seed kuratorowanych rekomendacji + przyszly podglad w panelu
Priorytet: P1 (blokuje sensowny seed rekomendacji)
Czas: ~1.5h CC
Typ: skrypt analityczny uruchamiany NA SERWERZE (MySQL=localhost serwera, niedostepny z Maca)

## Cel
Policzyc co sie FAKTYCZNIE sprzedaje per kategoria — fundament pod kuratorowane rekomendacje. Metryka (decyzja 148a): LICZBA ZAMOWIEN (distinct id_order) produktu z ostatnich 12 miesiecy, NIE przychod, NIE sztuki. Zrodlo: SQL bezposredni (decyzja 150a), NIE API PrestaShop.

## KROK 0. Read
- standalone/src/Tools/OrderStatus.php (wzorzec dostepu do pr_orders/pr_order_history/pr_order_state_lang — jak czytane sa zamowienia i statusy)
- standalone/src/Shop/MysqlProductEnrichmentService.php (wzorzec MysqlConnection, prefix pr_, id_shop=1)
- ADR-065 uzup.1 w _docs/10

## KROK 1. Ustal poprawne liczenie sprzedazy (KRYTYCZNE — tylko zrealizowane)
Sprzedaz = zamowienia ZREALIZOWANE, NIE koszyki/anulowane. W PrestaShop:
- pr_orders: id_order, current_state, date_add, valid (1=oplacone/zrealizowane wg PS)
- pr_order_detail: id_order, product_id, product_quantity, product_name
- pr_order_state_lang: nazwy statusow (id_lang — sprawdz ktory PL, jak OrderStatus)
DECYZJA do podjecia przez CC + raport: czy liczyc po `valid=1` (PS oznacza zamowienia oplacone/wazne) czy po whitelist konkretnych current_state (np. wyslane/dostarczone/oplacone), wykluczajac anulowane/zwrocone/oczekujace na platnosc. REKOMENDACJA: zacznij od `valid=1` (standard PS dla "policzalnej" sprzedazy), ale w raporcie pokaz tez liste wystepujacych current_state + ich nazwy, zebysmy zweryfikowali z Karolem czy filtr jest poprawny dla divezone (sklep moze miec customowe statusy).

## KROK 2. Mapowanie produkt -> kategoria
- Kategorie z pr_category_lang (id_lang PL). Produkt-kategoria: pr_category_product (id_product, id_category) LUB default category pr_product.id_category_default.
- DECYZJA CC + raport: uzyc id_category_default (jedna glowna kategoria/produkt — prostsze, ale produkt moze byc w kilku) czy pr_category_product (wszystkie). REKOMENDACJA: id_category_default dla top-N (jednoznaczne przypisanie), w raporcie zaznacz ograniczenie.
- Interesuja nas kategorie sprzetowe pod rekomendacje: automaty oddechowe, komputery nurkowe, maski, fajki, pletwy, pianki/skafandry, BCD/jackety, suche skafandry. CC zmapuje realne id_category z nazw (pokaze mapowanie w raporcie — nazwy kategorii w divezone moga sie roznic).

## KROK 3. Skrypt (PHP, uzywa MysqlConnection — NIE nowy plik produkcyjny, to narzedzie analityczne)
Lokalizacja: standalone/scripts/sales_report.php (lub _tools/ jesli taka konwencja — sprawdz). Skrypt:
- Parametr: liczba miesiecy wstecz (default 12), top N (default 15).
- Dla kazdej zmapowanej kategorii sprzetowej: SELECT product_id, product_name, COUNT(DISTINCT id_order) AS liczba_zamowien, SUM(product_quantity) AS sztuki (dodatkowo, dla kontekstu) FROM pr_order_detail JOIN pr_orders ... WHERE valid=1 AND date_add >= NOW() - INTERVAL 12 MONTH GROUP BY product_id ORDER BY liczba_zamowien DESC LIMIT N.
- Output: czytelny per kategoria (nazwa kategorii naglowek, potem tabela top N: product_id | nazwa | liczba zamowien | sztuki).
- Format: tekstowy do konsoli + zapis do pliku reports/sales_top_YYYYMMDD.txt (CC sciagnie zawartosc do raportu w czacie).

## KROK 4. Sprawdz przy okazji: czy Suunto Nautic jest w bazie
Karol chce polecac Suunto Nautic (nowosc). Sprawdz czy istnieje w pr_product (SELECT id_product, name FROM pr_product_lang WHERE name LIKE '%Nautic%' AND ...). Raport: jest/nie ma + product_id jesli jest. (Jesli nowosc nie zostala dodana do sklepu — to osobny temat dla Karola.)

## KROK 5. Uruchom na serwerze + raport
- Skrypt uruchamiany na prod (ea-php84, dostep do MySQL localhost). CC: scp skryptu + uruchomienie przez ssh, LUB jesli CC ma dostep ssh/run na serwerze — bezposrednio.
- Raport w czacie: per kategoria top 15 (product_id | nazwa | liczba zamowien 12mc | sztuki). Plus: lista wystepujacych current_state (do walidacji filtra), mapowanie kategorii, status Nautic.
- NIE seeduj jeszcze niczego — to tylko dane. Seed = osobny krok po analizie z Karolem.

## KROK 6. Git
git add standalone/scripts/sales_report.php (skrypt analityczny — commitowalny, bez PII; raporty reports/sales_top_*.txt do .gitignore jesli zawieraja nazwy/dane — sprawdz, raczej tylko produkty+liczby, ale dla pewnosci zignoruj).
commit: "T-030: skrypt analityczny sprzedazy (top N per kategoria, 12mc, liczba zamowien) — fundament re-seedu ADR-065"
push. Osobny commit docs: status.

## Out of scope
- Seed rekomendacji (osobny krok, po analizie danych z Karolem)
- Panel admina (osobny duzy strumien, planowany)
- Cykliczne uruchamianie (na razie ad-hoc; pozniej zrodlo dla panelu)

## Uwaga .gitignore
Jesli reports/sales_top_*.txt zawiera tylko product_id + nazwy produktow + liczby (bez danych klientow) — moze byc commitowalny, ale dla spojnosci z reszta reports/ raczej zignoruj. Decyzja CC + zaznacz w raporcie.
