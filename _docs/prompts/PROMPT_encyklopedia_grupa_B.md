# PROMPT: Generacja encyklopedii — Grupa B: Butle i zawory (9 pojęć)
# Model: GPT-5.2 thinking (minimum)
# Wersja: v1
# Data: 2026-02-27

---

## ROLA

Jesteś ekspertem od sprzętu nurkowego z 20-letnim doświadczeniem jako instruktor PADI/TDI
i właściciel sklepu nurkowego. Tworzysz encyklopedię sprzętową dla AI czatu e-commerce
(divezone.pl — największy sklep nurkowy w Polsce).

## CEL

Wygeneruj definicje operacyjne 9 pojęć z grupy "Butle i zawory".
Każda definicja musi być na tyle precyzyjna, żeby AI czat:
1. Prawidłowo rozróżniał podobne produkty (np. butla stalowa vs aluminiowa)
2. Nie łączył różnych SKU w jedno pojęcie
3. Znał potoczne nazwy klientów i mapował je na właściwe produkty
4. Wiedział czego NIE mylić z czym
5. Korygował błędną terminologię klientów (np. "butla z tlenem" → butla ze sprężonym powietrzem)

## FORMAT WYJŚCIOWY (JSON)

Dla każdego pojęcia wygeneruj obiekt JSON:

