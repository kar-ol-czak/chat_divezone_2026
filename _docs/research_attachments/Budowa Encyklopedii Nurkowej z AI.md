# **Architektura Systemu Ekstrakcji Wiedzy i Budowy Ustrukturyzowanej Ontologii Sprzętowej dla E-commerce w Branży Nurkowej**

## **1\. Wstęp i Dekonstrukcja Problemu Biznesowego**

Rozwój zaawansowanych interfejsów konwersacyjnych opartych na dużych modelach językowych (Large Language Models – LLM) dla sektora e-commerce wymaga wysoce precyzyjnego uziemienia terminologicznego (terminological grounding). Wdrożenie wyszukiwania semantycznego, bazującego na wektorowych reprezentacjach słów (np. z wykorzystaniem architektury PostgreSQL i rozszerzenia pgvector), jest podatne na zjawisko halucynacji semantycznych, jeśli wektory zapytań nie są mapowane na rygorystyczną ontologię domenową.

W wąsko wyspecjalizowanych dziedzinach, takich jak nurkowanie techniczne i rekreacyjne, brak ustrukturyzowanego słownika referencyjnego prowadzi do katastrofalnych błędów w interpretacji intencji użytkownika. Modele językowe, opierając się na ogólnych rozkładach prawdopodobieństwa w wielowymiarowych przestrzeniach wektorowych (latent spaces), mają tendencję do agregowania terminów powiązanych kontekstowo, lecz fundamentalnie różnych pod względem inżynieryjnym i funkcjonalnym.

Opisany przypadek, w którym system sztucznej inteligencji myli "kołowrotek" ze "szpulką", generuje nieistniejące w żargonie neologizmy (np. "oddechówka") lub wprowadza bezpośrednie tłumaczenia anglojęzycznego slangu ("kiełbasa" w miejsce bojki dekompresyjnej ze względu na termin "safety sausage"), stanowi klasyczny objaw braku zewnętrznej warstwy weryfikacyjnej. Aby mechanizm wyszukiwania semantycznego działał poprawnie i deterministycznie, absolutnie konieczne jest zbudowanie relacyjnej encyklopedii sprzętowej (Minimum Viable Product – MVP). Encyklopedia ta, sformatowana jako ustrukturyzowany plik JSON, posłuży jako ostateczne źródło prawdy (Single Source of Truth) dla modułu generującego w architekturze Retrieval-Augmented Generation (RAG).1

Niniejszy raport stanowi wyczerpującą analizę zgromadzonego korpusu danych (obejmującego około 650 000 tokenów), ewaluację architektur wykorzystujących modele klasy Long-Context (ze szczególnym uwzględnieniem modeli Gemini 3.1 Pro oraz Claude 4.6 Sonnet opublikowanych w lutym 2026 r.) 2 oraz szczegółowy projekt systemu ekstrakcji, który zagwarantuje spełnienie rygorystycznych kryteriów akceptacji.

## **2\. Diagnoza Błędów Semantycznych w Przestrzeniach Wektorowych**

Zrozumienie, dlaczego bazowy model LLM popełnia błędy wymienione w definicji problemu, jest kluczowe dla zaprojektowania skutecznej strategii ekstrakcji. Błędy te nie wynikają z "niewiedzy" modelu, lecz z natury osadzeń (embeddings) i sposobu, w jaki sieci neuronowe kompresują język.

### **2.1. Zjawisko Zbieżności Wektorowej (Vector Space Proximity)**

Problem utożsamiania "szpulki" (spool) z "kołowrotkiem" (reel) 4 wynika z faktu, że w ogólnym korpusie tekstowym oba te przedmioty współwystępują w identycznym kontekście (linka, nurkowanie jaskiniowe, bojka SMB, wynurzanie). W przestrzeni osadzeń (embedding space) wektory reprezentujące te dwa słowa mają bardzo wysoką miarę podobieństwa cosinusowego (cosine similarity). System pgvector, otrzymując zapytanie o "szpulkę", w naturalny sposób zwróci również produkty skategoryzowane jako "kołowrotek", ponieważ nie posiada twardej definicji mechanicznej różnicy między nimi.4 Szpulka to prosty walec obsługiwany oburącz (z użyciem karabinka), podczas gdy kołowrotek to skomplikowany mechanizm z korbką, obudową i blokadą.4 Encyklopedia sprzętowa musi wymusić relację nie\_mylic\_z, aby algorytm RAG mógł wdrożyć tzw. *hard negative mining* (wykluczanie twardych fałszywych dopasowań).

### **2.2. Halucynacje Neologiczne**

Pojawienie się terminu "oddechówka" jako synonimu "automatu oddechowego" to przykład nadaktywności modelu generatywnego, który stosuje polskie reguły słowotwórcze (np. wiatrówka, żaglówka) do tworzenia potocznych form od przymiotnika "oddechowy". Wprowadzenie rygorystycznej encyklopedii wymusi na systemie stosowanie tagu bledne\_uzycie, co pozwoli na mapowanie tego błędnego zapytania klienta na poprawny canonical\_term\_pl: "Automat oddechowy" bez jednoczesnego zanieczyszczania bazy produktowej nieistniejącymi słowami. Dodatkowo, powszechnym błędem jest nadużywanie terminu "aparat oddechowy" w odniesieniu do akcesoriów takich jak uprząż boczna (stage harness), ustniki czy o-ringi.4 Uprząż jest systemem taśm mocujących, a nie urządzeniem podającym gaz, co wymaga ostrego rozgraniczenia ontologicznego.

### **2.3. Zanieczyszczenie Wielojęzyczne (Cross-lingual Contamination)**

