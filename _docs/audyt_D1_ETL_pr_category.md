# Audyt D1 ETL: pr_category vs D2-hybrid mapping

**Data:** 2026-05-14  
**Skrypt:** `embeddings/audit_d1_pr_category.py`  
**Powiązany ADR:** ADR-055 (D2-hybrid hotfix), kontekst dla T-010 (przyszły D1 ETL)  
**Read-only:** bez zmian w produkcji.

## Stan wejściowy (po T-002 D2-hybrid)

| Metric | Wartość |
|---|---|
| Produkty total w `divechat_product_embeddings` | 2561 |
| `parent_category_name != NULL` | 2193 (85.6%) |
| `parent_category_name = NULL` | 368 (14.4%) |
| `is_active = true` | 2112 |

## Strategia A

| Metric | Wartość |
|---|---|
| Pokrycie (produkty z naturalnym parentem) | 2545 (99.4%) |
| Zgodność z D2 (dla nie-NULL bieżących) | 5 (0.2%) |
| Nowe przypisania (NULL → coś) | 365 |
| Rozjazdy (current != suggested) | 2175 |

## Strategia B

| Metric | Wartość |
|---|---|
| Pokrycie (produkty z naturalnym parentem) | 2545 (99.4%) |
| Zgodność z D2 (dla nie-NULL bieżących) | 848 (38.7%) |
| Nowe przypisania (NULL → coś) | 365 |
| Rozjazdy (current != suggested) | 1332 |

## Strategia C

| Metric | Wartość |
|---|---|
| Pokrycie (produkty z naturalnym parentem) | 2545 (99.4%) |
| Zgodność z D2 (dla nie-NULL bieżących) | 840 (38.3%) |
| Nowe przypisania (NULL → coś) | 365 |
| Rozjazdy (current != suggested) | 1340 |

## TOP 20 rozjazdów (Strategia A)

| Current (D2) | Suggested (D1) | Produkty | Przykład |
|---|---|---|---|
| Akcesoria Nurkowe | Akcesoria | 84 | SCUBATECH Pasek do maski, czarny silikon |
| Maski i fajki | Maski jednoszybowe | 78 | Maska SCUBATECH Frameless II |
| Oświetlenie | Małe i do Ręki | 78 | Latarka GRALMARINE K2 Max |
| Automaty Oddechowe | Węże do Automatów | 68 | Wąż SCUBATECH LP 50cm czarny, standard |
| Skafandry suche | Ocieplacze do Suchych | 60 | Ocieplacz SANTI BZ 200 Męski |
| Wypornościowe | Płyty i uprzęże | 59 | HALCYON Kieszeń na płytę do BC |
| Skafandry mokre | Skafandry Na ZIMNE wody | 56 | SCUBAPRO Everflex 5/4mm Damski |
| Oświetlenie | Duże z Głowicą | 48 | Latarka GRALMARINE GL 7 LED |
| Wypornościowe | Skrzydła z uprzężą do Poj. Butli | 47 | Zestaw HALCYON Eclipse System 30/40 lbs SS/ALU |
| Skafandry mokre | Skafandry Na CIEPŁE wody | 47 | Pianka BARE Dolphin Floaty 1mm |
| Wypornościowe | Systemy Balastowe | 46 | HALCYON Kieszenie trymujące, para, 5lb (2.2kg) |
| Bezpieczeństwo | Noże | 46 | Nóż SCUBATECH Eagle |
| Automaty Oddechowe | TECLINE | 43 | TECLINE V1 ICE TEC1 SEMITEC II + octopus + manometr |
| Automaty Oddechowe | Akcesoria do automatów | 40 | Zestaw o-ringów duży SCUBATECH |
| Komputery Nurkowe | Manometry | 40 | ScubaTech Manometr Tlenowy 300 bar wąż 15cm |
| Płetwy | Płetwy Gumowe JET | 39 | Płetwy DIVESYSTEM Tech Fin ze sprężynami - czarne |
| Akcesoria Nurkowe | Narzędzia i inne | 39 | Opaska neoprenowa na uszy SCUBAPRO |
| Płetwy | Płetwy Paskowe na Buta | 38 | Płetwy TUSA Liberator X-Ten (SF-5000) |
| Wypornościowe | Skrzydła | 34 | Skrzydło HALCYON Evolve Wing 40/60 lbs |
| Wypornościowe | Side Mount | 34 | XDEEP Kieszenie trymujące do konfiguracji Sidemount, 2 szt. |

