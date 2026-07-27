# CHAT-T-166 — BACKEND — dług techniczny: guard w matchSize + nazwa brands_without_chart

**Data:** 2026-07-24 | **Instancja:** backend | **Karta:** Chat - 55
**Nawiązuje do:** CHAT-T-161, CHAT-T-163, ADR-099
**Świat wdrożeniowy:** WYŁĄCZNIE backend standalone `chat.divezone.pl`.
**Charakter:** hardening + poprawa nazewnictwa. Zero zmian w zachowaniu widocznym
dla klienta przy poprawnym użyciu.

---

## 1. PUNKT A — guard w `SizeRecommender::matchSize()` (defense-in-depth)

### 1.1 Problem (zweryfikowany na PROD 2026-07-23)

`matchSize()` liczy przecięcie przez `array_filter` z pętlą po `$dims`.
**Przy pustym `$dims` pętla nigdy nie zwraca `false`** → callback przepuszcza
każdy rozmiar → wynik = WSZYSTKIE rozmiary chartu (16 dla chartu 1).

Chroni to wyłącznie guard w `execute()` (linie 143-149), o poziom wyżej niż
niebezpieczna pętla. Dziś jedynym wołającym jest `execute()` i jest poprawny —
zweryfikowane wywołaniem na PROD: `['brand'=>'Scubapro','gender'=>'M']` bez wymiarów
zwraca błąd, nie listę 16 rozmiarów.

**Ryzyko:** nowy wołający `matchSize()` (np. przy CHAT-T-165, który rozszerza
narzędzie na rękawice, kaptury i buty) dostanie cichą listę wszystkich rozmiarów
zamiast błędu. Na charcie jednorozmiarowym (Tecline Proterm, chart 19) taki błąd
wygląda jak POPRAWNE `match [ML]` — dokładnie tak zamaskował się w teście K11 podczas
CHAT-T-163, zanim zażądałem surowego wejścia.

Zgłoszone przez CC w CHAT-T-161 jako propozycja, świadomie odłożone poza tamten zakres.

### 1.2 Zmiana

Na samym wejściu `matchSize()`, PRZED pętlą przecięcia:

```php
if ($dims === []) {
    return ['error' => '...'];  // treść jak w execute() linie 143-149
}
```

Guard w `execute()` **ZOSTAJE** — to celowa redundancja (defense-in-depth),
nie duplikat do usunięcia.

### 1.3 Ograniczenie

**NIE zmieniaj sygnatury, nazwy ani zachowania `matchSize()` przy niepustym `$dims`.**
Metoda jest wołana przez zweryfikowaną ścieżkę T-161. Jedyna zmiana to nowy warunek
na wejściu.

---

## 2. PUNKT B — mylące pole `brands_without_chart`

### 2.1 Problem (zweryfikowany na PROD 2026-07-24)

`find_wetsuits_by_measurements` zwraca pole `brands_without_chart`. Nazwa mówi
„marki bez tabeli rozmiarów". Realna zawartość z wywołania na PROD
(`gender=K, chest=95, height=175`):

```
["Aqua Zone","AQUALUNG","BARE","EQUES","FOURTH ELEMENT","MARES","Outlet",
 "SCUBAPRO","SCUBATECH","SSI","TUSA","TUSA  SPORT","Typhoon"]
```

**AQUALUNG, BARE, MARES i SCUBAPRO chart MAJĄ** — w tym samym wyniku pojawiają się
w `alternatives` z policzonymi rozmiarami (Bare `8T`, Mares `5`). W polu są dlatego,
że mają POJEDYNCZE modele bez mapowania.

Treść jest prawdziwa per model, ale nazwa sugeruje co innego. To dokładnie pułapka
opisana w `_docs/44`: **nazwa pola nie jest jego znaczeniem**. Model czytający
`brands_without_chart` może powiedzieć klientowi „nie mamy tabeli dla Bare",
co jest nieprawdą.

