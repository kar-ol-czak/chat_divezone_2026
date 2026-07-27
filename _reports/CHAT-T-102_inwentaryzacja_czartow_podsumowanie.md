# CHAT-T-102 — Inwentaryzacja czartów rozmiarowych — podsumowanie

> READ-ONLY z `divezone_2025` (konto `divezone_chat_reader`, zero write). Zakres: skafandry suche/mokre, buty, rękawice (BEZ płetw). Id kategorii zweryfikowane w CHAT-T-101.


**Produktów w zakresie (active, dedup): 287**


## Metodyka klasyfikacji (heurystyka na `pr_product_lang.description`)

- **grafika** — `<img>` o nazwie/alt sugerującej tabelę (np. `bare-buty-tabela.jpg`, `Rozmiary…BARE.png`). Sygnał mocny.
- **tekst** — `<table>` będąca realną tabelą **progów**: krzyżuje ≥2 wymiary ciała (klatka/talia/biodra/wzrost/waga/noga). Próg ≥2 odsiewa spec-tabele (które mają najwyżej wagę produktu jako pojedynczą miarę). To dane już strukturalne — najcenniejsze.
- **grafika_do_weryfikacji** — niepewne: albo tabela samych etykiet S/M/L bez wymiarów, albo opis wspomina dobór/tabelę rozmiaru, lecz czart prawdopodobnie jest grafiką bez opisowej nazwy. **Heurystyka »ostatni img bywa lifestyle« (lekcja CHAT-T-099) — NIE przesądzamy, oznaczamy do ręcznej weryfikacji.**
- **nic** — brak jakiegokolwiek sygnału czartu (choć produkt może mieć warianty rozmiaru).
- Kalkulator inline (`<input>/<select>/<form>/JS`): **0 w całym zakresie** (zgodne z CHAT-T-101 — progi nie żyją w interaktywnym kalkulatorze w DB).

## Rozkład per kategoria zakresu

- skafander_suchy: 59
- skafander_mokry: 122
- buty: 51
- rekawice: 55

## Rozkład typu czartu (całość)

- grafika: 48
- tekst: 15
- grafika_do_weryfikacji: 104
- nic: 120
  - *(do_weryfikacji = 46 tabel samych etykiet bez progów + 58 wzmianek tekstowych)*

## Rozkład typu czartu per marka

| Marka | grafika | tekst | grafika_do_weryfikacji | nic | Σ |
|-------|--------:|------:|-----------------------:|----:|--:|
| SCUBAPRO | 5 | 1 | 41 | 17 | 64 |
| BARE | 18 | 5 | 18 | 18 | 59 |
| SANTI | 5 | 4 | 6 | 17 | 32 |
| AQUALUNG | 2 | 0 | 10 | 8 | 20 |
| MARES | 8 | 0 | 3 | 8 | 19 |
| TECLINE | 0 | 0 | 3 | 13 | 16 |
| TUSA | 0 | 0 | 7 | 7 | 14 |
| KWARK | 6 | 0 | 1 | 1 | 8 |
| Outlet | 1 | 1 | 3 | 2 | 7 |
| Avatar | 0 | 0 | 3 | 4 | 7 |
| TUSA  SPORT | 1 | 0 | 2 | 3 | 6 |
| Si-Tech | 0 | 0 | 0 | 5 | 5 |
| NO GRAVITY | 0 | 0 | 2 | 2 | 4 |
| Typhoon | 0 | 0 | 2 | 2 | 4 |
| SHOWA | 0 | 0 | 1 | 2 | 3 |
| (brak marki) | 1 | 0 | 0 | 2 | 3 |
| VDS System | 0 | 0 | 0 | 2 | 2 |
| FOURTH ELEMENT | 0 | 2 | 0 | 0 | 2 |
| BODY GLOVE | 0 | 0 | 0 | 2 | 2 |
| Aqua Zone | 1 | 0 | 1 | 0 | 2 |
| URSUIT | 0 | 1 | 0 | 0 | 1 |
| WATERPROOF | 0 | 0 | 0 | 1 | 1 |
| Checkup Dive Systems | 0 | 0 | 0 | 1 | 1 |
| SCUBATECH | 0 | 0 | 1 | 0 | 1 |
| EQUES | 0 | 1 | 0 | 0 | 1 |
| OMS | 0 | 0 | 0 | 1 | 1 |
| SEAL | 0 | 0 | 0 | 1 | 1 |
| SSI | 0 | 0 | 0 | 1 | 1 |

## Produkty z wariantami rozmiaru ALE bez pewnego czartu (priorytet pozyskania): 209

- SCUBAPRO: 57
- BARE: 36
- AQUALUNG: 18
- SANTI: 17
- TECLINE: 16
- TUSA: 13
- MARES: 11
- Avatar: 7
- Outlet: 5
- TUSA  SPORT: 5
- NO GRAVITY: 4
- Typhoon: 4
- SHOWA: 3
- VDS System: 2
- BODY GLOVE: 2
- KWARK: 2
- WATERPROOF: 1
- Checkup Dive Systems: 1
- SCUBATECH: 1
- OMS: 1
- Aqua Zone: 1
- (brak marki): 1
- SSI: 1

## Marki w zakresie wymagające pozyskania chartów (poza Scubapro/Bare)

Mamy już: **Scubapro, Bare** (ADR-099). Brakujące marki w zakresie:

- AQUALUNG (20 prod.)
- Aqua Zone (2 prod.)
- Avatar (7 prod.)
- BODY GLOVE (2 prod.)
- Checkup Dive Systems (1 prod.)
- EQUES (1 prod.)
- FOURTH ELEMENT (2 prod.)
- KWARK (8 prod.)
- MARES (19 prod.)
- NO GRAVITY (4 prod.)
- OMS (1 prod.)
- Outlet (7 prod.)
- SANTI (32 prod.)
- SCUBATECH (1 prod.)
- SEAL (1 prod.)
- SHOWA (3 prod.)
- SSI (1 prod.)
- Si-Tech (5 prod.)
- TECLINE (16 prod.)
- TUSA (14 prod.)
- TUSA  SPORT (6 prod.)
- Typhoon (4 prod.)
- URSUIT (1 prod.)
- VDS System (2 prod.)
- WATERPROOF (1 prod.)

---
*Diagnoza READ-ONLY, zero write. Następny etap: plan pozyskania chartów (OCR/research/dostawca) dla marek brakujących i pozycji nic/do-weryfikacji.*