## TOP 20 rozjazdów (Strategia B)

| Current (D2) | Suggested (D1) | Produkty | Przykład |
|---|---|---|---|
| Wypornościowe | Skrzydła i jackety | 289 | HALCYON Kieszeń na płytę do BC |
| Maski i fajki | Maski i Fajki | 205 | Maska SCUBATECH Panda dla dzieci niebieska |
| Oświetlenie | Latarki nurkowe | 147 | Latarka GRALMARINE K2 Max |
| Butle | Butle nurkowe | 76 | ScubaTech Siatka na butle 15L |
| Automaty Oddechowe | Węże | 68 | Wąż SCUBATECH LP 50cm czarny, standard |
| Komputery Nurkowe | Instrumenty pomiarowe | 63 | ScubaTech Manometr Tlenowy 300 bar wąż 15cm |
| Bezpieczeństwo | Bojki i kołowrotki | 59 | HALCYON Duża boja nurkowa (1.4m), zamknięta, pomarańczowa |
| Bezpieczeństwo | Noże | 47 | Nóż SCUBATECH Eagle |
| Bezpieczeństwo | Akcesoria Nurkowe | 41 | Sygnalizator na butlę SCUBAPRO Tank Banger |
| Akcesoria Nurkowe | Skrzydła i jackety | 40 | XDEEP Kompletny inflator |
| Torby na Sprzęt | Torby i Skrzynie | 37 | TUSA Torba na automat |
| Akcesoria Nurkowe | Maski i Fajki | 33 | SCUBATECH Pasek do maski, czarny silikon |
| Wypornościowe | Balast | 26 | Balast powlekany 1,2kg |
| Oświetlenie | Komputery Nurkowe | 25 | SUUNTO Torba na komputer |
| Komputery Nurkowe | Węże | 25 | Wąż MIFLEX HP 15cm |
| Akcesoria Nurkowe | Akcesoria Chemiczne | 23 | Klej do skafandrów MCNETT Aquasure, tuba 28g |
| Wypornościowe | Butle nurkowe | 19 | HALCYON Uprząż do butli stage 7l |
| Akcesoria Nurkowe | Fotografia i Video | 17 | OBUDOWA PODWODNA DO SMARTFONA DIVEVOLK SEATOUCH 4 MAX+ plus |
| Rękawice | Skafandry suche | 15 | Rękawice suche SHOWA 660 |
| Akcesoria Nurkowe | Akcesoria pływackie | 11 | Okulary pływackie View X-TREME GOGLE V-1000 |

## TOP 20 rozjazdów (Strategia C)

