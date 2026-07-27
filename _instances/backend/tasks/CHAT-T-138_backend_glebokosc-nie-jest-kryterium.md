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

## D2. KOREKTA SEKCJI D (architekt, 2026-07-16) — WYKONAJ TO, NIE SEKCJE D

**Sekcja D powyzej jest NIEAKTUALNA w trzech punktach. Nie realizuj jej.**
Powod korekty ponizej, kazdy zweryfikowany na zywej bazie Railway 2026-07-16.

### D2.1 Zla tabela
Sekcja D mowi: INSERT do `divechat_knowledge`. **To tabela martwa.**
Zweryfikowane: `divechat_knowledge` ma 37 wierszy (wszystkie z embeddingiem), ale
**0 odczytow w `standalone/src`** (`_docs/44`, rozjazd R-2). Narzedzie
`get_expert_knowledge` czyta **`encyclopedia_chunks`** (`ExpertKnowledge.php:105`).
Wpis do `divechat_knowledge` nigdy nie trafilby do bota.

**Cel: `encyclopedia_chunks`**, hasło istniejace `concept_key='AUTOMAT_ODDECHOWY'`
(`concept_number=1`, ma juz komplet 5 chunkow: definition/faq/purchase/seller/synonyms).
Decyzja Karola 122a: nowy chunk `chunk_type='faq'` pod istniejacym hasłem, NIE nowe hasło.

### D2.2 Zly wymiar embeddingu
Sekcja D mowi: `dimensions=1536`. **`encyclopedia_chunks.embedding` to `vector(3072)`**
(zweryfikowane `format_type` w `pg_attribute`; `ExpertKnowledge.php:16,75` embeduje
zapytanie z `dimensions=3072`). Model bez zmian: OpenAI `text-embedding-3-large`.
1536 dotyczy `divechat_product_embeddings`, nie encyklopedii.

### D2.3 Tresc w sekcji D jest merytorycznie BLEDNA — nie uzywaj jej
Sekcja D zawiera wersje sprzed poprawek Karola (decyzje 107b, 111a):
- **"Maksymalna glebokosc normy: 50 m"** — BLAD. 50 m to **warunek testu**, nie limit.
  Norma nie wyznacza dopuszczalnej glebokosci nurkowania.
- **"testy producentow 100-200 m"** — WYCIETE. Niepotwierdzone u zrodel, ryzyko fabrykacji.
- **bez WOB / J/l / mbar** (111a).

**Tresc ZATWIERDZONA (finalna, trzy rundy poprawek Karola — NIE MODYFIKUJ ANI SLOWA):**

```sql
INSERT INTO encyclopedia_chunks
  (concept_key, concept_number, name_pl, name_en, chunk_type, content, metadata)
VALUES (
  'AUTOMAT_ODDECHOWY', 1, 'automat oddechowy', 'regulator', 'faq',
  'FAQ: automat oddechowy

Q: Do jakiej głębokości jest certyfikowany automat oddechowy? Co to norma EN 250?
A: Obowiązująca norma dla automatów do nurkowania na sprężonym powietrzu to EN 250:2014. Określa minimalne wymagania bezpieczeństwa, metody badań i oznakowanie. Norma nie wyznacza dopuszczalnej głębokości nurkowania — opisuje warunki testu: automat bada się przy ciśnieniu 6 barów absolutnych (odpowiednik 50 m) i wentylacji 62,5 l/min, i musi tam utrzymać wymagane parametry oddechowe. To norma sprzętowa, nie zasada nurkowania. Automaty przeznaczone do zimnej wody przechodzą dodatkowy test w około 4°C — odporność na zamarzanie i brak niekontrolowanego freeflow. EN 250A dodaje test oddychania przez dwóch nurków jednocześnie z jednego pierwszego stopnia (nurek + partner na oktopusie). EN 13949 nie zastępuje EN 250 — dokłada wymagania dla automatów do nitroksu i tlenu.',
  '{"name_en": "regulator", "name_pl": "automat oddechowy", "related_keys": ["PIERWSZY_STOPIEN", "DRUGI_STOPIEN", "OCTOPUS", "ZLACZE_DIN", "NITROKS"], "concept_number": 1, "pipeline_version": "v2", "source": "CHAT-T-138 decyzje 107b/111a"}'::jsonb
);
```

Format `Q:`/`A:` w jednym polu `content` — zgodny z istniejacym chunkiem `faq` tego hasła
(id 19), sprawdzony przed zapisem. Istniejacy `faq` NIE mowi nic o EN 250 ani o glebokosci
certyfikacji — nowy chunk uzupelnia, nie zaprzecza. `id` to serial, NIE podawaj recznie.
BEZ `[Uwaga dla bota]` w `content` — ograniczenie 45a siedzi w prompcie (juz wdrozone),
w bazie wiedzy trafiloby do embeddingu jako instrukcja zamiast wiedzy.

### D2.4 Embedding — bez tego wpis jest martwy
Po INSERT wygeneruj `embedding` dla nowego chunku: OpenAI `text-embedding-3-large`,
**`dimensions=3072`**, wejscie = pole `content` tego wiersza (identycznie jak reszta
`encyclopedia_chunks`). Wpis z `embedding IS NULL` nie zostanie znaleziony przez
`get_expert_knowledge` (SQL odsiewa po cosine `> 0.45`, `ExpertKnowledge.php:96`).