Tłumaczenie angielskiego slangu nurkowego na język polski w sposób dosłowny to znany problem modeli wielojęzycznych. "Safety sausage" to powszechnie uznany angielski synonim dla powierzchniowej boi sygnałowej (SMB).4 Kiedy model LLM operuje w przestrzeni dwujęzycznej bez ścisłych granic nałożonych w prompcie, relacje semantyczne "przeciekają" między językami. Prowadzi to do absurdalnych asocjacji, gdzie polskie zapytanie zostaje powiązane ze słowem "kiełbasa". Wymuszenie rygorystycznej separacji (canonical\_term\_pl vs canonical\_term\_en) i ograniczenie anglicyzmów tylko do form jawnie autoryzowanych (np. "Jacket", "Lung" jako formy historyczne lub typowo slangowe jak "Octopus" dla zapasowego drugiego stopnia) 4 całkowicie eliminuje ten szum.

## **3\. Analiza i Harmonizacja Zgromadzonego Korpusu Danych**

System ma docelowo przetworzyć korpus o rozmiarze około 650 000 tokenów. Opanowanie tak gigantycznej i heterogenicznej bazy wymaga precyzyjnego zarządzania wagami epistemologicznymi (epistemological weighting), czyli ustalenia, w jaki sposób model ma rozwiązywać konflikty informacyjne.

### **3.1. Charakterystyka Składowych Korpusu i Ich Znaczenie**

1. **PADI Encyclopedia of Recreational Diving (Ch3 & Ch5) \[EN, \~164k tokenów\]:** Stanowi bezwzględny autorytet (Primary Source) w zakresie taksonomii międzynarodowej, nazewnictwa anglojęzycznego (canonical\_term\_en) oraz fundamentalnych zasad działania sprzętu podtrzymującego życie. Styl jest wysoce encyklopedyczny.4 Jako dokument oryginalny, jest odporny na błędy translacyjne. To z niego model zaczerpnie definicję, że regulator redukuje ciśnienie w dwóch etapach (first and second stage) do ciśnienia otoczenia.  
2. **Książka OWD IANTD (Markdown) \[PL, \~105k tokenów\]:** Najważniejsze źródło dla formalnej, inżynieryjnej terminologii w języku polskim (canonical\_term\_pl oraz techniczny). Dostarcza niezwykle szczegółowych parametrów fizycznych – na przykład, że pierwszy stopień redukuje ciśnienie do poziomu 8,3–12,4 bar powyżej ciśnienia otoczenia, co jest kluczowe dla zdefiniowania relacji nadrzędno-podrzędnych w encyklopedii.4  
3. **Skrypt szkoleniowy CMAS (albikrosno.pl) \[PL\]:**  
   Pełni rolę pomocniczą i ugruntowującą. Służy do weryfikacji krzyżowej (cross-referencing) terminologii technicznej z podręcznikiem IANTD.  
4. **nurkomania.pl – teoria i sprzęt (JSON) \[PL, \~380k tokenów\]:** Mimo ogromnej objętości i około 50% poziomu duplikacji w sekcji sprzętowej, jest to absolutnie kluczowe źródło dla pozyskania synonimów typu potoczny. Zawiera bezcenne opisy procedur, specyficzny polski żargon (np. "ukręcenie palca" w kontekście nieprawidłowego trzymania szpulki) oraz subiektywne oceny modeli.4 Styl blogowy sprawia jednak, że definicje tu zawarte ustępują miejsca podręcznikom PADI i IANTD w przypadku jakichkolwiek sprzeczności mechanicznych.  
5. **Metadane sklepu divezone.pl:** Dostarczają realnych wzorców wyszukiwania (aliasy, synonimy wyszukiwarkowe). Kategorie sklepowe ("Pianki na ZIMNE wody", "Skrzydła z uprzężą do Twina") definiują finalne pozycjonowanie komercyjne.4 To źródło decyduje o tym, że formalne pojęcie kompensatora pływalności będzie rzutowane na najpopularniejsze zapytania "BCD" i "Jacket".4

### **3.2. Implementacja Hierarchii Źródeł (Source Resolution Logic)**

Aby model LLM przetworzył te dane zgodnie z założonym priorytetem (PADI \> IANTD \> CMAS \> Nurkomania), zasady te nie mogą być jedynie ogólną instrukcją. Muszą zostać wbudowane w strukturę systemową (System Prompt) jako twardy algorytm decyzyjny.

W przypadku konfliktu (np. plik CSV ze sklepu nazywa uprząż "aparatem oddechowym" 4, a podręcznik IANTD rezerwuje tę nazwę wyłącznie dla dwustopniowego mechanizmu z wężami 4), model musi zostać poinstruowany, aby kategorycznie odrzucił nazewnictwo ze sklepu w polu canonical\_term\_pl, przeniósł ten błąd do tablicy synonimy\_pl z etykietą bledne\_uzycie lub niezalecany, a poprawną definicję wywiódł bezpośrednio z IANTD/PADI.

Struktura łącząca 650 000 tokenów wykracza poza tradycyjne zarządzanie pamięcią w AI. Wymaga architektury, która jest w stanie przechowywać ten ogrom wiedzy bez zjawiska "wygasania uwagi" (attention fading).

## **4\. Ewaluacja Podejść Architektonicznych dla Ekstrakcji Wiedzy (Rozwiązanie Problemu 1, 2 i 3\)**

Zarządzanie korpusem 650 tysięcy tokenów wymaga rygorystycznego doboru modelu. Do niedawna standardem branżowym było około 128 tysięcy tokenów (np. GPT-4 Turbo).5 Obecnie rynek oferuje modele zdolne do asymilacji 1 miliona tokenów i więcej, takie jak Gemini 3.1 Pro (limit wejściowy 1 048 576 tokenów) 7 oraz Claude 4.6 Sonnet (1M tokenów w wersji beta).9

W odpowiedzi na kluczowe pytania dotyczące strategii wygenerowania 40 kategorii ustrukturyzowanej encyklopedii, poddano analizie trzy główne podejścia.

### **4.1. Podejście A: Single-Pass Generation (Pełny korpus → Jedna sesja → Cała encyklopedia)**

W tym scenariuszu cały korpus 650k tokenów oraz instrukcja systemowa zostają wysłane do modelu Gemini 3.1 Pro w jednym potężnym zapytaniu, z żądaniem wygenerowania docelowego pliku JSON zawierającego wszystkie 40 kategorii sprzętowych jednocześnie.