| Current (D2) | Suggested (D1) | Produkty | Przykład |
|---|---|---|---|
| Wypornościowe | Skrzydła i jackety | 278 | HALCYON Kieszeń na płytę do BC |
| Maski i fajki | Maski i Fajki | 205 | Maska SCUBATECH Panda dla dzieci niebieska |
| Oświetlenie | Latarki nurkowe | 147 | Latarka GRALMARINE K2 Max |
| Butle | Butle nurkowe | 76 | ScubaTech Siatka na butle 15L |
| Automaty Oddechowe | Węże | 68 | Wąż SCUBATECH LP 50cm czarny, standard |
| Komputery Nurkowe | Instrumenty pomiarowe | 63 | ScubaTech Manometr Tlenowy 300 bar wąż 15cm |
| Bezpieczeństwo | Bojki i kołowrotki | 59 | HALCYON Duża boja nurkowa (1.4m), zamknięta, pomarańczowa |
| Bezpieczeństwo | Noże | 47 | Nóż SCUBATECH Eagle |
| Bezpieczeństwo | Akcesoria Nurkowe | 41 | Sygnalizator na butlę SCUBAPRO Tank Banger |
| Akcesoria Nurkowe | Skrzydła i jackety | 40 | XDEEP Kompletny inflator |
| Torby na Sprzęt | Torby i Skrzynie | 37 | TUSA Torba na automat |
| Akcesoria Nurkowe | Maski i Fajki | 33 | SCUBATECH Pasek do maski, czarny silikon |
| Wypornościowe | Balast | 26 | Balast powlekany 1,2kg |
| Komputery Nurkowe | Węże | 25 | Wąż MIFLEX HP 15cm |
| Oświetlenie | Komputery SUUNTO | 24 | SUUNTO Torba na komputer |
| Akcesoria Nurkowe | Akcesoria Chemiczne | 23 | Klej do skafandrów MCNETT Aquasure, tuba 28g |
| Wypornościowe | Butle nurkowe | 19 | HALCYON Uprząż do butli stage 7l |
| Akcesoria Nurkowe | Fotografia i Video | 17 | OBUDOWA PODWODNA DO SMARTFONA DIVEVOLK SEATOUCH 4 MAX+ plus |
| Rękawice | Skafandry suche | 15 | Rękawice suche SHOWA 660 |
| Wypornościowe | Jackety (BCD) | 11 | Zeagle Jacket STILETTO |

## TOP 20 grup NULL → coś (Strategia A)

| Suggested parent | Produkty | Przykłady |
|---|---|---|
| Węże do Inflatorów | 38 | 364: Wąż SCUBATECH do inflatora 55cm, 3037: Wąż SCUBATECH do inflatora 70cm, 3484: Wąż MIFLEX do inflatora 55cm |
| Skrzynie transportowe | 38 | 2947: Explorer Skrzynia 1913, 2948: Explorer Skrzynia 2209, 2949: Explorer Skrzynia 2214 |
| Książki nurkowe | 33 | 1908: Książka Wypadki nurkowe, 1909: Książka Ratownictwo Nurkowe, 1910: Książka Nitrox i wstęp do innych mieszanin |
| Buty | 32 | 1158: Buty BARE Ice Boot 6mm, 1160: Skarpety BARE Neo Socks, 2290: Buty SCUBAPRO Delta Boots 6,5mm |
| Kaptury | 30 | 1147: Kaptur BARE Dry Hood 7/5mm, 1148: Kaptur BARE Tech Dry Hood 7/5mm z zamkiem, 1228: Maska Podlodowa EQUES |
| Rękawice | 29 | 1153: Rękawice BARE 5mm Glove, 1154: Rękawice BARE 5mm Gauntlet Glove, 2286: Rękawice SCUBAPRO Everflex 5mm |
| Torby na Sprzęt i Plecaki Plażowe | 19 | 3271: Torba SCUBAPRO Mesh Sack, 3273: Plecak SCUBAPRO Beach Bag, 3638: Torba SCUBAPRO Mesh Bag Coated |
| Odzież Termoaktywna | 19 | 4180: Bielizna termoaktywna NO GRAVITY - Bluza, 4181: Bielizna termoaktywna NO GRAVITY - Spodnie, 4383: KWARK Legginsy długie Power Stretch Pro |
| Ogrzewanie nurkowe | 17 | 3923: SANTI Kamizelka z Systemem Ogrzewania, 4678: SANTI Konektor do zaworu, 5196: Akumulator AMMONITE SYSTEM Thermo TYPE 24 |
| do 100 PLN | 9 | 3382: Książka Mrok + DVD Zanurzeni w Boesmansgat, 3383: Książka Autobiografia pod ciśnieniem + DVD, Sheck Exley, 3656: Książka Nurkująca kobieta |
| Narzędzia i inne | 9 | 4994: Lusterko nurkowe 360OBSERVE, 5956: SANTI Plecak CUBE, 6072: MARES Bag Cruise Carpet  - Torba / Mata |
| AQUALUNG | 8 | 5983: AQUALUNG Legend 3 + Octopus Legend  / LEG3ND, 6065: AQUALUNG Legend 3 DIN (1+2 stopień), 6066: AQUALUNG Legend 3 MBS DIN (1+2 Stopień) |
| SUCHE Trylaminat, Cordura | 7 | 5981: Suchy skafander AVATAR męski, 6338: Suchy skafander AVATAR damski, 6376: BARE Expedition HD2 Tech Dry Męski |
| Duże z Głowicą | 7 | 6363: Akumulator AMMONITE SYSTEM TYPE 9, 6364: Akumulator AMMONITE SYSTEM TYPE 14, 6365: Akumulator AMMONITE SYSTEM TYPE 24 |
| Komputery SCUBAPRO | 5 | 2308: SCUBAPRO Nadajnik Smart+ Galileo, 3627: Obudowa z gumkami do Uwatec Digital / Aladin Prime / Te, 6718: SCUBAPRO Nadajnik Smart+ PRO |
| Vouchery prezentowe | 5 | 4649: DIVEZONE Karta podarunkwa - Voucher 100 zł, 4650: DIVEZONE Karta podarunkowa - Voucher 200 zł, 4651: DIVEZONE Karta podarunkowa - Voucher 300 zł |
| od 100 do 500 PLN | 4 | 3655: Książka Kurs Nurkowania - podręcznik + 12 filmów, 5841: Zestaw do morsowania Buty + Rękawiczki neopren 5mm, 6089: Zestaw do morsowania Buty + Rękawice + Mata Mares |
| Morsowanie | 4 | 5645: Rękawiczki do morsowania AQUALUNG Thermocline 5mm, 6209: Buty do morsowania TECLINE Komfort 5mm, 6211: Buty do morsowania TECLINE Komfort Titanium 7mm |
| Skafandry Na CIEPŁE wody | 3 | 5482: Tusa CONTOUR PLUS 525, Pianka Męska 5 mm, 5483: Tusa CONTOUR PLUS 525, Pianka Damska 5 mm, 5795: BARE Sprint Shorty 1,5mm dla 2 latka OUTLET |
| Dla dzieci i juniorów | 3 | 5764: Buty plażowe dziecięce Tusa Reef Turer Aquashoes  28, 3, 6729: maska pełnotwarzowa SEAC UNICA S/M BLACK/ORANGE - outle, 7584: Okularki dziecięce pływackie 3 do 5 lat VIEW V-421 JA |

