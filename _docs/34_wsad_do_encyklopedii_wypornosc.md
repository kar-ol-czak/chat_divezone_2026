# 34. Wsad do encyklopedii: fizyka wyporności i kompresji. Wnioski + ostrzeżenie o brakach

**Data:** 2026-06-08
**Od:** projekt czatu (Chat_dla_klientow_2026)
**Do:** projekt Encyklopedia_Divezone_2026 (osobny projekt Claude, osobne memories)
**Status:** HANDOFF. Gotowy wsad merytoryczny, nie wymaga przetwarzania surowych wątków forum.
**Źródło pełne:** dok. 33 w projekcie czatu (fizyka_wypornosci_zrodla)

---

## OSTRZEŻENIE (czytaj najpierw)

Encyklopedii brakuje wyliczeń doboru wyporności worka i kompresji pianki. Co więcej, CZĘŚĆ OBECNEJ TREŚCI ENCYKLOPEDII NA TEN TEMAT MOŻE BYĆ BŁĘDNA, tym samym błędem, który wykryto w bocie: wiązaniem suchego skafandra z większą wymaganą wypornością worka. Errata bota (decyzje 208/210 w encyklopedii, handoff 86/89) poprawiła kierunek, ale NIE dostarczyła metody liczenia. Skutek: model przy braku danych dopowiada liczby wyporności z własnej wiedzy bazowej, często błędne.

Karol (ekspert i product owner) chce dołączyć do encyklopedii szczegółowe wyliczenia wyporności i kompresji. Ten dokument jest tym wsadem. Zaleca się, by encyklopedia NIE wyciągała wniosków samodzielnie z surowych wątków forum, tylko przyjęła poniższe zweryfikowane ustalenia.

---

## 1. Ustalenie merytoryczne (poprawna fizyka)

Wymagana wyporność worka = ciężar zabieranego gazu + utrata wyporu kombinezonu przez kompresję na głębokości. Każda składowa ×2, jeśli liczymy najgorszy scenariusz dla nurka i partnera.

- Suchy skafander powłokowy: składowa kompresji ~0. Wyporność worka dyktuje sam gaz. To przypadek MAŁEJ wymaganej wyporności.
- Gruba pianka mokra: składowa kompresji duża, rośnie z głębokością i grubością. To przypadek DUŻEJ wymaganej wyporności.
- Suchy skafander zwykle wymaga więcej BALASTU (osobna wielkość), ale to NIE zwiększa wyporności worka. Nie mylić balastu z wypornością.

## 2. Wzór na ciężar gazu (deterministyczny)

ciężar gazu [kg] = pojemność [l] × ciśnienie [bar] / 1000 × 1,3

- single 15 l / 200 bar: 3,9 kg
- twin 2×12 / 200 bar: 6,24 kg

## 3. Profil kompresji pianki (literatura: Bardy 2005, via Wikipedia Wetsuit)

- ~30% wyporu ginie do ~10 m, kolejne ~30% do ~60 m, stabilizacja ~65% na ~100 m.
- Pełna pianka 7 mm: na 40 m traci ~6 l objętości i ~6 kg wyporu.
- Wartości szkoleniowe (sama pełna pianka 7 mm): 10 m ~3,5-3,7 kg; 40 m ~5,5-6,0 kg; 60 m ~7,0-7,5 kg.

Zastrzeżenie z literatury: u dużej osoby lub przy zestawie 7 mm + docieplacz/kaptur/shorty strata może być prawie dwukrotnie większa.

Przybliżenie (NIE dana z literatury): docieplacz pod piankę ~60% wyporu długiej pianki (obejmuje tułów i nogi, bez rąk i głowy). Oznaczać jako szacunek.

## 4. Realne zakresy worków

- Single 12-15 l + suchy: worek 13-16 l wystarcza (13 l to dolna granica produkcyjna).
- Twin 2×12 + stage + suchy: ~18 l (40 lbs) wystarcza, z zastrzeżeniem na utrzymanie sprzętu na powierzchni.
- Single + gruba pianka: wyporność rośnie przez kompresję, rządzi składowa kompresji.

## 5. Konfiguracja, której NIE podawać w przykładach

Twin 2×12 + gruba pianka 7+7. Niespotykane w praktyce (twin = głębokie/długie nury = suchar). Pomijać.

## 6. Czego encyklopedii brakuje (lista do uzupełnienia)

1. Hasło/sekcja metodyczna: dobór wyporności worka (wzór gazu + tabela kompresji + reguła partnera).
2. Tabela kompresji pianki z głębokością (sekcja 3).
3. Rozdzielenie pojęć balast vs wyporność worka.
4. Audyt istniejących haseł sprzętowych (jacket, skrzydło, zestawy) pod kątem reliktu "suchy → większy worek". Te same pliki/hasła, które poprawiano w bocie: JACKET, ZESTAW_SKRZYDLO_SINGLE, ZESTAW_DO_NURKOWANIA, ZESTAW_SKRZYDLO_TWIN.

## 7. Źródła

Pełna lista i wyliczenia: dok. 33 projektu czatu. Kluczowe: Wikipedia Wetsuit (Bardy 2005), wątki forum-nuras/jds.pl (post metodyczny jacekplacek), obliczenia architekta zweryfikowane wobec literatury.
