# PROMPT: Generacja encyklopedii — Grupa A: Oddychanie (15 pojęć)
# Model: GPT-5.2 thinking (lub Opus 4.6 extended — do uzgodnienia z Karolem)
# Wersja: pilot v1
# Data: 2026-02-27

---

## ROLA

Jesteś ekspertem od sprzętu nurkowego z 20-letnim doświadczeniem jako instruktor PADI/TDI
i właściciel sklepu nurkowego. Tworzysz encyklopedię sprzętową dla AI czatu e-commerce
(divezone.pl — największy sklep nurkowy w Polsce).

## CEL

Wygeneruj definicje operacyjne 15 pojęć z grupy "Oddychanie".
Każda definicja musi być na tyle precyzyjna, żeby AI czat:
1. Prawidłowo rozróżniał podobne produkty (np. wąż LP vs HP)
2. Nie łączył różnych SKU w jedno pojęcie
3. Znał potoczne nazwy klientów i mapował je na właściwe produkty
4. Wiedział czego NIE mylić z czym

## FORMAT WYJŚCIOWY (JSON)

Dla każdego pojęcia wygeneruj obiekt JSON:

```json
{
  "id": "AUTOMAT_ODDECHOWY",
  "nazwa_pl": "automat oddechowy",
  "nazwa_en": "regulator",
  "definicja": "Precyzyjny opis operacyjny (3-5 zdań). Musi zawierać: co to jest, do czego służy, z czego się składa/jak działa, kluczowe parametry zakupowe.",
  "podtypy": ["lista podtypów jeśli istnieją"],
  "synonimy_pl": {
    "exact": ["dokładne synonimy"],
    "near": ["bliskie synonimy"],
    "potoczne": ["jak klienci mówią w sklepie/Google"],
    "archaiczne": ["starsze terminy wciąż używane"]
  },
  "synonimy_en": {
    "exact": ["exact English synonyms"],
    "near": ["near English synonyms"]
  },
  "nie_mylic_z": [
    {
      "concept": "REBREATHER",
      "dlaczego": "Rebreather to obieg zamknięty/półzamknięty recyrkulujący gaz. Automat (regulator) to obieg otwarty wypuszczający bąble."
    }
  ],
  "parametry_zakupowe": ["parametry ważne przy wyborze produktu"],
  "marki_w_sklepie": ["marki dostępne w divezone.pl dla tej kategorii"],
  "powiazane_produkty": ["concept keys produktów kupowanych razem"],
  "faq": [
    {"pytanie": "...", "odpowiedz": "..."}
  ],
  "uwagi_dla_ai": "Specjalne instrukcje: co AI musi wiedzieć żeby nie popełnić błędu."
}
```

## LISTA 15 POJĘĆ DO WYGENEROWANIA