```json
{
  "id": "BUTLA_NURKOWA",
  "nazwa_pl": "butla nurkowa",
  "nazwa_en": "scuba cylinder / scuba tank",
  "definicja": "Precyzyjny opis operacyjny (3-5 zdań). Musi zawierać: co to jest, do czego służy, z czego się składa/jak działa, kluczowe parametry zakupowe.",
  "podtypy": ["lista podtypów jeśli istnieją"],
  "synonimy_pl": {
    "exact": ["dokładne synonimy"],
    "near": ["bliskie synonimy"],
    "potoczne": ["jak klienci mówią w sklepie/Google"],
    "archaiczne": ["starsze terminy wciąż używane"],
    "bledne_ale_popularne": ["niepoprawne terminy masowo wyszukiwane, np. butla z tlenem"]
  },
  "synonimy_en": {
    "exact": ["exact English synonyms"],
    "near": ["near English synonyms"]
  },
  "nie_mylic_z": [
    {
      "concept": "TWINSET",
      "dlaczego": "Twinset to dwie butle połączone manifoldem. Butla nurkowa to pojedynczy zbiornik."
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

## LISTA 9 POJĘĆ DO WYGENEROWANIA

1. BUTLA_NURKOWA — butla nurkowa (stalowa / aluminiowa). Pojedynczy zbiornik na sprężony gaz oddechowy. Podtypy: stalowa, aluminiowa. Kluczowe parametry: materiał, pojemność (l), ciśnienie robocze (200/232/300 bar), waga.
2. BUTLA_STAGE — butla stage (dodatkowa butla boczna). Butla do nurkowania technicznego, mocowana z boku. Zawiera gaz dekompresyjny, bottom gas lub bailout.
3. BUTLA_ARGONU — butla do argonu. Mała butla (0.4-2l) do napełniania suchego skafandra argonem. NIE jest gazem oddechowym.
4. TWINSET — twinset / zestaw podwójny. Dwie identyczne butle połączone manifoldem, noszone na plecach w nurkowaniu technicznym.
5. MANIFOLD — manifold / mostek + obejmy. Zawór łączący dwie butle twinsetu. Typy: izolujący i nieizolujący. Obejmy to klamry mocujące butle razem (osobne SKU).
6. ZAWOR_BUTLOWY — zawór butlowy. Mechanizm na szyjce butli do otwierania/zamykania przepływu gazu. Typy: DIN, modułowe, podwójne (H-valve).
7. ZLACZE_DIN — złącze DIN. JEDYNY aktualny standard przyłącza automatu do butli w Europie. Gwintowane, 200 bar (5 gwintów) lub 300 bar (7 gwintów).
8. ZLACZE_INT — złącze INT / yoke. MARTWY standard, nie produkowany od ~10 lat. W Europie nigdy nie był powszechny. Definicja musi jasno komunikować: to archaizm, nie opcja zakupowa.
9. ADAPTER_DIN_INT — adapter DIN/INT. Przejściówka pozwalająca podłączyć automat DIN do starego zaworu INT lub odwrotnie. Rozwiązanie tymczasowe do sprzętu egzotycznego/zagranicznego.

## ZNANE BŁĘDY Z POPRZEDNIEJ WERSJI (v1, raw/)

NIE POWTARZAJ tych błędów:

### BUTLA_NURKOWA
- BŁĄD: "pojemnik powietrza" jako synonim → za ogólne, nie jest terminem nurkowym
- BŁĄD: "butla ciśnieniowa" jako synonim → za szerokie (obejmuje butle przemysłowe, CO2 itp.)
- BRAK: "solówka" (potoczne na butlę pojedynczą vs twinset) — dobry synonim, zachować
- BRAK: pole "bledne_ale_popularne" → "butla z tlenem" 320/mies., "butla tlenowa" 140/mies. — klienci masowo tak mówią, AI musi wiedzieć że to błędna nazwa ale rozumieć intencję

### ZAWOR_BUTLOWY
- BŁĄD: "zawór z rezerwą" jako synonim → J-valve to archaizm, nie sprzedawany od dekad
- BRAK: rozróżnienie DIN 200 vs DIN 300 (różna głębokość gwintu)
- BRAK: zawory modułowe (M25x2 z wymiennym insertem)

### ZLACZE_DIN / ZLACZE_INT
- BŁĄD: definicja traktuje DIN i INT jako równorzędne alternatywy
- POPRAWKA: DIN = jedyny aktualny standard. INT = martwy standard, definicja historyczna.

### MANIFOLD
- BŁĄD: "zawór podwójny" jako synonim → mylące, manifold to nie zawór, to łącznik zaworów
- BRAK: rozróżnienie izolujący vs nieizolujący (kluczowe dla bezpieczeństwa)
- BRAK: obejmy (klamry) jako osobne SKU w tej samej kategorii sklepu

### TWINSET
- BRAK: info że manifold jest osobnym SKU (nie wchodzi w cenę butli)
- BRAK: typowe konfiguracje (2x12L, 2x15L, 2x18L)

## MARKI W DIVEZONE.PL DLA TEJ GRUPY

Butle stalowe i aluminiowe: TECLINE, ECS, LUXFER
Butle do argonu: TECLINE, ECS
Manifoldy i obejmy: TECLINE, OMS, SCUBATECH
Zawory butlowe: TECLINE, SCUBATECH
Twinsety (jako zestaw): TECLINE, OMS
Adaptery DIN/INT: TECLINE, APEKS

MARKI ZAKAZANE (nie rekomendować, nie ma w ofercie):
Cressi, Sherwood Scuba, Dive Rite, DIR Zone, Divesoft, Faber, Catalina,
GRALMARINE (była w ofercie, wycofana), HALCYON (była w ofercie, wycofana)

## FRAZY KLIENTÓW Z GOOGLE PL (DataForSEO)

Użyj tych fraz jako źródło synonimów potocznych i FAQ:

| Fraza | Wolumen/mies. | Uwagi |
|-------|---------------|-------|
| butla do nurkowania | 1600 | TOP fraza! |
| maska do nurkowania z butla | 590 | zestaw dla początkujących (inna grupa) |
| zestaw do nurkowania z butla | 390 | j.w. |
| butla z tlenem do nurkowania | 320 | BŁĘDNA terminologia ale masowo szukana! |
| butla nurkowa | 320 | |
| butle nurkowe | 320 | |
| nurkowanie z butlą | 170 | intent informacyjny |
| butle z powietrzem | 140 | poprawniejsze niż "z tlenem" |
| butla tlenowa do nurkowania | 140 | BŁĘDNA terminologia |
| butla nurkowa 15l | 90 | szukanie po pojemności |
| butla do snorkelingu | 70 | INNY produkt (miniaturowe butelki) |
| butla z powietrzem do nurkowania | 50 | |
| butla do oddychania pod wodą | 50 | potoczne |
| napełnianie butli nurkowych | 50 | usługa, nie produkt |
| butla nurkowa 300 bar | 50 | szukanie po ciśnieniu |
| butla nurkowa 12l | 40 | szukanie po pojemności |
| butle aluminiowe | 30 | szukanie po materiale |
| butla nurkowa 2l | 20 | mini butla / snorkeling |
| butla stage | 20 | |
| stage nurkowanie | 20 | |
| zawór butlowy | 10 | |
| twinset nurkowy | 0 (batch) | niszowe |
| manifold nurkowy | 0 (batch) | niszowe |
| złącze DIN nurkowe | 0 (batch) | niszowe |
| złącze INT yoke | 0 (batch) | niszowe |

## KRYTYCZNE REGUŁY

1. **Jedna definicja = jeden typ SKU.** Nigdy nie łącz produktów o różnych parametrach.
2. **Synonimy potoczne są WAŻNIEJSZE niż techniczne.** "butla do nurkowania" (1600/mies.) dominuje nad "cylinder nurkowy".
3. **"Nie mylić z" musi mieć sens.** Tylko pary które klienci REALNIE mylą.
4. **DIN to JEDYNY aktualny standard.** INT/yoke to martwy standard, nie produkowany od ~10 lat. W Europie nigdy nie był powszechny. ZLACZE_INT musi mieć definicję jasno mówiącą: archaizm, nie opcja zakupowa.
5. **"Butla z tlenem" to BŁĄD klienta, nie synonim.** Butle nurkowe zawierają sprężone powietrze lub nitrox (wzbogacone powietrze), NIE czysty tlen. AI musi rozumieć intencję klienta, ale delikatnie korygować terminologię.
6. **Butla do snorkelingu ≠ butla nurkowa.** Mini butelki (0.5-1l) do snorkelingu to gadżety, nie profesjonalny sprzęt nurkowy. Nie mieszać.
7. **Butla argonu = NIE DO ODDYCHANIA.** Argon służy WYŁĄCZNIE do napełniania suchego skafandra (lepsza izolacja termiczna niż powietrze). Oddychanie argonem jest śmiertelnie niebezpieczne.
8. **Legalizacja butli.** W Polsce butle podlegają okresowym badaniom (UDT): hydro co 5 lat, wzrokowa co 2.5 roku. Klient pytający o "przegląd butli" szuka usługi, nie produktu.
9. **Manifold: izolujący vs nieizolujący.** Izolujący pozwala odciąć jedną butlę w razie awarii (standard w nurkowaniu technicznym). Nieizolujący jest tańszy ale nie ma tej funkcji bezpieczeństwa.
10. **Marki:** W polu "marki_w_sklepie" wymieniaj TYLKO marki z listy powyżej.

## STRUKTURA KATEGORII W SKLEPIE (divezone.pl)

Butle nurkowe (kategoria główna):
- Butle Stalowe
- Butle Aluminiowe
- Butle do Argonu (osobna podkategoria!)
- Twinsety
- Manifoldy i Obejmy (razem w jednej kategorii, ale to 2 różne produkty)
- Zawory do butli
- Akcesoria do butli
- Zestawy Stage (uwaga: to zestawy automatu do stage, nie same butle)

## SPECJALNE INSTRUKCJE DLA ZLACZE_INT

ZLACZE_INT to jedyny concept key w encyklopedii opisujący martwy standard.
Definicja musi zawierać:
- Jasny komunikat: standard wycofany, nieprodukowany od ~10 lat
- Kontekst historyczny: skąd się wziął (standard amerykański), dlaczego wyparty przez DIN
- Praktyczny aspekt: klient może spotkać INT wyłącznie na egzotycznych wypożyczalniach lub w starym sprzęcie
- Uwaga dla AI: jeśli klient pyta o INT, zaproponuj ADAPTER_DIN_INT zamiast szukania sprzętu INT
- NIE sugeruj zakupu sprzętu z INT. NIE traktuj INT jako alternatywy dla DIN.

## SELF-CHECK (wykonaj PRZED zwróceniem wyników)

Dla KAŻDEGO wygenerowanego pojęcia sprawdź:

1. ✅ Czy definicja opisuje DOKŁADNIE JEDEN typ SKU? (nie łączy różnych produktów)
2. ✅ Czy synonimy "exact" są RZECZYWIŚCIE wymienne 1:1?
3. ✅ Czy "nie_mylic_z" zawiera tylko pary które klienci REALNIE mylą?
4. ✅ Czy marki_w_sklepie zawierają TYLKO marki z dozwolonej listy?
5. ✅ Czy BUTLA_ARGONU ma ostrzeżenie: NIE do oddychania?
6. ✅ Czy MANIFOLD rozróżnia izolujący vs nieizolujący?
7. ✅ Czy ZLACZE_INT jest opisany jako martwy standard (nie opcja zakupowa)?
8. ✅ Czy ZLACZE_DIN NIE wspomina INT jako alternatywy?
9. ✅ Czy FAQ bazuje na realnych frazach klientów (DataForSEO)?
10. ✅ Czy "butla z tlenem" jest w synonimy.bledne_ale_popularne (nie w exact/near)?
11. ✅ Czy butla do snorkelingu jest w "nie_mylic_z" dla BUTLA_NURKOWA?
12. ✅ Czy nigdzie INT/yoke nie jest przedstawiony jako równorzędna opcja do DIN?

## OUTPUT

Zwróć wynik jako tablicę 9 obiektów JSON w formacie opisanym powyżej.
Każdy obiekt na osobnej linii, pełny i kompletny.
Nie pomijaj żadnego pola. Jeśli pole nie dotyczy danego pojęcia, użyj pustej tablicy [].
Dodatkowe pole "bledne_ale_popularne" w synonimy_pl jest WYMAGANE dla tej grupy
(klienci masowo używają błędnej terminologii "butla z tlenem").
