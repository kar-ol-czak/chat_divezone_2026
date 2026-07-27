# Prompt: Misleading terms dla encyklopedii sprzętu nurkowego

## Kontekst
Buduję ustrukturyzowaną encyklopedię sprzętu nurkowego (~46 kategorii) dla AI chatbota sklepu divezone.pl. Każda kategoria ma pole `misleading_term` — to terminy, które klienci BŁĘDNIE używają szukając danego sprzętu. AI musi wiedzieć, że "butla tlenowa" = klient szuka butli nurkowej (nie tlenowej), "gogle" = klient szuka maski nurkowej itd.

## Zadanie
Dla każdej kategorii z listy poniżej zaproponuj misleading_term — błędne, potoczne lub mylące nazwy, które klienci mogą wpisać w wyszukiwarkę sklepu nurkowego, a które powinny mapować na dane pojęcie.

## Zasady
1. Tylko terminy BŁĘDNE lub MYLĄCE. Poprawne synonimy (potoczne, techniczne, angielskie) już mam.
2. Uwzględnij: błędy językowe, mylne tłumaczenia z angielskiego, mylenie z innymi sportami (pływanie, snorkeling), mylenie komponentu z całością, błędne uproszczenia.
3. Język: głównie PL, ale jeśli klient w Polsce wpisze błędny termin EN — też podaj.
4. Nie wymyślaj na siłę. Jeśli dla danej kategorii nie ma sensownych misleading terms, napisz "brak".
5. Format: `concept_key | canonical_term_pl | misleading_terms (z krótkim wyjaśnieniem dlaczego misleading)`

## Znane przykłady (już zidentyfikowane)
- maska → "gogle do nurkowania", "okulary do nurkowania" (gogle/okulary to pływanie, nie nurkowanie)
- butla nurkowa → "butla tlenowa", "butla z tlenem" (nurkowie oddychają powietrzem/nitroxem, nie czystym tlenem)
- automat oddechowy → "aparat tlenowy" (j.w. + aparat to termin strażacki/medyczny)
- komputer nurkowy → "zegarek nurkowy", "zegarek do nurkowania" (zegarek ≠ komputer, inne urządzenie)
- boja dekompresyjna → "kiełbasa" (dosłowne tłumaczenie "safety sausage" — niedopuszczalne w PL)
- automat oddechowy → "oddechówka" (neologizm nieistniejący w żargonie nurkowym)

## Lista 46 kategorii (uzupełnij misleading_term)

