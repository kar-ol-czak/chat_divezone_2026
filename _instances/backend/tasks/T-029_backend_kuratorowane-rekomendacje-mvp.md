# T-029: Kuratorowane rekomendacje produktowe — MVP (ADR-065)

Instancja: backend
Powiazane: ADR-065 (model 3-warstwowy, decyzje 129 MVP/130a PG/131a), 140b (2 reguly doboru jako pierwszy seed), pytania klientow
Priorytet: P1 (zmienia bota z wyszukiwarki w doradce — najczestsze pytania to dobor)
Czas: ~4h CC
Pliki: nowa tabela PG, nowe narzedzie standalone/src/Tools/CuratedRecommendations.php, standalone/config/tools.php, SystemPrompt.php (instrukcja kiedy siegac)

## Cel (ADR-065)
Warstwa miedzy zywa baza a wiedza zespolu: kategoria -> 1-3 RECZNIE wybrane produkty + uzasadnienie. Bot przy pytaniu o dobor ("jaki komputer na start", "automat na Malediwy") siega po kuratorowana liste, NIE zgaduje. Cena/dostepnosc z zywego MySQL.

## KROK 0. Read
- ADR-065 w _docs/10 (caly model 3-warstwowy + 2 mechanizmy staleness)
- standalone/src/Tools/ShippingInfo.php (wzorzec narzedzia z $pg: getName/getDescription/getParametersSchema/execute)
- standalone/src/Tools/ProductSearch.php linie 959+ (enrichWithMySQLData — zwraca id_product, price_netto, tax_rate, quantity, availability, active, visibility)
- standalone/config/tools.php (rejestracja narzedzi)

## KROK 1. DECYZJA ARCHITEKTONICZNA — enrichWithMySQLData jest PRIVATE w ProductSearch
Nowe narzedzie potrzebuje tych samych zywych danych MySQL. enrichWithMySQLData jest prywatne. Opcje (wybierz i uzasadnij w handoffie):
- a) Wydziel do wspoldzielonego serwisu MysqlEnrichmentService (czyste, ale ruszasz ProductSearch — ryzyko regresji, przetestuj search po zmianie).
- b) Nowe narzedzie ma wlasna, mniejsza metode enrich (tylko potrzebne pola) — duplikacja, ale izolacja (zero ryzyka dla ProductSearch).
REKOMENDACJA: a) jesli wydzielenie jest czyste (metoda nie zalezy od stanu ProductSearch); b) jesli ProductSearch mocno spleciony. Architekt preferuje a) dlugoterminowo, ale NIE kosztem regresji search. Pokaz decyzje w KROK STOP.

## KROK 2. Tabela PostgreSQL (decyzja 130a)
divechat_curated_recommendations:
- id SERIAL PK
- category_key VARCHAR (np. 'computer_beginner', 'regulator_by_destination_global', 'regulator_by_destination_europe')
- category_label_pl TEXT (opis DLA BOTA: kiedy ta kategoria pasuje — bot czyta to przy wyborze)
- product_id INTEGER (-> pr_product.id_product przez MySQL enrichment)
- priority SMALLINT (1-3, kolejnosc prezentacji)
- rationale_pl TEXT (czemu polecamy — bot pokazuje klientowi)
- verified_at TIMESTAMPTZ (ostatnia weryfikacja ekspercka)
- recheck_interval_days INTEGER (30/90/180/365)
- active BOOLEAN DEFAULT true
- created_at, updated_at TIMESTAMPTZ
Indeks: (category_key, active). Migracja jako plik SQL + wykonanie na Railway (Karol uruchomi lub CC przez DATABASE_URL — potwierdz w STOP kto).

