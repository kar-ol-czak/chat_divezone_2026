# WALIDACJA GRUPY C: KONTROLA PŁYWALNOŚCI

**Walidator:** 15 lat TDI/IANTD + serwis sprzętu nurkowego
**Data walidacji:** 2026-02-27
**Wersja walidowanego JSON:** v2

---

## [1] JACKET — WERDYKT: PASS z uwagami

### Uwagi:
1. **[synonim/potoczne]** „kamizelka jacket" jako exact jest pleonazmem — klient albo mówi „jacket" albo „kamizelka", raczej nie skleja obu. Sugeruję przesunięcie do near.
2. **[synonim/brakujący]** Fraza „jacket nurkowy mares" (10/mies. DataForSEO) nie wymaga osobnego synonimu, ale warto dodać wzmiankę w uwagach_dla_ai, że klienci szukają po markach.
3. **[podtypy]** Brakuje podtypu „jacket z tylnym napompowaniem" (back-inflate jacket) — to hybryda między klasycznym jacketem a skrzydłem, sprzedawana przez SCUBAPRO i MARES. Klienci pytają o tę kategorię i mogą nie wiedzieć, czy to jacket czy wing.
4. **[merytoryczny]** Definicja poprawnie rozdziela jacket od skrzydła. Uwagi_dla_ai słusznie przestrzegają przed traktowaniem BCD = jacket. ✓

### Brakujące synonimy:
- (brak krytycznych braków — DataForSEO pokryte)

---

## [2] SKRZYDLO — WERDYKT: PASS z uwagami

### Uwagi:
1. **[synonim/brakujący]** Brakuje „skrzydło nurkowe" jako samodzielnej frazy w exact lub near — jest tylko „skrzydlo nurkowe wing" w potoczne. Sama fraza „skrzydło nurkowe" jest naturalnym wariantem, który klienci wpisują.
2. **[synonim/brakujący]** „Opona" — potoczne określenie donut wingów, obecne w v1, usunięte z v2. Nurkowie techniczni w Polsce mówią „opona" na donut wing (np. „jadę na oponie Tecline"). Sugeruję dodanie do potoczne.
3. **[bezpieczeństwo]** FAQ o doborze wyporności jest poprawne, ale mogłoby zawierać orientacyjne zakresy: single rekreacyjny 10–16 kg, single techniczny 14–20 kg, twin 18–30+ kg. Nie jako regułę, ale jako punkt wyjścia.
4. **[sklep]** TUSA jest w dozwolonych markach dla skrzydeł, ale nie jest wymieniona. Jeśli sklep nie prowadzi skrzydeł TUSA — OK. Jeśli prowadzi — należy dodać.

### Brakujące synonimy:
- „skrzydło nurkowe" (brak jako samodzielna fraza)
- „opona" (potoczne, usunięte z v1)

---

## [3] BACKPLATE — WERDYKT: PASS z uwagami

### Uwagi:
1. **[synonim/brakujący]** „Płyta" jako samodzielny near synonim — w v1 była, w v2 zniknęła. Nurkowie mówią „jadę na plycie stalowej", „jaką masz płytę?" — to powszechne skrócenie.
2. **[synonim/brakujący]** „Blacha" bez dookreślenia — w v2 jest „blacha do skrzydla" w potoczne, ale sama „blacha" jest równie powszechna.
3. **[sklep]** MARES (linia XR) produkuje backplate'y — jeśli sklep je prowadzi, marka powinna być wymieniona. Brak MARES może być celowy (brak w ofercie), ale warto zweryfikować.
4. **[podtypy]** „Plyta pod single z pasami" i „plyta pod twin z srubami" — technicalnie to nie podtypy produktu, tylko sposób użycia. Lepszym podziałem byłoby: stalowa, aluminiowa, standard, krótka, z otworami do trymówek.

### Brakujące synonimy:
- „płyta" (samodzielnie)
- „blacha" (samodzielnie)

---

## [4] UPRZAZ — WERDYKT: PASS z uwagami

### Uwagi:
1. **[synonim/brakujący]** „Szelki nurkowe" — obecne w v1, usunięte z v2. To naturalny sposób, w jaki klienci piszą. Sugeruję dodanie do near.
2. **[merytoryczny]** Podtyp „uprzaz z szybkozrzuconymi klamrami" — w kontekście DIR/GUE jest to kontrowersyjne (szybkowypinane klamry na ramieniu są krytykowane w nurkowaniu technicznym). Warto dodać notę, że to podtyp rekreacyjny/komfortowy.
3. **[kompletność]** FAQ mogłoby zawierać pytanie o ciągłą taśmę (continuous webbing) vs regulowaną — to jedno z najczęstszych pytań kupujących uprząż.

### Brakujące synonimy:
- „szelki nurkowe"
- „uprząż nurkowa" (bez „harness" — brak samodzielnej polskiej frazy)

