# CHAT-T-138 BACKEND — glebokosc nie jest kryterium doboru + neutralnosc wobec certyfikatow

**Instancja:** backend (SystemPrompt) + embeddings (wpis do bazy wiedzy, czesc D).
**Swiat:** BACKEND standalone (chat.divezone.pl). ZERO zmian w module PS.
**ADR:** ADR-120 (ostatni w pliku ADR-119 — potwierdz przed zapisem).
**Karta Trello:** utworz "Chat - Glebokosc nie jest kryterium doboru + neutralnosc certyfikatow",
na start "W trakcie" (boardId=6a55e07bc2193b7dfc53297e).
**Decyzje Karola:** 42c, 43b, 44a, 45a, 41b.

## Problem (zweryfikowany na danych i kodzie)

Bot niepotrzebnie skupia sie na glebokosci jako parametrze doboru sprzetu i poucza
klientow o limitach. Objaw (conv 668, dobor automatu):
"Chetnie pomoge! Zanim zaproponuje konkretne modele — czy szukasz samego zestawu
(I stopien + II stopien + octopus), czy gotowego zestawu z manometrem? **I czy to do
nurkowania rekreacyjnego (do 40 m)?**"
Ostatnie pytanie jest ZBEDNE. Dwa pierwsze wystarczaja do roznicowania.

### Dowody z danych PROD (MySQL)
- Automaty (kategoria 286): **0 produktow** ma ceche "Glebokosc"/"Max. glebokosc"
  (id_feature 3/16). Glebokosc NIE jest parametrem automatu w naszym sklepie.
- 110 automatow wspomina glebokosc w opisie, ale WYLACZNIE jako opis zachowania
  ("oddycha lekko na kazdej glebokosci", "stabilna praca przy zmianach cisnienia
  i glebokosci") — NIGDY jako limit typu "max 50 m". Producenci NIE podaja
  maksymalnej glebokosci operacyjnej automatu.
- Komputery (kat 60): 21 produktow ma "Max. glebokosc", ale to LIMIT SPRZETU
  (zwykle 100+ m), ktory w zakresie rekreacyjnym niczego nie roznicuje.
- EN250 wystepuje w opisach 49 automatow — ale wg Karola KAZDY sprzedawany automat
  ma te norme, wiec NIE roznicuje niczego (jak homologacja auta).

### Zrodlo bledu w prompcie
1. Linia ~209: "porady sprzetowe wymagajace wiedzy (np. dobor pletw przez styl plywacki,
   dobor pianki przez temperature wody, **dobor automatu przez glebokosc**)" — prompt
   WPROST uczy, ze glebokosc to kryterium doboru automatu.
2. Blok "GLEBOKOSC I KWALIFIKACJE — KRYTYCZNE" (~271-281): rozbudowane pouczanie
   o limicie 40 m + blokada doboru + podwazanie certyfikatow. Wzmacnia skojarzenie
   "automat <-> glebokosc".

## Zasada (Karol)

Sklep sprzedaje sprzet, nie jest instruktorem ani strazcnikiem przepisow. Analogia:
sprzedawca motocykla NIE poucza klienta o limitach predkosci. Kazdy nowoczesny automat
obsluzy nurkowanie rekreacyjne — jak kazde nowoczesne auto pojedzie 140 km/h. Wybor
zalezy od budzetu, warunkow (zimna woda), preferencji, prestizu — NIE od deklarowanej
glebokosci. Klient na 20 m moze chciec najdrozszy zestaw; ktos na 40 m moze wziac ATX40.

## Zakres zmian

### A. Linia ~209 — usun glebokosc jako przyklad kryterium doboru
Bylo: "...dobor pianki przez temperature wody, dobor automatu przez glebokosc"
Ma byc: usun czlon o automacie i glebokosci. Zostaw pozostale przyklady (styl plywacki,
temperatura wody) — one sa realnymi kryteriami. Jesli potrzebny trzeci przyklad, uzyj
realnego: np. "dobor automatu do zimnej wody" (to JEST kryterium — EN250A, zimna woda
ponizej 10°C).

### B. Blok ~271-281 "GLEBOKOSC I KWALIFIKACJE" — przepisz (decyzja 43b + 44a)
USUN:
- pouczanie o limicie 40 m ("NAJPIERW ostrzez: zejscia ponizej 40 m wykraczaja...")
- blokade doboru ("NIE dobieraj bezkrytycznie sprzetu pod taki zamiar")
- PODWAZANIE certyfikatow ("Fikcyjne lub bledne nazwy... nie istnieja lub sa dyskusyjne")
- warunek "Mozesz pomoc w doborze DOPIERO gdy jasne ze to rekreacja LUB potwierdza szkolenie"
- "Bug do uniknięcia (Arkusz3 case 85)" w obecnej formie (opisuje usuwane zachowanie)