## TOP 20 grup NULL → coś (Strategia B)

| Suggested parent | Produkty | Przykłady |
|---|---|---|
| Skafandry mokre | 94 | 1147: Kaptur BARE Dry Hood 7/5mm, 1148: Kaptur BARE Tech Dry Hood 7/5mm z zamkiem, 1153: Rękawice BARE 5mm Glove |
| Torby i Skrzynie | 56 | 2947: Explorer Skrzynia 1913, 2948: Explorer Skrzynia 2209, 2949: Explorer Skrzynia 2214 |
| Węże | 39 | 364: Wąż SCUBATECH do inflatora 55cm, 3037: Wąż SCUBATECH do inflatora 70cm, 3484: Wąż MIFLEX do inflatora 55cm |
| Książki nurkowe | 33 | 1908: Książka Wypadki nurkowe, 1909: Książka Ratownictwo Nurkowe, 1910: Książka Nitrox i wstęp do innych mieszanin |
| Skafandry suche | 25 | 3923: SANTI Kamizelka z Systemem Ogrzewania, 4678: SANTI Konektor do zaworu, 5196: Akumulator AMMONITE SYSTEM Thermo TYPE 24 |
| PREZENTY | 21 | 3342: EXPLORER Skrzynia MUB 78, 3382: Książka Mrok + DVD Zanurzeni w Boesmansgat, 3383: Książka Autobiografia pod ciśnieniem + DVD, Sheck Exley |
| Odzież Termoaktywna | 19 | 4180: Bielizna termoaktywna NO GRAVITY - Bluza, 4181: Bielizna termoaktywna NO GRAVITY - Spodnie, 4383: KWARK Legginsy długie Power Stretch Pro |
| Akcesoria Nurkowe | 15 | 3222: Zatyczki do uszu Docs Proplugs, 4994: Lusterko nurkowe 360OBSERVE, 5582: Sygnalizator Strobo SEAC Flash Light |
| Automaty Oddechowe | 14 | 4574: Automat TECLINE V2 Ice I-stopień L+P, 4805: TECLINE V1 Ice Tec1 SemiTec I, 5983: AQUALUNG Legend 3 + Octopus Legend  / LEG3ND |
| WYPRZEDAŻE | 12 | 5424: Buty BARE HD Tropic Boot 3mm roz. 35, 6110: Kaptur 5mm rozmiar M OUTLET, 6386: Jacket TUSA Tina XS Outlet |
| Komputery Nurkowe | 9 | 2308: SCUBAPRO Nadajnik Smart+ Galileo, 3627: Obudowa z gumkami do Uwatec Digital / Aladin Prime / Te, 4480: SCUBAPRO Wąż HP adapter  (przedłużka) do nadajnika UWAT |
| Latarki nurkowe | 9 | 6090: HI-MAX H17 zestaw HI-MAX, 3800lm, 6097: HI-MAX - Ładowarka MULTI 2, 6363: Akumulator AMMONITE SYSTEM TYPE 9 |
| Maski i Fajki | 5 | 3776: Maska IST PROEAR (z ochrona uszu), 6182: TUSA Osłona paska maski - neoprenowa (MS-20PP) PROMO, 6578: Maska OMS Tattoo Ultra Clear Pomarańczowa roz. A WYPRZE |
| Morsowanie | 4 | 5645: Rękawiczki do morsowania AQUALUNG Thermocline 5mm, 6209: Buty do morsowania TECLINE Komfort 5mm, 6211: Buty do morsowania TECLINE Komfort Titanium 7mm |
| Płetwy | 3 | 5212: Płetwy SCUBAPRO Go Travel Fin, 6380: Płetwy TUSA Travel Right (SF-0110), 7474: Halcyon płetwy Vector Pro Fins (bez balastów) |
| Dla dzieci i juniorów | 3 | 5764: Buty plażowe dziecięce Tusa Reef Turer Aquashoes  28, 3, 6729: maska pełnotwarzowa SEAC UNICA S/M BLACK/ORANGE - outle, 7584: Okularki dziecięce pływackie 3 do 5 lat VIEW V-421 JA |
| Odzież nurkowa | 2 | 6593: Koszulka SANTI Lady Funk XS WYPRZEDAŻ, 7060: SSI LOOP BANDANA - komin na szyje |
| Skrzydła i jackety | 1 | 5577: Beuchat Boat Set, mały zestaw nurkowy |
| Fotografia i Video | 1 | 7580: Statyw BigBlue Extendable GoPro Tray |

