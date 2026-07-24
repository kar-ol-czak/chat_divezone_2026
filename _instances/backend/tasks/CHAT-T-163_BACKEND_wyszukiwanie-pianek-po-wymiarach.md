# CHAT-T-163 — BACKEND — wyszukiwanie pianek po wymiarach klienta (przeliczanie rozmiaru między markami)

**Data:** 2026-07-24 | **Instancja:** backend | **Nawiązuje do:** CHAT-T-161, CHAT-T-162 (oba wdrożone), ADR-099
**Świat wdrożeniowy:** WYŁĄCZNIE backend standalone `chat.divezone.pl`. Moduł PS nietknięty.
**Źródło potrzeby:** Karol, sesja 2026-07-24 (dwa scenariusze) + recenzja 394

---

## 1. PROBLEM

Dziś bot potrafi tylko: „podaj produkt → policzę rozmiar". Nie potrafi odwrotnie:
„mam takie wymiary → co mi pasuje i jest dostępne".

Dwa scenariusze zgłoszone przez Karola:

1. Klient wskazuje piankę, brak jego rozmiaru na stanie lub brak w tabeli.
   Bot ma znaleźć piankę o tych samych parametrach (grubość, długość, płeć)
   innego producenta, pasującą wymiarowo.
2. Klient chce piankę o określonych wymiarach. Bot ma pokazać najpierw dostępne
   od ręki, potem te na zamówienie.

**Kluczowa trudność (Karol):** rozmiary nazwowo NIE są porównywalne między
producentami. Potrzebne przeliczenie PRZED wyszukiwaniem.

### 1.1 Dowód nieporównywalności (pomiar PROD 2026-07-24)

Ta sama klientka, `chest=95`, `height=175`, `gender=K`:

| marka | rozmiar |
|---|---|
| Aqualung | L, ML, MT, LT, XL, XLS |
| Bare | **8T** |
| Mares | **5** |
| Scubapro | L, LT |
| Tecline Proterm | ML |

Bare i Mares używają numerów. „L" nie znaczy tego samego u dwóch producentów.

**Wniosek metodyczny: NIE budujemy tabeli przeliczeniowej etykiet.**
Przepuszczamy wymiary klienta przez chart każdej marki OSOBNO. Wynik to
deterministyczne przecięcie (ta sama logika co `SizeRecommender`), nie mapowanie L→8T.

### 1.2 Co jest w danych (pomiar PROD 2026-07-24)

Marki z chartem skafandrowym progowym: **Aqualung, Bare, Mares, Scubapro
(M/K/DZIECI), Tecline Proterm (UNISEX)**.

Kategorie 337+367, `active=1`: **114 produktów, 75 z chartem, 39 bez** (39, nie 36
jak 2026-07-23 — po ATTR-T-057 doszły 3 pianki dziecięce).

Pokrycie etykiet chart→warianty sklepu:

| marka | etykiet w charcie | ma odpowiednik w `pr_attribute_lang` |
|---|---:|---:|
| Aqualung | 15 | 15 |
| Bare | 30 | **27** |
| Mares | 8 | 8 |
| Scubapro | 17 | 17 |
| Tecline Proterm | 6 | 6 |

3 etykiety Bare bez bezpośredniego odpowiednika → `divezone_attr_size_label_alias`
(72 wpisy, `SizeRecommender::loadAliases()` już z niej korzysta).

Cechy strukturalne (`pr_feature_product`), wartości są TEKSTEM:

- **Grubość neoprenu** (73 produkty): `5 mm`(23), `7 mm`(21), `3 mm`(11), `2 mm`(5),
  `2+3mm`(4), `6 mm`(3), `2,5 mm`(3), `lycra`(2), `Lycra / piżama przeciwsłoneczna`(1)
- **Długość pianki** (75): `Długa`(56), `Shorty`(19)
- **Damska / Męska** (78)

---

## 2. DECYZJE KAROLA (2026-07-24)

| # | pytanie | decyzja |
|---|---|---|
| 15 | co to „pasująca alternatywa" | **b)** grubość ORAZ długość muszą się zgadzać, płeć obowiązkowo |
| 16 | kolejność przy braku rozmiaru | **a)** najpierw TEN SAM model na zamówienie, potem inne marki od ręki |
| 17 | czy podawać rozmiar innej marki | **b)** tak, z jawną adnotacją o innym oznaczeniu |
| 18 | źródło dostępności | **a)** `availability` z enrichmentu, `quantity` IGNOROWANE |

