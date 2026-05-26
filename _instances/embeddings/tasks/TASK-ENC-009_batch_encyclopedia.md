# TASK-ENC-009: Batch generacja encyklopedii — Gemini 3.1 Pro
# Data: 2026-03-05
# Status: DO ZROBIENIA
# Instancja: embeddings
# Zależność: TASK-ENC-008c DONE (Gemini v3 zwalidowany, zasady #17-#20 w prompcie)

---

## CEL

Generacja 106 haseł encyklopedii w 8 rundach tematycznych.
Model: Gemini 3.1 Pro (gemini-3.1-pro-preview).
Workflow: CC generuje rundę → zapisuje do pliku → STOP → czeka na OK architekta.

## KROK 0: Poprawka pozycji long-tail w prompcie (PRZED startem!)

Plik: `_docs/PROMPT_gemini_encyklopedia_v3.md`

W zasadzie #18 zmień tekst tak, aby zawierał jawną instrukcję pozycji:

Dodaj na końcu zasady #18:
```
WAŻNE: Sekcję "Frazy long-tail" umieść BEZPOŚREDNIO po sekcji "Synonimy
i słowa kluczowe", NIE na końcu hasła.
```

## KROK 1-8: Generacja po rundach

Uruchom batch GRUPAMI poniżej. Po każdej grupie ZATRZYMAJ SIĘ i czekaj
na feedback architekta. NIE uruchamiaj następnej grupy bez OK.

Output per runda: `data/encyclopedia/v3/batch/R{N}_{nazwa_grupy}.md`
Raport per runda: `data/encyclopedia/v3/batch/R{N}_report.md`

### Runda R1: Grupa A — Oddychanie (batch 1-3, 15 haseł)

Batche ze skryptu: 1, 2, 3
```
Batch 1: AUTOMAT ODDECHOWY, PIERWSZY STOPIEŃ, DRUGI STOPIEŃ, OCTOPUS, ZESTAW REKR
Batch 2: ZESTAW TWIN, ZESTAW STAGE, ZESTAW SM, WĄŻ LP, WĄŻ HP
Batch 3: REBREATHER, NITROX, ANALIZATOR TLENOWY, ZESTAW SERWISOWY, MANOMETR
```

Komenda:
```bash
python3 scripts/generate_encyclopedia.py --phase batch --model gemini --start-batch 0 --end-batch 2
```

Jeśli --end-batch nie istnieje, dodaj go do skryptu (prosty arg, stop po batch N).

Output: `data/encyclopedia/v3/batch/R1_oddychanie.md`
Raport: `data/encyclopedia/v3/batch/R1_report.md` (tokeny, czas, chars/hasło, errors)

**→ STOP. Czekaj na review architekta.**

### Runda R2: Grupa B — Butle i zawory (batch 4-5, 9 haseł)

Batche: 4, 5
```
Batch 4: BUTLA NURKOWA, BUTLA STAGE, BUTLA ARGON, TWINSET, MANIFOLD
Batch 5: ZAWÓR BUTLOWY, ZŁĄCZE DIN, ZŁĄCZE INT, ADAPTER DIN/INT
```

Output: `data/encyclopedia/v3/batch/R2_butle_zawory.md`
**→ STOP.**

### Runda R3: Grupa C — Kontrola pływalności (batch 6-9, 16 haseł)

Batche: 6, 7, 8, 9
```
Batch 6: JACKET, SKRZYDŁO, BACKPLATE, UPRZĄŻ, ZESTAW SKRZYDŁO SINGLE
Batch 7: ZESTAW SKRZYDŁO TWIN, INFLATOR, WĄŻ INFLATORA, WĄŻ KARBOWANY, ZAWÓR UPUSTOWY
Batch 8: SIDEMOUNT, BALAST, PAS BALASTOWY, KIESZENIE ZINTEGROWANE, TRYMÓWKA
Batch 9: SZELKI STAGE
```

Output: `data/encyclopedia/v3/batch/R3_kontrola_plywalnisci.md`
**→ STOP.**

### Runda R4: Grupa D — Instrumenty i nawigacja (batch 10-11, 6 haseł)

Batche: 10, 11
```
Batch 10: KOMPUTER NURKOWY, TRANSMITER, KONSOLA, KOMPAS, ZEGAREK
Batch 11: TABLICZKA DO PISANIA
```

Output: `data/encyclopedia/v3/batch/R4_instrumenty.md`
**→ STOP.**

### Runda R5: Grupy E+F — Maski + Pianki (batch 12-15, 13 haseł)