1. AUTOMAT_ODDECHOWY — automat oddechowy / regulator (pojęcie ogólne systemu oddychania w obiegu otwartym)
2. PIERWSZY_STOPIEN — pierwszy stopień automatu (reduktor ciśnienia z butli do pośredniego; przyłącze DIN — jedyny aktualny standard)
3. DRUGI_STOPIEN — drugi stopień automatu (dostarcza gaz do oddychania na żądanie)
4. OCTOPUS — octopus / alternatywne źródło gazu (zapasowy drugi stopień)
5. ZESTAW_AUTOMATU_REKR — zestaw automatu rekreacyjny (bundle: I st. + II st. + octopus + manometr + węże)
6. ZESTAW_AUTOMATU_TWIN — zestaw automatu do twinsetu (konfiguracja do butli podwójnych)
7. ZESTAW_AUTOMATU_STAGE — zestaw automatu do stage (konfiguracja do butli bocznych)
8. ZESTAW_AUTOMATU_SM — zestaw automatu sidemount (konfiguracja do sidemount)
9. WAZ_LP — wąż niskiego ciśnienia LP (do II stopnia/octopusa, końcówka 9/16" UNF)
10. WAZ_HP — wąż wysokiego ciśnienia HP (do manometru/transmitera, końcówka 7/16" UNF)
11. REBREATHER — rebreather (aparat oddechowy obiegu zamkniętego/półzamkniętego)
12. NITROX — nitrox / EANx (gaz oddechowy z podwyższoną zawartością tlenu powyżej 21%)
13. ANALIZATOR_TLENU — analizator tlenu / O2 analyzer (pomiar % tlenu w mieszance)
14. ZESTAW_SERWISOWY — zestaw serwisowy automatu / service kit (uszczelki, o-ringi, membrany)
15. MANOMETR — manometr / SPG (wskaźnik ciśnienia gazu w butli)


## ZNANE BŁĘDY Z POPRZEDNIEJ WERSJI (adversarial review 3 modeli)

NIE POWTARZAJ tych błędów. Każdy był zidentyfikowany niezależnie przez minimum 2 z 3 modeli AI.

### AUTOMAT_ODDECHOWY
- BŁĄD: "breathing apparatus" jako exact synonym → za szerokie, obejmuje rebreathery i SCBA
- BŁĄD: "demand valve" jako synonim → odnosi się tylko do II stopnia
- BŁĄD: "nie mylić z: szpulka, uprząż" → absurdalne pary. POWINNO BYĆ: nie mylić z rebreather
- BRAK: synonim potoczny "reg", "akwalung" (1600 wyszukiwań/mies.!)
- BRAK: info że "aparat oddechowy" w sklepie = TYLKO obieg otwarty

### PIERWSZY_STOPIEN
- BRAK: rozróżnienie tłokowy (piston) vs membranowy (diaphragm) — kluczowe dla klientów
- BRAK: info o portach HP i LP (ile, do czego)
- BRAK: info o przyłączu DIN (jedyny aktualny standard; INT/yoke to martwy standard, nie produkowany od ~10 lat, w Europie nigdy nie był używany)

### DRUGI_STOPIEN
- BRAK (minor): typy membranowy vs tłokowy

### OCTOPUS
- OK w v1, ale brak info o kolorystyce (żółty = standard ratowniczy)

### WAZ_DO_AUTOMATU (USUNIĘTY — rozbity na WAZ_LP + WAZ_HP + WAZ_INFLATORA)
- KRYTYCZNY BŁĄD: łączył 3 różne produkty (LP 9/16", HP 7/16", inflator QD) w jedno pojęcie
- Gemini: "Jeśli klient wkręci wąż LP do portu HP, wąż eksploduje pod wodą"
- BEZWZGLĘDNIE generuj WAZ_LP i WAZ_HP jako OSOBNE pojęcia z ostrzeżeniem o niekompatybilności

### REBREATHER
- BŁĄD: definicja sugeruje rebreather = tylko CCR. Brakuje SCR (półzamknięty)

### INFLATOR (w grupie C, ale powiązany)
- BŁĄD: definicja łączyła mechanizm inflatora z wężem karbowanym — to osobne SKU
- Inflator = głowica z przyciskami, wąż inflatora = wąż LP z QD, wąż karbowany = karbowany przewód

### MANOMETR
- BRAK: synonim "zegarek" (archaiczny), rozróżnienie cyfrowy vs analogowy

## MARKI W DIVEZONE.PL DLA TEJ GRUPY

Automaty oddechowe: APEKS, SCUBAPRO, ATOMIC AQUATICS, AQUALUNG, TECLINE
Także w ofercie (mniej produktów): POSEIDON, SCUBATECH, xDEEP, MARES, HOLLIS
Instrumenty (manometry): SUUNTO, TECLINE, TERMO, MARES, SCUBAPRO, AQUALUNG

MARKI ZAKAZANE (nie rekomendować, nie ma w ofercie):
Cressi, Sherwood Scuba, Dive Rite, DIR Zone, Divesoft, Oceanic, Aeris, Subgear

## FRAZY KLIENTÓW Z GOOGLE PL (DataForSEO)

Użyj tych fraz jako źródło synonimów potocznych i FAQ:

| Fraza | Wolumen/mies. | Uwagi |
|-------|---------------|-------|
| akwalung | 1600 | archaiczny ale masowo szukany! |
| fajka do nurkowania | 260 | (osobna grupa E, ale powiązana) |
| automat nurkowy | 140 | |
| automat do nurkowania | 140 | |
| automat oddechowy | 140 | |
| serwis nurkowy | 110 | |
| manometr nurkowy | 70 | |
| automat oddechowy do nurkowania | 50 | |
| octopus nurkowanie | 30 | |
| automaty oddechowe | 30 | |
| wąż do inflatora | 20 | (grupa C) |
| octopus nurkowy | 20 | |
| serwis automatów nurkowych | 20 | |
| wąż HP do manometru | 10 | |
| automat oddechowy apeks | 10 | |
| automat oddechowy aqualung | 10 | |

## KRYTYCZNE REGUŁY

1. **Jedna definicja = jeden typ SKU.** Nigdy nie łącz produktów o różnych końcówkach, ciśnieniach roboczych lub funkcjach.
2. **Synonimy potoczne są WAŻNIEJSZE niż techniczne.** Klienci piszą "automat nurkowy" (140/mies.), nie "regulator oddechowy obiegu otwartego".
3. **"Nie mylić z" musi mieć sens.** Tylko pary które klienci REALNIE mylą. Nie wymyślaj absurdalnych par.
4. **Parametry zakupowe = to po czym klient filtruje.** Np. tłokowy vs membranowy, długość węża, średnica przyłącza. NIE "DIN vs INT" (INT martwy standard).
5. **FAQ oparte na realnych pytaniach klientów.** Użyj fraz z DataForSEO i typowych pytań w sklepie.
6. **Bezpieczeństwo:** Jeśli pomyłka między produktami może być niebezpieczna (np. wąż LP w porcie HP), MUSI być ostrzeżenie w "uwagi_dla_ai".
7. **Kontekst sklepowy divezone.pl:** Definicje muszą mapować się na realne kategorie i produkty w sklepie. Nie opisuj produktów których sklep nie sprzedaje.
8. **Marki:** W polu "marki_w_sklepie" wymieniaj TYLKO marki z listy powyżej. Nigdy nie rekomenduj marek zakazanych.
9. **DIN to JEDYNY aktualny standard przyłącza.** INT/yoke to martwy standard, nie produkowany od ~10 lat. W Europie nigdy nie był powszechny. Nie traktuj INT jako równorzędnej opcji. Jeśli wspominasz INT, zawsze zaznacz że to archaiczny standard spotykany już tylko w egzotycznych lokalizacjach.

## STRUKTURA KATEGORII W SKLEPIE (divezone.pl)

Automaty Oddechowe:
- Zestawy rekreacyjne
- Marki: APEKS, AQUALUNG, ATOMIC, POSEIDON, SCUBAPRO, SCUBATECH, TECLINE, xDEEP
- Akcesoria do automatów

Węże (osobna kategoria główna!):
- Węże do Automatów (= WAZ_LP)
- Węże do Manometrów (= WAZ_HP)
- Węże do Inflatorów (= WAZ_INFLATORA, grupa C)
- Węże do Skafandrów (= WAZ_SUCHY_SKAFANDER, grupa G)

Instrumenty pomiarowe:
- Manometry
- Konsole
- Kompasy
- Zegarki nurkowe


## SELF-CHECK (wykonaj PRZED zwróceniem wyników)

Dla KAŻDEGO wygenerowanego pojęcia sprawdź:

1. ✅ Czy definicja opisuje DOKŁADNIE JEDEN typ SKU? (nie łączy różnych produktów)
2. ✅ Czy synonimy "exact" są RZECZYWIŚCIE wymienne 1:1?
3. ✅ Czy "nie_mylic_z" zawiera tylko pary które klienci REALNIE mylą?
4. ✅ Czy marki_w_sklepie zawierają TYLKO marki z dozwolonej listy?
5. ✅ Czy WAZ_LP i WAZ_HP mają ostrzeżenie o niekompatybilności i zagrożeniu bezpieczeństwa?
6. ✅ Czy REBREATHER rozróżnia CCR i SCR?
7. ✅ Czy PIERWSZY_STOPIEN zawiera info o DIN jako jedynym standardzie (INT tylko wzmianka jako archaiczny)?
8. ✅ Czy FAQ bazuje na realnych frazach klientów (nie wymyślonych)?
9. ✅ Czy nigdzie INT/yoke nie jest przedstawiony jako równorzędna opcja do DIN?

## OUTPUT

Zwróć wynik jako tablicę 15 obiektów JSON w formacie opisanym powyżej.
Każdy obiekt na osobnej linii, pełny i kompletny.
Nie pomijaj żadnego pola. Jeśli pole nie dotyczy danego pojęcia, użyj pustej tablicy [].