* **Zdolność pojemnościowa:** Model Gemini 3.1 Pro bez problemu pomieści 650 000 tokenów na wejściu.7 Testy "Needle in a Haystack" wykazują, że model ten osiąga ponad 99% skuteczności w odzyskiwaniu pojedynczych informacji nawet przy głębokości 1 miliona tokenów.6  
* **Ryzyko "Context Rot" i gubienia detali (Lost in the Middle):** Chociaż model świetnie radzi sobie ze znalezieniem *pojedynczej* igły w stogu siana, zadanie postawione w tym projekcie to ekstrakcja *wielu igieł* (multi-needle retrieval) oraz ich głęboka synteza krzyżowa. Zjawisko znane jako "Context Rot" powoduje, że gdy model musi wyabstrahować kilkadziesiąt niezależnych relacji JSON z bardzo długiego tekstu, jego uwaga ulega drastycznej degradacji.12 Przy wyciąganiu kategorii 35-tej (np. "Sygnalizatory"), model może zapomnieć o niuansach zadeklarowanych w instrukcji systemowej dotyczących użycia anglicyzmów, co doprowadzi do halucynacji lub powierzchownego wypełnienia pól.  
* **Ograniczenia techniczne wyjścia (Output Limit):** Modele z rodziny Gemini 3.1 Pro posiadają twardy limit tokenów wyjściowych (Output Token Limit) wynoszący 65 536 tokenów.7 Wygenerowanie kompletnej, zniuansowanej encyklopedii z obszernymi opisami relacji i dowodami dla 40 kategorii w jednym wywołaniu stwarza gigantyczne ryzyko nagłego przerwania strumienia JSON przed domknięciem klamr.  
* **Wniosek:** To podejście jest stanowczo odradzane. Nie spełnia rygorów precyzji terminologicznej i niesie ryzyko fatalnego ucięcia pliku wynikowego.

### **4.2. Podejście B: Fragmentacja i Map-Reduce (RAG per kategoria w modelu Claude)**

Alternatywne rozwiązanie (często stosowane przed erą okien 1M+) polega na pocięciu 650k tokenów na małe fragmenty (chunking), umieszczeniu ich w wektorowej bazie danych, a następnie odpytywaniu modelu (np. Claude 4.5 lub 4.6 Sonnet) dla każdej z 40 kategorii osobno, podając mu tylko te fragmenty, które wyszukiwarka uzna za "istotne" (np. 5–10k tokenów per wywołanie).14

* **Zalety:** Rozwiązuje problem limitu wyjściowego i przeciążenia atencji. Modele Claude z rodziny Sonnet słyną z fenomenalnej precyzji w krótkich kontekstach oraz rygorystycznego trzymania się schematów JSON.15  
* **Wady i utrata spójności ontologicznej:** To podejście drastycznie zaburza wymóg budowania obustronnych relacji (nie\_mylic\_z). Jeśli zapytamy bazę wektorową o fragmenty dotyczące "kołowrotka", algorytm może nie pobrać fragmentu podręcznika mówiącego o "szpulce", ponieważ uzna je za rozbieżne leksykalnie. W efekcie, gdy Claude będzie generował obiekt dla "kołowrotka", nie będzie świadomy istnienia "szpulki" i nie nawiąże relacji.4 System traci pełny obraz sytuacji (holistic understanding), co przy definiowaniu sztywnych ram referencyjnych dla e-commerce jest niedopuszczalne. Fragmentacja niszczy synergię między podręcznikiem technicznym a slangiem blogowym.  
* **Wniosek:** Podejście to nie zrealizuje celu budowy wysoce ustrukturyzowanej bazy relacyjnej.

### **4.3. Podejście C: Hybrydowe Index-Synthesize (Gemini indeksuje, Claude syntetyzuje)**

Wykorzystanie Gemini 3.1 Pro do "przeczytania" całego korpusu i wygenerowania mapy (indeksu), gdzie dokładnie znajdują się definicje, a następnie przesłanie precyzyjnie wybranych, dłuższych wycinków do modelu Claude.

* **Zalety:** Teoretycznie łączy analityczną głębię Claude z ogromną pamięcią Gemini.  
* **Wady:** Niewspółmiernie wysoka złożoność inżynieryjna (pipeline engineering). Trudność w debugowaniu: w przypadku błędu w finalnym JSON nie wiadomo, czy zawinił indeksator (Gemini) czy syntetyzator (Claude). Koszty operacyjne rosną wykładniczo, ponieważ płaci się zarówno za przetwarzanie wielkich kontekstów w Google, jak i za wywołania API Anthropic.17

### **4.4. Tabela Porównawcza Architektur**

Poniższa tabela stanowi wielokryterialne podsumowanie rozważanych koncepcji, wprowadzając jednocześnie docelową, rekomendowaną architekturę (szczegółowo opisaną w rozdziale 5).

| Kryterium | Podejście A: Single-Pass (Gemini 1-shot) | Podejście B: Chunking / RAG (Claude per kat.) | Podejście C: Hybryda (Gemini Index \+ Claude) | Rekomendacja: GCIE (Gemini Context Caching) |
| :---- | :---- | :---- | :---- | :---- |
| **Jakość i precyzja terminologii** | Niska (gubienie detali w gąszczu żądań, ryzyko spłycenia definicji) | Wysoka lokalnie, ale bardzo niska globalnie (brak szerokiego poglądu) | Bardzo wysoka | **Najwyższa** (pełny wgląd w ontologię PADI/IANTD przy każdej definicji) |
| **Spójność relacyjna (np. dwustronność)** | Bardzo słaba (Context Rot) | Słaba (wyszukiwarka wektorowa zgubi powiązania) | Wysoka | **Znakomita** (model każdorazowo przeszukuje ten sam zamrożony korpus) |
| **Kontrolowalność i JSON Schema** | Fatalna (nieuniknione przekroczenie limitu 65k tokenów wyjściowych) | Znakomita | Dobra (ale bardzo złożony pipeline) | **Znakomita** (generowanie tylko jednego węzła JSON na zapytanie) |
| **Koszty operacyjne (API)** | Wysokie (odpytywanie całego korpusu w złej strukturze) | Średnie | Bardzo wysokie (podwójne koszty u dwóch dostawców) | **Bardzo niskie** (koszt odczytu z bufora jest ułamkiem ceny przetwarzania na żywo) |
| **Łatwość iteracji i debugowania** | Znikoma (każda poprawka to przebudowa całości) | Dobra | Trudna | **Bardzo wysoka** (każdą kategorię można nadpisać izolowanym promptem) |

