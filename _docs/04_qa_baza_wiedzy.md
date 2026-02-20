# Baza wiedzy Q&A - Czat AI divezone.pl
# Wersja: 1.0 | Data: 2026-02-18
# Status: DRAFT - wymaga weryfikacji przez eksperta

---

## Jak korzystać z tego pliku

- Każde Q&A ma unikalny identyfikator (np. QA-001)
- Pole `direct_answer` oznacza czy odpowiedź może być zwrócona bez Claude (true = FAQ/logistyka)
- Pole `category` odpowiada kategorii w bazie wektorowej
- ZWERYFIKOWANE = sprawdzone przez eksperta, DRAFT = do weryfikacji
- Edytuj odpowiedzi tak, żeby odpowiadały aktualnej ofercie i polityce divezone.pl

---

## KATEGORIA: LOGISTYKA I OBSŁUGA KLIENTA

### QA-001
- **Status:** DRAFT
- **direct_answer:** true
- **category:** logistyka
- **Pytanie:** Jaki jest czas dostawy?
- **Odpowiedź:** Zamówienia złożone do godziny 14:00 w dni robocze wysyłamy tego samego dnia. Standardowa dostawa kurierem DPD/InPost to 1-2 dni robocze. Paczkomaty InPost zazwyczaj następnego dnia roboczego. [ZWERYFIKUJ: godzina graniczna i przewoźnicy]

