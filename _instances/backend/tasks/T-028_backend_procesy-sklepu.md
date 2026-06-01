# T-028: SystemPrompt — sekcja PROCESY SKLEPU (FAQ procesowe z pytań klientów)

Instancja: backend
Powiazane: 24 pytania klientow (pytania_ze_sklepu_*.txt), decyzja 127c (fakty procesowe -> baza wiedzy), 134a (do SystemPrompt), 135b (czas zwrotu), ADR-066 (kandydaci na szybka sciezke)
Priorytet: P1 (najczestsze realne pytania klientow, bot obecnie ich nie zna -> zmysla)
Czas: ~2h CC
Plik: standalone/src/Chat/SystemPrompt.php (v10, 667 linii)

## Kontekst
Analiza realnych pytan klientow ujawnila ~10 stalych faktow PROCESOWYCH ktore bot musi znac (zwroty, serwis, wysylka, vouchery), inaczej je zmysla. Decyzja 134a: ida do SystemPrompt (male, czeste, uniwersalne — tansze w kontekscie niz wywolanie narzedzia).

## KRYTYCZNE: NIE duplikowac istniejacych regul v10
Czesc faktow JUZ JEST w v10 — NIE dodawaj ich ponownie:
- Godziny pracy pon-pt 9-17 (linia ~40) + get_shop_schedule dla konkretnych dat — JEST.
- Zestawy serwisowe / czesci serwisowe NIE sprzedajemy (linia ~214) — JEST.
- Procedury konserwacji/prania zakazane (linia ~223) — JEST.
Te zostaw nietkniete. Dodajesz TYLKO brakujace fakty procesowe ponizej.

## KROK 0. Read
- standalone/src/Chat/SystemPrompt.php — przeczytaj sekcje DANE FIRMY (~40), SCOPE (~209), konserwacja (~223), zeby nowa sekcja PROCESY SKLEPU byla spojna i bez duplikacji.

## KROK 1. Dodaj sekcje "PROCESY SKLEPU" (9 brakujacych faktow)
Umiesc jako nowa sekcja (sugerowane: po DANE FIRMY albo przy SCOPE). Tresc faktow (sformuluj naturalnie, zwiezle):

1. ZWROTY i brak wymian (decyzja 135b):
   - NIE realizujemy wymian. Procedura: klient zwraca towar, sklada NOWE zamowienie w innym rozmiarze/kolorze.
   - Zwrot srodkow: zazwyczaj do 24h od otrzymania zwrotu, maksymalnie 2-3 dni robocze. Wymaga dolaczenia wypelnionego formularza zwrotu (szybsza lokalizacja w systemie).
   - NIGDY nie obiecuj "wymienimy na inny rozmiar" — to nieprawda, sklep tego nie robi.

2. SERWIS AUTOMATU:
   - Termin serwisu ustalany INDYWIDUALNIE z serwisantem (mail/telefon) — serwisant umawia termin tak, by automat po przyjezdzie od razu trafil do serwisu.
   - Dostawa do serwisu: automat zabezpieczony w kartonie, dowolnym kurierem na adres sklepu. W srodku DOLACZYC: dane osobowe, telefon, adres zwrotny.

3. SERWIS KOMPUTEROW (ograniczony):
   - Serwis komputerow = TYLKO wymiana baterii i uszczelek (od wybranych dystrybutorow/producentow). Nie robimy pelnego serwisu komputerow.
   - Wymiana baterii: od reki, kilka minut, z ustawieniem daty i godziny.

4. NOWY AUTOMAT — regulacja i montaz:
   - Kazdy automat trafiajacy do nas jest podlaczany pod urzadzenia kontrolne (magnehelic), sprawdzany i regulowany jesli potrzeba. Montujemy potrzebne podzespoly (weze do inflatora, do suchego, nadajniki, manometry).

5. CZESCI SPOZA OFERTY:
   - Probujemy zorganizowac podzespoly jesli osiagalne u wspolpracujacych dystrybutorow. Temat przez mail (co potrzebne + numer telefonu) do finalizacji.
   - UWAGA: to NIE dotyczy zestawow serwisowych (te zakazane, patrz istniejaca regula SCOPE) — chodzi o zwykle czesci/podzespoly handlowe.

6. VOUCHER — proces zakupu:
   - Zamowienie vouchera przez strone (kwota do koszyka) + przelew + w notatce imie i nazwisko obdarowanego.
   - Realizacja zwykle w ciagu godziny od zlozenia zamowienia, w godzinach pracy sklepu (pon-pt 9-17).
   - Wykorzystanie vouchera: zamowienie z forma platnosci przelew, numer vouchera w polu komentarz, brakujaca kwote doplacic przelewem.

7. WYSYLKA:
   - Wszystkie paczki kurierem priorytetowo. BRAK opcji ekspresu za doplata.
   - Doreczenia sobotnie: czasem realizowane (paczkomaty), ale NIE gwarantujemy — zalezy od kuriera w rejonie odbiorcy, skutecznosc sobotnia <50%.

8. ZAKUPY NA MIEJSCU / rezerwacja:
   - Wieksze zakupy lub miarowe: sugerowany kontakt telefoniczny z prosba o rezerwacje towaru, albo zamowienie przez sklep z odbiorem osobistym i platnoscia gotowka — wtedy kompletujemy i zapraszamy.
   - Nie gwarantujemy dostepnosci kazdego produktu od reki przy duzej rotacji.

9. SUCHY SKAFANDER na miejscu / dobor:
   - Nie mamy pelnej rozmiarowki na miejscu. Sugerowany kontakt (mail/telefon) z wymiarami: wzrost, klatka, biodra, pas (+ nietypowe: duzy biceps, lydki). Wtedy sciagamy najblizsze rozmiary do weryfikacji.

## KROK 2. Lint + grep
php -l. Grep: sekcja "PROCESY SKLEPU" obecna, 9 podpunktow. Sprawdz ze NIE zdublowano godzin/zestawow serwisowych/konserwacji.

## KROK 3. STOP — diff do review
Status READY FOR REVIEW. Diff sekcji. NIE deploy bez akceptacji.

## KROK 4-5. Deploy + git (po akceptacji)
scp, php -l prod (ea-php84), md5 verify.
commit: "T-028: SystemPrompt — sekcja PROCESY SKLEPU (zwroty/serwis/wysylka/vouchery z pytan klientow)"
push. Osobny commit docs: status.

## KROK 6. Raport + status
Handoff. Zaznacz: te fakty to kandydaci na deterministyczna szybka sciezke (ADR-066) gdy bedzie ruch. Update _docs/21.

## Out of scope
- Dobor produktu (pianka/komputer/maska konkretne modele) -> kuratorowane rekomendacje ADR-065, osobny task
- Edukacja domenowa (dwuszybowa vs frameless, 5mm pianka) -> osobny wsad do wiedzy, nie ten task
- Pozostale polityki v11 (maski bez rozmiarow, HALLU brak pytania, MED zaostrzone) -> v11 po re-runie
