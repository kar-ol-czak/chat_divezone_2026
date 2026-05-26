# TASK-ENC-009b: Rozszerzenie mapowania CONCEPT_TO_SHOP_CATEGORIES
# Data: 2026-03-05
# Status: DO ZROBIENIA
# Instancja: embeddings
# Zależność: TASK-ENC-009a DONE, review architekta R1/R2

---

## PROBLEM

`CONCEPT_TO_SHOP_CATEGORIES` w `scripts/generate_encyclopedia.py` (linia ~485)
ma tylko 14 wpisów. Brakuje ~90 haseł. Efekt: batche bez zmapowanych haseł
nie dostają ŻADNYCH danych sprzedażowych w kontekście API → Gemini nie cytuje %.

Dotyczy to krytycznych haseł: WĄŻ LP, WĄŻ HP, MANOMETR, ZAWÓR BUTLOWY,
OCIEPLACZ, RĘKAWICE, SIDEMOUNT, BACKPLATE, INFLATOR, KAPTUR, BUTY itd.

## ROZWIĄZANIE

### Krok 1: Rozszerz CONCEPT_TO_SHOP_CATEGORIES

Uzupełnij mapowanie. Klucze to nazwy haseł (krótkie, przed "/"),
wartości to fragmenty stringów do matchowania w plikach sprzedażowych.
Matchowanie jest case-insensitive (`kw.lower() in line_lower`).

Dodaj co najmniej te wpisy (zachowaj istniejące 14):

