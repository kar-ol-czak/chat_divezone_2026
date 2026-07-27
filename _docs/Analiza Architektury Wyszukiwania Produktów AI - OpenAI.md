# Analiza architektury wyszukiwania produktów w czacie AI w sklepie nurkowym

## Podsumowanie i wnioski kluczowe

Wasza architektura czterowarstwowa sensownie uderza w problem niedopasowania słownictwa, bo łączy podejście semantyczne (wektory osadzeń) z podejściem leksykalnym (dopasowanie tekstu) oraz dodaje warstwę rozumienia intencji w pętli czatu. citeturn6search2turn6search3turn3search1turn0search0 Jednocześnie najsłabszym punktem jest to, że „wzbogacanie produktu” miesza różne sygnały w jednym tekście do osadzenia, co bywa skuteczne, ale łatwo rozmywa sygnał i utrudnia sterowanie wagami. citeturn11search1turn11search24turn7search21 Dla katalogu rzędu 2 556 produktów największy zwrot zwykle daje solidna leksykalna warstwa bazowa z synonimami i odmianą języka polskiego, dopiero potem strojenie hybrydy i bardziej złożone modele. citeturn3search1turn4search1turn4search2turn6search2

Krok 1: dołóżcie pełnotekstowe wyszukiwanie w PostgreSQL z konfiguracją dla polskiego oraz słownikiem synonimów (dict_xsyn), a trigram zostawcie głównie na nazwy własne i literówki. citeturn3search1turn4search1turn0search3 Krok 2: zbudujcie mały, ręcznie oceniony zbiór testowy z realnych fraz (GSC, wyszukiwarka sklepu) i na nim stroicie wagi fuzji albo przejdźcie na fuzję rang (RRF) tam, gdzie skale wyników są nieporównywalne. citeturn2search0turn7search3turn0search1

## Ocena architektury czterowarstwowej i prostsze warianty

**Pytanie 1. Czy architektura adresuje vocabulary mismatch i co zmienić?**  
Tak, bo traktujecie problem jako „lukę leksykalną” między językiem klienta a językiem katalogu i próbujecie ją domknąć trzema dźwigniami: wzbogaceniem tekstu dokumentu, hybrydą semantyka plus leksyka oraz przepisaniem zapytania na język sklepu. citeturn6search3turn6search2turn4search1turn3search1 To jest spójne z tym, co literatura IR (wyszukiwanie informacji) opisuje jako klasyczne metody walki z niedopasowaniem słownictwa: przepisanie i rozszerzenie zapytania, słowniki synonimów, fuzja wyników systemów różnego typu. citeturn11search1turn11search12turn2search0turn7search3

Najważniejsze zmiany, które mają wysokie prawdopodobieństwo poprawy jakości w e-commerce, nawet przy małej skali:

1) **Rozdzielenie sygnałów zamiast „jednego tekstu do osadzenia”.**  
Zamiast dopisywać „Szukaj też jako:” do jednego pola, rozważcie osobne pola i osobne wyniki cząstkowe, a potem fuzję. To ułatwia sterowanie wagami (np. osobno nazwa, marka, kategoria, cechy, aliasy) i zmniejsza ryzyko „dryfu” po dodaniu zbyt wielu rozszerzeń. citeturn11search24turn7search21turn7search17 W praktyce jest to odpowiednik „wielopolowego” wyszukiwania znanego z modeli BM25F i podejść wieloaspektowych. citeturn7search0turn7search17

2) **Pełnotekst zamiast trigram jako główna leksykalna warstwa.**  
pg_trgm jest świetne do podobieństwa napisów, literówek i dopasowań nazw własnych, ale nie jest pełnoprawnym modelem rankingowym dla zapytań wielowyrazowych i języka naturalnego. citeturn0search3turn9search12turn3search1 PostgreSQL ma wbudowane pełnotekstowe typy i funkcje (tsvector, tsquery), a także słownik synonimów dict_xsyn, który wprost służy do wyszukiwania po synonimach. citeturn3search1turn4search1turn4search27 Dla polskiego warto też dołożyć lematyzację (np. przez konfigurację z plikami słownika hunspell lub osobny analizator) oraz unaccent na brak polskich znaków. citeturn4search7turn4search2turn3search2

