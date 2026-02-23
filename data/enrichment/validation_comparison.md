# Porównanie walidacji v1 vs v2 (zmiana VALIDATION_PROMPT)

Data: 2026-02-23

## Zmiana w prompcie

Do sekcji "OCEŃ każdą frazę" dodano zasadę zachowywania fraz z kontekstem użycia
(geografia, warunki, typ nurkowania, przeznaczenie). Walidator ma usuwać TYLKO frazy
faktycznie mylące lub identyczne z nazwą produktu.

## Podsumowanie liczbowe

| Metryka | v1 (stary prompt) | v2 (nowy prompt) | Delta |
|---|---|---|---|
| Fraz po walidacji | 403 | 455 | **+52 (+13%)** |
| Średnio fraz/produkt | 13.4 | 15.2 | +1.8 |
| Usunięte przez walidatora | 59 | 31 | **-28 (-47%)** |
| Dodane przez walidatora | 221 | 246 | +25 |

## Kluczowy efekt

Walidator v2 usunął **o 47% mniej fraz** (31 vs 59). Frazy z kontekstem użycia
(geografia, warunki) zostały zachowane zgodnie z intencją:

### Przykłady fraz uratowanych w v2 (wcześniej usunięte)

**Automaty oddechowe:**
- "automat do jaskini i wraków" (TECLINE V2 Ice) — v1 usunął, v2 zachował
- "automat na egipt safari" (AQUALUNG Legend 3) — v1 usunął, v2 zachował
- "automat do nurkowania w Polsce" (AQUALUNG Legend 3 DIN) — v1 usunął, v2 zachował
- "automat do nurkowania technicznego" (AQUALUNG MBS DIN) — v1 usunął, v2 zachował

**Komputery:**
- "komputer na egipt safari" (MARES Icon HD) — v1 usunął, v2 zachował
- "komputer nurkowy na bałtyk" (MARES Icon HD) — v1 usunął, v2 zachował
- "komputer na wyjazd egipt nitrox" (SCUBAPRO Chromis) — v1 usunął, v2 zachował
- "bottom timer do zimnej wody polska egipt" (SCUBAPRO Digital 330) — v1 usunął, v2 zachował

**Pianki:**
- "pianka damska kamieniołom" (SCUBAPRO Everflex) — v2 dodał nową
- "pianka 7mm kamieniołom" (SCUBAPRO Definition 7mm) — v2 dodał nową
- "pianka do nurkowania 5mm chorwacja" (SCUBAPRO Definition 5mm) — v2 dodał nową
- "pianka damska 5mm na chorwację" (SCUBAPRO Definition 5mm Damski) — v2 dodał nową

**Zestawy nurkowe:**
- "kompaktowy zestaw nurkowy na wyjazd do egiptu" (Beuchat Boat Set) — v2 dodał
- "mały zestaw nurkowy na wyjazd w polskę" (Beuchat Boat Set) — v2 dodał

### Frazy nadal poprawnie usuwane w v2

- "pianka 5mm do egiptu" (Scubapro OneFlex 5mm) — produkt na ZIMNE wody
- "pianka 7mm męska" / "pianka 7mm damska" (OneFlex 7mm) — produkt jest unisex
- "pianka męska 5mm zimne wody" (Fourth Element Proteus Damski) — złe płeć
- "automat oddechowy i stopień din" (TECLINE Military) — niejasne sformułowanie

## Per-product comparison

| ID | Produkt | v1 fraz | v2 fraz | Delta | v1 usun. | v2 usun. |
|---|---|---|---|---|---|---|
| 2278 | SCUBAPRO Everflex 5/4mm Damski | 13 | 14 | +1 | 1 | 1 |
| 3197 | SCUBAPRO Definition 7mm Damski | 14 | 14 | 0 | 2 | 1 |
| 3198 | SCUBAPRO Definition Vest 6mm Damski | 12 | 14 | +2 | 3 | 2 |
| 4170 | SCUBAPRO Definition 5mm Damski | 14 | 13 | -1 | 0 | 1 |
| 4171 | SCUBAPRO Definition 5mm Męski | 15 | 16 | +1 | 1 | 1 |
| 4243 | Scubapro OneFlex Overal 5mm | 17 | 15 | -2 | 1 | 1 |
| 4244 | Scubapro OneFlex Overal 7mm | 15 | 17 | +2 | 3 | 1 |
| 4245 | Scubapro OneFlex Vest 5mm ocieplacz | 12 | 16 | +4 | 1 | 1 |
| 4699 | FOURTH ELEMENT Proteus 5mm Męski | 13 | 14 | +1 | 2 | 2 |
| 4700 | FOURTH ELEMENT Proteus 5mm Damski | 15 | 14 | -1 | 1 | 1 |
| 451 | SUUNTO Bezprzewodowy miernik ciśnienia | 13 | 17 | +4 | 1 | 1 |
| 2308 | SCUBAPRO Nadajnik Smart+ Galileo | 13 | 17 | +4 | 2 | 1 |
| 2339 | SCUBAPRO Digital 330 - na pasku | 11 | 15 | +4 | 3 | 1 |
| 3434 | MARES Icon HD | 16 | 18 | +2 | 2 | 1 |
| 3627 | Obudowa z gumkami Uwatec/Aladin | 11 | 13 | +2 | 3 | 1 |
| 3844 | SCUBAPRO Digital 330 - w obudowie | 13 | 14 | +1 | 3 | 1 |
| 4296 | MARES New Matrix | 13 | 16 | +3 | 3 | 2 |
| 4480 | SCUBAPRO Wąż HP adapter do nadajnika | 12 | 13 | +1 | 1 | 1 |
| 4626 | SCUBAPRO Chromis | 14 | 14 | 0 | 2 | 0 |
| 4627 | SCUBAPRO Interfejs USB Chromis | 13 | 15 | +2 | 3 | 2 |
| 4574 | Automat TECLINE V2 Ice L+P | 14 | 17 | +3 | 1 | 0 |
| 4805 | TECLINE V1 Ice Tec1 SemiTec I | 17 | 18 | +1 | 1 | 1 |
| 5577 | Beuchat Boat Set | 13 | 15 | +2 | 3 | 1 |
| 5983 | AQUALUNG Legend 3 + Octopus | 13 | 15 | +2 | 3 | 0 |
| 6065 | AQUALUNG Legend 3 DIN | 12 | 15 | +3 | 2 | 1 |
| 6066 | AQUALUNG Legend 3 MBS DIN | 13 | 16 | +3 | 3 | 2 |
| 6326 | AQUALUNG Legend 3 Elite + Octo | 15 | 15 | 0 | 1 | 1 |
| 6563 | TECLINE V1 Ice Military Line | 13 | 16 | +3 | 2 | 0 |
| 6649 | AQUALUNG Helix Pro + Octopus | 12 | 16 | +4 | 2 | 1 |
| 6650 | AQUALUNG Helix Pro + Octo + Manometr | 12 | 13 | +1 | 3 | 1 |

## Wnioski

1. Nowy prompt skutecznie ograniczył nadmierne usuwanie fraz kontekstowych (-47% usunięć)
2. Frazy z geografią (Egipt, Bałtyk, Chorwacja, kamieniołom) i typem nurkowania (tech, jaskinie, wraki) są zachowywane
3. Walidator nadal poprawnie usuwa frazy faktycznie mylące (zła płeć, zimny produkt → Egipt)
4. Średnia fraz/produkt wzrosła z 13.4 do 15.2 — więcej materiału do embedowania