```python
CONCEPT_TO_SHOP_CATEGORIES: dict[str, list[str]] = {
    # --- ISTNIEJĄCE (nie zmieniaj) ---
    "AUTOMAT ODDECHOWY": ["automaty", "zestawy", "regulator"],
    "JACKET": ["jacket", "bcd", "kamizelk"],
    "SKRZYDŁO": ["skrzydł", "wing"],
    "SUCHY SKAFANDER": ["suchy", "skafandr", "drysuit"],
    "PIANKA MOKRA": ["piank", "wetsuit", "neopren"],
    "KOMPUTER NURKOWY": ["komputer", "computer"],
    "LATARKA NURKOWA": ["latark", "oświetl", "light"],
    "BUTLA NURKOWA": ["butl", "cylinder"],
    "PŁETWY PASKOWE": ["płetw", "fins"],
    "PŁETWY KALOSZOWE": ["płetw", "fins"],
    "MASKA JEDNOSZYBOWA": ["mask"],
    "BOJA NURKOWA": ["boj", "smb", "szpulk"],
    "BALAST": ["balast", "ciężar"],
    "TORBA NURKOWA": ["torb", "bag"],
    # --- NOWE ---
    # Grupa A: Oddychanie
    "PIERWSZY STOPIEŃ AUTOMATU": ["automaty", "akcesoria do automatów"],
    "DRUGI STOPIEŃ AUTOMATU": ["automaty", "akcesoria do automatów"],
    "OCTOPUS": ["automaty", "akcesoria do automatów"],
    "ZESTAW AUTOMATÓW REKREACYJNY": ["automaty", "zestawy", "akcesoria do automatów"],
    "ZESTAW AUTOMATÓW DO TWINSETU": ["automaty", "zestawy"],
    "ZESTAW AUTOMATU DO STAGE": ["automaty", "stage"],
    "ZESTAW AUTOMATÓW SIDEMOUNT": ["automaty", "side mount", "sidemount"],
    "WĄŻ LP": ["węże do automatów", "węże do inflator"],
    "WĄŻ HP": ["węże do manometr", "manometr"],
    "MANOMETR": ["manometr", "węże do manometr"],
    "ZESTAW SERWISOWY AUTOMATU": ["akcesoria do automatów", "serwis"],
    "REBREATHER": ["rebreather"],
    "NITROX": ["nitrox"],
    "ANALIZATOR TLENOWY": ["analizator"],
    # Grupa B: Butle i zawory
    "BUTLA STAGE": ["butl", "stage"],
    "BUTLA DO ARGONU": ["butl", "argon"],
    "TWINSET": ["butl", "twinset", "manifold"],
    "MANIFOLD": ["manifold", "obejm"],
    "ZAWÓR BUTLOWY": ["zawor", "zawór", "butl"],
    "ZŁĄCZE DIN": ["din"],
    "ZŁĄCZE INT/YOKE": ["int", "yoke"],
    "ADAPTER DIN/INT": ["adapter"],
    # Grupa C: Kontrola pływalności
    "BACKPLATE": ["płyt", "uprzęż", "backplate"],
    "UPRZĄŻ": ["płyt", "uprzęż", "harness"],
    "SKRZYDŁO Z UPRZĘŻĄ DO POJEDYNCZEJ BUTLI": ["skrzydł", "systemy balast"],
    "SKRZYDŁO Z UPRZĘŻĄ DO TWINA": ["skrzydł", "twinset"],
    "INFLATOR BCD": ["inflator", "węże do inflator"],
    "WĄŻ INFLATORA BCD": ["węże do inflator"],
    "WĄŻ KARBOWANY": ["karbowany", "inflator"],
    "ZAWÓR UPUSTOWY BCD": ["upustow"],
    "SIDEMOUNT": ["side mount", "sidemount"],
    "PAS BALASTOWY": ["balast", "pas"],
    "KIESZENIE NA BALAST": ["systemy balast", "balast"],
    "TRYMÓWKA": ["trym", "balast"],
    "SZELKI STAGE": ["stage", "szelki"],
    # Grupa D: Instrumenty
    "TRANSMITER CIŚNIENIA": ["transmit", "komputer"],
    "KONSOLA NURKOWA": ["konsol", "manometr"],
    "KOMPAS NURKOWY": ["kompas"],
    "ZEGAREK NURKOWY": ["zegarek"],
    "TABLICZKA DO PISANIA": ["tabliczk"],
    # Grupa E: Maski
    "MASKA DWUSZYBOWA": ["mask", "korekcyj"],
    "MASKA PEŁNOTWARZOWA": ["mask"],
    "MASKA KOREKCYJNA": ["mask", "korekcyj"],
    "MASKA PANORAMICZNA": ["mask", "panoram"],
    "MASKA DO NURKOWANIA DLA DZIECI": ["mask"],
    "ZESTAW MASKA + FAJKA": ["maska+fajka", "zestawy maska", "fajk"],
    "FAJKA NURKOWA": ["fajk"],
    # Grupa F: Pianki
    "PIANKA NA ZIMNE WODY": ["piank"],
    "PIANKA NA CIEPŁE WODY": ["piank"],
    "PIANKA SHORTY": ["piank", "shorty"],
    "KOMPLET PIANEK": ["piank"],
    "PIANKA PÓŁSUCHA": ["piank", "półsuch"],
    "DOCIEPLACZ": ["docieplacz", "ocieplacz"],
    "KAPTUR NURKOWY": ["kaptur"],
    # Grupa G: Suchy skafander
    "ZAWORY SUCHEGO SKAFANDRA": ["suchy", "zawor"],
    "WĄŻ DO SUCHEGO SKAFANDRA": ["suchy", "wąż"],
    "MANSZETY DO SUCHEGO SKAFANDRA": ["manszet", "suchy"],
    "SYSTEM SUCHYCH RĘKAWIC": ["rękawic", "pierścien"],
    "BUTY DO SUCHEGO SKAFANDRA": ["buty", "suchy"],
    "OGRZEWANIE NURKOWE": ["ogrzewan", "grzew"],
    "OCIEPLACZ": ["ocieplacz", "ocieplacze do suchych"],
    "ODZIEŻ TERMOAKTYWNA": ["termoaktywn", "bielizn"],
    "RĘKAWICE NURKOWE": ["rękawic"],
    # Grupa H: Płetwy, buty
    "BUTY NURKOWE NEOPRENOWE": ["buty"],
    "PŁETWY JET FINS": ["płetw", "jet"],
    "SPRĘŻYNY DO PŁETW": ["sprężyn", "płetw"],
    "RASHGUARD": ["rashguard"],
    # Grupa I: Bezpieczeństwo
    "SZPULKA": ["szpulk", "bojk"],
    "KOŁOWROTEK": ["kołowrot", "reel"],
    "WOREK PODNOSZĄCY": ["worek", "podnosz"],
    "NÓŻ NURKOWY": ["noż", "nóż"],
    "SEKATOR": ["sekator", "cutter"],
    "ŚWIATŁO CHEMICZNE": ["światło", "cyalume"],
    # Grupa J: Oświetlenie + foto
    "LAMPA FOTO/VIDEO": ["lamp", "foto", "video"],
    "OBUDOWA PODWODNA": ["obudow", "housing"],
    "KAMERA GOPRO": ["gopro", "kamer"],
    # Grupa K: Akcesoria
    "KARABINEK NURKOWY": ["karabink"],
    "RETRACTOR": ["retractor"],
    "SKRZYNIA TRANSPORTOWA": ["skrzyn", "transport"],
    "ANTIFOG": ["antifog", "anti-fog"],
    "PASEK DO MASKI": ["pasek", "mask"],
    "SMAR SILIKONOWY": ["smar", "silikon"],
    "KLEJ NEOPRENOWY": ["klej"],
    "O-RING": ["o-ring", "oring"],
    "SUSZARKA / WIESZAK": ["suszark", "wieszak"],
    # Grupa M: Zestawy, inne
    "ODZIEŻ NURKOWA": ["odzież"],
    "LOGBOOK NURKOWY": ["logbook"],
    "ZESTAW DO NURKOWANIA": ["zestaw"],
}
```