## **5\. Rekomendowana Architektura: Globalny Kontekst z Iteracyjną Ekstrakcją (GCIE) przy użyciu Context Caching**

Jedyną architekturą, która z całą stanowczością spełnia wymogi niezawodności, braku halucynacji semantycznych i poprawności strukturalnej JSON, jest **Globalny Kontekst z Iteracyjną Ekstrakcją (GCIE)**, realizowany poprzez funkcję **Context Caching** (Buforowanie Kontekstu) na modelu **Gemini 3.1 Pro Preview**.7

Rozwiązanie to zamienia skomplikowany problem "wielu igieł" na serię 40 niezależnych problemów "pojedynczej igły", zachowując przy tym widoczność całego lasu danych.

### **5.1. Mechanika Działania Architektury GCIE**

1. **Faza 1: Zapis do Bufora (Cache Write):** Cały, ważący około 650 000 tokenów korpus tekstowy (połączone pliki Markdown z PADI i IANTD, skrypt CMAS, pliki JSON z Nurkomanii, metadane sklepu Divezone) wraz z fundamentalnymi instrukcjami systemowymi definiującymi reguły rozstrzygania konfliktów, zostaje wysłany do API Google w celu utworzenia zbuforowanego kontekstu (Context Cache).19 Operacja ta wykonywana jest jednorazowo. Kontekst ten zostaje "zamrożony" na serwerach Google na zdefiniowany czas (np. na 1 godzinę, zwaną *Time to Live \- TTL*).19  
2. **Faza 2: Iteracyjna Ekstrakcja (Cache Read & Extraction):**  
   Zamiast jednego żądania o całość, skrypt aplikacyjny uruchamia pętlę generującą 40 oddzielnych zapytań (promptów użytkownika) kierowanych do tego samego, zbuforowanego zasobu. Każde zapytanie instruuje model: *"Korzystając z załadowanego, globalnego kontekstu, wygeneruj pełny, ustrukturyzowany obiekt JSON wyłącznie dla kategorii:"*.  
3. **Faza 3: Post-processing i Walidacja:** Otrzymujemy 40 małych, wysoce precyzyjnych plików JSON (każdy o wielkości zaledwie kilku tysięcy tokenów wyjściowych, absolutnie bezpiecznych z punktu widzenia limitu 65 536 tokenów 7). Pliki te są następnie łączone programistycznie przez prosty skrypt Python w finalną encyklopedię. Ten sam skrypt może w ułamku sekundy zweryfikować kryterium akceptacji nr 2, sprawdzając, czy każda definicja w polu nie\_mylic\_z posiada odpowiednik w docelowym węźle (wymuszając relacje dwustronne).

### **5.2. Dlaczego GCIE jest rozwiązaniem optymalnym?**

Po pierwsze, eliminujemy "Context Rot". Ponieważ każdy z 40 promptów pyta tylko o jedną rzecz (np. "opisz mi wszystko, co wiesz o bojach dekompresyjnych w tym korpusie, i zrób z tego JSON"), cały mechanizm atencji wektora (attention mechanism) skupia się wyłącznie na tym zadaniu. Zapobiega to pomijaniu detali. Po drugie, zapewniamy absolutną spójność terminologiczną. Podczas analizy "szpulki", model "widzi" cały korpus Nurkomanii oraz IANTD i jest w stanie poprawnie wywnioskować z podręcznika, że szpulka nie jest kołowrotkiem (bo brak jej korby 4), poprawnie tworząc węzeł nie\_mylic\_z i wyciągając ostrzeżenia bezpieczeństwa (np. o ryzyku "ukręcenia palca" 4) jako dowód (evidence). Po trzecie, wykorzystujemy Structured Outputs (funkcja JSON Schema udostępniana w Gemini 3.1 Pro przez Pydantic) 20, co na poziomie sprzętowym API wymusza zwrot idealnie uformowanego formatu bez halucynowania dodatkowych kluczy czy formatowania Markdown.

## **6\. Izolacja Językowa i Dwujęzyczność w Długim Kontekście**

Pytanie nr 4 dotyczy ryzyka przemieszania języka polskiego i angielskiego przy analizie 650k tokenów. Ryzyko kontaminacji krzyżowej (cross-lingual contamination) w modelach LLM jest powszechne. Istnieje ogromne niebezpieczeństwo, że angielska definicja z PADI "leaknie" do polskiego opisu w JSON-ie. Przykłady z bazy (ID 213, 214\) pokazują, jak łatwo slang "safety sausage" miesza się z pojęciami formalnymi.4

### **6.1. Techniki Przeciwdziałania Kontaminacji Językowej**

Aby model Gemini 3.1 Pro, analizując zbuforowany materiał w dwóch językach, bezbłędnie oddzielił warstwy lingwistyczne, należy zastosować kombinację inżynierii promptu oraz ustrukturyzowanych schematów wyjścia:

1. **Wymuszenie Schematu Zależnego Typologicznie (Strict JSON Schema via API):** Użycie w konfiguracji API pola response\_mime\_type: "application/json" oraz ścisłej definicji JSON Schema.20 Jeśli pole nazywa się definicja\_pl, w jego opisie systemowym w Pydantic dodajemy rygorystyczny warunek: *"Only valid Polish syntax. Reject all English terms"*. Dzięki temu mechanizmy dekodujące (controlled decoding) na serwerach Google odrzucą tokeny anglojęzyczne dla tego konkretnego klucza.21  
2. **Metodologia Chain-of-Dictionary (CoD):** Zastosowanie techniki wzbogacania promptów znanej jako Chain-of-Dictionary.22 W prompcie systemowym deklarujemy bezpośrednie paradygmaty tłumaczeń dla pojęć wysoce ryzykownych. Np. wpisujemy twardą regułę: "Oktopus" (PL) oznacza "Octopus" (EN). Pojęcie "Lung" (EN) w odniesieniu do automatu to archaizm i nie należy go stosować w synonimach PL. "Safety sausage" to angielski slang, który pod żadnym pozorem nie może być tłumaczony jako "kiełbasa" w sekcji polskiej, lecz jako "boja dekompresyjna/SMB".4 Taki łańcuch wymusza na modelu właściwą dezambiguację.  
3. **Kapsułkowanie Ról Tłumaczeniowych:**  
   Dzięki zastosowaniu pętli GCIE (40 osobnych wywołań), model na początku każdego żądania otrzymuje jasną granicę – musi sformułować wynik w sztywnym, izolowanym bloku danych. Ograniczenie wielozadaniowości na poziomie jednego wywołania to najsilniejszy bufor przeciwko mieszaniu języków.

## **7\. Inżynieria Promptu: Architektura Corpus-in-Context (CiC)**

Aby model klasy 1M+ tokenów zrealizował ekstrakcję bez zjawiska gubienia detali z powodu przeciążenia uwagą (attention overload), prompt musi być sformatowany metodą "Bottom-Heavy" w architekturze Corpus-in-Context (CiC).23 Oznacza to, że gigantyczny ładunek danych musi znajdować się *na początku*, a właściwe instrukcje decyzyjne *na samym końcu*.23 Świeżość instrukcji w oknie kontekstowym decyduje o sile wpływu na generację. Włączenie parametru thinking\_level=HIGH w Gemini 3.1 Pro dodatkowo zapewni głębokie przetwarzanie ukryte przed zwróceniem struktury JSON.24

### **7.1. Blok 1: Prompt Systemowy i Korpus (Wysyłane do Cache – jednorazowo)**

Zaleca się użycie znaczników XML w celu logicznej separacji źródeł 25, co pozwoli modelowi odróżnić wagę autorytetów zgodnie z wymogiem (PADI \> IANTD \> Nurkomania).

XML

\<system\_role\>  
Jesteś głównym inżynierem ds. danych ontologicznych i ekspertem nurkowania w architekturze e-commerce.  
Twoim zadaniem jest przetworzenie obszernego korpusu wiedzy (teoria, podręczniki, opisy sklepowe) w celu wygenerowania absolutnie precyzyjnego słownika referencyjnego (JSON).  
\</system\_role\>

\<conflict\_resolution\_rules\>  
KRYTYCZNE ZASADY HIERARCHII ŹRÓDEŁ W PRZYPADKU SPRZECZNOŚCI:  
1\. PADI Encyclopedia (EN) oraz Podręcznik IANTD (PL) są bezwzględnym źródłem prawdy dla mechaniki, inżynierii i bezpieczeństwa. Jeśli PADI twierdzi, że "regulator" to urządzenie dwustopniowe, a baza sklepu twierdzi, że ustnik to "aparat", uznaj bazę sklepu za błąd potoczny.  
2\. Portal nurkomania.pl dostarcza polskiego żargonu i potocznych zastosowań (np. ryzyko "ukręcenia palca" przy szpulce), ale nie nadpisuje definicji fizycznych.  
3\. Kategoryzacja sklepowa definiuje ostateczną formę nazw w e-commerce.  
4\. ZERO mieszania języków. Anglicyzmy używane w Polsce (np. "Jacket", "BCD", "Octopus") są dopuszczalne w tablicach PL wyłącznie z etykietą "typ": "anglicyzm". Nie generuj ślepych tłumaczeń slangu.  
\</conflict\_resolution\_rules\>

\<source\_data\_1\_padi\_en\>

\</source\_data\_1\_padi\_en\>

\<source\_data\_2\_iantd\_pl\>

\</source\_data\_2\_iantd\_pl\>

\<source\_data\_3\_cmas\_pl\>

\</source\_data\_3\_cmas\_pl\>

\<source\_data\_4\_nurkomania\_pl\>

\</source\_data\_4\_nurkomania\_pl\>

\<source\_data\_5\_divezone\_metadata\>

\</source\_data\_5\_divezone\_metadata\>

### **7.2. Blok 2: Prompt Iteracyjny (Wysyłany 40 razy do załadowanego bufora)**

XML

\<execution\_command\>  
Na podstawie załadowanego korpusu danych sprzętowych w pamięci podręcznej, wykonaj bezbłędną ekstrakcję wiedzy dla konkretnej grupy asortymentowej. Zignoruj wszystkie inne kategorie i skup 100% uwagi na poniższym zadaniu.

CEL: Wygeneruj ustrukturyzowany obiekt JSON wyłącznie dla kategorii: "AUTOMAT ODDECHOWY".

WYMOGI WALIDACYJNE:  
\- Pola "canonical\_term\_pl" i "canonical\_term\_en" muszą być precyzyjne inżynieryjnie.  
\- Obiekt "relacje" MUSI zawierać wnikliwą analizę wykluczeń (typ: "nie\_mylic\_z"). Przeanalizuj, czy dany przedmiot nie jest mylony w bazie ze swoim podzespołem lub przedmiotem o podobnej funkcji.  
\- Obiekt "synonimy\_pl" nie może zawierać słów angielskich (chyba że są zadeklarowane jako "anglicyzm"). Oznacz spotykane błędne nazwy jako "bledne\_uzycie" lub "niezalecany" (np. oddechówka).  
\- Pole "evidence" musi zawierać krótkie cytaty lub odwołania z załadowanego korpusu udowadniające Twoją dedukcję.

Zwróć odpowiedź w czystym, prawidłowym formacie JSON, dokładnie według zadeklarowanego wcześniej schematu strukturalnego API.  
\</execution\_command\>