## TOP 20 grup NULL → coś (Strategia C)

| Suggested parent | Produkty | Przykłady |
|---|---|---|
| Skafandry mokre | 94 | 1147: Kaptur BARE Dry Hood 7/5mm, 1148: Kaptur BARE Tech Dry Hood 7/5mm z zamkiem, 1153: Rękawice BARE 5mm Glove |
| Torby i Skrzynie | 56 | 2947: Explorer Skrzynia 1913, 2948: Explorer Skrzynia 2209, 2949: Explorer Skrzynia 2214 |
| Węże | 39 | 364: Wąż SCUBATECH do inflatora 55cm, 3037: Wąż SCUBATECH do inflatora 70cm, 3484: Wąż MIFLEX do inflatora 55cm |
| Książki nurkowe | 33 | 1908: Książka Wypadki nurkowe, 1909: Książka Ratownictwo Nurkowe, 1910: Książka Nitrox i wstęp do innych mieszanin |
| Skafandry suche | 25 | 3923: SANTI Kamizelka z Systemem Ogrzewania, 4678: SANTI Konektor do zaworu, 5196: Akumulator AMMONITE SYSTEM Thermo TYPE 24 |
| PREZENTY | 21 | 3342: EXPLORER Skrzynia MUB 78, 3382: Książka Mrok + DVD Zanurzeni w Boesmansgat, 3383: Książka Autobiografia pod ciśnieniem + DVD, Sheck Exley |
| Odzież Termoaktywna | 19 | 4180: Bielizna termoaktywna NO GRAVITY - Bluza, 4181: Bielizna termoaktywna NO GRAVITY - Spodnie, 4383: KWARK Legginsy długie Power Stretch Pro |
| Akcesoria Nurkowe | 16 | 3222: Zatyczki do uszu Docs Proplugs, 4994: Lusterko nurkowe 360OBSERVE, 5577: Beuchat Boat Set, mały zestaw nurkowy |
| Automaty Oddechowe | 14 | 4574: Automat TECLINE V2 Ice I-stopień L+P, 4805: TECLINE V1 Ice Tec1 SemiTec I, 5983: AQUALUNG Legend 3 + Octopus Legend  / LEG3ND |
| WYPRZEDAŻE | 12 | 5424: Buty BARE HD Tropic Boot 3mm roz. 35, 6110: Kaptur 5mm rozmiar M OUTLET, 6386: Jacket TUSA Tina XS Outlet |
| Komputery Nurkowe | 9 | 2308: SCUBAPRO Nadajnik Smart+ Galileo, 3627: Obudowa z gumkami do Uwatec Digital / Aladin Prime / Te, 4480: SCUBAPRO Wąż HP adapter  (przedłużka) do nadajnika UWAT |
| Latarki nurkowe | 9 | 6090: HI-MAX H17 zestaw HI-MAX, 3800lm, 6097: HI-MAX - Ładowarka MULTI 2, 6363: Akumulator AMMONITE SYSTEM TYPE 9 |
| Maski i Fajki | 5 | 3776: Maska IST PROEAR (z ochrona uszu), 6182: TUSA Osłona paska maski - neoprenowa (MS-20PP) PROMO, 6578: Maska OMS Tattoo Ultra Clear Pomarańczowa roz. A WYPRZE |
| Morsowanie | 4 | 5645: Rękawiczki do morsowania AQUALUNG Thermocline 5mm, 6209: Buty do morsowania TECLINE Komfort 5mm, 6211: Buty do morsowania TECLINE Komfort Titanium 7mm |
| Płetwy | 3 | 5212: Płetwy SCUBAPRO Go Travel Fin, 6380: Płetwy TUSA Travel Right (SF-0110), 7474: Halcyon płetwy Vector Pro Fins (bez balastów) |
| Dla dzieci i juniorów | 3 | 5764: Buty plażowe dziecięce Tusa Reef Turer Aquashoes  28, 3, 6729: maska pełnotwarzowa SEAC UNICA S/M BLACK/ORANGE - outle, 7584: Okularki dziecięce pływackie 3 do 5 lat VIEW V-421 JA |
| Odzież nurkowa | 2 | 6593: Koszulka SANTI Lady Funk XS WYPRZEDAŻ, 7060: SSI LOOP BANDANA - komin na szyje |
| Fotografia i Video | 1 | 7580: Statyw BigBlue Extendable GoPro Tray |