Batche: 12, 13, 14, 15
```
Batch 12: MASKA JEDNOSZYBOWA, DWUSZYBOWA, PEŁNOTWARZOWA, KOREKCYJNA, PANORAMICZNA
Batch 13: MASKA DZIECI, ZESTAW MASKA+FAJKA, FAJKA
Batch 14: PIANKA MOKRA, ZIMNE WODY, CIEPŁE WODY, SHORTY, KOMPLET
Batch 15: PIANKA PÓŁSUCHA, DOCIEPLACZ, KAPTUR
```

Output: `data/encyclopedia/v3/batch/R5_maski_pianki.md`
**→ STOP.**

### Runda R6: Grupa G — Suchy skafander + Rękawice (batch 16-18, 11 haseł)

Batche: 16, 17, 18
```
Batch 16: SUCHY SKAFANDER, ZAWORY SUCHEGO, ZAWÓR UPUSTOWY, WĄŻ SUCHEGO, MANSZETY
Batch 17: SYSTEM SUCHYCH RĘKAWIC, BUTY SUCHEGO, OGRZEWANIE, OCIEPLACZ, ODZIEŻ TERMO
Batch 18: RĘKAWICE NURKOWE
```

Output: `data/encyclopedia/v3/batch/R6_suchy_skafander.md`
**→ STOP.**

### Runda R7: Grupy H+I+J — Płetwy + Bezpieczeństwo + Oświetlenie (batch 19-23, 15 haseł)

Batche: 19, 20, 21, 22, 23
```
Batch 19: BUTY NEOPRENOWE, PŁETWY PASKOWE, KALOSZOWE, JET FINS, SPRĘŻYNY
Batch 20: RASHGUARD
Batch 21: BOJA, SZPULKA, KOŁOWROTEK, WOREK PODNOSZĄCY, NÓŻ
Batch 22: SEKATOR, ŚWIATŁO CHEMICZNE, LATARKA, LAMPA FOTO
Batch 23: OBUDOWA PODWODNA, GOPRO, KARABINEK, RETRACTOR
```

Output: `data/encyclopedia/v3/batch/R7_pletwy_bezp_oswietlenie.md`
**→ STOP.**

### Runda R8: Grupy K+L+M — Akcesoria + Zestawy (batch 24-26, 14 haseł)

Batche: 24, 25, 26
```
Batch 24: TORBA, SKRZYNIA, ANTIFOG, PASEK MASKI, SMAR SILIKONOWY
Batch 25: KLEJ NEOPRENOWY, O-RING, SUSZARKA/WIESZAK, ZESTAW SERWISOWY (UWAGA: duplikat z batch 3!)
Batch 26: ODZIEŻ NURKOWA, KSIĄŻKI, LOGBOOK, MORSOWANIE, ZESTAW DO NURKOWANIA
```

Output: `data/encyclopedia/v3/batch/R8_akcesoria_zestawy.md`

## UWAGA: DUPLIKAT

ZESTAW SERWISOWY AUTOMATU występuje w batch 3 (R1) i batch 25 (R8).
Usuń go z batch 25 przed generacją.

## WYMAGANIA TECHNICZNE

1. Dodaj --end-batch arg do skryptu (inclusive, 0-indexed)
2. Output per rundę do JEDNEGO pliku .md (nie osobne pliki per batch)
3. Raport per rundę: tokeny in/out, czas, chars total, avg chars/hasło, errors
4. Między wywołaniami API: sleep 5s (już jest w skrypcie)
5. Timeout per wywołanie: 120s (Gemini ~80-90s, margines bezpieczeństwa)

## STRUCTURE OUTPUT DIR

```
data/encyclopedia/v3/batch/
├── R1_oddychanie.md          (15 haseł)
├── R1_report.md
├── R2_butle_zawory.md        (9 haseł)
├── R2_report.md
├── R3_kontrola_plywalnisci.md (16 haseł)
├── R3_report.md
├── R4_instrumenty.md         (6 haseł)
├── R4_report.md
├── R5_maski_pianki.md        (13 haseł)
├── R5_report.md
├── R6_suchy_skafander.md     (11 haseł)
├── R6_report.md
├── R7_pletwy_bezp_oswietlenie.md (15 haseł)
├── R7_report.md
├── R8_akcesoria_zestawy.md   (14 haseł, minus duplikat = 13)
├── R8_report.md
└── (po wszystkich rundach)
    └── encyclopedia_v3_all.md  (merge wszystkich R1-R8)
```

## NIE RÓB

- Nie uruchamiaj rundy N+1 bez OK architekta na rundę N
- Nie modyfikuj zasad #1-#20 (chyba że architekt powie inaczej)
- Nie zmieniaj model stringów
- Nie nadpisuj test/, test_v2/, test_v3/