## **8\. Analiza Ryzyk i Konkretne Strategie Mitigacji**

Mimo ogromnych zdolności modeli klasy 1M+ tokenów, projekt przetwarzania specjalistycznej ontologii sprzętowej wiąże się z konkretnymi wyzwaniami technicznymi.

### **8.1. Ryzyko Asymetrii Relacji (Brak powiązań dwustronnych)**

Kryterium akceptacji nr 2 wymaga, aby każda relacja nie\_mylic\_z była dwustronna (A wskazuje na B, a B wskazuje na A). Model LLM rozpatrujący każdą kategorię w osobnej iteracji (architektura GCIE) wyprodukuje pliki, które z dużym prawdopodobieństwem wskażą konflikt jednostronnie (np. przy generowaniu "Szpulki" wpisze w relacje "Kołowrotek", ale przy generowaniu "Kołowrotka" może skupić się na szpulce, ale pominąć relację formalną).

**Mitigacja:** Należy zastosować walidację w postaci post-processingu (Python). Algorytm zwinie wszystkie 40 plików JSON do jednego obiektu. Skrypt wyszuka wszystkie wystąpienia "typ": "nie\_mylic\_z". Jeśli kategoria A posiada wpis o kategorii B, skrypt automatycznie dokona injekcji asercji odwrotnej do węzła kategorii B. System sztucznej inteligencji nie powinien odpowiadać za to, co można zabezpieczyć stuprocentowym algorytmem warunkowym.

### **8.2. Ryzyko Przekroczenia Limitu Tokenów Wyjściowych (Truncation)**

W modelach Gemini 3.1 Pro twardy limit wyjścia to 65 536 tokenów.7 Próba zlecenia modelowi napisania pełnej encyklopedii w jednym przebiegu niesie gigantyczne ryzyko przekroczenia tego progu i zerwania struktury JSON w połowie. **Mitigacja:** Rekomendowana architektura GCIE. Dzięki podziałowi zadania na 40 osobnych wywołań w pętli opierającej się na buforze pamięci (Context Cache), każdy wygenerowany plik JSON nie przekroczy 2000-3000 tokenów. Bezpieczeństwo strukturalne zostaje zagwarantowane.

### **8.3. Ryzyko "Gładkiego" Słownictwa (Brak wykluczeń slangu)**

Modele często "wygładzają" język w chęci bycia pomocnymi, ignorując nakaz kategoryzowania niepoprawnych pojęć. **Mitigacja:** Wykorzystanie pola evidence zmusza model do wykonania tzw. *Chain-of-Thought* wewnątrz samego dokumentu JSON.25 Skonstruowanie parametru thinking\_level: HIGH przed wygenerowaniem wyniku skłoni model Gemini do dogłębnego przemyślenia zależności (np. "Dlaczego w bazie sklepu nazwali to uprzężą oddechową? To błąd logiczny. Ustawiam typ na bledne\_uzycie").24

## **9\. Analiza Ekonomiczna i Czasowa (Kosztorys dla Gemini 3.1 Pro)**

Pytanie numer 5 dotyczy szacunkowych kosztów dla wolumenu 650k tokenów wejściowych i około 50k tokenów wyjściowych dla encyklopedii, w oparciu o cennik modelu na dzień udzielenia odpowiedzi (stan na połowę/koniec lutego 2026 r.).3

**Cennik API Google Gemini 3.1 Pro Preview (dla zapytań powyżej 200k tokenów):**

* **Input (standardowy):** 4,00 USD za 1 milion tokenów.26  
* **Output:** 18,00 USD za 1 milion tokenów.26  
* **Context Caching \- Zapis (Write):** W cenie standardowego inputu (4,00 USD / 1M).26  
* **Context Caching \- Przechowywanie (Storage):** 4,50 USD za 1 milion tokenów za godzinę.26  
* **Context Caching \- Odczyt (Read):** 0,40 USD za 1 milion tokenów.26 To kluczowa oszczędność – odczyt zbuforowanego kontekstu kosztuje zaledwie 10% pełnego kosztu inputu.

### **Szacunkowy Kosztorys Wdrożenia Architektury GCIE (40 Iteracji z Cache)**

Założenia wejściowe:

* Korpus: 650 000 tokenów (0,65 M).  
* Liczba wywołań (kategorii do opracowania): 40\.  
* Sumaryczna wielkość wyjścia (Output): ok. 50 000 tokenów (0,05 M).  
* Czas operacji pętli po stronie serwerów i przechowywania cache: maksymalnie 1 godzina.19

**Kalkulacja:**

1. **Ładowanie korpusu do pamięci podręcznej (Cache Write, jednorazowo):**  
   0,65 M × 4,00 USD \= **2,60 USD**.  
2. **Przechowywanie korpusu w pamięci (TTL \= 1 godzina):**  
   0,65 M × 4,50 USD \= **2,92 USD**.  
3. **Odpytywanie z pamięci podręcznej (Cache Read \- 40 wywołań):**  
   Łącznie system pobierze referencyjnie: 40 × 0,65 M \= 26,0 M tokenów odczytanych z bufora.  
   26,0 M × 0,40 USD \= **10,40 USD**.  
4. **Generowanie plików JSON (Output):**  
   Wszystkie 40 iteracji wygeneruje łącznie zakładane 50 tysięcy tokenów wyjściowych.  
   0,05 M × 18,00 USD \= **0,90 USD**.

**Całkowity Szacunkowy Koszt Wygenerowania Encyklopedii (MVP):**

2,60 USD \+ 2,92 USD \+ 10,40 USD \+ 0,90 USD \= **16,82 USD.**