3) **Fuzja rang lub strojenie wagi na danych, zamiast stałej 0,7 i 0,3.**  
Stała waga bywa dobrym startem, ale literatura pokazuje, że prosta kombinacja wypukła bywa skuteczna i „oszczędna w danych” do strojenia, a fuzja rang RRF jest często używana, gdy skale wyników są trudne do normalizacji, choć RRF ma parametr i też bywa wrażliwe. citeturn7search3turn2search0turn2search4

4) **Mały etap ponownego porządkowania wyników, nawet przy 5 do 10 kandydatach.**  
Wasze założenie, że „LLM i tak przestawi wyniki” jest częściowo prawdziwe w sensie narracji odpowiedzi, ale nie zastępuje modelu rankingowego, który jest trenowany do oceny trafności par zapytanie plus produkt. citeturn6search0turn5search3 Prace o ponownym porządkowaniu (cross-encoder) pokazują, że to standardowy element architektury retrieve-then-rerank, bo daje zauważalne zyski jakościowe w top wynikach. citeturn6search12turn5search19 Przy małym katalogu możecie re-rankować np. top 30 z hybrydy, co mieści się w czasie, jeśli macie GPU lub mały model. citeturn5search3turn6search0

**Pytanie 2. Prostsze podejście przy 2 556 produktach, bez osadzeń?**  
Da się uprościć, ale „czysto przez LLM” bez wyszukiwania w bazie zwykle rozbije się o dwa ograniczenia: okno kontekstu i deterministykę doboru kandydatów. citeturn10search9turn11search12 W praktyce i tak potrzebujecie mechanizmu selekcji kandydatów, a to jest właśnie rola wyszukiwarki (leksykalnej, wektorowej lub hybrydowej). citeturn6search12turn3search1

Najprostszy wariant, który często daje porównywalny efekt przy małym katalogu, wygląda tak:

- Pierwszy etap: PostgreSQL full text search z polską analizą i synonimami, plus osobny tor dopasowań nazw własnych przez pg_trgm do: marka, model, nazwa produktu. citeturn3search1turn4search1turn0search3  
- Drugi etap: LLM nie „szuka”, tylko wybiera i uzasadnia z top N, oraz zadaje dopytanie, jeśli brakuje parametrów. Ten schemat jest zbieżny z tym, jak projektuje się rozmówne wyszukiwanie produktów jako zestaw podzadań (wykrycie intencji, wydobycie cech, dobór działania, ranking). citeturn8search1turn8search25turn8search4

Wektory osadzeń nadal mają sens jako „trzeci tor” na długie ogólne pytania i rzadkie słownictwo, ale nie muszą być jedynym filarem. citeturn6search2turn2search1

**Pytanie 3. Publikacje i case studies o chatbotach e-commerce z podobnym problemem.**  
Są co najmniej trzy nurty, które bezpośrednio zahaczają o wasz problem:

- Asystenci zakupowi i obsługowi w e-commerce, gdzie łączy się wyszukiwanie i generację: AliMe Assist opisuje asystenta zakupowego z wielorundową interakcją i QA w środowisku e-commerce. citeturn0search12turn0search8 AliMe Chat opisuje silnik czatu łączący IR i generację poprzez etap ponownego porządkowania, co jest bliskie temu, co robicie w pętli czatu. citeturn8search2turn0search28  
- Conversational Product Search jako dziedzina z własnymi zbiorami danych i podzadaniami: PSCon proponuje protokół zbierania danych rozmównego wyszukiwania produktów i opisuje sześć podzadań, w tym ranking i dobór pytań doprecyzowujących. citeturn8search1 MG-ShopDial to wielocelowy zbiór rozmów e-commerce, nastawiony na realne, zmieniające się potrzeby informacyjne. citeturn8search25  
- Prace stricte o rozmównych zapytaniach w e-commerce i „odklejeniu” języka rozmowy od wyszukiwarki: świeża praca z 23.01.2026 opisuje ramę: osadzenia domenowe plus filtry strukturalne, z brakiem danych etykietowanych rozwiązanym przez dane syntetyczne generowane przez LLM i dostrajanie modeli. citeturn8search4turn8search8

