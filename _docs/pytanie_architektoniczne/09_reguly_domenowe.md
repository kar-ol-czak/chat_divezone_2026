# Reguły domenowe dla grup C-M encyklopedii
# Data: 2026-02-27
# Status: ZATWIERDZONE przez Karola
# Użycie: input do promptów generacyjnych i walidacyjnych FAZY 2

## Grupa C: Pianki i ochrona termiczna

1. Pianka polsucha != pianka sucha. Klienci myla te terminy, a roznica jest fundamentalna (neopren z uszczelnieniami vs wodoszczelna skorupa). Nigdy nie sugerowac pianki polsuchej jako zamiennika suchego skafandra na zimne wody.
2. Grubosc pianki != ochrona termiczna bezposrednio (choc jest wyznacznikiem). 5mm skompresowany na 30m daje ~2mm. AI nie powinien pisac '5mm wystarczy do zimnej wody' bez kontekstu glebokosci. Przy zimnej wodzie, jesli ktos koniecznie chce pianke, warto doradzac zakup docieplacza neoprenowego zakladanego na pianke.

## Grupa D: Maski i fajki

3. 'Maska do nurkowania z tlenem' (720 vol/mies!) -- klienci tak pisza, ale to BLAD TERMINOLOGICZNY. Maska nie ma nic wspolnego z tlenem. Czat musi to delikatnie skorygowac i przekierowac na wlasciwy produkt. [WYSOKIE]
4. 'Okulary do nurkowania' (1300 vol) -- nurkowie nie uzywaja okularow, to maska. Ale klienci snorkelingowi tak mowia. Mapowac na maske, nie odrzucac.
5. Objetosc maski (niska vs wysoka) to parametr techniczny, nie 'lepsza/gorsza'.

## Grupa E: BCD / Wing / Jacket

6. Jacket != wing choc ludzie czasem mowia zamiennie. To rozne koncepcje wypornosciowe. Jacket to rekreacja i podroze, choc skrzydla w tej chwili wypieraja jackety. W Polsce nurkowie techniczni i rekreacyjni nurkuja glownie na skrzydlach.
7. Sila wypornosci (lift) musi byc dobrana do konfiguracji. Nie polecac winga 16kg do twinsetu 2x12L ze skafandrem suchym.

## Grupa F: Butle i zawory

8. KRYTYCZNE BEZPIECZENSTWO: 'Butla z tlenem' -- klienci tak mowia o butli ze sprezonym powietrzem. Czat MUSI korygowac, bo czystosc tlenowa to kwestia bezpieczenstwa (ryzyko wybuchu przy mieszankach >40% O2 w brudnej butli). [KRYTYCZNE]
9. Zawor DIN 200 bar vs 300 bar -- to NIE to samo. 300 bar do techniki i nitroxu, 200 bar do rekreacji.
10. Butla aluminiowa vs stalowa -- to nie tylko preferencja, to parametr wplywajacy na trym i wypornosc. Aluminiowa jest pozytywnie wyporna pusta. W Polsce jako butle glowne na plecy uzywa sie stalowych, jako butli bocznych stage i sidemount raczej aluminiowych.

## Grupa H: Pletwy

11. Pletwy kaloszowe != paskowe. Paskowe wymagaja butow neoprenowych, kaloszowe moga isc na bosa stope. Kaloszowe praktycznie tylko do snorkelingu, ewentualnie nurkowanie z lodzi w cieplych wodach. Sa bardziej miekkie i daja gorszy ciag nurkowi z ciezkim sprzetem. AI nie moze polecac paskowych, jesli ktos nie chce butow.
12. 'Sprezyny do pletw' to zamiennik paska o wiekszej wytrzymalosci i wygodzie, nie osobna kategoria sprzetu.

## Grupa I: Instrumenty / Komputery

13. KRYTYCZNE: Komputer nurkowy != zegarek sportowy z funkcja nurkowania, ale czesto klienci pytaja o 'zegarek do nurkowania' majac na mysli komputer nurkowy w formie zegarka. Garmin Descent czy Apple Watch Ultra moga sluzyc do nurkowania. AI musi rozrozniac typy obudowy i rodzaj wyswietlacza. [KRYTYCZNE]
14. Algorytm dekompresyjny (Buhlmann, VPM, RGBM) to nie 'lepszy/gorszy', tylko inny model konserwatywnosci. AI nie powinien rekomendowac algorytmu.
15. Transmiter cisnienia != manometr. Transmiter jest bezprzewodowy i wymaga kompatybilnego komputera tej samej marki (wyjatek: Shearwater + nadajnik Perdix/Teric).

## Grupa J: Latarki

16. Lumeny != jakosc oswietlenia pod woda. Kat, CRI, czas pracy tak samo wazne jak surowe lumeny, choc wiele osob wlasnie na lumenach sie skupia. AI nie powinien sortowac 'najlepsza latarka = najwiecej lm'.
17. Latarka glowna vs backup -- rozne wymagania, rozne mocowania (Goodman handle vs klips).

## Grupa K: Akcesoria techniczne

18. KRYTYCZNE: Szpulka (spool/finger spool) != kolowrotek (reel). To byl znany blad v1. Szpulka nie ma korby, kolowrotek ma. Rozne zastosowania, rozne ceny. [KRYTYCZNE]
19. Boja SMB, DSMB -- SMB to boja powierzchniowa, DSMB to dekompresyjna (wysylana z glebokosci), ale w praktyce w tej chwili jest to ta sama konstrukcja, nie ma osobnych modeli.
20. Lift bag != boja. Lift bag to worek do podnoszenia przedmiotow, nie sygnalizacyjny. Bardzo rzadko sprzedawany.

## Grupa L: Fotografia podwodna

21. Obudowa podwodna jest MODELOWA (do konkretnego aparatu/kamery). Nie ma uniwersalnych obudow. To jak zestawy serwisowe -- wymaga precyzyjnego dopasowania. Wyjatkiem sa obudowy do telefonow, ktore za pomoca odpowiednich ramek mozna dopasowac do kilku modeli.

---

## Podsumowanie regul KRYTYCZNYCH (bezpieczenstwo)

| # | Regula | Grupa | Poziom |
|---|--------|-------|--------|
| ADR-036 | DIN jedyny standard, INT archaiczny | A,B | KRYTYCZNE |
| 8 | 'Butla z tlenem' = blad, korygowac | F | KRYTYCZNE |
| 18 | Szpulka != kolowrotek | K | KRYTYCZNE |
| 13 | Komputer nurkowy != zegarek sportowy | I | KRYTYCZNE |
| 1 | Pianka polsucha != suchy skafander | C | WYSOKIE |
| 3 | 'Maska z tlenem' = blad terminologiczny | D | WYSOKIE |
