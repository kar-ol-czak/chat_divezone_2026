# 33. Fizyka doboru wyporności worka (skrzydła/BCD). Źródła i wyliczenia

**Data:** 2026-06-08
**Autor:** architekt czatu (Claude Opus 4.8), weryfikacja merytoryczna Karol
**Status:** ŹRÓDŁO PRAWDY dla erraty wyporności w bazie bota (TASK-ENC-014) oraz wsad do encyklopedii
**Powiązane:** ADR-091, dok. 34 (wsad do encyklopedii), dok. 35 (metoda do bazy bota)
**Powód powstania:** bot odpowiedział błędnie na pytanie o wyporność jacketu (butla 18l + suchy skafander), twierdząc że suchy skafander wymaga większej wyporności worka. Diagnoza wykazała brak metody liczenia w danych bota i odwróconą fizykę.

---

## 1. Sedno problemu (błąd źródłowy)

Pierwotna treść bota (i errata encyklopedii) wiązała "suchy skafander" z większą wymaganą wypornością worka. To jest fizycznie odwrócone. Prawda:

- Wyporność worka kompensuje całkowitą ujemną pływalność systemu pod wodą, na którą składają się DWIE niezależne składowe: ciężar zabieranego gazu oraz utrata wyporu kombinezonu przez kompresję na głębokości.
- Suchy skafander powłokowy NIE generuje istotnej składowej kompresyjnej (nurek steruje gazem w skafandrze, brak trwałej utraty wyporu poza przypadkiem rozszczelnienia). Dla suchara wyporność worka dyktuje praktycznie sam ciężar gazu.
- Gruba pianka mokra (np. 7 mm, tym bardziej 7+7) traci znaczną część wyporu na głębokości i to ona, nie suchar, winduje wymaganą wyporność worka.

Wniosek: suchy skafander to przypadek o MAŁEJ wymaganej wyporności worka. Gruba pianka to przypadek o DUŻEJ. Pierwotny błąd przypisywał sucharowi wartości należne grubej piance.

Uwaga kategorialna: balast a wyporność to dwie różne wielkości. Suchy skafander zwykle wymaga WIĘCEJ BALASTU (kompensacja dodatniej pływalności ocieplacza i skafandra), ale to nie przekłada się na wyporność worka. Mieszanie tych dwóch było częścią pierwotnego błędu.

---

## 2. Dwie składowe wymaganej wyporności

### Składowa A: ciężar zabieranego gazu (deterministyczna)

Wzór: ciężar gazu [kg] = pojemność [l] × ciśnienie [bar] / 1000 × 1,3
(1,3 kg = przybliżona masa 1 m3 powietrza)

Przykłady:
- single 12 l / 200 bar: 12 × 200 / 1000 × 1,3 = 3,1 kg
- single 15 l / 200 bar: 15 × 200 / 1000 × 1,3 = 3,9 kg
- twin 2×12 l / 200 bar: 24 × 200 / 1000 × 1,3 = 6,24 kg

To gaz, który na początku nurkowania ciąży w dół i który worek musi udźwignąć.

### Składowa B: utrata wyporu kombinezonu przez kompresję (zmienna)

- Suchy skafander powłokowy: ~0 w normalnym nurkowaniu. Istotna tylko przy całkowitym rozszczelnieniu.
- Pianka mokra: znaczna, rośnie z głębokością i grubością. To dominująca składowa przy grubych piankach.

---

## 3. Profil kompresji pianki (dane z literatury)

Źródło: Wikipedia "Wetsuit", za badaniem Bardy, Mollendorf, Pendergast 2005 (Journal of Physics D: Applied Physics 38(20):3832-3840, hydrostatyczna kompresja pianki neoprenowej).

Profil straty wyporu z głębokością (proporcjonalny do objętości nieskompresowanej):
- ~30% wyporu ginie w pierwszych ~10 m
- kolejne ~30% do ~60 m
- objętość stabilizuje się przy ~65% straty na ~100 m

Tabela robocza (efektywna utrata, pełna pianka jednoczęściowa):

| Głębokość | Strata wyporu | Efektywna grubość z 7 mm |
|---|---|---|
| 0 m | 0% | 7,0 mm |
| 10 m | ~30% | ~4,9 mm |
| 40 m | ~45-50% | ~3,5-3,9 mm |
| 60 m | ~60% | ~2,8 mm |

---

## 4. Wyliczenie wyporu pianki (zweryfikowane z Wikipedią)

Punkt odniesienia z literatury (Wikipedia/Bardy): pełna pianka 6 mm, powierzchnia ciała ~2 m2, objętość nieskompresowana ~10,5 l (1,75 × 0,006 m3), masa ~4 kg, wypór netto na powierzchni ~6 kg. Strata wyporu ~3 kg na 10 m, rosnąca do ~6 kg na ~60 m. TE LICZBY SĄ ZGODNE Z OBLICZENIAMI PONIŻEJ.

Skalowanie objętości liniowo z grubości dla pianki 7 mm:
- 10,5 l × 7/6 = ~12,25 l neoprenu