Uzupełniająco, duże platformy publikują wyniki o przepisaniu zapytań w e-commerce jako metodzie domykania luki semantycznej: Amazon opisuje QUEEN dla niejednoznacznych zapytań. citeturn0search13 Taobao opisuje BEQUE, wdrożone i ocenione także w testach A/B, jako podejście do długiego ogona zapytań. citeturn6search3turn6search31

## Warstwa wzbogacania produktów i alternatywy dla fraz generowanych przez LLM

**Pytanie 4. Czy LLM-generated search phrases to najlepszy sposób i jakie są alternatywy?**  
To jest jedna z klasycznych dróg, bo w praktyce przypomina rozszerzanie zapytania lub dokumentu, czyli znane metody redukcji niedopasowania słownictwa. citeturn11search1turn11search12turn9search26 Nie jest to jednak jedyna droga i nie zawsze najlepsza, bo łatwo o „dryf” pojęć, gdy dodajecie dużo terminów pobocznych. citeturn11search24turn11search32

Najbardziej praktyczne alternatywy, uporządkowane od najmniej do najbardziej „modelowych”:

1) **Słownik synonimów i normalizacja języka po stronie leksykalnej.**  
W PostgreSQL istnieje dict_xsyn, który zastępuje słowa grupami synonimów w full text search. citeturn4search1turn4search27 Dla domeny nurkowej możecie utrzymywać mały słownik mapowań: pianka, skafander mokry, wetsuit, semidry, automat, regulator, jacket, BCD, skrzydło, wing. To jest tanie, przewidywalne i łatwe do testowania. citeturn4search1turn3search1

2) **Przepisanie zapytania na język sklepu, ale jako osobny krok, nie jako „wymuszone uzasadnienie”.**  
Podejście „osobny krok generowania ograniczeń strukturalnych” jest wprost opisane w pracy o rozmównych zapytaniach e-commerce z 2026 roku. citeturn8search4turn8search8 To daje możliwość generowania filtrów typu: kategoria, płeć, grubość, typ nurkowania, budżet, a potem szukania w bazie po cechach i tekście. citeturn8search4turn3search1

3) **Dostrajanie modelu osadzeń na parach zapytanie, produkt.**  
To jest często skuteczniejsze niż dopisywanie synonimów, bo uczy przestrzeń wektorową waszego języka. W modzie e-commerce Myntra trenowała reprezentacje zapytań i produktów na danych kliknięć i raportuje poprawy w metrykach rankingowych. citeturn5search6turn5search2 W realiach czatu, na starcie bez kliknięć, można generować dane syntetyczne do dostrajania, co jest opisane w pracy z 2026 roku. citeturn8search4turn8search8

4) **Wieloaspektowe osadzenia zamiast jednego wektora.**  
Jeśli macie problem typu „biała skarpeta Nike” myli się z „biała skarpeta Adidas”, to literatura pokazuje podejścia, które trzymają osobne osadzenia dla aspektów jak kategoria, marka, kolor. citeturn7search17turn7search5 To jest bliższe waszej domenie, bo marka i model są dla nurków bardzo ważne (np. komputery nurkowe), a pojedynczy wektor często gubi twarde atrybuty. citeturn7search17turn5search6