### QA-002
- **Status:** DRAFT
- **direct_answer:** true
- **category:** logistyka
- **Pytanie:** Ile kosztuje dostawa?
- **Odpowiedź:** [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-003
- **Status:** DRAFT
- **direct_answer:** true
- **category:** logistyka
- **Pytanie:** Jak mogę zwrócić towar?
- **Odpowiedź:** Masz 14 dni od otrzymania przesyłki na zwrot towaru bez podania przyczyny. Towar musi być nieużywany, w oryginalnym opakowaniu. Formularz zwrotu znajdziesz na stronie w zakładce "Zwroty i reklamacje". Koszt odsyłki pokrywa kupujący, chyba że towar był wadliwy. [ZWERYFIKUJ: zgodność z aktualnym regulaminem]

### QA-004
- **Status:** DRAFT
- **direct_answer:** true
- **category:** logistyka
- **Pytanie:** Czy mogę odebrać zamówienie osobiście?
- **Odpowiedź:** [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-005
- **Status:** DRAFT
- **direct_answer:** true
- **category:** logistyka
- **Pytanie:** Jaki jest status mojego zamówienia?
- **Odpowiedź:** [AUTOMATYCZNA - system sprawdza w bazie po numerze zamówienia i emailu klienta. Nie wymaga gotowej odpowiedzi, obsługiwane przez narzędzie check_order_status]

### QA-006
- **Status:** DRAFT
- **direct_answer:** true
- **category:** logistyka
- **Pytanie:** Jakie są metody płatności?
- **Odpowiedź:** [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-007
- **Status:** DRAFT
- **direct_answer:** true
- **category:** logistyka
- **Pytanie:** Czy wystawiacie faktury VAT?
- **Odpowiedź:** Tak, fakturę VAT wystawiamy do każdego zamówienia. Dane do faktury podajesz podczas składania zamówienia. Jeśli potrzebujesz faktury do wcześniejszego zamówienia, skontaktuj się z nami podając numer zamówienia. [ZWERYFIKUJ]

---

## KATEGORIA: DOBÓR SPRZĘTU - KOMPUTERY NURKOWE

### QA-008
- **Status:** DRAFT
- **direct_answer:** false
- **category:** komputery_nurkowe
- **Pytanie:** Jaki komputer nurkowy dla początkującego?
- **Odpowiedź:** Dla nurka początkującego (do 30 metrów, nurkowanie rekreacyjne) najlepiej sprawdzą się komputery z prostym, czytelnym wyświetlaczem i intuicyjnym menu. Kluczowe cechy to: tryb nitrox (do 40% O2), alarm głębokości i czasu dna, czytelny wyświetlacz pod wodą. Budżet 800-2000 PLN jest wystarczający na dobry komputer na lata. Nie potrzebujesz na start trybów dekompresyjnych, obsługi wielu gazów ani zaawansowanych funkcji technicznych. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-009
- **Status:** DRAFT
- **direct_answer:** false
- **category:** komputery_nurkowe
- **Pytanie:** Czym się różnią komputery nurkowe w różnych przedziałach cenowych?
- **Odpowiedź:** Przedziały cenowe komputerów nurkowych odpowiadają poziomom zaawansowania. Do 1500 PLN to komputery podstawowe z wyświetlaczem segmentowym, jednym gazem nitrox, idealne na start. 1500-3500 PLN to średnia półka z kolorowym wyświetlaczem, kompasem cyfrowym, trybem freediving, Bluetooth do aplikacji. Powyżej 3500 PLN to komputery zaawansowane i techniczne: wiele gazów, tryb CCR, wymienne baterie, integracja z nadajnikiem ciśnienia. Najważniejsze to dopasować komputer do swojego aktualnego i planowanego poziomu nurkowania. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-010
- **Status:** DRAFT
- **direct_answer:** false
- **category:** komputery_nurkowe
- **Pytanie:** Komputer zegarowy czy klasyczny?
- **Odpowiedź:** Komputery w formie zegarka są wygodne na co dzień i w podróży, ale mają mniejszy wyświetlacz. Komputery klasyczne (dedykowane, na nadgarstek) mają większy, czytelniejszy ekran pod wodą. Jeśli nurkujesz okazjonalnie i zależy Ci na urządzeniu 2w1 (zegarek + komputer), format zegarowy ma sens. Jeśli nurkujesz regularnie, lepiej sprawdzi się dedykowany komputer z dużym wyświetlaczem. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

---

## KATEGORIA: DOBÓR SPRZĘTU - AUTOMATY ODDECHOWE

### QA-011
- **Status:** DRAFT
- **direct_answer:** false
- **category:** automaty_oddechowe
- **Pytanie:** Jaki automat oddechowy dla początkującego?
- **Odpowiedź:** Na start potrzebujesz zestawu: automat I stopnia + II stopnia + oktopus (zapasowy II stopień) + manometr (lub konsola). Dla początkującego ważna jest łatwość oddychania i niezawodność, nie potrzebujesz najdroższego sprzętu. Budżet 1500-3000 PLN za kompletny zestaw to rozsądny wybór. Jeśli nurkujesz w Polsce (zimna woda poniżej 10°C), automat MUSI mieć zabezpieczenie środowiskowe (environmental seal), które chroni przed zamarzaniem I stopnia. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-012
- **Status:** DRAFT
- **direct_answer:** false
- **category:** automaty_oddechowe
- **Pytanie:** Co to jest DIN i INT i który wybrać?
- **Odpowiedź:** DIN i INT to dwa systemy podłączenia automatu do zaworu butli. INT (yoke) to zacisk nakładany na zawór, prostszy, popularny w ciepłych krajach i na wypożyczalniach. DIN to gwintowane wkręcenie automatu w zawór, bezpieczniejsze przy wyższych ciśnieniach (do 300 bar), standard w Europie i nurkowaniu technicznym. Większość nowoczesnych automatów ma adapter DIN/INT w zestawie lub do dokupienia, więc nie jest to decyzja nieodwracalna. W Polsce i Europie DIN jest standardem. Na wakacjach w Egipcie czy Tajlandii spotkasz głównie INT. [ZWERYFIKUJ: czy wszystkie automaty w ofercie mają adaptery]

### QA-013
- **Status:** DRAFT
- **direct_answer:** false
- **category:** automaty_oddechowe
- **Pytanie:** Czym się różni automat membranowy od tłokowego?
- **Odpowiedź:** Chodzi o konstrukcję I stopnia. Tłokowy (piston) jest prostszy mechanicznie, tańszy w serwisie, daje dobrą wydajność oddechową. Membranowy (diaphragm) lepiej izoluje mechanizm od wody, co jest kluczowe w zimnej, brudnej lub słonej wodzie, bo zmniejsza ryzyko zamarzania i korozji. Do nurkowania w Polsce (zimna woda) i technicznego lepszy membranowy. Do ciepłych wód tłokowy w zupełności wystarczy. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

---

## KATEGORIA: DOBÓR SPRZĘTU - PIANKI I OCHRONA TERMICZNA

### QA-014
- **Status:** DRAFT
- **direct_answer:** false
- **category:** pianki
- **Pytanie:** Jaką piankę wybrać do nurkowania?
- **Odpowiedź:** Wybór pianki zależy przede wszystkim od temperatury wody. Powyżej 28°C wystarczy lycra lub pianka 2mm. 20-28°C to pianka mokra 3-5mm (ciepłe morza, Egipt latem). 10-20°C to pianka mokra 5-7mm lub półsucha (Morze Śródziemne, Chorwacja). Poniżej 10°C pianka sucha jest jedynym sensownym wyborem (Polska, Bałtyk, jeziora). Pianka musi dobrze przylegać do ciała, nie może być ani za luźna (woda cyrkuluje i wychładzasz się), ani za ciasna (ogranicza oddychanie i krążenie). Najlepiej przymierzyć osobiście. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-015
- **Status:** DRAFT
- **direct_answer:** false
- **category:** pianki
- **Pytanie:** Czym się różni pianka mokra od półsuchej i suchej?
- **Odpowiedź:** Pianka mokra wpuszcza cienką warstwę wody między ciało a piankę, która nagrzewa się od ciała i izoluje. Pianka półsucha ma uszczelnienia (na nadgarstkach, kostkach, szyi), które ograniczają wymianę wody, ale nie eliminują jej całkowicie. Jest cieplejsza od mokrej. Pianka sucha nie wpuszcza wody wcale, pod spód zakładasz ocieplającą podpiankę. Wymaga dodatkowego szkolenia (obsługa zaworu nadmiarowego i inflacyjnego), ale jest jedynym rozwiązaniem do nurkowania w zimnej wodzie. [ZWERYFIKUJ]

### QA-016
- **Status:** DRAFT
- **direct_answer:** false
- **category:** pianki
- **Pytanie:** Jak dobrać rozmiar pianki nurkowej?
- **Odpowiedź:** Rozmiar pianki dobiera się na podstawie wzrostu, wagi, obwodu klatki piersiowej i bioder. Każdy producent ma własną tabelę rozmiarów. Pianka powinna przylegać ciasno, ale nie ograniczać ruchów i oddychania. Nowa pianka neoprenowa jest ciasna i z czasem się nieco rozciąga (o ok. 0.5-1 rozmiar). Jeśli wahasz się między dwoma rozmiarami, przy piankach mokrych wybierz mniejszy, przy suchych większy. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

---

## KATEGORIA: DOBÓR SPRZĘTU - MASKI I PŁETWY

### QA-017
- **Status:** DRAFT
- **direct_answer:** false
- **category:** maski
- **Pytanie:** Jak dobrać maskę do nurkowania?
- **Odpowiedź:** Maska musi pasować do kształtu twarzy. Przyłóż maskę do twarzy bez zakładania paska, wciągnij nosem powietrze. Jeśli maska trzyma się sama, pasuje. Jeśli wpada powietrze, nie jest dla Ciebie. Kluczowe cechy: mała objętość wewnętrzna (łatwiejsze wyrównywanie, lepsze pole widzenia), szkło hartowane (bezpieczeństwo), silikonowy fartuch (dopasowanie). Osoby z wadą wzroku mogą zamontować szkła korekcyjne w wielu modelach masek. Kolor silikonu: przezroczysty daje więcej światła, czarny lepiej sprawdza się w fotografii podwodnej. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-018
- **Status:** DRAFT
- **direct_answer:** false
- **category:** płetwy
- **Pytanie:** Jakie płetwy do nurkowania wybrać?
- **Odpowiedź:** Do nurkowania używa się płetw z otwartą piętą (z regulowanym paskiem), noszonych na butach neoprenowych. Płetwy pełne (zamknięta pięta) to sprzęt do snorkelingu, nie do nurkowania z aparatem. Twarde płetwy dają lepszy napęd ale męczą nogi, miękkie są wygodniejsze ale mniej efektywne. Na start najlepsze są płetwy o średniej twardości. Długość: dłuższe = więcej napędu ale cięższe, krótsze = zwrotność. Materiał: technopolimer (tańsze, wytrzymałe), guma (droższe, lepsze właściwości). [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

---

## KATEGORIA: DOBÓR SPRZĘTU - OGÓLNE

### QA-019
- **Status:** DRAFT
- **direct_answer:** false
- **category:** ogólne_doradztwo
- **Pytanie:** Jaki sprzęt potrzebuję na początek przygody z nurkowaniem?
- **Odpowiedź:** Na sam początek (kurs OWD) najważniejsze to mieć własną maskę, fajkę i buty neoprenowe, reszta jest zazwyczaj wypożyczana na kursie. Po kursie, stopniowo kompletuj: 1) maska + fajka + buty, 2) komputer nurkowy (bezpieczeństwo), 3) automat oddechowy z oktopusem i manometrem, 4) jacket BCD, 5) pianka odpowiednia do wód w których nurkujesz, 6) płetwy. Nie kupuj wszystkiego na raz, lepiej inwestować w dobry sprzęt stopniowo niż kupić tani komplet. [ZWERYFIKUJ: kolejność priorytetów zakupowych]