*Wnioski z kosztorysu i analizy czasowej:* Zastosowanie Context Caching pozwoliło na przetworzenie de facto 26 milionów tokenów wejściowych (ze względu na 40 zapytań podtrzymujących w tle 650 tysięcy jednostek leksykalnych) za zaledwie kilkanaście dolarów. Gdybyśmy zrezygnowali z architektury GCIE na rzecz standardowego odpytywania z pełnym przesyłaniem kontekstu za każdym razem, operacja ta kosztowałaby 104,00 USD (26M × 4,00 USD). Operacja wywołania 40 żądań przez API zajmie od kilkunastu sekund (przy zrównolegleniu wywołań asynchronicznych) do maksymalnie kilkunastu minut (sekwencyjnie). Dla modelu Claude 4.6 Sonnet koszt ten wyniósłby blisko 260 USD za samo standardowe przetwarzanie wejścia w oknie 1M Beta (10 USD / 1M tokenów) 28, pomijając ewentualne możliwości prompt cachingu (którego cennik jest bardziej złożony i droższy dla długich okien).

## **10\. Konkluzje Implementacyjne**

Podsumowując powyższą analizę, budowa precyzyjnej ontologii sprzętowej dla wyszukiwarki semantycznej jest kluczowym warunkiem eliminacji halucynacji w e-commerce. Modelowanie wektorowe jest z zasady ślepe na rygor inżynieryjny, spłaszczając odległości semantyczne między obiektami współwystępującymi (jak szpulka i kołowrotek).

1. **Architektura Główna:** Wdrożenie **Globalnego Kontekstu z Iteracyjną Ekstrakcją (GCIE)** przy wsparciu technologii **Context Caching** w modelu **Gemini 3.1 Pro Preview** to rozwiązanie deklasujące inne podejścia. Łączy ono ontologiczną spójność (cały system widzi definicje z PADI i IANTD przy każdej iteracji) z uniknięciem "Context Rot" (model analizuje tylko jeden wycinek na jedno wywołanie), gwarantując jednocześnie bezproblemowe zmieszczenie się w limitach wyjściowych tokenów.  
2. **Zapobieganie Halucynacjom Ontologicznym:** Konfiguracja API Gemini z wymuszeniem parametru wyjściowego response\_mime\_type: "application/json" oraz ścisłego schematu wyeliminuje nieprzewidywalność strukturalną. Zaaplikowanie promptu w formacie "Corpus-in-Context" z instrukcjami zadanymi na końcu łańcucha wyzeruje pokusę mieszania logiki, gwarantując egzekwowanie zasady PADI \> IANTD \> Kategoryzacja sklepowa.  
3. **Integralność Dwujęzyczna:** Zdefiniowanie twardych reguł translacyjnych i łańcuchów wykluczeń (Chain-of-Dictionary) na poziomie instrukcji systemowej – nakazujących kategoryzowanie anglicyzmów (np. BCD, Jacket) jako dopuszczalnych w polskim środowisku e-commerce, ale stygmatyzujących błędy (np. lung, safety sausage) jako niezalecane – ustabilizuje przestrzeń definicyjną.  
4. **Wektoryzacja w pgvector:** Gotowy zestaw zintegrowanych dokumentów JSON, ze wzbogaconym post-processingiem skryptowym wymuszającym pełną dwustronność relacji nie\_mylic\_z, zapewni niekwestionowane źródło prawdy (Single Source of Truth) dla modułu generacyjnego. AI weryfikujące zapytanie w locie (np. "szukam szpulki 150m") wychwyci błąd logiczny na podstawie bazy (szpulka służy z zasady do obsługi mniejszych dystansów jak 15–30m bojki, a z powodu braku korby rozwinięcie 150m jest absurdalne), co pozwoli zaserwować klientowi rzetelną poradę oraz odpowiednią kolekcję kołowrotków w systemie rekomendacji.

#### **Cytowane prace**