5) **Matryoshka embeddings jako optymalizacja rozmiaru, nie jako lek na niedopasowanie słów.**  
Matryoshka Representation Learning opisuje osadzenia, które można „ucinać” do mniejszego wymiaru. citeturn2search2turn2search14 W przypadku modeli OpenAI dokumentacja mówi, że text-embedding-3-large ma domyślnie 3 072 wymiary, ale można je skrócić parametrem dimensions bez utraty właściwości reprezentowania pojęć. citeturn11search2turn11search10 To pomaga w kosztach i pamięci, ale samo w sobie nie rozwiązuje luki słownikowej. citeturn2search2turn11search1

Ważna uwaga kontrolna: w waszym opisie pojawia się „text-embedding-3-large, 1 536 wymiarów”. Dokumentacja OpenAI wskazuje 1 536 jako domyślny rozmiar dla text-embedding-3-small, a 3 072 dla text-embedding-3-large, z możliwością redukcji przez dimensions. citeturn11search2turn2search3 To warto sprawdzić, bo pomyłka modelu lub wymiaru potrafi utrudnić porównywanie eksperymentów. citeturn11search2turn2search7

**Pytanie 5. Luki w podejściu do promptu dla fraz.**  
Wasze „uziemienie” w realnych frazach z danych analitycznych jest mocnym punktem, bo ogranicza fantazjowanie modelu i wiąże wzbogacanie z faktycznym językiem klientów. citeturn8search4turn0search1 Główne ryzyka, które widać w tego typu podejściu, są znane z literatury o rozszerzaniu zapytań: dryf zapytania, mieszanie intencji oraz spadek precyzji po dodaniu zbyt ogólnych słów. citeturn11search24turn11search32turn11search9

Konkretnie, w waszym kontekście warto domknąć trzy „szwy”:

- **Kontrola jakości i separacja typów fraz.** Frazy „marka plus model” (Shearwater Teric) zachowują się inaczej niż frazy opisowe (komputer dla początkującego), więc dobrze je trzymać w osobnych polach i inaczej ważyć w rankingu. citeturn7search0turn7search21turn0search3  
- **Jawne mapowanie na cechy strukturalne.** Jeśli fraza mówi „zimna woda”, to finalnie powinno to trafić do filtrów lub cech, a nie tylko do tekstu. Podejście „osadzenia plus filtry strukturalne” jest właśnie po to, żeby unikać sytuacji, w której semantyka przykrywa twarde wymagania. citeturn8search4turn8search8  
- **Ocena na zbiorze testowym, a nie tylko walidacja drugim LLM.** Drugi LLM jako recenzent daje spójność stylistyczną, ale nie zastępuje oceny trafności wyszukiwania w metrykach IR. citeturn1search0turn6search2

**Pytanie 6. Anti-phrases i negacja.**  
W praktyce to ryzykowne jako mechanizm oparty wyłącznie na osadzeniach, bo modele wektorowe często słabo radzą sobie z negacją i odwróceniem znaczenia. citeturn3search35turn3search3turn0search1 Jest natomiast sensowne jako mechanizm „logiczny”, czyli: LLM wykrywa negację, zamienia ją na filtr wykluczający kategorie lub typy produktów, a wyszukiwarka stosuje filtr, zamiast liczyć, że wektor zrozumie „to nie jest”. citeturn0search1turn8search4

Dodatkowo, istnieją prace wprost o przepisaniu zapytań z negacją w wyszukiwaniu produktów, które pokazują architekturę: cache przepisanych zapytań, model przepisujący oraz przekazanie wynikowych zapytań do wyszukiwarki. citeturn0search1turn0search33 To jest bliższe waszemu zastosowaniu niż „frazy negatywne w opisie produktu”. citeturn0search1turn3search3

## Wyszukiwanie hybrydowe i strojenie fuzji w PostgreSQL