### 2.2 Zmiana

Zmień nazwę pola na `brands_with_unmapped_models` i dopisz w opisie narzędzia
(`getDescription()` lub opis pola), co dokładnie oznacza: marki, które mają
w kategorii MODELE bez przypisanej tabeli rozmiarów — niezależnie od tego,
czy marka ma tabelę dla innych modeli.

**Sprawdź, czy SystemPrompt odwołuje się do starej nazwy** (`grep -n
'brands_without_chart' src/Chat/SystemPrompt.php`) i zaktualizuj, jeśli tak.
Narzędzie jest świeże (wdrożone 2026-07-24), więc poza nim i promptem nie powinno
mieć konsumentów — **zweryfikuj to grepem, nie założeniem.**

---

## 3. KRYTERIA AKCEPTACJI

| # | wejście | oczekiwane |
|---:|---|---|
| 1 | `matchSize($sizes, [])` wołane BEZPOŚREDNIO | błąd, NIE lista rozmiarów |
| 2 | to samo na charcie 19 (jednorozmiarowy Tecline) | błąd, NIE `match [ML]` |
| 3 | `execute(['brand'=>'Scubapro','gender'=>'M'])` bez wymiarów | błąd jak dziś (regresja) |
| 4 | `recommend_wetsuit_size` z wymiarami, chart 1, `height=172` | `multiple [XS, MS]` bez zmian |
| 5 | `find_wetsuits_by_measurements` (K, 95, 175) | `brands_with_unmapped_models` zamiast starej nazwy |
| 6 | to samo | `alternatives` bez zmian: Scubapro, Bare `8T`, Aqualung, Mares `5` |
| 7 | grep starej nazwy w `src/` | zero trafień poza komentarzem historycznym |

Kryteria 3, 4 i 6 to REGRESJA po T-161 i T-163.

---

## 4. CZEGO NIE RUSZAĆ

- Guard w `execute()` (143-149) — ZOSTAJE, celowa redundancja
- Logika przecięcia przy niepustym `$dims`, sygnatura `matchSize`
- Wymóg płci, aliasy etykiet, `matchPointwise`
- Round-robin marek i kolejność `in_stock` → `available_to_order` (decyzja 16a)
- Reguły SystemPrompt z T-161/T-162/T-163/T-164
- `config/tools.php` (brak nowych klas), `config/routes.php`
- Moduł `newtmp2`, `_ops/newtmp2_root/purge_litespeed.php` (SEKRET)

---

## 5. KOLEJNOŚĆ WOBEC CHAT-T-165

**Ten task wykonaj PO CHAT-T-165** albo uzgodnij z architektem.
Oba dotykają `SizeRecommender.php`, a T-165 zmienia `MATCH_DIMS` i dodaje obsługę
`chart_type='tresciowy'`. Wykonanie równolegle w dwóch oknach = konflikt w tym samym
pliku. Punkt B (`FindWetsuitsByMeasurements`) jest niezależny i może iść wcześniej.

---

## 6. DEPLOY

```
standalone/src/Tools/SizeRecommender.php             →  ~/public_html/chat.divezone.pl/src/Tools/
standalone/src/Tools/FindWetsuitsByMeasurements.php  →  ~/public_html/chat.divezone.pl/src/Tools/
standalone/src/Chat/SystemPrompt.php                 →  TYLKO jeśli grep wykaże odwołanie do starej nazwy
```

**ZAKAZ blanket-rsync `standalone/`.** Backup `_deploy_bak/CHAT-T-166/`, md5 per plik,
`ea-php84 -l`, smoke `/api/health`. Bez `composer dump-autoload`.
**STOP przed rsync — czekaj na „deployuj" (ADR-089).** Zero migracji PG, zero cache PS.

---

## 7. RAPORT

`_docs/21_STATUS_PROJEKTU.md` NA GÓRZE. Raport z 7 kryteriami, surowy `tool_result`
dla 1, 2 i 5.