---

## [5] ZESTAW_SKRZYDLO_SINGLE — WERDYKT: PASS z uwagami

### Uwagi:
1. **[sklep]** MARES (XR line) produkuje zestawy BPW — jeśli sklep prowadzi, warto dodać.
2. **[kompletność]** FAQ „Skrzydlo nurkowe wing czy jacket nurkowy" jest świetne i trafia w realną potrzebę klientów. ✓
3. **[bezpieczeństwo]** Uwaga o niedopasowaniu zestawu single do twina jest krytyczna i dobrze sformułowana. ✓
4. **[parametry]** Brakuje parametru „kompatybilność inflatora w zestawie" — klienci powinni wiedzieć, czy zestaw zawiera inflator czy trzeba dokupić osobno.

### Brakujące synonimy:
- (brak krytycznych braków)

---

## [6] ZESTAW_SKRZYDLO_TWIN — WERDYKT: PASS z uwagami

### Uwagi:
1. **[bezpieczeństwo]** FAQ o wyporności mówi „zwykle potrzeba wyraznie wiekszej wypornosci niz do single" — to za mało. Sugeruję dodanie: „dla typowego twinsetu 2×12L w suchym skafandrze z butlami stage zwykle potrzeba minimum 20–25 kg lift, a do konfiguracjin z wieloma stage'ami i eksploracyjnych jeszcze więcej." Obecne sformułowanie nie chroni przed zamówieniem winga 16 kg do ciężkiego twina.
2. **[sklep]** Jak wyżej — MARES XR mogą mieć zestawy twin w ofercie.
3. **[podtypy]** Brakuje podtypu „zestaw twin eksploracyjny / jaskiniowy" — to inna kategoria wymagań (redundancja, większy lift, wzmocnione materiały).

### Brakujące synonimy:
- (brak krytycznych braków)

---

## [7] INFLATOR — WERDYKT: PASS z uwagami

### Uwagi:
1. **[synonim/brakujący]** „LPI" jako samodzielny near synonim w polskiej sekcji — nurkowie w Polsce używają skrótu LPI (Low Pressure Inflator). Jest w EN near jako „LPI inflator", ale w PL brakuje samego „LPI".
2. **[synonim/brakujący]** „Zawór dodawczo-upustowy" z v1 — technicznie bardziej precyzyjne niż „zawór dodawczy" z archaicznych, bo inflator ma OBE funkcje. Sugeruję dodanie do near.
3. **[merytoryczny]** Definicja dobrze rozdziela inflator od węża inflatora i od węża karbowanego. ✓
4. **[bezpieczeństwo]** Poprawnie oznaczony jako element newralgiczny bezpieczeństwa. ✓

### Brakujące synonimy:
- „LPI" (potoczne / near w sekcji PL)
- „zawór dodawczo-upustowy" (near)

---

## [8] WAZ_INFLATORA — WERDYKT: PASS z uwagami