## Rekomendacje design D1 ETL (T-010)

_(wypełnione na bazie liczb powyżej)_

- **Pokrycie:** A=99.4%, B=99.4%, C=99.4%. Wszystkie 3 strategie dają identyczne pokrycie (2545 produktów) — różnią się TYLKO wyborem cat, nie tym czy którakolwiek się znajdzie. Pokrycie nie jest dyskryminatorem.
- **Zgodność z D2:** A=0.2%, B=38.7%, C=38.3%. **Niska zgodność B (≈39%) jest kluczowym wnioskiem.** D2-hybrid używa pseudokategorii z NAZEWNICTWO SKLEPU (np. 'Wypornościowe', 'Maski i fajki'), które NIE istnieją literalnie w pr_category PrestaShop (drzewo ma 'Skrzydła i jackety', 'Maski i Fajki').
- **NULL → coś:** A=365, B=365, C=365. Identyczne (ten sam zbiór produktów). Wśród nich: część to autentyczne luki D2 (Książki nurkowe, Buty, Kaptury, Rękawice, Odzież Termoaktywna, Ogrzewanie), część to intencjonalne NULL (WYPRZEDAŻE, Vouchery, 'do 100 PLN', price buckets pod PREZENTY).
- **Rozjazdy (current != suggested):** A=2175, B=1332, C=1340. Strategia A ma ogromną liczbę rozjazdów bo wybiera leaf cat (specyficzną) zamiast top-level grupy. B i C są zbliżone — różnią się głównie dla produktów na level=4 (brand-cat).

