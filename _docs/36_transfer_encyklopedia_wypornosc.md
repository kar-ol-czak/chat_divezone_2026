# 36. TRANSFER do systemu Encyklopedii Nurkowania: fizyka wyporności i kompresji

**Data:** 2026-06-08
**Od:** projekt czatu (Chat_dla_klientow_2026), architekt Claude Opus 4.8
**Do:** system Encyklopedia_Divezone_2026 (osobny projekt, osobne memories, pipeline Evidence Registry → JSON Schema → Validator → pgvector)
**Cel:** samodzielna paczka wsadowa. Tamten projekt NIE musi sięgać do repo czatu. Zawiera: ostrzeżenie o brakach/błędach, poprawną fizykę, pełne wyliczenia, evidence (cytaty forów + Wikipedia/Bardy 2005), realne zakresy, listę haseł do audytu.
**Załącznik zewnętrzny (dostarcza Karol osobno):** pełne transkrypty trzech wątków forum (surowe źródło). Ten dokument cytuje tylko fragmenty istotne dla wyporności, z atrybucją.

---

## SEKCJA 0 — OSTRZEŻENIE (czytaj najpierw)

Encyklopedii brakuje metody doboru wyporności worka i danych o kompresji pianki. CZĘŚĆ OBECNEJ TREŚCI ENCYKLOPEDII MOŻE BYĆ BŁĘDNA tym samym błędem, który wykryto w bocie czatu: wiązaniem suchego skafandra z większą wymaganą wypornością worka. Wcześniejsza errata encyklopedii (decyzje 208/210, handoff 86/89) poprawiła kierunek, ale NIE dostarczyła metody liczenia. Karol (product owner) chce dołączyć do encyklopedii szczegółowe wyliczenia wyporności i kompresji. Ten dokument jest tym wsadem. Zaleca się NIE wyciągać wniosków samodzielnie z surowych wątków forum, tylko przyjąć poniższe zweryfikowane ustalenia jako evidence wysokiej jakości.

Hasła encyklopedii do audytu pod kątem reliktu "suchy → większy worek" (te same, które poprawiano w bocie): jacket, skrzydło do pojedynczej butli, skrzydło do twina, zestaw do nurkowania.

---

## SEKCJA 1 — WNIOSKI (closed, zweryfikowane)

### W1. Poprawna fizyka doboru wyporności
Wymagana wyporność worka = ciężar zabieranego gazu + utrata wyporu kombinezonu przez kompresję na głębokości. Każda składowa ×2 dla najgorszego scenariusza (nurek + partner, awaryjne źródło wyporu).

### W2. Suchy skafander vs gruba pianka
- Suchy skafander powłokowy: składowa kompresji bliska zeru (nurek steruje gazem w skafandrze, trwała utrata tylko przy rozszczelnieniu). Wyporność worka dyktuje praktycznie sam ciężar gazu. PRZYPADEK MAŁEJ WYPORNOŚCI.
- Gruba pianka mokra: składowa kompresji duża, rośnie z głębokością i grubością. PRZYPADEK DUŻEJ WYPORNOŚCI.
- Pierwotny błąd (bot i prawdopodobnie encyklopedia) przypisywał sucharowi wartości należne grubej piance. To odwrócenie fizyki.

### W3. Balast a wyporność to dwie różne wielkości
Suchy skafander zwykle wymaga WIĘCEJ BALASTU (kompensacja dodatniej pływalności skafandra i ocieplacza), ale to NIE zwiększa wyporności worka. Mieszanie tych pojęć było częścią błędu.

### W4. Wzór na ciężar gazu (deterministyczny)
ciężar gazu [kg] = pojemność [l] × ciśnienie [bar] / 1000 × 1,3
(1,3 kg = przybliżona masa 1 m3 powietrza)
- single 12 l / 200 bar: 3,1 kg
- single 15 l / 200 bar: 3,9 kg
- twin 2×12 / 200 bar: 6,24 kg

### W5. Realne zakresy worków (z praktyki rynku i forów)
- single 12-15 l + suchy → worek 13-16 l (13 l = najmniejszy produkowany, dolna granica rynkowa)
- twin 2×12 + stage + suchy → 18-22 l (40-50 lbs). Rynek grupuje się przy 18 l / 22 l / 40 lbs / 50 lbs / 23 l. Bestseller swego czasu: Tecline Donut 22 (22 l). Górna granica 22 l uzasadniona cięższą konfiguracją twina i zapasem na utrzymanie sprzętu na powierzchni, NIE samym suchym skafandrem.
- single + gruba pianka → większy worek, bo rządzi kompresja.