Strata na 40 m (~48% objętości):
- 12,25 l × 48% = ~5,9 l utraconej objętości
- ~1 l utraconej objętości wypieranego płynu ≈ ~1 kg utraconego wyporu
- czyli ~5,9 kg utraconego wyporu na 40 m

| Pianka | Objętość neoprenu na powierzchni | Utrata na 40 m | Utrata wyporu |
|---|---|---|---|
| 6 mm pełna jednoczęściowa | ~10,5 l | ~5,0 l | ~5,0 kg |
| 7 mm pełna jednoczęściowa | ~12,25 l | ~5,9 l | ~5,9 kg |

Robocze przyjęcie dla pełnej pianki 7 mm: na 40 m traci ~6 l objętości i ~6 kg wyporu.

Wartości szkoleniowo-praktyczne dla samej pełnej pianki 7 mm:
- 10 m: ~3,5-3,7 kg straty
- 40 m: ~5,5-6,0 kg straty
- 60 m: ~7,0-7,5 kg straty

### Zastrzeżenie (z Wikipedii)

To dotyczy przeciętnej pełnej pianki. U dużej osoby, w dużym rozmiarze, albo przy zestawie typu 7 mm + docieplacz / kaptur / shorty strata może być większa. Źródło podaje, że przy dużej osobie i zestawie typu farmer-john + kurtka utrata wyporu może być prawie dwukrotnie większa.

### Docieplacz pod piankę (przybliżenie praktyczne, NIE dana z literatury)

Przyjęcie robocze Karola: docieplacz pod piankę obejmuje tułów i nogi (od stóp do szyi), bez rąk i głowy, więc ma ~60% wyporu długiej pianki. UWAGA: 60% to szacunek inżynierski, nie liczba z literatury. W treści dla bota/encyklopedii oznaczać jako przybliżenie, nie jako fakt źródłowy.

---

## 5. Reguła najgorszego scenariusza (nurek + partner)

Worek służy też partnerowi. Przy planowaniu sytuacji awaryjnej (uszkodzone źródło wyporu) worek musi wyciągnąć z głębokości nurka I partnera w tej samej konfiguracji.

Reguła robocza z forum (jacekplacek, forum-nuras / jds.pl): do wyporu liczonego dla siebie dolicz tyle samo dla partnera (×2 dla składowych liczonych w najgorszym scenariuszu).

Przykład z forum (twin 2×12 + pianka, której kompresja na 30 m = 7 kg):
- gaz: 6,24 kg × 2 (partner) = 12,48 kg
- pianka: 7 kg × 2 = 14 kg
- razem: 26,48 kg, czyli worek min. 27 l z zapasem

---

## 6. Realne zakresy worków (z praktyki forów, do kalibracji treści)

- Single 12-15 l + suchy powłokowy: worek 13-16 l w zupełności wystarcza (uwaga: 13 l to dolna granica, bo workow mniejszych praktycznie się nie produkuje). Praktycy forum: single + suchy mieszczą się ~17-20 lbs / ~8-9 kg wyporu netto, fizyka pozwala na mniej, ale rynek nie oferuje.
- Twin 2×12 + stage(y) + suchy: worek 40 lbs (~18 l) wystarcza wg wielu praktyków (klon, Miły Maciej). Zastrzeżenie (maran): większy worek bywa potrzebny do utrzymania zdjętego sprzętu na powierzchni / przy rozszczelnieniu skafandra na głębokości.
- Single + gruba pianka (7 mm): wyporność rośnie istotnie przez kompresję, tu rządzi składowa B, nie gaz.

### KONFIGURACJA NIEREALISTYCZNA (NIE używać w przykładach)

Twin 2×12 + gruba pianka 7+7. Karol (ekspert): nie spotykane w praktyce. Twin bierze się na głębokie/długie nury, a wtedy nurkuje się w sucharze, nie w grubej piance. Arytmetycznie daje ~35-40 l worka, ale jako scenariusz nierealny, więc pomijamy w treści bota i encyklopedii.

---

## 7. Realne pary konfiguracji do przykładów (zamiast hasłowego "suchy vs pianka")

1. Single/twin + suchy powłokowy: rządzi ciężar gazu, kompresja ~0. Worek mały do średniego.
2. Single + gruba pianka mokra: rządzi kompresja, rośnie z głębokością. Worek większy.
3. Najgorszy scenariusz dla obu: ×2 na partnera.

---

## 8. Źródła

- Wikipedia "Wetsuit" (sekcja buoyancy), za: Bardy E., Mollendorf J., Pendergast D. (2005), "Thermal conductivity and compressive strain of foam neoprene insulation under hydrostatic pressure", Journal of Physics D: Applied Physics 38(20):3832-3840.
- forum-nuras.com / jds.pl, wątki: dobór twina (Adamsky1977 2010), różnice wyporności/ciężaru butli (miszka999 2006), wybór worka pod pojedynczą butlę (Olaff 2012). Kluczowy post metodyczny: jacekplacek (wyliczenie gaz + kompresja ×partner). Wyliczenia wyporu/ciężaru butli: trzesiek (2010).
- Obliczenia własne architekta, zweryfikowane wobec powyższych.