**Pytanie 7. Trigram vs BM25 vs SPLADE vs ColBERT przy 2 556 produktach i PostgreSQL.**  
Jeżeli trzymacie się PostgreSQL jako jedynego silnika, najrozsądniejszy „rdzeń” leksykalny to full text search (tsvector, tsquery) z konfiguracją słowników, bo to jest natywnie wspierane i daje ranking trafności, a nie tylko podobieństwo napisów. citeturn3search1turn3search17turn4search27 pg_trgm traktowałbym jako dodatek do: literówek, dopasowań typu „zawiera” i nazw własnych, bo tak jest zaprojektowany, jako trigramowe podobieństwo tekstu z indeksami do szybkiego wyszukiwania podobnych ciągów. citeturn0search3turn9search28turn9search12

BM25 jest klasycznym modelem leksykalnym i ma mocne uzasadnienie teoretyczne w probabilistycznym modelu trafności. citeturn1search7turn1search23 PostgreSQL nie daje BM25 „1 do 1” w standardowej funkcji rankingu, ale daje własne funkcje rankingu full text search, które w małej skali katalogu zwykle są wystarczające jako tor leksykalny. citeturn3search1turn3search17turn3search25

SPLADE i ColBERT to modele uczenia maszynowego do wyszukiwania, które zwykle wymagają osobnego pipeline, osobnych indeksów i dodatkowej infrastruktury. citeturn0search2turn1search2turn7search6 Przy 2 556 produktach zysk jakości może być, ale koszt wdrożenia jest wysoki względem korzyści, jeśli jeszcze nie macie dobrze ustawionej leksyki, synonimów i ewaluacji. citeturn3search1turn4search1turn6search2

**Pytanie 8. Jak kalibrować wagi 0,7 i 0,3.**  
Są dwie metody „systematyczne”, które są lepsze niż ręczne zgadywanie:

1) **Strojenie jednej wagi na zbiorze testowym.**  
Bruch i współautorzy pokazują, że kombinacja wypukła (convex combination) może być efektywna i wymaga mało przykładów do dostrojenia jednego parametru pod domenę. citeturn7search3turn6search2 W praktyce robicie siatkę wag, np. 0,0 do 1,0 co 0,05 i oceniacie NDCG@k, MRR, Recall@k na ręcznie oznaczonych zapytaniach. citeturn1search0turn1search20turn7search3

2) **Fuzja rang RRF, gdy skale wyników są nieporównywalne.**  
RRF agreguje pozycje w rankingach i jest klasyczną metodą łączenia wyników wielu systemów wyszukiwania. citeturn2search0turn2search4 To bywa praktyczniejsze od ważenia surowych wyników cosinus i trigram, bo te wyniki mają inne rozkłady. citeturn7search3turn0search3

W kontekście PostgreSQL istotne jest też „co ważyć”. Często lepsze od globalnej wagi jest ważenie per pole: inaczej nazwa i marka, inaczej opis i cechy. To jest analogiczne do BM25F i do podejść wielopolowych. citeturn7search0turn7search21turn3search1

## Przepisanie zapytania, planowanie i modele dedykowane

**Pytanie 9. Czy structured reasoning jako parametr wystarczy, czy robić osobny krok planowania?**  
Samo wymuszenie pola typu search_reasoning może poprawić dyscyplinę modelu, ale nie jest gwarancją poprawy jakości wyszukiwania, bo model nadal może wpisać „ładne uzasadnienie” i wykonać złą operację. citeturn1search4turn10search9 Badania nad rozmównym wyszukiwaniem pokazują, że „prawidłowe przepisanie”. czyli jawne rozwiązanie skrótów i kontekstu, potrafi dać duży skok jakości, a różnica między automatycznym a ręcznym przepisaniem jest zauważalna. citeturn1search4turn1search1

Dlatego, jeżeli zależy wam na niezawodności, mocniejszy jest osobny krok planowania, który zwraca strukturę:

- intencja: szukanie konkretnego produktu vs doradztwo,
- encje: marka, model, typ sprzętu,
- filtry: kategoria, zakres ceny, cechy,
- zapytania do toru leksykalnego i toru semantycznego.