### Główny wniosek

**D1 ETL z `pr_category` w surowej formie NIE zastąpi D2-hybrid.** Pseudokategorie z NAZEWNICTWO SKLEPU (model-facing names) różnią się systematycznie od nazw w drzewie PrestaShop (admin-facing names). Audyt potwierdza: ~60% produktów dostałoby INNĄ wartość `parent_category_name` gdyby D1 ETL zastąpił D2 surowo.

### Rekomendowany design T-010 (D1 ETL)

1. **D1 ETL wybiera level=2 cat z pr_category** (Strategia B) jako baza — to najbardziej naturalne odwzorowanie drzewa PrestaShop na pojęcie 'top-level grupa".
2. **Mapowanie pseudokategoria PrestaShop → NAZEWNICTWO SKLEPU** jako warstwa translacji (kontynuacja D2-hybrid, ale jako tabela/JSON, nie hardcoded SQL UPDATE). Przykład: `'Skrzydła i jackety' → 'Wypornościowe'`, `'Maski i Fajki' → 'Maski i fajki'`, `'Latarki nurkowe' → 'Oświetlenie'`.
3. **Pipeline ETL idempotentny**: re-run aktualizuje `parent_category_name` z PS tree + aliases. Bez zewnętrznego SQL UPDATE.
4. **Edge cases które D1 powinien obsłużyć**: 365 produktów obecnie NULL dostałoby parent (np. 33 książki nurkowe → 'Książki nurkowe', 32 buty → 'Buty', 30 kapturów, 29 rękawic). Decyzja Karola: które z nich rzeczywiście chcemy wpuścić, a które zostają NULL intencjonalnie (WYPRZEDAŻE, price buckets PREZENTY).

### D2-hybrid status post-D1

**D2-hybrid można odrzucić** po wdrożeniu D1+aliases. Warstwa aliasów (punkt 2 powyżej) zastępuje hardcoded SQL UPDATE. Jeśli warstwa aliasów jest tabelą w PG (np. `divechat_category_aliases`), Karol może edytować online bez deploy.

### Wielokategoryjne produkty (~7 cat/produkt)

Większość kategorii to marketingowe (price buckets, 'Stare produkty", 'instrukcja do wszystkiego") + inactive admin tagi. Po filtrze active=1 i level>=2, średnio 1-2 sensowne cat per produkt. Algorytm `_pick_one` (most common, alfabetyczny tiebreak) deterministycznie wybiera. Alternatywa: kolumna `parent_categories text[]` zamiast pojedynczego varchar — wymaga zmiany schematu PG + ProductSearch.php filter logic. **Decyzja Karola**: czy single-value (status quo) wystarczy, czy multi-value jest potrzebne (np. produkt na pograniczu 'Akcesoria" + 'Bezpieczeństwo").

### Uwagi architekta (do T-010)

Karol po review v1: (1) Rozjazd "Maski i fajki" (D2) vs "Maski i Fajki" (PS tree) różni się TYLKO wielkością litery — warstwa aliasów w T-010 musi używać Unicode-aware lowercase normalization w lookup (`str.casefold()` po stronie Python lub `LOWER()` + `unaccent` po stronie PG), żeby case-only różnice nie generowały false-mismatches; (2) Strategia A (najgłębsza cat) ujawnia że produkt często ma sensowną sub-kategorię obok parent-grupy — dodać do backlogu osobny task T-XXX: kolumna `subcategory_name` w `divechat_product_embeddings` (analog `parent_category_name`, ale z deeper cat), co pozwoli na 2-poziomowe filtry w `ProductSearch.php` (np. brand-level „APEKS" pod „Automaty Oddechowe").