### D2.5 Kolejnosc i STOP-y
1. **pg_dump** tabeli `encyclopedia_chunks` PRZED czymkolwiek. Sciezka + rozmiar w raporcie.
2. **STOP** — pokaz Karolowi SQL przed wykonaniem (ADR-089).
3. INSERT → zwroc `id` nowego wiersza.
4. Embedding 3072 → UPDATE tego `id`.
5. Weryfikacja: `SELECT id, concept_key, chunk_type, (embedding IS NULL) AS bez_wektora,
   vector_dims(embedding) AS wymiary FROM encyclopedia_chunks WHERE id = <nowy_id>;`
   Oczekiwane: `bez_wektora=false`, `wymiary=3072`.
6. Test PROD przez realny czat: "co to norma EN 250?" → bot odpowiada z bazy wiedzy
   (norma sprzetowa, 50 m = warunek testu, NIE limit). Regresja: "szukam automatu" →
   bot NIE podaje EN 250 z siebie.
7. Rozmowe testowa oznacz markerem `[test CHAT-T-138, nie klient]` wg reguly E.

### D2.6 Kryterium akceptacji 4 — korekta
Punkt 4 w "Kryteria akceptacji" mowi "50 m = zakres certyfikacji" — to sformulowanie
z bledenej wersji. Obowiazuje: **50 m = warunek testu, norma nie wyznacza limitu glebokosci**.

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

## Wynik D2 (instancja embeddings, CC 2026-07-16) — DONE

Wykonano sekcje D2 (NIE D). Operacja na danych Railway PG, zero rsync.

**KROK 1 — pg_dump:** `_backups/encyclopedia_chunks_20260716_przed_T138.sql`
(21 397 465 B ≈ 20 MB, 530 wierszy COPY). Weryfikacja D2 na żywej bazie: `embedding` =
`vector(3072)` ✓; koncept `AUTOMAT_ODDECHOWY` (num=1) ma 5 chunkow (definition/synonyms/
purchase/faq id19/seller); 0 istniejacych chunkow o EN 250.

**KROK 2 — STOP (ADR-089):** SQL z D2.3 pokazany Karolowi, zatwierdzony bez zmian.

**ODSTĘPSTWO od D2.3 (constraint, decyzja Karola):** `encyclopedia_chunks` ma UNIQUE
`idx_enc_concept_type (concept_key, chunk_type)` — nie da sie dodac DRUGIEGO chunku `faq`
do `AUTOMAT_ODDECHOWY` (jest juz id 19). D2.3 (INSERT nowego faq) niewykonalny; architekt
zweryfikowal tresc i wymiar, ale nie constraint. **Decyzja Karola: dołączyć zatwierdzoną parę
Q/A o EN 250 do istniejacego faq id 19 + re-embed.** Tekst Q/A 1:1 z D2.3 (wyodrebniony z
task file, nie przepisywany); doklejony bez powtorzonego naglowka „FAQ: automat oddechowy".

**KROK 3-4 — UPDATE + embedding:** id 19 content 1644 → 2501 znakow (5 → 6 par Q/A). Embedding
`text-embedding-3-large`, `dimensions=3072`, wejscie = nowy `content`. UPDATE embedding id 19.

**KROK 5 — weryfikacja (D2.5 pkt 5):** `id=19, concept=AUTOMAT_ODDECHOWY, chunk_type=faq,
bez_wektora=false, wymiary=3072` ✓. Frazy zatwierdzone obecne verbatim (EN 250:2014, „6 barów
absolutnych (odpowiednik 50 m)", „62,5 l/min", „Norma nie wyznacza dopuszczalnej głębokości",
„EN 13949 nie zastępuje EN 250"); frazy błędne (D) nieobecne (brak „100-200 m", „testy
producenta", „Maksymalna głębokość normy"). Test retrieval (jak `get_expert_knowledge`,
cosine>0.45): „co to norma EN 250?" → id 19 rank1 sim 0.6044; „Do jakiej głębokości certyfikowany
automat?" → id 19 rank1 0.5844; „szukam automatu" → id 19 POZA wynikami.

**KROK 6 — test PROD przez realny czat** (HMAC `/api/chat`, customerId=0):
- conv **715** „co to norma EN 250?" → `tools_used=[get_expert_knowledge]`, bot: „EN 250:2014…
  **Nie wyznacza maksymalnej głębokości — to norma sprzętowa, nie przepis nurkowy.** Automat
  testuje się przy ciśnieniu odpowiadającym 50 m (6 barów absolutnych) i wentylacji 62,5 l/min…
  EN 250A… EN 13949" ✓ (50 m = warunek testu, NIE limit — D2.6).
- conv **716** (regresja) „szukam automatu oddechowego" → `tools_used=[]`, bot pyta tylko o budżet
  + zakres zestawu (sam czy z manometrem), ZERO EN 250, ZERO głębokości ✓ (bug conv 668 też nie
  wystąpił).
- Obie oznaczone `[test CHAT-T-138, nie klient]` w `divechat_conversation_review`
  (status=do_weryfikacji=default, verdict=NULL, updated_by=NULL) — reguła E.

**KRYTERIA (D2):** tabela właściwa (encyclopedia_chunks, nie divechat_knowledge) ✓; wymiar 3072 ✓;
treść zatwierdzona 1:1 ✓; embedding NOT NULL ✓; bot odpowiada z bazy na EN 250 ✓; regresja (nie
podaje z siebie) ✓; zero rsync ✓. Commit: task file (per ścieżka).