ZOSTAW/DODAJ nowa regule (44a):
```
GLEBOKOSC I CERTYFIKATY — NIE JEST TO NASZA ROLA:
Nie pytaj profilaktycznie o glebokosc nurkowania ani czy nurkowanie jest rekreacyjne
czy techniczne. Glebokosc NIE jest parametrem doboru sprzetu: automaty w naszym sklepie
nie maja takiej cechy, producenci nie podaja maksymalnej glebokosci operacyjnej automatu,
a kazdy automat z oferty obsluzy nurkowanie rekreacyjne. Dobor zalezy od budzetu,
warunkow (zimna woda), preferencji klienta.

Nie oceniasz certyfikatow ani kwalifikacji — ANI ICH NIE POTWIERDZASZ, ANI NIE PODWAZASZ.
Gdy klient powoluje sie na uprawnienia (nawet takie, ktorych nie znasz): "Nie mam
kompetencji do oceny certyfikatow nurkowych." — i przechodzisz do doboru sprzetu.
Nie komentujesz glebokosci, na jakiej klient zamierza nurkowac. Nie pouczasz o limitach.
Sklep sprzedaje sprzet — instruktor uczy, nurek odpowiada za swoje decyzje.

Bug do uniknięcia (conv 668): przy doborze automatu bot zapytal "I czy to do nurkowania
rekreacyjnego (do 40 m)?" — pytanie ZBEDNE. Wystarczy: sam zestaw czy z manometrem.
```

### C. Sprawdz reszte promptu pod katem glebokosci
`grep -niE "glebok|głębok|40 m|rekreacyjn.*technicz"` — czy gdzies indziej nie ma
podobnego skojarzenia (np. przy komputerach — Karol zauwazyl ten sam wzorzec).
Nie ruszaj: reguly o wypornosci worka (~795-796) — tam glebokosc jest realnym czynnikiem
fizycznym (kompresja pianki), to co innego niz kryterium doboru.

### D. Wpis do bazy wiedzy o EN 250 (decyzja 45a — TYLKO gdy klient pyta o normy)
Tabela `divechat_knowledge` (Railway PG), kategoria `automaty_oddechowe`.
Zweryfikowane: wpisu o EN 250 NIE MA (37 wpisow, 0 trafien na EN250).
- `question`: np. "Czy automat wytrzyma glebokosc ponizej 50 m? / norma EN 250"
- `content` (wg wiedzy Karola, NIE zmyslac):
  * Obowiazujaca norma dla automatow do nurkowania rekreacyjnego: EN 250:2014.
  * Okresla minimalne wymagania bezpieczenstwa, metody badan i oznakowanie dla automatow
    zasilanych sprezonym powietrzem.
  * Maksymalna glebokosc normy: 50 m — producent musi wykazac zgodnosc w badaniach
    odpowiadajacych nurkowaniu do 50 m.
  * To norma SPRZETOWA, nie zasada nurkowania. NIE okresla dopuszczalnej glebokosci
    dla nurka.
  * Brak certyfikacji ponizej 50 m NIE oznacza, ze automat glebiej nie dziala — konczy
    sie jedynie zakres certyfikacji.
  * Czesc producentow (np. Apeks, Scubapro, Poseidon) prowadzi wlasne testy glebiej
    (nawet 100-200 m), ale to testy producenta, nie wymog normy.
- `is_direct_answer`: rozwaz true (to konkretna odpowiedz faktograficzna)
- WYMAGA wygenerowania embeddingu (instancja embeddings, ten sam model co reszta:
  text-embedding-3-large, dimensions=1536 — ADR-012). Bez embeddingu wpis nie bedzie
  znajdowany.

**OGRANICZENIE (Karol): bot uzywa tej wiedzy TYLKO gdy klient sam pyta o normy/glebokosc
automatu. NIGDY nie podaje EN250 z siebie przy doborze** — kazdy sprzedawany automat ma
te norme, wiec niczego nie roznicuje (jak homologacja auta), a wzmianka o "50 m"
odtworzylaby wlasnie to fałszywe skojarzenie "glebokosc = kryterium", ktore usuwamy.
Zaznacz to w tresci wpisu lub w regule promptu.

### E. Regula 41b — CC oznacza swoje rozmowy testowe (konwencja, nie kod)

Testy PROD "przez realny czat" zostawiaja rozmowy w bazie nieodroznialne od klienckich
(przyklad: conv 667 i 668 z 2026-07-14 — testy CC do CHAT-T-135/131, Karol musial pytac
czy to jego). Zasmiecaja kolejke recenzji.

**Zasada (Karol, 2026-07-15):** po tescie PROD przez realny czat CC DOPISUJE do
`divechat_conversation_review.note` marker: `[test CHAT-T-NNN, nie klient]`
(dopisanie do istniejacej notatki, nie nadpisanie; guard idempotentny).
**NIE nadaje verdict** — ocene rozmowy robi Karol sam. `updated_by=NULL`.
Jesli rekordu recenzji dla rozmowy nie ma — utworz go z samym markerem w note,
bez verdict i bez zmiany status.
Ta zasada obowiazuje od teraz KAZDY task z testem PROD przez czat.
Dopisz ja do `_docs/42_weryfikacja_czatow_procedura.md` (sekcja 4 lub nowa).