## KROK 3. Narzedzie CuratedRecommendations.php
- getName: 'get_curated_recommendations'
- getDescription: jasno KIEDY uzywac — "gdy klient prosi o DOBOR/POLECENIE produktu w kategorii gdzie liczy sie osad ekspercki (jaki komputer na start, automat na egzotyke, pianka w nietypowym rozmiarze). NIE do wyszukiwania konkretnego modelu (to search_products)."
- getParametersSchema: param 'category' (enum z dostepnych category_key + opisy z category_label_pl, zeby LLM sam dopasowal) LUB free-text query klasyfikowany do kategorii. REKOMENDACJA: enum kategorii — deterministyczne, LLM wybiera z listy.
- execute:
  1. SELECT z divechat_curated_recommendations WHERE category_key=? AND active=true ORDER BY priority.
  2. enrich product_id przez MySQL (KROK 1) — cena, availability, active.
  3. TWARDY staleness: produkt nieaktywny/nieistniejacy w MySQL (active=0 lub brak) -> POMIN + zaloguj warning (kandydat do alertu, ale MVP bez alertu).
  4. Filtr dostepnosci: zwroc dostepne (in_stock / available_to_order) z cena+rationale. Niedostepne pomin lub oznacz.
  5. Jesli ZERO dostepnych -> zwroc sygnal "brak_dostepnych" zeby bot zrobil fallback redirect ("sprawdzmy dostepnosc, najlepiej kontakt").
- Zwroc tez metadane: category_label, ile skuratorowanych vs ile dostepnych.

## KROK 4. Rejestracja + SystemPrompt
- tools.php: $registry->register(new CuratedRecommendations($pg));
- SystemPrompt: krotka instrukcja w sekcji planowania — kiedy bot ma siegac po get_curated_recommendations vs search_products vs get_expert_knowledge. Kluczowe rozgraniczenie: ekspert (wiedza ogolna) / curated (co MY polecamy) / search (konkretny model). NIE rozdmuchuj promptu — kilka zdan.

## KROK 5. SEED — pierwsze kategorie (decyzja 140b + pliki)
Wstaw recznie (SQL INSERT) startowe kategorie z realnej wiedzy zespolu:
- regulator_by_destination_global (Karaiby/Azja/Oceania -> Scubapro/Aqualung, globalny serwis) — rationale + 1-3 realne product_id ze sklepu
- regulator_by_destination_europe (Europa/Srodziemne/Czerwone -> Apeks/Techline)
- computer_beginner (kolorowy wyswietlacz, nie najtanszy)
UWAGA: product_id MUSZA byc realne — Karol poda konkretne modele/SKU, albo CC znajdzie przez search w bazie i potwierdzi z Karolem w STOP. NIE wstawiaj zmyslonych ID. Jesli nie ma pewnych product_id — zostaw kategorie z pustym seedem i zaznacz do uzupelnienia.

## KROK 6. STOP — review (przed jakimkolwiek seedem produktow)
Pokaz: decyzja KROK 1 (a/b + czemu), schemat tabeli, kod narzedzia (execute), instrukcja SystemPrompt, PROPOZYCJA seedu (kategorie + ktore product_id — do potwierdzenia przez Karola, bo to realne produkty). NIE deploy, NIE seed bez akceptacji product_id.

## KROK 7. Deploy + git (po akceptacji)
Migracja na Railway, scp narzedzia + tools.php + SystemPrompt, php -l prod, md5. Test: zapytaj dev endpoint "jaki komputer na start" -> czy woła get_curated_recommendations.
commit: "T-029: kuratorowane rekomendacje MVP (tabela PG + narzedzie + seed startowy) — ADR-065"
push. Osobny commit docs: status + ADR-065 status Zaakceptowany->Wdrozony MVP.

## KROK 8. Raport
Handoff: decyzja enrich (a/b), ile kategorii zaseedowano, czego brakuje (product_id do uzupelnienia), jak bot reaguje w tescie. Zaznacz: panel admina + cron staleness = PELNA wersja, osobny task pozniej (ADR-065). Update _docs/21.

## Out of scope (PELNA wersja, pozniej)
- Panel admina z autocomplete (CRUD kategorii/produktow)
- Cron staleness: miekki (interwal verified_at) z powiadomieniami
- Indeks wektorowy encyklopedii (osobna optymalizacja, gdy baza urosnie)