Taki schemat jest zgodny z tym, jak PSCon formalizuje zadanie rozmównego wyszukiwania produktów jako zestaw podzadań, w tym predykcję działania systemu i ranking. citeturn8search1turn8search25 Jest też zgodny z pracą o e-commerce z 2026 roku, gdzie osobno powstaje model do zamiany języka naturalnego na ograniczenia strukturalne. citeturn8search4turn8search8

**Pytanie 10. Czy ma sens dedykowany model do przepisania zapytań, czy wystarczy prompt.**  
Przy waszej skali i fazie projektu, prompt zwykle wystarcza do startu, ale literatura i case studies dużych platform pokazują, że dedykowane przepisanie zapytań ma realny wpływ na wyniki, zwłaszcza dla długiego ogona. citeturn6search3turn0search13turn6search31 Taobao raportuje wdrożenie przepisania długiego ogona z testami A/B i wpływem na metryki biznesowe, co jest rzadkie w publikacjach. citeturn6search3turn6search31

Praktyczny kompromis, który pasuje do waszej architektury czatu i budżetu:

- LLM offline generuje i ocenia przepisania zapytań dla fraz z GSC i wyszukiwarki wewnętrznej, budujecie „pamięć przepisania”. citeturn0search1turn6search3  
- Online najpierw sprawdzacie cache, a dopiero w razie braku prosicie LLM o przepisanie. Ten schemat jest opisany w pracy o negacji w wyszukiwaniu produktów. citeturn0search1turn0search33

W czacie ma to dodatkową zaletę: możecie logować plan, decyzję i wynik wyszukiwania jako dane treningowe, zanim pojawi się dużo kliknięć. citeturn8search4turn8search1

## Rzeczy pominięte, metryki, język polski i dwa tryby intencji

**Pytanie 11. Czy rozważyć multi-vector retrieval per aspekt produktu.**  
Tak, jeśli obserwujecie błędy typu „dobre semantycznie, złe po marce lub parametrze”, bo podejścia wieloaspektowe wprost są projektowane, żeby nie gubić takich cech jak marka. citeturn7search17turn7search5 W praktyce, dla waszego katalogu, najprostsza forma multi-vector to nie ColBERT, tylko osobne wektory per pole i fuzja wyników, co jest bliskie idei multi-field retrieval. citeturn7search21turn7search9

**Pytanie 12. Czy GraphRAG lub knowledge graph może być lepszy niż płaskie osadzenia.**  
Dla katalogu produktowego, który jest mocno ustrukturyzowany, klasyczna baza relacyjna z atrybutami i filtrowaniem często jest praktyczniejsza niż GraphRAG, bo GraphRAG był projektowany głównie do „narracyjnych” zbiorów tekstu i zadań typu globalne podsumowanie. citeturn5search0turn5search4turn8search4 Natomiast knowledge graph ma sens jako warstwa logiki i relacji typu: kategoria, kompatybilność, akcesoria, alternatywy, a także do prowadzenia dialogu doprecyzowującego. citeturn8search33turn5search37turn8search19

W praktyce dla waszego przypadku „graf” może być minimalny: marka, linia produktu, kompatybilność, typ nurkowania, plus relacje. Nie musi to być pełny GraphRAG. citeturn8search33turn5search17

**Pytanie 13. Czy late interaction (ColBERT) ma sens przy 2 556 produktach.**  
ColBERT jest skuteczny, bo trzyma wektory na poziomie tokenów i liczy podobieństwo w późnej interakcji, co zwiększa precyzję dopasowania. citeturn1search2turn7search6 Cena to złożoność indeksu i obliczeń, bo to „wielowektorowe” dopasowanie jest cięższe od jednego wektora na dokument. citeturn7search14turn7search6 Przy 2 556 produktach da się to zrobić, ale najpierw warto wycisnąć tańsze elementy: pełnotekst, synonimy, unaccent, fuzję, a dopiero potem rozważać ColBERT, jeśli nadal macie konkretne klasy błędów. citeturn3search1turn4search1turn3search2turn6search2