1. RAG vs. long-context LLMs: A side-by-side comparison \- Meilisearch, otwierano: lutego 25, 2026, [https://www.meilisearch.com/blog/rag-vs-long-context-llms](https://www.meilisearch.com/blog/rag-vs-long-context-llms)  
2. Introducing Claude Sonnet 4.6 \- Anthropic, otwierano: lutego 25, 2026, [https://www.anthropic.com/news/claude-sonnet-4-6](https://www.anthropic.com/news/claude-sonnet-4-6)  
3. Google releases Gemini 3.1 Pro: Here's what's new and who gets it first, otwierano: lutego 25, 2026, [https://timesofindia.indiatimes.com/technology/tech-news/google-releases-gemini-3-1-pro-heres-whats-new-and-who-gets-it-first/articleshow/128569493.cms](https://timesofindia.indiatimes.com/technology/tech-news/google-releases-gemini-3-1-pro-heres-whats-new-and-who-gets-it-first/articleshow/128569493.cms)  
4. synonyms\_review\_v3.csv  
5. Long Context Models: Working with 1M+ Token Windows \- Let's Data Science, otwierano: lutego 25, 2026, [https://letsdatascience.com/blog/long-context-models-working-with-1m-token-windows](https://letsdatascience.com/blog/long-context-models-working-with-1m-token-windows)  
6. Gemini 1.5: Unlocking multimodal understanding across millions of tokens of context \- Googleapis.com, otwierano: lutego 25, 2026, [https://storage.googleapis.com/deepmind-media/gemini/gemini\_v1\_5\_report.pdf](https://storage.googleapis.com/deepmind-media/gemini/gemini_v1_5_report.pdf)  
7. Gemini 3.1 Pro Preview \- Google AI for Developers, otwierano: lutego 25, 2026, [https://ai.google.dev/gemini-api/docs/models/gemini-3.1-pro-preview](https://ai.google.dev/gemini-api/docs/models/gemini-3.1-pro-preview)  
8. Gemini 3.1 Pro | Generative AI on Vertex AI \- Google Cloud Documentation, otwierano: lutego 25, 2026, [https://docs.cloud.google.com/vertex-ai/generative-ai/docs/models/gemini/3-1-pro](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/models/gemini/3-1-pro)  
9. Claude Opus 4.6 \- Anthropic, otwierano: lutego 25, 2026, [https://www.anthropic.com/claude/opus](https://www.anthropic.com/claude/opus)  
10. Context windows \- Claude API Docs, otwierano: lutego 25, 2026, [https://platform.claude.com/docs/en/build-with-claude/context-windows](https://platform.claude.com/docs/en/build-with-claude/context-windows)  
11. The Needle in the Haystack Test and How Gemini Pro Solves It | Google Cloud Blog, otwierano: lutego 25, 2026, [https://cloud.google.com/blog/products/ai-machine-learning/the-needle-in-the-haystack-test-and-how-gemini-pro-solves-it](https://cloud.google.com/blog/products/ai-machine-learning/the-needle-in-the-haystack-test-and-how-gemini-pro-solves-it)  
12. Context Rot: How Increasing Input Tokens Impacts LLM Performance | Chroma Research, otwierano: lutego 25, 2026, [https://research.trychroma.com/context-rot](https://research.trychroma.com/context-rot)  
13. Scaling Instruction-Tuned LLMs to Million-Token Contexts via Hierarchical Synthetic Data Generation \- arXiv.org, otwierano: lutego 25, 2026, [https://arxiv.org/html/2504.12637v1](https://arxiv.org/html/2504.12637v1)  
14. RAG vs Large Context Window: Real Trade-offs for AI Apps \- Redis, otwierano: lutego 25, 2026, [https://redis.io/blog/rag-vs-large-context-window-ai-apps/](https://redis.io/blog/rag-vs-large-context-window-ai-apps/)  
15. I benchmarked Claude 3.5 Sonnet vs Gemini 1.5 Pro for everyday web development tasks (Speed, Context, & Agentic Coding) : r/ClaudeAI \- Reddit, otwierano: lutego 25, 2026, [https://www.reddit.com/r/ClaudeAI/comments/1rcdd6r/i\_benchmarked\_claude\_35\_sonnet\_vs\_gemini\_15\_pro/](https://www.reddit.com/r/ClaudeAI/comments/1rcdd6r/i_benchmarked_claude_35_sonnet_vs_gemini_15_pro/)  
16. The Ultimate AI Model Comparison: Gemini 3.1 Pro vs. Claude Sonnet 4.6 and Claude Opus 4.6 \- iWeaver, otwierano: lutego 25, 2026, [https://www.iweaver.ai/blog/gemini-3-1-pro-vs-claude-sonnet-4-6-claude-opus-4-6/](https://www.iweaver.ai/blog/gemini-3-1-pro-vs-claude-sonnet-4-6-claude-opus-4-6/)  
17. Google Gemini API Pricing 2026: Complete Cost Guide per 1M Tokens \- MetaCTO, otwierano: lutego 25, 2026, [https://www.metacto.com/blogs/the-true-cost-of-google-gemini-a-guide-to-api-pricing-and-integration](https://www.metacto.com/blogs/the-true-cost-of-google-gemini-a-guide-to-api-pricing-and-integration)  
18. Anthropic Claude API Pricing 2026: Complete Cost Breakdown \- MetaCTO, otwierano: lutego 25, 2026, [https://www.metacto.com/blogs/anthropic-api-pricing-a-full-breakdown-of-costs-and-integration](https://www.metacto.com/blogs/anthropic-api-pricing-a-full-breakdown-of-costs-and-integration)  
19. Context caching | Gemini API | Google AI for Developers, otwierano: lutego 25, 2026, [https://ai.google.dev/gemini-api/docs/caching](https://ai.google.dev/gemini-api/docs/caching)  
20. Structured outputs | Gemini API \- Google AI for Developers, otwierano: lutego 25, 2026, [https://ai.google.dev/gemini-api/docs/structured-output](https://ai.google.dev/gemini-api/docs/structured-output)  
21. How to consistently output JSON with the Gemini API using controlled generation \- Medium, otwierano: lutego 25, 2026, [https://medium.com/google-cloud/how-to-consistently-output-json-with-the-gemini-api-using-controlled-generation-887220525ae0](https://medium.com/google-cloud/how-to-consistently-output-json-with-the-gemini-api-using-controlled-generation-887220525ae0)  
22. Chain-of-Dictionary (CoD) \- Learn Prompting, otwierano: lutego 25, 2026, [https://learnprompting.org/docs/advanced/few\_shot/chain-of-dictionary](https://learnprompting.org/docs/advanced/few_shot/chain-of-dictionary)  
23. Gemini 3 prompting best practices... precision, verbosity, context : r/singularity \- Reddit, otwierano: lutego 25, 2026, [https://www.reddit.com/r/singularity/comments/1p191ir/gemini\_3\_prompting\_best\_practices\_precision/](https://www.reddit.com/r/singularity/comments/1p191ir/gemini_3_prompting_best_practices_precision/)  
24. Thinking | Generative AI on Vertex AI \- Google Cloud Documentation, otwierano: lutego 25, 2026, [https://docs.cloud.google.com/vertex-ai/generative-ai/docs/thinking](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/thinking)  
25. How to Write Structured Prompts for Better, More Consistent Output \- YouTube, otwierano: lutego 25, 2026, [https://www.youtube.com/watch?v=rj8lUyrRZdc](https://www.youtube.com/watch?v=rj8lUyrRZdc)  
26. Gemini Developer API pricing, otwierano: lutego 25, 2026, [https://ai.google.dev/gemini-api/docs/pricing](https://ai.google.dev/gemini-api/docs/pricing)  
27. Gemini 3.1 Pro: A Hands-On Test of Google's Newest AI \- Analytics Vidhya, otwierano: lutego 25, 2026, [https://www.analyticsvidhya.com/blog/2026/02/gemini-3-1-pro-a-hands-on-test-of-googles-newest-ai/](https://www.analyticsvidhya.com/blog/2026/02/gemini-3-1-pro-a-hands-on-test-of-googles-newest-ai/)  
28. Claude Opus 4.6 API Pricing: 1M Context & Guide (2026) \- GlobalGPT, otwierano: lutego 25, 2026, [https://www.glbgpt.com/hub/claude-opus-4-6-api-pricing/](https://www.glbgpt.com/hub/claude-opus-4-6-api-pricing/)