| concept_key | canonical_term_pl | canonical_term_en | definicja operacyjna (skrót) |
|---|---|---|---|
| automat_oddechowy | automat oddechowy | regulator | System redukujący ciśnienie gazu z butli do ciśnienia otoczenia |
| backplate | backplate (płyta) | backplate | Sztywna płyta rdzeń systemu skrzydło |
| balast | balast (obciążenie) | ballast weight | Dodatkowe obciążenie ołowiane kompensujące pływalność |
| boja_dekompresyjna | boja dekompresyjna (SMB) | surface marker buoy | Nadmuchiwana boja sygnalizacyjna wystrzeliwana na powierzchnię |
| butla_nurkowa | butla nurkowa | scuba cylinder/tank | Metalowy zbiornik na sprężony gaz oddechowy |
| butla_stage | butla stage | stage bottle | Dodatkowa butla boczna na gaz dekompresyjny |
| buty_nurkowe | buty nurkowe | dive boots | Obuwie neoprenowe do płetw paskowych |
| drugi_stopien | drugi stopień | second stage | Część automatu z ustnikiem, redukcja do ciśnienia otoczenia |
| fajka | fajka (snorkel) | snorkel | Rurka do oddychania na powierzchni |
| inflator | inflator | BCD inflator | Mechanizm dodawania/wypuszczania powietrza z jacketa/skrzydła |
| jacket | jacket (BCD) | jacket BCD | Kamizelka wypornościowa z workami po bokach |
| kaptur | kaptur neoprenowy | diving hood | Neoprenowe nakrycie głowy i szyi |
| karabinek | karabinek nurkowy | bolt snap | Metalowy zaczep tłokowy do mocowania sprzętu |
| kolowrotek | kołowrotek nurkowy | dive reel | Urządzenie z KORBKĄ do nawijania 50-200m+ linki |
| kompas | kompas nurkowy | diving compass | Przyrząd nawigacyjny wskazujący kierunek pod wodą |
| komputer_nurkowy | komputer nurkowy | dive computer | Elektroniczne urządzenie monitorujące głębokość/czas/dekompresję |
| konsola | konsola nurkowa | gauge console | Obudowa łącząca manometr + kompas + głębokościomierz |
| latarka_nurkowa | latarka nurkowa | dive light | Wodoszczelne źródło światła pod wodą |
| manifold | manifold (mostek) | manifold | Zawór łączący dwie butle twinsetu w jeden system |
| manometr | manometr (SPG) | submersible pressure gauge | Przyrząd wskazujący ciśnienie gazu w butli |
| maska_dwuszybowa | maska dwuszybowa | two-lens mask | Maska z dwiema szybami, możliwość korekcji |
| maska_jednoszybowa | maska jednoszybowa | single lens mask | Maska z jedną ciągłą szybą, szerokie pole widzenia |
| maska_pelnotwarzowa | maska pełnotwarzowa | full face mask | Maska na całą twarz ze zintegrowanym automatem |
| nitrox | nitrox (EANx) | nitrox/enriched air | Mieszanina oddechowa >21% tlenu |
| noz_nurkowy | nóż nurkowy | dive knife | Narzędzie tnące do uwolnienia z zaplątania |
| ocieplacz | ocieplacz | drysuit undersuit | Warstwa izolacyjna pod suchym skafandrem |
| octopus | octopus | octopus/alternate air source | Zapasowy drugi stopień (żółty, dłuższy wąż) |
| pianka_mokra | pianka mokra | wetsuit | Skafander neoprenowy, woda wnika do środka |
| pianka_polsucha | pianka półsucha | semi-dry suit | Skafander z uszczelnieniami, minimalne przenikanie wody |
| pierwszy_stopien | pierwszy stopień | first stage | Komponent na butli, redukcja do ciśnienia pośredniego |
| pletwy_jet | płetwy gumowe (jet fins) | jet fins | Ciężkie gumowe płetwy paskowe z dyszami |
| pletwy_kaloszowe | płetwy kaloszowe | full foot fins | Płetwy z zamkniętą kieszenią, na bosą stopę |
| pletwy_paskowe | płetwy paskowe | open heel fins | Płetwy z paskiem, na buty nurkowe |
| rebreather | rebreather | rebreather/CCR | Aparat oddechowy oczyszczający wydychany gaz |
| rekawice | rękawice nurkowe | diving gloves | Ochrona dłoni przed zimnem i urazami |
| retractor | retractor | gear retractor | Mechanizm sprężynowy zwijający linkę do sprzętu |
| sidemount | sidemount | sidemount | Konfiguracja z butlami po bokach ciała |
| skrzydlo | skrzydło (wing) | wing/BP&W | Worek wypornościowy na plecach, z backplate |
| suchy_skafander | suchy skafander | drysuit | Wodoszczelny kombinezon, izolacja od wody |
| szpulka | szpulka nurkowa | finger spool | Prosta rolka BEZ korbki, 15-80m linki, trzymana w palcach |
| twinset | twinset | twinset/doubles | Dwie butle połączone manifoldem na plecach |
| uprzaz | uprząż nurkowa | harness | System pasów do mocowania sprzętu na ciele |
| waz_do_automatu | wąż do automatu | regulator hose | Elastyczny przewód między stopniami automatu |
| zawor_butlowy | zawór butlowy | cylinder valve | Mechanizm otwierania/zamykania gazu na butli |
| zlacze_din | złącze DIN | DIN connection | Gwintowane połączenie automatu z butlą |
| zlacze_int | złącze INT (yoke) | INT/yoke connection | Nakładane połączenie automatu z butlą (jarzmo) |

## Dodatkowy kontekst
- Klienci divezone.pl to Polacy, piszą po polsku, czasem mieszają z angielskim
- Wielu klientów to początkujący, mylą sprzęt nurkowy z pływackim/snorkelingowym
- Popularne błędy: "tlen" zamiast "powietrze/gaz", "gogle" zamiast "maska", komponent = całość
- Sezon zimowy = mniej zapytań o pianki, więcej o suche skafandry

## Oczekiwany format odpowiedzi
Dla każdego concept_key podaj listę misleading_term z krótkim wyjaśnieniem:

```
concept_key: [misleading_term1 (dlaczego), misleading_term2 (dlaczego), ...]
```

Jeśli brak sensownych misleading terms, napisz "brak".