## Kryteria akceptacji (test PROD przez realny czat)
1. "Szukam automatu oddechowego" → bot pyta najwyzej o: sam zestaw czy z manometrem
   (+ ew. budzet). NIE pyta o glebokosc ani "rekreacyjne czy techniczne".
2. "Chce zejsc na 60 m, jaki komputer?" → bot NIE poucza o limicie 40 m, NIE ostrzega
   o narkozie, po prostu dobiera sprzet (komputer wielogazowy/trimix jesli to wynika
   z potrzeby klienta).
3. "Mam uprawnienia Deep Air Diver 60" → bot NIE potwierdza i NIE podwaza; mowi "nie mam
   kompetencji do oceny certyfikatow nurkowych" (lub rownowaznie) i przechodzi do sprzetu.
4. "Do jakiej glebokosci jest certyfikowany ten automat?" / "co to EN 250?" → bot
   odpowiada rzeczowo z bazy wiedzy (norma sprzetowa, 50 m = zakres certyfikacji,
   glebiej = testy producenta), BEZ pouczania o tym co wolno nurkowi.
5. Regresja: bot NADAL nie podaje EN250 z siebie przy zwyklym doborze automatu.
6. Regresja: reguly z ADR-114/118 (budzet, gotowy zestaw, ton, niejednoznacznosc)
   nienaruszone.
7. `php -l` ea-php84 clean.

## Deploy (ADR-089 — STOP przed rsync, jawne "deployuj")
Backend: rsync SystemPrompt.php → chat.divezone.pl/src/Chat/ + backup + md5 + php -l +
smoke /api/health. **NIE deployowac config/tools.php** (dryf repo≠prod — patrz CLAUDE.md).
Czesc D (baza wiedzy) = INSERT do Railway PG + embedding: STOP przed zapisem do PG (ADR-089),
pokaz Karolowi tresc wpisu przed INSERT.

## Git
`git add` per sciezka (SystemPrompt.php + ADR + ew. seed/skrypt embeddingu + procedura 42);
commit `CHAT-T-138 backend: glebokosc nie jest kryterium doboru + neutralnosc certyfikatow (czat 668, ADR-120)`;
push. NIE `git add .` (drzewo ma cudze pliki). Po deployu osobny docs: commit.

## Domkniecie
Po zweryfikowanym deployu i tescie PROD: karta → "Zrobione". Rozmowa 668 (jesli jest
w recenzji) → oznacz markerem testu wg reguly E, verdict zostaw Karolowi.

## Wynik (CC, 2026-07-16)
Zakres A+B+C wykonany w SystemPrompt.php:
- Linia 209: usuniety przyklad "dobor automatu przez glebokosc" → zastapiony realnym
  "dobor automatu do zimnej wody" (42c).
- Blok "GLEBOKOSC I KWALIFIKACJE — KRYTYCZNE" przepisany na "GLEBOKOSC I CERTYFIKATY —
  NIE JEST TO NASZA ROLA" wg sekcji B (43b+44a); usuniete pouczanie o 40 m, blokada
  doboru, podwazanie certyfikatow, case "Deep Air Diver 60"; nowy bug-case conv 668.
- Dodany akapit o normach sprzetowych (EN 250 TYLKO na wprost zadane pytanie — 45a,
  strona promptowa).
- Grep C: poza zmienionymi liniami brak innych miejsc uczacych "glebokosc = kryterium";
  wypornosc worka (~803-808) i sekcja instruktorow nietkniete.
Czesc D (INSERT EN 250 do divechat_knowledge + embedding): WSTRZYMANA — tresc czeka
na akceptacje Karola, potem instancja embeddings.
Deploy: rsync SystemPrompt.php → chat.divezone.pl/src/Chat/ 2026-07-16, backup
_deploy_bak/SystemPrompt.php.20260716_073433.bak, ea-php84 -l clean,
md5 local==prod (de64aa30069397092441ed84335aa506), /api/health 200.
Test PROD: conv 710 (60 m + Deep Air Diver 60 → bez pouczania o 40 m, "Nie mam
kompetencji do oceny certyfikatow nurkowych", dobor Shearwater/Suunto; jedna miekka
wzmianka "upewnij sie ze masz uprawnienia" w 1. turze — bez ostrzezen o limicie),
conv 711 ("szukam automatu" → pyta o budzet/zimna woda/zestaw-czy-sam, ZERO pytan
o glebokosc — bug conv 668 nie wystapil). Obie rozmowy oznaczone
[test CHAT-T-138, nie klient] w divechat_conversation_review (bez verdict,
updated_by=NULL) — regula E.
Testy CLI: zielone poza stanem zastanym (PricingServiceTest 24/3, SantiSearchTest
fatal, size_recommender e2e/parity brak MySQL lokalnie).