### Uwagi:
1. **[sklep]** Tylko 2 marki (SCUBAPRO, TECLINE) — to bardzo mało. Węże inflatora produkują też MARES, HOLLIS. Jeśli sklep ma je w ofercie, lista jest niekompletna. Jeśli nie — OK, ale wygląda podejrzanie.
2. **[kompletność]** Powiązane produkty mogłyby zawierać odniesienie do automatu/pierwszego stopnia (to z niego wychodzi wąż LP), ale automaty mogą być w innej grupie.
3. **[disambiguation]** Uwagi_dla_ai poprawnie wskazują na najczęstszą pomyłkę klientów (wąż LP vs wąż karbowany). ✓

### Brakujące synonimy:
- (brak — DataForSEO fraza „wąż do inflatora" 20/mies. jest w exact ✓)

---

## [9] WAZ_KARBOWANY — WERDYKT: PASS z uwagami

### Uwagi:
1. **[synonim/ryzyko]** „Wąż do inflatora" w potoczne — to ta sama fraza co exact synonim WAZ_INFLATORA. Dokumentuje realne zachowanie klientów, ale w systemie automatycznym może powodować kolizję mapowania. Sugeruję oznaczenie tej frazy flagą „disambiguation_required" lub odpowiednim tagiem, żeby AI zawsze dopytywał.
2. **[encoding]** Ten wpis (i kolejne 9–16) używa polskich znaków diakrytycznych, podczas gdy wpisy 1–8 są w ASCII. Niespójność kodowania — do normalizacji przed produkcją.
3. **[merytoryczny]** Definicja technicznie poprawna, dobrze wyjaśnia różnicę od węża LP. ✓

### Brakujące synonimy:
- (brak krytycznych braków)

---

## [10] DUMP_VALVE — WERDYKT: FAIL

### Błędy:

1. **[merytoryczny]** Concept ID „DUMP_VALVE" jest generyczny, ale definicja dotyczy WYŁĄCZNIE zaworu upustowego suchego skafandra. Tymczasem inne wpisy (INFLATOR, WAZ_KARBOWANY, ZESTAW_SKRZYDLO_TWIN, SKRZYDLO) odwołują się do DUMP_VALVE jako elementu BCD (skrzydła/jacketu). **Powstaje niespójność krzyżowych referencji** — klient szukający zaworu upustowego do skrzydła zostanie skierowany na zawór do suchego skafandra.

   → **Poprawka:** Albo (a) rozszerzyć definicję o podtypy: zawór upustowy BCD i zawór upustowy suchego skafandra (jako dwa osobne podtypy z różnymi parametrami zakupowymi), albo (b) stworzyć dwa osobne koncepty: DUMP_VALVE_BCD i DUMP_VALVE_DRYSUIT, i zaktualizować wszystkie krzyżowe referencje.

2. **[merytoryczny]** Samoodwołanie w nie_mylic_z — DUMP_VALVE odwołuje się do konceptu „DUMP_VALVE" w swoim własnym nie_mylic_z:
   ```json
   {"concept": "DUMP_VALVE", "dlaczego": "Klienci czasem mają na myśli zawór upustowy BCD..."}
   ```
   To błąd logiczny — koncept nie może ostrzegać przed myleniem sam ze sobą.

   → **Poprawka:** Usunąć samoodwołanie. Zamiast tego dodać np. `{"concept": "INFLATOR", "dlaczego": "Inflator dodaje gaz do BCD, a dump valve go upuszcza — to różne funkcje."}` (aktualnie jest, ale samoodwołanie musi zniknąć).

3. **[sklep/powiazane]** Powiązane produkty (BALAST, TRYMOWKA, SIDEMOUNT, JACKET, SKRZYDLO) nie zawierają ŻADNEGO produktu z kategorii suchych skafandrów. Jeśli koncept dotyczy suchego skafandra, powiązany powinien być suchy skafander, inflator suchego skafandra i ewentualnie uszczelki/serwis.

   → **Poprawka:** Dodać do powiązanych: SUCHY_SKAFANDER (lub odpowiedni ID z grupy ekspozycji termicznej), INFLATOR_SUCHEGO_SKAFANDRA (jeśli istnieje osobny koncept), klej/uszczelki do skafandra.

4. **[bezpieczeństwo]** Zawór upustowy suchego skafandra i zawór upustowy BCD to RÓŻNE produkty o różnych montażach, różnych średnicach i różnych zasadach działania. Klient, który zamówi zawór „dump valve" bez doprecyzowania, może otrzymać niekompatybilny element. Definicja nie zawiera wystarczającego ostrzeżenia o tym ryzyku na poziomie nie_mylic_z.

   → **Poprawka:** Jeśli koncept pozostaje jednolity, dodać w nie_mylic_z wyraźne rozróżnienie typu: „Zawór upustowy BCD (na skrzydle/jackecie) i zawór upustowy suchego skafandra to RÓŻNE produkty o innym montażu i funkcji. Zawsze dopytaj czy chodzi o BCD czy o suchy skafander."

5. **[FAQ]** Oba pytania FAQ zaczynają się od „Inflator nurkowy..." — to pytania o inflator, nie o dump valve. FAQ powinno odpowiadać na pytania o sam zawór upustowy.

   → **Poprawka:** Zmienić na pytania typu: „Zawór upustowy suchego skafandra — jak dobrać do mojego skafandra?", „Czy zawór upustowy do suchego skafandra jest uniwersalny?"

---

## [11] SIDEMOUNT — WERDYKT: PASS z uwagami

### Uwagi:
1. **[synonim/DataForSEO]** „sidemount apeks" (10/mies.) — Apeks nie jest na liście dozwolonych marek dla skrzydeł/jacketów, ale klienci tego szukają. Warto w uwagach_dla_ai dodać: „Jeśli klient pyta o Apeks sidemount, zaproponuj dostępne alternatywy (xDEEP, HOLLIS, TECLINE)."
2. **[sklep]** TUSA w markach sidemount — weryfikuję: TUSA nie jest znana z systemów sidemount. Jeśli sklep nie prowadzi sidemount TUSA, usunąć z listy.
3. **[kompletność]** FAQ o kursie sidemount jest dobrym uzupełnieniem. ✓
4. **[merytoryczny]** Poprawne traktowanie sidemount jako konfiguracji/systemu, a nie części. ✓

### Brakujące synonimy:
- (brak krytycznych braków — DataForSEO frazy pokryte)

---

## [12] BALAST — WERDYKT: PASS z uwagami

### Uwagi:
1. **[synonim/klasyfikacja]** „Ołowiane kafle" i „kafelki" są w archaiczne, ale to AKTYWNY slang, nie archaizm. Nurkowie nadal mówią „kafle" i „kafelki" na ciężarki — to potoczne, nie archaiczne. Sugeruję przesunięcie do potoczne.
2. **[nie_mylic_z]** Brakuje BACKPLATE — w v1 było, w v2 usunięte. Klienci mylą ciężką płytę stalową z balastem (pytanie „czy ta blacha 3 kg zastąpi mi ciężarki?"). BACKPLATE ma BALAST w swoim nie_mylic_z, ale powinno być dwukierunkowo.
3. **[bezpieczeństwo]** Uwagi o szybkim zrzucie balastu prawidłowe. ✓

### Brakujące synonimy:
- „kafle" (przesunąć z archaiczne do potoczne)
- „kafelki" (przesunąć z archaiczne do potoczne)

---

## [13] PAS_BALASTOWY — WERDYKT: PASS

Definicja technicznie poprawna. Synonimy kompletne. Podtypy obejmują kluczowe warianty (taśmowy, gumowy Marseille). Bezpieczeństwo — klamra szybkowyczepna i jednoręczna obsługa podkreślone. Marki dozwolone. FAQ adresuje realne pytania.

### Brakujące synonimy:
- (brak)

---

## [14] KIESZENIE_ZINTEGROWANE — WERDYKT: PASS

Definicja poprawna. Wyraźnie odróżniona od balastu i pasa. Podtypy kompletne (szybkozrzutowe, stałe, trymowe, DIR). Parametry zakupowe uwzględniają kompatybilność z BCD — kluczowy punkt. FAQ realistyczne.

### Brakujące synonimy:
- (brak)

---

## [15] TRYMOWKA — WERDYKT: PASS

Definicja prawidłowa — precyzyjne rozróżnienie od balastu głównego. Uwagi_dla_ai poprawnie ostrzegają przed myleniem trymówki (ciężarek) z kieszenią trymową (nośnik). Marki realistyczne.

### Brakujące synonimy:
- (brak)

---

## [16] SZELKI_STAGE — WERDYKT: PASS

Definicja poprawna — wyraźnie odróżniona od sidemount i od uprzęży. Podtypy odpowiednie. Parametry uwzględniają średnicę butli i typ karabinków. FAQ dobrze adresuje częste pytanie „czy do sidemount potrzebuję szelek stage".

### Brakujące synonimy:
- (brak)

---

# PODSUMOWANIE

| Werdykt | Ilość | Koncepty |
|---------|-------|---------|
| **PASS** | 4 | PAS_BALASTOWY, KIESZENIE_ZINTEGROWANE, TRYMOWKA, SZELKI_STAGE |
| **PASS z uwagami** | 11 | JACKET, SKRZYDLO, BACKPLATE, UPRZAZ, ZESTAW_SKRZYDLO_SINGLE, ZESTAW_SKRZYDLO_TWIN, INFLATOR, WAZ_INFLATORA, WAZ_KARBOWANY, SIDEMOUNT, BALAST |
| **FAIL** | 1 | DUMP_VALVE |

**Łącznie: 4 PASS, 11 PASS z uwagami, 1 FAIL**

---

## UWAGI PRZEKROJOWE

### 1. Niespójność kodowania znaków
Wpisy 1–8 (JACKET → WAZ_INFLATORA) używają ASCII bez polskich znaków diakrytycznych. Wpisy 9–16 (WAZ_KARBOWANY → SZELKI_STAGE) używają pełnych polskich znaków (ą, ę, ó, ś, ź, ż, ć, ł, ń). **Do normalizacji przed produkcją** — sugeruję ujednolicenie do pełnych polskich znaków we wszystkich polach tekstowych.

### 2. Reguła DIN vs INT
Żaden z 16 konceptów grupy C nie odnosi się do standardu przyłącza DIN/INT — to poprawne, ponieważ grupa dotyczy kontroli pływalności, nie automatów. Reguła nie jest naruszona. ✓

### 3. Bezpieczeństwo — dobór liftu
Kluczowa reguła „nie polecaj winga 16 kg do twinsetu 2×12L ze skafandrem suchym" jest adresowana w uwagach_dla_ai w SKRZYDLO, ZESTAW_SKRZYDLO_SINGLE i ZESTAW_SKRZYDLO_TWIN, ale **brakuje konkretnych zakresów orientacyjnych**. Sugeruję dodanie tabeli referencyjnej w uwagach_dla_ai przynajmniej w ZESTAW_SKRZYDLO_TWIN.

### 4. Kolizja fraz „wąż do inflatora"
Fraza „wąż do inflatora" jest exact synonimem WAZ_INFLATORA i jednocześnie potocznym synonimem WAZ_KARBOWANY. Oba wpisy poprawnie to dokumentują i mają uwagi o disambiguation, ale system automatyczny musi mieć jasną regułę rozstrzygania — domyślnie kierować na WAZ_INFLATORA (cieńszy wąż LP), a dopytywać o wąż karbowany.

### 5. DUMP_VALVE — priorytet naprawy
Jedyny FAIL w grupie. Wymaga decyzji architektonicznej: jeden koncept z podtypami BCD/drysuit, czy dwa osobne koncepty. Rekomendacja: **dwa osobne** (DUMP_VALVE_BCD i DUMP_VALVE_DRYSUIT), ponieważ to zupełnie inne produkty o różnym montażu, różnych parametrach i różnych kontekstach zakupowych.