### W6. Konfiguracja nierealistyczna (NIE używać w przykładach)
Twin 2×12 + gruba pianka 7+7. Niespotykane w praktyce: twin bierze się na głębokie/długie nury, a wtedy nurkuje się w sucharze. Arytmetycznie ~35-40 l worka, ale jako scenariusz nierealny.

---

## SEKCJA 2 — EVIDENCE: kompresja pianki (literatura)

### E1. Źródło naukowe
Bardy E., Mollendorf J., Pendergast D. (2005), "Thermal conductivity and compressive strain of foam neoprene insulation under hydrostatic pressure", Journal of Physics D: Applied Physics 38(20):3832-3840. Cytowane w: Wikipedia "Wetsuit", sekcja buoyancy. Link: https://en.wikipedia.org/wiki/Wetsuit

### E2. Profil kompresji (z literatury)
Strata wyporu proporcjonalna do objętości nieskompresowanej:
- ~30% wyporu ginie w pierwszych ~10 m
- kolejne ~30% do ~60 m
- objętość stabilizuje się przy ~65% straty na ~100 m

Tabela efektywna (pełna pianka, punkt wyjścia 7 mm):

| Głębokość | Strata wyporu | Efektywna grubość z 7 mm |
|---|---|---|
| 0 m | 0% | 7,0 mm |
| 10 m | ~30% | ~4,9 mm |
| 40 m | ~45-50% | ~3,5-3,9 mm |
| 60 m | ~60% | ~2,8 mm |

### E3. Punkt odniesienia z literatury (Wikipedia/Bardy)
Pełna pianka 6 mm, powierzchnia ciała ~2 m2: objętość nieskompresowana ~10,5 l (1,75 × 0,006 m3), masa ~4 kg, wypór netto na powierzchni ~6 kg. Strata wyporu ~3 kg na 10 m, rosnąca do ~6 kg na ~60 m.

### E4. Zastrzeżenie z literatury
U dużej osoby lub przy zestawie typu farmer-john + kurtka (lub 7 mm + docieplacz/kaptur/shorty) utrata wyporu może być prawie dwukrotnie większa niż dla przeciętnej pełnej pianki.

---

## SEKCJA 3 — WYLICZENIA (krok po kroku, zweryfikowane wobec E1-E4)

### Wyliczenie kompresji pianki 7 mm na 40 m
Skalowanie objętości liniowo z grubości: 10,5 l × 7/6 = ~12,25 l neoprenu.
Strata na 40 m (~48% objętości): 12,25 l × 48% = ~5,9 l utraconej objętości.
1 l utraconej objętości wypieranego płynu ≈ 1 kg utraconego wyporu.
Wynik: ~5,9 kg utraconego wyporu na 40 m.

| Pianka | Objętość neoprenu na powierzchni | Utrata na 40 m | Utrata wyporu |
|---|---|---|---|
| 6 mm pełna jednoczęściowa | ~10,5 l | ~5,0 l | ~5,0 kg |
| 7 mm pełna jednoczęściowa | ~12,25 l | ~5,9 l | ~5,9 kg |

Robocze dla pełnej pianki 7 mm: na 40 m traci ~6 l objętości i ~6 kg wyporu.

Wartości szkoleniowo-praktyczne (sama pełna pianka 7 mm):
- 10 m: ~3,5-3,7 kg straty
- 40 m: ~5,5-6,0 kg straty
- 60 m: ~7,0-7,5 kg straty

### Docieplacz pod piankę (PRZYBLIŻENIE, NIE dana z literatury)
Przyjęcie robocze: docieplacz obejmuje tułów i nogi (od stóp do szyi), bez rąk i głowy, więc ~60% wyporu długiej pianki. OZNACZAĆ JAKO SZACUNEK INŻYNIERSKI, nie fakt źródłowy.

### Wyliczenie najgorszego scenariusza (przykład z forum, evidence C1)
Twin 2×12, pianka o kompresji 7 kg na 30 m:
- gaz: 6,24 kg × 2 (partner) = 12,48 kg
- pianka: 7 kg × 2 = 14 kg
- razem: 26,48 kg → worek min. 27 l z zapasem

---

## SEKCJA 4 — EVIDENCE: fora nurkowe (cytaty z atrybucją)

UWAGA dla pipeline: poniżej fragmenty istotne dla wyporności. Pełne transkrypty trzech wątków dostarcza Karol osobno jako surowe źródło. Reszta wątków (dyskusje o transporcie twina, SAC, sprzęcie) nie dotyczy wyporności.