### QA-020
- **Status:** DRAFT
- **direct_answer:** false
- **category:** ogólne_doradztwo
- **Pytanie:** Jaki komplet sprzętu do nurkowania w Egipcie?
- **Odpowiedź:** Do nurkowania w Egipcie (ciepła woda 22-28°C) potrzebujesz: pianka mokra 3mm lub 5mm (zależnie od pory roku i wrażliwości na zimno), automat oddechowy (nie musi być zimowodny, INT jest powszechnie dostępny), komputer nurkowy, maska, płetwy z butami, jacket BCD. Pianka 5mm jest bezpieczniejszym wyborem, bo na głębokości woda jest chłodniejsza. Rękawice 2mm opcjonalnie do ochrony przed otarciami. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-021
- **Status:** DRAFT
- **direct_answer:** false
- **category:** ogólne_doradztwo
- **Pytanie:** Jaki sprzęt do nurkowania w Polsce?
- **Odpowiedź:** Nurkowanie w Polsce to zimna woda (4-18°C zależnie od sezonu i głębokości). Minimum to: pianka półsucha 7mm lub pianka sucha (poniżej 10°C sucha jest zdecydowanie lepsza), automat zimowodny z membraną środowiskową, komputer nurkowy, rękawice 5mm+, kaptur 5-7mm, buty neoprenowe 5mm+. Automat MUSI być przystosowany do zimnej wody. Jest to bezwzględny wymóg bezpieczeństwa, nie oszczędzaj na tym. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