**Uzasadnienie 18a (pomiar):** prototyp na PROD zwrócił `quantity=0` dla wszystkich
wariantów pianek, a inne zapytanie `quantity=500` (wartość domyślna). `quantity`
na wariantach jest niewiarygodne. `availability` z `MysqlProductEnrichmentService`
to jedno źródło prawdy od CHAT-T-062.

---

## 3. NOWE NARZĘDZIE `find_wetsuits_by_measurements`

Nowa klasa `standalone/src/Tools/FindWetsuitsByMeasurements.php`.
Wzoruj się na `SizeRecommender.php` (przecięcie, aliasy) i `ProductSearch.php`
(enrichment, availability).

### 3.1 Parametry

| parametr | typ | wymagany | opis |
|---|---|---|---|
| `gender` | enum M/K/DZIECI | **TAK** | twarda reguła, jak w `SizeRecommender` |
| `chest`,`waist`,`hip`,`height`,`weight` | number | nie, ale >=1 | wymiary równocenne (CHAT-T-161) |
| `thickness` | string | nie | dokładna wartość cechy, np. `5 mm` |
| `length` | enum `Długa`/`Shorty` | nie | |
| `reference_product_id` | int | nie | gdy klient wyszedł od konkretnego modelu |

**Guard na pusty zbiór wymiarów OBOWIĄZKOWY na wejściu `execute()`** — pusty
zbiór w przecięciu zwraca wszystko (pułapka z `_docs/44`).

### 3.2 Algorytm

**KROK 1 — przelicz rozmiar w każdej marce osobno.**
Dla każdego chartu (`chart_type='progowy'`, `category_hint='skafander'`,
`gender` = podana lub `UNISEX`): przecięcie wymiarów klienta.
Przecięcie liczy SQL, `HAVING COUNT(DISTINCT dimension) = <liczba podanych>`.
NIE licz w PHP przez zestawianie wyników (pułapka z `_docs/44`).

**KROK 2 — znajdź produkty tej marki z pasującą etykietą wariantu.**
`al.name = r.size_label`, a przy braku trafienia przez
`divezone_attr_size_label_alias` (3 etykiety Bare tego wymagają).

**KROK 3 — filtry cech (decyzja 15b).**
Gdy `thickness` podane: dokładne dopasowanie wartości cechy „Grubość neoprenu".
**Porównanie TEKSTOWE, bez parsowania na liczby** — `2+3mm`, `2,5 mm`, `lycra`
rozsypią każdy parser. Analogicznie „Długość pianki".
Płeć: cecha „Damska / Męska" musi być zgodna z `gender`.

**KROK 4 — dostępność (decyzja 18a).**
`MysqlProductEnrichmentService::enrich()`. Sortowanie:
`in_stock` → `available_to_order` → `unavailable` (te ostatnie POMIŃ w wyniku).

**KROK 5 — kolejność wyniku (decyzja 16a).**
Gdy podano `reference_product_id`:
1. **ten sam produkt** w innym rozmiarze, także `available_to_order` (sekcja `same_model`)
2. inne marki `in_stock` (sekcja `alternatives`)
3. inne marki `available_to_order`

Bez `reference_product_id`: sam `alternatives`, `in_stock` przed `available_to_order`.

### 3.3 Zwrotka

```
[
  'same_model' => [ ['id','name','size_label','availability','price','url'], ... ],
  'alternatives' => [ ['id','name','brand','size_label','availability','price','url',
                       'thickness','length'], ... ],
  'measurements_used' => ['chest'=>95,'height'=>175],
  'brands_without_chart' => ['TUSA','Typhoon', ...]
]
```

`brands_without_chart`: marki obecne w kategoriach 337/367, dla których nie ma
chartu (39 produktów). Model ma o nich wspomnieć jako „są też inne modele,
ale rozmiar trzeba potwierdzić telefonicznie" — NIE ukrywać ich istnienia.

**Limit: max 8 pozycji w `alternatives`.**