### C1. Metoda liczenia (najważniejsze evidence)
Źródło: forum-nuras.com / jds.pl, wątek "wybór worka pod pojedynczą butlę", użytkownik jacekplacek, 08-03-2012.
Treść metody (cytat): worek liczymy dla nurka i partnera. Dla nurka w suchym powłokowym i butli 15 l do 200 bar wystarcza ~4 l wyporu (ciężar gazu na początku nurkowania), ×2 dla partnera = 8 l. Dla twina 2×12 / 200 bar: [2×12×200]/1000 × 1,3 = 6,24 kg gazu, ×2 = 12,48 kg. Suchy powłokowy nie pokonuje zmian wyporu z kompresji (zjawisko z pianki). Przy piankach o kompresji 7 kg na 30 m doliczamy tę wartość + drugie tyle dla partnera = +14 l. Twin + pianka: 12,48 + 14 = 26,48 l, czyli worek ~27 l.
Znaczenie: to deterministyczna metoda, fundament wniosku W1/W4. Cytat oddany wiernie co do liczb.

### C2. Suchar a wyporność (potwierdzenie niezależne)
Źródło: ten sam wątek, użytkownik piotr_c (konstruktor worków xDeep), 08-03-2012.
Treść: przy sucharze utrata wyporu występuje właściwie tylko przy całkowitym rozszczelnieniu skafandra (duża dziura). Dla butli 15 l najgorszy przypadek to ~15,6 kg na minusie, a worek ~17 kg (np. Zeos 38) daje ~2 kg zapasu i utrzymuje zestaw na powierzchni po zdjęciu w wodzie.
Znaczenie: potwierdza W2 (suchar = mała składowa kompresji) niezależnie od C1.

### C3. Realne zakresy worków do twina (praktyka)
Źródło: forum-nuras.com, wątek "Twinset", użytkownicy klon, Miły Maciej, maran, 19-09-2010.
Treść: do twina 2×12 + stage worek 40 lbs (~18 l) wystarcza wg wielu praktyków (klon używa 40 lbs do twina + 3-4 stage). Zastrzeżenie (maran, Miły Maciej): większy worek bywa potrzebny do utrzymania zdjętego sprzętu na powierzchni lub przy rozszczelnieniu skafandra na głębokości.
Znaczenie: evidence dla W5 (twin 18-22 l, górna granica = zapas, nie suchar).

### C4. Wyliczenia wyporności/ciężaru butli (kontekst składowej gazu)
Źródło: forum-nuras.com, wątek "Twinset", użytkownik trzesiek, 02-09-2010.
Treść: butla 12 l/200 bar, masa pustej 12,2 kg, ~2400 Nl powietrza, masa powietrza 3,1 kg. Butla 12 l/300 bar, masa pustej 18 kg, ~3250 Nl, masa powietrza 4,2 kg.
Znaczenie: potwierdza wartości masy gazu użyte we wzorze W4.

### C5. Balast a typ skafandra/wody (kontekst W3)
Źródło: forum-nuras.com, wątek "różnice w wyporności/ciężarze butli", użytkownicy ocn, bann, Konrad Dubiel, 08-12-2006.
Treść: w słonej wodzie potrzeba więcej balastu na cały sprzęt (też piankę i BC), nie tylko butlę. Różnice wyporności butli stalowa vs aluminiowa ~2 kg. Suchar i gruba pianka zmieniają zapotrzebowanie na balast.
Znaczenie: evidence dla W3 (balast to osobna wielkość od wyporności worka).

---

## SEKCJA 5 — CZEGO ENCYKLOPEDII BRAKUJE (lista do uzupełnienia)

1. Hasło/sekcja metodyczna: dobór wyporności worka (wzór gazu W4 + tabela kompresji E2 + reguła partnera W1).
2. Tabela kompresji pianki z głębokością (E2).
3. Wyraźne rozdzielenie pojęć balast vs wyporność worka (W3).
4. Audyt haseł sprzętowych pod kątem reliktu "suchy → większy worek": jacket, skrzydło single, skrzydło twin, zestaw do nurkowania.
5. Zastrzeżenie o zmienności kompresji (duża osoba, zestaw wielowarstwowy, E4) oraz oznaczenie szacunku docieplacza (~60%) jako nie-źródłowego.

---

## SEKCJA 6 — NOTA O JAKOŚCI EVIDENCE

- E1-E4 (literatura): wysoka pewność, źródło recenzowane via Wikipedia.
- W4, sekcja 3 (wyliczenia): deterministyczne, zweryfikowane wobec E3.
- C1-C5 (fora): evidence praktyczne/eksperckie, spójne z literaturą. C1 to metoda, nie pomiar, ale arytmetycznie zgodna z E.
- Szacunek docieplacza 60%: NISKA pewność, oznaczyć jako przybliżenie.
- Zakresy rynkowe W5: potwierdzone przez product ownera (Karol) danymi sprzedażowymi (Tecline Donut 22 jako bestseller, grupowanie 18/22 l / 40/50 lbs).

Pełne źródło wewnętrzne (gdyby potrzebne): dok. 33 projektu czatu.