---

## KATEGORIA: SERWIS I KONSERWACJA

### QA-022
- **Status:** DRAFT
- **direct_answer:** true
- **category:** serwis
- **Pytanie:** Czy oferujecie serwis sprzętu nurkowego?
- **Odpowiedź:** [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-023
- **Status:** DRAFT
- **direct_answer:** false
- **category:** serwis
- **Pytanie:** Jak często serwisować automat oddechowy?
- **Odpowiedź:** Automat oddechowy powinien przechodzić przegląd serwisowy co 12 miesięcy lub co 100-200 nurkowań (zależnie od producenta). Obejmuje to wymianę uszczelek, membrany, smarowanie, test wydajności. Między przeglądami: po każdym nurkowaniu płucz automat słodką wodą, przechowuj w suchym, zacienionym miejscu, nie zginaj węży. Koszt rocznego przeglądu to zazwyczaj 200-500 PLN zależnie od marki i zakresu. [ZWERYFIKUJ: cennik serwisu jeśli prowadzicie]

---

## KATEGORIA: PORÓWNANIA I SPECJALISTYCZNE

### QA-024
- **Status:** DRAFT
- **direct_answer:** false
- **category:** porównania
- **Pytanie:** Który producent automatów jest najlepszy?
- **Odpowiedź:** Nie ma jednego najlepszego producenta automatów oddechowych. Kluczowe jest dopasowanie do warunków nurkowania: do zimnej wody potrzebujesz automatu membranowego z zabezpieczeniem środowiskowym, do ciepłych wód sprawdzi się prostszy automat tłokowy. Ważniejsze od marki jest: regularny serwis (co 12 miesięcy), dopasowanie do typu nurkowania (rekreacyjne/techniczne) i komfort oddychania. Mogę pomóc dobrać konkretny model jeśli powiesz mi w jakich warunkach nurkujesz i jaki masz budżet. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-025
- **Status:** DRAFT
- **direct_answer:** false
- **category:** porównania
- **Pytanie:** Jaki komputer do nurkowania technicznego?
- **Odpowiedź:** Komputer do nurkowania technicznego (dekompresyjnego, multi-gas, trimix) musi obsługiwać: algorytm Bühlmann ZHL-16C z gradient factors, minimum 3-5 gazów, tryb CCR (jeśli planujesz rebreather), duży czytelny wyświetlacz (najlepiej kolorowy). Kluczowe różnice między komputerami rekreacyjnymi a technicznymi: rekreacyjne mają prostsze menu i 1-2 gazy, techniczne oferują pełną konfigurację parametrów dekompresyjnych, backup plan i możliwość przełączania gazów pod wodą. Komputer techniczny to inwestycja na lata, więc warto wybrać model z aktualizowanym firmware i aktywną społecznością użytkowników. Powiedz mi jaki poziom nurkowania technicznego planujesz, a pomogę dobrać odpowiedni model z naszej oferty. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-026
- **Status:** DRAFT
- **direct_answer:** false
- **category:** foto_wideo
- **Pytanie:** Jaki sprzęt do fotografii podwodnej na początek?
- **Odpowiedź:** Na start najlepszym wyborem jest obudowa podwodna na smartfon lub kompaktowy aparat z dedykowaną obudową. Obudowy na smartfony (np. Divevolk, SeaLife) kosztują 500-2000 PLN i dają dobre wyniki do 40m. Jeśli masz aparat kompaktowy, sprawdź czy producent oferuje do niego obudowę podwodną. Do dobrych zdjęć pod wodą kluczowe jest światło, dlatego nawet prosta lampa wideo znacząco poprawi jakość. Zaawansowane zestawy (lustrzanka/bezlusterkowiec + obudowa aluminiowa + lampy) to budżet od 15000 PLN w górę. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

---

## KATEGORIA: BEZPIECZEŃSTWO

### QA-027
- **Status:** DRAFT
- **direct_answer:** false
- **category:** bezpieczeństwo
- **Pytanie:** Jakie akcesoria bezpieczeństwa powinienem mieć?
- **Odpowiedź:** Minimum to: komputer nurkowy (lub tabele dekompresyjne + zegarek + głębokościomierz, ale komputer jest bezpieczniejszy), nóż lub nożyczki (splątanie w sieci/lince), boja sygnalizacyjna SMB z kołowrotkiem (obowiązkowa przy nurkowaniach z łodzi i drifcie), latarka (nawet w dzień, do zaglądania w szczeliny i sygnalizacji). Opcjonalnie: gwizdek, lusterko sygnalizacyjne, marker dye. Sygnalizacja powierzchniowa (SMB) to sprzęt, na którym nie wolno oszczędzać. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

### QA-028
- **Status:** DRAFT
- **direct_answer:** false
- **category:** bezpieczeństwo
- **Pytanie:** Czym się różni jacket BCD od skrzydła (wing)?
- **Odpowiedź:** Jacket BCD to zintegrowany system z wypornością wokół tułowia (przód, boki, tył). Łatwy w obsłudze, wygodny na powierzchni, idealny dla początkujących i nurków rekreacyjnych. Skrzydło (wing) ma pęcherz wyporności tylko z tyłu, montowane na płycie stalowej lub aluminiowej. Daje lepszą pozycję trymową (horyzontalną) pod wodą, standard w nurkowaniu technicznym. Wielu nurków rekreacyjnych przechodzi na skrzydło po kilkudziesięciu nurkach, gdy zaczyna im zależeć na lepszym trymie. [PRODUKTY: dobierane dynamicznie przez AI na podstawie zapytania klienta]

---

## KATEGORIA: SPECYFICZNE PYTANIA PRODUKTOWE

### QA-029
- **Status:** DRAFT
- **direct_answer:** false
- **category:** produkty_specyficzne
- **Pytanie:** Czy ten produkt jest kompatybilny z moim sprzętem?
- **Odpowiedź:** [AUTOMATYCZNA - to pytanie wymaga kontekstu produktowego. AI powinno dopytać: jaki produkt klient rozważa i jaki sprzęt już posiada, a następnie sprawdzić kompatybilność na podstawie cech technicznych w bazie. Typowe pytania o kompatybilność: automat DIN/INT + zawór butli, obudowa foto + model aparatu, mocowanie lampy + ramię, komputer + nadajnik ciśnienia bezprzewodowy]

### QA-030
- **Status:** DRAFT
- **direct_answer:** false
- **category:** produkty_specyficzne
- **Pytanie:** Czy macie zamiennik do produktu X innej marki?
- **Odpowiedź:** [AUTOMATYCZNA - AI wyszukuje semantycznie produkty o podobnych cechach technicznych i zastosowaniu. Wymaga wiedzy o tym jakie produkty są wzajemnymi konkurentami. System prompt powinien zawierać kluczowe pary konkurencyjne, reszta powinna wynikać z wyszukiwania wektorowego po cechach.]

### QA-031
- **Status:** DRAFT
- **direct_answer:** true
- **category:** zdrowie_nurkowanie
- **Pytanie:** Czy mogę nurkować z astmą?
- **Odpowiedź:** Nurkowanie z astmą jest możliwe, ale wymaga indywidualnej oceny lekarza medycyny nurkowej. Kluczowe czynniki to: typ astmy (alergiczna vs wysiłkowa), częstotliwość ataków, stosowane leki i wyniki spirometrii. Astma dobrze kontrolowana (brak ataków od min. 48h, brak stosowania bronchodylatorów ratunkowych) zwykle pozwala na nurkowanie rekreacyjne. Bezwzględne przeciwwskazania to: astma wysiłkowa, ciężka astma wymagająca stałej steroidoterapii, historia hospitalizacji z powodu astmy w ostatnim roku. Zawsze skonsultuj się z lekarzem medycyny nurkowej (nie zwykłym lekarzem). W Polsce listę takich lekarzy znajdziesz na stronie Polskiego Towarzystwa Medycyny Hiperbarycznej.

### QA-032
- **Status:** DRAFT
- **direct_answer:** true
- **category:** zdrowie_nurkowanie
- **Pytanie:** Jakie są przeciwwskazania zdrowotne do nurkowania?
- **Odpowiedź:** Bezwzględne przeciwwskazania to: odma opłucnowa w wywiadzie, ciężkie choroby serca (wady zastawkowe, niewydolność), padaczka, insulinozależna cukrzyca (choć ostatnio pojawiają się protokoły pozwalające), ciąża. Względne przeciwwskazania wymagające konsultacji z lekarzem medycyny nurkowej: astma, cukrzyca, nadciśnienie, choroby uszu i zatok, zaburzenia lękowe, operacje klatki piersiowej. Przed kursem nurkowym wymagane jest wypełnienie kwestionariusza medycznego (RSTC Medical Form). Jeśli zaznaczysz "tak" przy którymkolwiek pytaniu, potrzebujesz zaświadczenia od lekarza. Badania nurkowe obejmują: spirometrię, audiometrię, EKG, RTG klatki piersiowej.

### QA-033
- **Status:** DRAFT
- **direct_answer:** true
- **category:** gazy_nurkowe
- **Pytanie:** Jaka jest różnica między nitroksem a zwykłym powietrzem?
- **Odpowiedź:** Powietrze to mieszanka 21% tlenu i 79% azotu. Nitrox (Enriched Air Nitrox, EANx) to mieszanka z podwyższoną zawartością tlenu, najczęściej 32% (EAN32) lub 36% (EAN36). Korzyści nitroxu: dłuższe czasy bezdekompresyjne (ok. 30-40% dłuższe dla EAN32 vs powietrze na 18-30m), mniejsze zmęczenie po nurkowaniu, krótsze przerwy powierzchniowe. Ograniczenia: mniejsza maksymalna głębokość operacyjna (MOD) ze względu na toksyczność tlenu. Dla EAN32 MOD to ok. 33m (ppO2=1.4), dla EAN36 ok. 28m. Wymaga kursu (PADI Enriched Air Diver lub odpowiednik) i analizatora tlenu. Komputer nurkowy musi obsługiwać nitrox (większość współczesnych obsługuje). Nitrox nie wymaga specjalnej butli, ale butla musi być oznakowana żółto-zieloną naklejką i czyszczona do pracy z tlenem przy mieszankach powyżej 40%.

### QA-034
- **Status:** DRAFT
- **direct_answer:** true
- **category:** gazy_nurkowe
- **Pytanie:** Kiedy warto nurkować na nitroksie zamiast na powietrzu?
- **Odpowiedź:** Nitrox (EAN32/36) szczególnie się opłaca gdy: nurkujesz wielokrotnie w ciągu dnia (np. na safari nurkowym, 3-4 nurkowania dziennie), nurkujesz w zakresie 15-30m (tu różnica w czasach NDL jest największa), chcesz mieć większy margines bezpieczeństwa dekompresyjnego, odczuwasz zmęczenie po nurkowaniach na powietrzu. Nitrox jest mniej przydatny przy: nurkowaniach płytkich do 12m (czasy NDL i tak bardzo długie), nurkowaniach głębszych niż 30m (MOD ogranicza), jednorazowych nurkowaniach z dużymi przerwami. W wielu bazach nurkowych (szczególnie Egipt, Malediwy) nitrox jest dostępny za dopłatą 5-10 EUR/butla. Własna karta nitrox (po kursie) pozwala zamawiać mieszankę wszędzie na świecie.

### QA-035
- **Status:** DRAFT
- **direct_answer:** true
- **category:** pielegnacja_sprzetu
- **Pytanie:** Jak przechowywać piankę neoprenową żeby dłużej wytrzymała?
- **Odpowiedź:** Po każdym nurkowaniu: opłucz piankę słodką wodą (najlepiej ciepłą, nie gorącą). Co kilka nurkowań: użyj specjalnego płynu do pianek (np. McNett Sink The Stink, Gear Aid Revivex) który usuwa bakterie, sól i nieprzyjemny zapach. Suszenie: zawsze w cieniu, nigdy na słońcu (UV degraduje neopren). Wieszaj na szerokim wieszaku za ramiona lub w pasie, nie na cienkim wieszaku (deformuje materiał). Najlepiej rozłożoną na płasko. Przechowywanie długoterminowe: czysta, sucha, złożona luźno lub na szerokim wieszaku, w chłodnym ciemnym miejscu. Nie zginaj mocno (trwałe zagięcia). Zamki błyskawiczne: smaruj parafiną lub dedykowanym smarem do zamków (np. Gear Aid Zipper Lubricant). Unikaj: suszarki, bezpośredniego słońca, bagażnika samochodu latem, kontaktu z olejami i rozpuszczalnikami. Dobrze utrzymana pianka wytrzymuje 4-7 lat intensywnego użytkowania.

### QA-036
- **Status:** DRAFT
- **direct_answer:** true
- **category:** pielegnacja_sprzetu
- **Pytanie:** Jak dbać o automat oddechowy między nurkowaniami?
- **Odpowiedź:** Po każdym nurkowaniu: zamknij osłonkę pyłową (dust cap) na pierwszym stopniu ZANIM odkręcisz automat od butli. To zapobiega dostaniu się wody do wnętrza. Opłucz cały automat słodką wodą, najlepiej zanurz na 15-30 minut. Nie naciskaj przycisku purge podczas płukania drugiego stopnia (wciąga wodę do wnętrza). Suszenie: w cieniu, z lekko poluzowaną regulacją (zmniejsza naprężenie sprężyn). Przechowywanie: w suchym, chłodnym miejscu, bez ciężkich przedmiotów na wężach. Serwis: obowiązkowy co 12 miesięcy lub 100-200 nurkowań (zależnie od producenta). Serwis obejmuje wymianę membran, uszczelek, sprężyn i smarowanie. Koszt serwisu: 200-500 zł w zależności od modelu. Między sesjami nurkowania na wyjazdach: płukanie po każdym dniu, pełny serwis po sezonie.

### QA-037
- **Status:** DRAFT
- **direct_answer:** true
- **category:** pielegnacja_sprzetu
- **Pytanie:** Jak konserwować komputer nurkowy?
- **Odpowiedź:** Po każdym nurkowaniu: opłucz słodką wodą, ze szczególną uwagą na czujnik ciśnienia (jeśli ma) i przyciski. Zanurz na kilka minut, naciskając przyciski pod wodą (usuwa sól z mechanizmów). Suszenie: w cieniu, nie na słońcu. Przechowywanie: z naładowaną baterią (baterie litowe degradują się szybciej gdy są rozładowane). Wymiana baterii: w zależności od modelu co 1-3 lata. W modelach z baterią wymienną przez użytkownika (np. Suunto) wymień samodzielnie i sprawdź o-ring. W modelach z baterią serwisową (np. Shearwater) oddaj do autoryzowanego serwisu. Sprawdź instrukcję swojego modelu. Po wymianie baterii zawsze sprawdź szczelność (test w wodzie lub komora ciśnieniowa w serwisie). Aktualizacja firmware: sprawdzaj na stronie producenta, nowe wersje mogą poprawić algorytmy i dodać funkcje. Pasek/bransoleta: metalowe elementy smaruj silikonem, gumowe sprawdzaj pod kątem pęknięć.

---

## NOTATKI DO ROZBUDOWY

Tematy do dodania w kolejnych iteracjach (na podstawie realnych rozmów z klientami):
- [ ] Dobór latarki nurkowej (główna, backup, foto/wideo)
- [ ] Torby i walizki nurkowe (podróżne, na kółkach, carry-on)
- [ ] Nurkowanie z dziećmi (minimalny wiek, sprzęt junior)
- [ ] Różnice między certyfikacjami (PADI, SSI, CMAS)
- [ ] Ubezpieczenie nurkowe (DAN, DiveAssure)
- [ ] Sprzęt do freedivingu vs sprzęt do nurkowania aparatowego
- [x] Przeciwwskazania zdrowotne (QA-031, QA-032)
- [x] Nitrox / gazy nurkowe (QA-033, QA-034)
- [x] Pielęgnacja sprzętu (QA-035, QA-036, QA-037)
