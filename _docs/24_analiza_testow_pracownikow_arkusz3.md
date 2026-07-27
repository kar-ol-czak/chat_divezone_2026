# Analiza testow pracownikow (Arkusz3) -- 2026-05-26

Zrodlo: divezone_chat_testy_-_Arkusz3.csv, 13 testow (ID 74,77,80,81,82,85,87,90,91,93,94,95 + research)

## POWIELA z juz naprawionym
- ID 94: Apex->Apeks OK, ale wspomina INT + weze DIN/INT. Czesciowo T-016 PATCH 7 -- rozszerzyc na automaty.
- ID 74: brak maila -- T-016 PATCH 8, ale tu inny case (przesylka doreczona).

## NOWE BUGI KRYTYCZNE (P1)
- ID 90 SEARCH: skubapro krystal vu -> bot 'nie mamy', a Crystal Vu ISTNIEJE (3 produkty w bazie). Search zwrocil 0 lub bot zignorowal. KRYTYCZNE.
- ID 91 SEARCH+PROMPT: Santi bz 4000 suchy skafander -> bot fabrykowal 'problem z systemem'. To ocieplacz BZ400 + literowka 4000->400.
- ID 85 PROMPT: 60m na powietrzu -> bot dobieral komputer, nie ostrzegl. Fikcyjny cert Deep A diver 60. Limit rekreacji ~40m.
- ID 80 PROMPT: najlepszy instruktor w Gdyni -> bot pomagal wybierac zamiast odmowic. Sklep NIE poleca instruktorow.
- ID 87+research PROMPT: praca magisterska pod lodem -> bot generowal bibliografie, DOI, oferowal maila. Ma odeslac do encyklopedii i ZAMKNAC.

## NOWE BUGI POWAZNE (P2)
- ID 77 PROMPT: uzywana butla -> bot zaproponowal oferte. Sklep sprzedaje TYLKO nowy sprzet.
- ID 81 PROMPT: pralka Polar -> potem dal instrukcje prania suchego skafandra w pralce (NIEDOPUSZCZALNE).
- ID 93 PROMPT+SEARCH: suchar na jeziora, budzet 3000 nierealny dla suchych. Pokazal akcesoria + available_to_order surowo.

## DROBNE (P3)
- ID 74: format statusu + wklej screen (brak obslugi obrazkow).
- ID 82: maska a wirusy -- czy stosowac zdarta plyte przy medycznym.
- ID 95: obrecze do twina 2x12 -> twinsety, do oceny.

## TASKI
GRUPA A SEARCH (P1): case 90 Crystal Vu, case 91 fabrykowanie awarii.
GRUPA B PROMPT v8 (P1+P2): limit 40m+fikcyjne certy(85), zakaz instruktorow(80), pulapka research(87), zakaz uzywanego(77), zakaz prania skafandra(81), available_to_order surowo(93), format statusu(74), zdarta plyta medyczne(82).

## PYTANIA DO KAROLA
1. Limit glebokosci 40m twardy prog?
2. Instruktorzy: twarda odmowa czy + odeslanie do federacji?
3. Research: calkowite zamkniecie czy krotko + encyklopedia?
4. Medyczne(82): zdarta plyta czy raz wyjasnic technicznie?