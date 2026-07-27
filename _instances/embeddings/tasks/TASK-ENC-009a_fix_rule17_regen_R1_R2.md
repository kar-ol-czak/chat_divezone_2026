# TASK-ENC-009a: Poprawka zasady #17 + regeneracja R1 i R2
# Data: 2026-03-05
# Status: DO ZROBIENIA
# Instancja: embeddings
# Zależność: TASK-ENC-009 R1 i R2 zakończone, review architekta wykazał 2 problemy

---

## PROBLEM

1. **Zasada #17 za ogólna** — Gemini nie mapuje nazw kategorii sklepowych z pliku
   dane_sprzedazowe_crosssell_12m.md na hasła encyklopedii. Efekt: ZERO cytowanych %
   w R1 (15 haseł) mimo że dane istnieją. Np. "Węże do Manometrów → Manometry: 40.5%"
   nie trafiło do hasła WĄŻ HP ani MANOMETR.

2. **Hasło AUTOMAT ODDECHOWY** — "Certyfikacja EN250A" błędnie umieszczona jako
   parametr zakupowy. To standard rynkowy w Polsce (zasada #8), powinno być w FAQ.

## KROK 1: Zamień zasadę #17 w prompcie

Plik: `_docs/PROMPT_gemini_encyklopedia_v3.md`

Znajdź istniejącą zasadę #17 i ZASTĄP ją poniższą wersją:

```markdown
### ZASADA #17: CROSS-SELL Z DANYMI SPRZEDAŻOWYMI
W sekcji "Powiązane produkty (Cross-selling)" ZAWSZE cytuj konkretne procenty
z pliku dane_sprzedazowe_crosssell_12m.md. Mapuj nazwy kategorii sklepowych
na hasła encyklopedii:
- "Węże do Automatów" → WĄŻ LP
- "Węże do Manometrów" → WĄŻ HP
- "Węże do Inflatorów" → WĄŻ INFLATORA BCD
- "Manometry" → MANOMETR
- "Akcesoria do automatów" → ZESTAW SERWISOWY AUTOMATU
- "Systemy Balastowe" → KIESZENIE NA BALAST (ZINTEGROWANE)
- "Skrzydła single" → SKRZYDŁO Z UPRZĘŻĄ DO POJEDYNCZEJ BUTLI
- "Płyty i uprzęże" → BACKPLATE / UPRZĄŻ
- "Skafandry zimne" → SUCHY SKAFANDER
- "Ocieplacze do suchych" → OCIEPLACZ
- "Rękawice i Pierścienie" → SYSTEM SUCHYCH RĘKAWIC / RĘKAWICE NURKOWE
- "Bojki dekompresyjne" → BOJA NURKOWA
- "Szpulki" → SZPULKA
- "Butle Aluminiowe" → BUTLA NURKOWA
- "Zawory do butli" → ZAWÓR BUTLOWY
- "Płetwy Paskowe" → PŁETWY PASKOWE
- "Płetwy Kaloszowe" → PŁETWY KALOSZOWE
- "Płetwy Gumowe JET" → PŁETWY JET FINS
- "Komputery SUUNTO" / "Komputery SHEARWATER" → KOMPUTER NURKOWY
- "Zestawy Maska+Fajka" → ZESTAW MASKA + FAJKA
- "Maski jednoszybowe" → MASKA JEDNOSZYBOWA
- "Maski dwuszybowe" → MASKA DWUSZYBOWA
- "Maski korekcyjne" → MASKA KOREKCYJNA
- "Maski panoramiczne" → MASKA PANORAMICZNA
- "Side Mount" → SIDEMOUNT
- "Karabinki nurkowe" → KARABINEK NURKOWY
- "Buty" → BUTY NURKOWE NEOPRENOWE / BUTY DO SUCHEGO SKAFANDRA
- "Kaptury" → KAPTUR NURKOWY
- "Noże" → NÓŻ NURKOWY

Format cytowania:
"Wąż HP (→ WAZ_HP) — 40.5% klientów kupujących węże do manometrów
kupuje je razem z manometrem [dane sprzedażowe]"

Cytuj też ranking kategorii jeśli relevantny:
"Akcesoria do automatów to #7 najczęściej kupowana kategoria (421 zamówień/rok)"

Cytuj TEŻ dane z pliku dane_sprzedazowe_bestsellery_12m.md jeśli wymieniasz
konkretny produkt jako rekomendację.

Jeśli dane sprzedażowe nie zawierają statystyk dla danego cross-sell,
napisz rekomendację bez procentów. NIE wymyślaj liczb.
```

## KROK 2: Regeneruj R1

Uruchom DOKŁADNIE te same batche co w R1 (batch 0-2, 15 haseł grupy A).
Output: `data/encyclopedia/v3/batch/R1_oddychanie.md` (nadpisz stary)
Raport: `data/encyclopedia/v3/batch/R1_report.md` (nadpisz)

Przed generacją zachowaj stary R1:
```bash
mv data/encyclopedia/v3/batch/R1_oddychanie.md data/encyclopedia/v3/batch/R1_oddychanie_v1.md
mv data/encyclopedia/v3/batch/R1_report.md data/encyclopedia/v3/batch/R1_report_v1.md
```

## KROK 3: Regeneruj R2

To samo dla R2 (batch 3-4, 9 haseł grupy B: butle i zawory).
Output: `data/encyclopedia/v3/batch/R2_butle_zawory.md` (nadpisz)
Raport: `data/encyclopedia/v3/batch/R2_report.md` (nadpisz)

Zachowaj stary:
```bash
mv data/encyclopedia/v3/batch/R2_butle_zawory.md data/encyclopedia/v3/batch/R2_butle_zawory_v1.md
mv data/encyclopedia/v3/batch/R2_report.md data/encyclopedia/v3/batch/R2_report_v1.md
```

## KROK 4: STOP

Czekaj na review architekta obu rund.

## KRYTERIA SUKCESU

### R1 (sprawdzam te hasła pod kątem danych sprzedażowych):
- MANOMETR: powinien cytować "40.5% klientów kupuje z wężem HP" i/lub "26.8% → węże HP"
- WĄŻ LP: powinien cytować "22.0% → węże do manometrów", "21.6% → węże do inflatorów"
- WĄŻ HP: powinien cytować "40.5% → manometry", "33.7% → węże do automatów"
- AUTOMAT ODDECHOWY: EN250A NIE w parametrach zakupowych, TAK w FAQ
- ZESTAW REKR: powinien cytować ranking "Akcesoria do automatów #7 (421 zam.)"

### R2 (sprawdzam):
- BUTLA NURKOWA: "70.6% kupuje razem z zaworem" (Butle Alu → Zawory)
- ZAWÓR BUTLOWY: "49.0% → Butle Aluminiowe"

## NIE RÓB

- Nie zmieniaj zasad #1-#16, #18-#20
- Nie modyfikuj test/, test_v2/, test_v3/
- Nie uruchamiaj R3-R8