### Krok 2: Opcjonalnie — zwiększ limit wyników

W `extract_crosssell_for_categories()` jest `result_lines[:40]`.
Dla dużych batchy (5 haseł z wieloma kategoriami) 40 linii może nie wystarczyć.
Zwiększ do 60.

### Krok 3: NIE regeneruj R1/R2

To fix na przyszłe rundy (R3-R8). R1 i R2 są approved.
Uruchom R3 po zrobieniu fixu.

## WERYFIKACJA

Po fixie, przed R3, zrób dry-run: wypisz jakie linie crosssell matchują
dla batcha 6 (JACKET, SKRZYDŁO, BACKPLATE, UPRZĄŻ, SKRZYDŁO SINGLE).
Oczekiwane: linia "Skrzydła single → Systemy Balastowe: 43.5%".

## NIE RÓB

- Nie regeneruj R1/R2
- Nie zmieniaj promptu
- Nie uruchamiaj R3 bez weryfikacji dry-run


---

## DODATKOWE ZADANIE: Integracja transkrypcji sidemount (PRZED R3!)

Nowy plik z wiedzą eksperta:
`_docs/wiedza_nurkowa/Kwestionariusz_Eksperta/sidemount.txt`

### Krok A: Dołącz do transkryptu głównego

Plik: `_docs/wiedza_nurkowa/transkrypt_kwestionariusza_eksperta.md`

1. Zmień header z "Grup: 19 (brak osobnego nagrania sidemount...)" 
   na "Grup: 20"
2. Na końcu pliku dodaj sekcję:

```markdown

---

## Grupa 20: Sidemount

[tutaj wklej pełną treść z sidemount.txt]
```

### Krok B: Zaktualizuj CONCEPT_TO_EXPERT_GROUP w skrypcie

W `scripts/generate_encyclopedia.py`, zmień mapowanie SIDEMOUNT:

```python
"SIDEMOUNT": ["Grupa 2: BCD, jackety, skrzydla", "Grupa 20: Sidemount"],
```

Dodaj też mapowanie dla zestawu automatów SM (w R1 nie miał danych SM):
```python
"ZESTAW AUTOMATÓW SIDEMOUNT": ["Grupa 1: Automaty oddechowe", "Grupa 20: Sidemount"],
```

### Krok C: Weryfikacja

Po dodaniu, zrób grep:
```bash
grep -c "Grupa 20" _docs/wiedza_nurkowa/transkrypt_kwestionariusza_eksperta.md
# powinno zwrócić 1

grep "Grupa 20" scripts/generate_encyclopedia.py
# powinno zwrócić 2 linie (SIDEMOUNT i ZESTAW AUTOMATÓW SIDEMOUNT)
```