**Pytanie 14. Jakie metryki śledzić.**  
Do oceny wyszukiwania jako komponentu retrieval podstawowe są metryki IR typu: Recall, MRR i NDCG w top wynikach. citeturn1search0turn1search20 TREC CAsT, choć to nie e-commerce, jest dobrą referencją dla rozmównego kontekstu, bo używa NDCG@3 jako metryki głównej dla scenariusza rozmównego i raportuje też MRR, MAP oraz Recall. citeturn1search20turn1search0

Dla czatu e-commerce, bez logów kliknięć, minimalny zestaw, który jest mierzalny od pierwszego dnia, to:

- odsetek rozmów, w których system zwrócił co najmniej jeden sensowny produkt na zapytanie „znajdź”, liczony na ręcznie oznaczonym zbiorze testowym, a potem na danych produkcyjnych,  
- udział przypadków, gdzie LLM musiał dopytać o kluczowy parametr, oraz czy po dopytaniu produkt został znaleziony, co pasuje do ujęcia CPS jako procesu wieloturnowego. citeturn8search1turn8search25turn1search24

**Pytanie 15. Polski, niszowa branża, dwujęzyczne nazwy, czy to zmienia rekomendacje.**  
Tak, bo polski jest językiem fleksyjnym i sama leksyka bez lematyzacji często traci trafność na odmianach. Morfeusz jest klasycznym narzędziem do analizy morfologicznej polskiego i wspiera lematyzację. citeturn4search6turn4search2 W PostgreSQL można podejść do tego przez konfigurację full text search z odpowiednimi plikami słownika oraz dołożyć unaccent, aby obsłużyć brak znaków diakrytycznych w zapytaniach. citeturn4search7turn3search2turn4search27

Dwujęzyczność nazw produktowych to argument za tym, aby w indeksie trzymać równolegle: nazwę oryginalną, polską wersję tytułu i polskie aliasy, zamiast liczyć, że opis producenta po angielsku zawsze pomoże. Wektory osadzeń mogą wspierać wyszukiwanie wielojęzyczne, ale i tak wygrywa jawne uzupełnienie braków w danych produktowych, zwłaszcza w niszy. citeturn2search7turn8search4

**Pytanie 16. Konkretna nazwa produktu vs ogólne doradztwo, czy potrzebne różne strategie retrieval.**  
Tak. W literaturze CPS rozdziela się podzadania: wykrycie intencji, wydobycie cech, wybór działania, ranking i dobór pytań, co implikuje różne strategie w zależności od intencji. citeturn8search1turn8search25

W praktyce proponuję dwa tryby:

- „Znajdź dokładnie” dla zapytań typu marka plus model plus cena, gdzie priorytetem jest dopasowanie nazw własnych i wariantów pisowni, czyli tor leksykalny i trigram. citeturn0search3turn9search28turn3search1  
- „Doradź i zawęź” dla zapytań ogólnych, gdzie LLM powinien dopytać o kluczowe parametry i dopiero potem uruchomić wyszukiwanie z filtrami, co jest zgodne z podejściem „osadzenia plus filtry strukturalne” oraz z ujęciem CPS jako dialogu, nie jako jednego strzału wyszukiwarki. citeturn8search4turn8search1

Warto tu pamiętać o koszcie narzędzi i tokenów w pętli czatu. Dokumentacja narzędzi Anthropic wskazuje, że użycie narzędzi jest rozliczane po tokenach wejścia, w tym po tokenach definicji narzędzi, oraz po tokenach wyjścia. citeturn10search9turn10search0 To znaczy, że rozbudowywanie schematu narzędzia o długie pola opisowe zwykle zwiększa koszt, nawet jeśli nie zwiększa liczby wywołań narzędzia. citeturn10search9turn10search7