---

## 4. SYSTEMPROMPT — reguła prezentacji (decyzja 17b)

Do sekcji DOBÓR ROZMIARU, po regule SPRAWDŹ ZANIM ZAPYTASZ:

> ALTERNATYWY INNEJ MARKI: gdy podajesz rozmiar produktu innej marki niż ta,
> o którą klient pytał, ZAWSZE dodaj, że to oznaczenie tego producenta i różni się
> od poprzedniego (np. „u Bare ten sam wymiar to rozmiar 8T"). Rozmiar jest
> policzony z tabeli tej marki, więc jest tak samo wiarygodny — ale bez tego
> zdania klient uzna różnicę oznaczeń za błąd.
> KOLEJNOŚĆ: najpierw ten sam model na zamówienie, dopiero potem inne marki.
> Klient wybrał model świadomie.

---

## 5. KRYTERIA AKCEPTACJI

| # | wejście | oczekiwane |
|---:|---|---|
| 1 | `gender=K, chest=95, height=175` | wynik zawiera Bare `8T` i Mares `5`, nie tylko litery |
| 2 | to samo + `thickness='5 mm'` | tylko produkty 5 mm |
| 3 | to samo + `length='Shorty'` | tylko shorty |
| 4 | `gender=K` bez wymiarów | **błąd**, nie lista wszystkiego (guard) |
| 5 | `reference_product_id` pianki bez rozmiaru klienta | `same_model` PRZED `alternatives` |
| 6 | produkt `unavailable` | **nieobecny** w wyniku |
| 7 | `thickness='2+3mm'` | działa, nie wywala się na parsowaniu |
| 8 | `gender=DZIECI` | brak wyników (po ATTR-T-057 pianki dziecięce bez chartu), błąd czytelny |
| 9 | wynik zawiera `brands_without_chart` | niepuste, zawiera TUSA |
| 10 | rozmiar policzony przez to narzędzie dla marki X | **identyczny** z `recommend_wetsuit_size` dla produktu marki X |

Kryterium 10 jest najważniejsze: dwie ścieżki liczące rozmiar NIE MOGĄ dać różnych
wyników. Jeśli dadzą, jedna z nich ma błąd.

---

## 6. CZEGO NIE RUSZAĆ

- **`SizeRecommender.php`** — wdrożone i zweryfikowane (T-161). Jeśli chcesz
  współdzielić logikę przecięcia, WYDZIEL ją, nie modyfikuj zachowania.
- `ProductDetails.php`, `SystemPrompt.php` — świeżo wdrożone (T-162), tylko dopisek z §4
- **Uzupełnianie chartów** (TUSA, Typhoon, SCUBATECH) — projekt atrybutów, cudza karta
- `config/routes.php` (dryf), moduł `newtmp2`, `_ops/newtmp2_root/purge_litespeed.php` (SEKRET)
- `MysqlProductEnrichmentService`, logika cen

**`config/tools.php`:** nowe narzędzie wymaga rejestracji, ale plik ma dryf repo↔prod
(ADR-131, `_docs/44`). **NIE rób blanket-rsync.** Deploy wariantem „prod + nowe linie":
pobierz wersję produkcyjną, dodaj `use` i `register`, wypchnij. STOP przed tym krokiem.

---

## 7. DEPLOY

| plik | uwaga |
|---|---|
| `standalone/src/Tools/FindWetsuitsByMeasurements.php` | **nowa klasa** → wymaga `composer dump-autoload` na serwerze |
| `standalone/config/tools.php` | wariant „prod + nowe linie", NIE nadpisanie |
| `standalone/src/Chat/SystemPrompt.php` | dopisek z §4 |

Backup `_deploy_bak/CHAT-T-163/`, rsync per ścieżka, md5 ×3, `ea-php84 -l` ×3,
smoke `/api/health`, weryfikacja że narzędzie jest w rejestrze.
**STOP przed rsync — czekaj na „deployuj" (ADR-089).**
Zero migracji PG. Zero cache PS.

---

## 8. RAPORT

`_docs/21_STATUS_PROJEKTU.md` NA GÓRZE. Raport z 10 kryteriami, surowy `tool_result`
dla 1, 5 i 10.
