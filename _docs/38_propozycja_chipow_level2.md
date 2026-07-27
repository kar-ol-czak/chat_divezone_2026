# 38 — Struktura drzewa chipów (ZATWIERDZONA, oparta na analizie rozmów)

**Data:** 2026-06-28 | **Status:** ZATWIERDZONA przez Karola | **Zastępuje:** wcześniejszą propozycję Level 2 z 2026-06-14 (opartą na liczbach kategorii, nie na rozmowach).
**Powiązane:** ADR-071 (model węzła), ADR-096 (ai_prompt + kontekst ścieżki), ADR-103 (ta struktura), CHAT-T-088 (fundament drzewa na produkcji), CHAT-T-088f (seed tej struktury).

---

## Podstawa decyzji: analiza realnych rozmów

Źródło: `divechat_messages` (Railway), 1217 wiadomości użytkowników → po odfiltrowaniu fixture'ów red-team i deduplikacji **772 unikalne realne wiadomości**. Analiza intencji (2026-06-28).

**Rozkład wejść (najczęstsze):**
- Marka/model wprost: **126** (16% wszystkich) — Suunto Ocean, Apeks XTX200, Santi BZ4000, Shearwater Peregrine, Tusa Freedom. Dominujący wzorzec: klient wpisuje nazwę, pyta „macie / ile / dostępny / X czy Y", NIE klika taksonomii.
- Komputer nurkowy: 67 | Maska: 69 (fajka 19, zwykle przy masce) | Suchy+ocieplacz: 35 | Rozmiary: 35 | Płetwy: 30 | Snorkeling/start: 24 | Automat: 31 | Wysyłka/płatność: 21 | Dostępność/stock: 20 | Pianka: 18 | Butla: 18 | Status zamówienia: 14 | Jacket/skrzydło: 13 | Latarka: 5 | Zwroty: 5 | Serwis: 3.

**Wnioski, które ukształtowały strukturę:**
1. Marka (126) dowodzi, że dobór musi iść przez **liść AI**, nie sztywne Level 3 — większość klientów ominie każdą taksonomię, wchodząc z konkretem.
2. Budżet to najsilniejsza wspólna oś doboru (pada wszędzie), ale klient podaje go sam → pytanie AI, nie chip.
3. Twin/sidemount/zimna-ciepła jako oś doboru **nie istnieje w rozmowach** (potwierdzone empirycznie dla automatu i komputera). Odrzucone.
4. Obsługa zamówienia (status 14 + dostępność 20 + wysyłka 21 ≈ 55) to największy blok nie-doborowy → własny chip Level 1.
5. Serwis (3) i zwroty (5) najrzadsze → serwis usunięty, zwroty zeszły pod „Moje zamówienie".

**Filozofia:** mało chipów Level 1, szerokie bramy, dobór przez AI z dobrym `ai_prompt`. Level 3 tylko tam, gdzie rozgraniczenie realnie pada w rozmowach. Cała inteligencja doboru w `ai_prompt` liścia (pyta o budżet, poziom, markę, zastosowanie). Nigdzie nie pada „DIN" (wszystkie automaty/zawory w sklepie są DIN — jedyny standard).

---

## RZECZYWISTY MODEL WĘZŁA (zweryfikowany na Railway 2026-06-28)

UWAGA: schemat `divechat_chip_nodes` różni się od wcześniejszych założeń. Realne kolumny:
`id, node_key (text, UNIQUE), parent_id (bigint NULL), level (int), sort_order (int), bot_text (text NULL), buttons (jsonb NOT NULL DEFAULT '[]'), context_hint (text NULL), model_level (text NULL), active (boolean NOT NULL DEFAULT true), updated_at, label (text NULL), ai_prompt (text NULL)`.

**NIE MA kolumny `action_type` ani `is_active`.** Wnioski dla seeda:
- Kolumna aktywności to **`active`** (nie `is_active`). Ukrycie serwisu: `UPDATE ... SET active=false`.
- **Typ akcji liścia koduje `buttons` (jsonb)** — lista `{label, target}`. `target` przyjmuje: `"ai"` (przejście do rozmowy z AI) lub `"link:<klucz>"` (np. `link:link_zwroty`, `link:link_serwis`, `link:link_kontakt`). Brak w obecnych danych targetu typu `form` — status zamówienia trzeba zrealizować jako `ai` z ai_prompt kierującym na formularz, ALBO dodać nowy target `form:order_status` (wymaga wsparcia we froncie — zweryfikować, patrz niżej).
- **Hierarchia drzewa = `parent_id` + `level`**, NIE zagnieżdżenie w `buttons`. Chip z podchipami (np. „Maska i fajka" → 4 podchipy) = węzły-DZIECI z `parent_id`. `buttons` służy tylko akcjom terminalnym liścia (pisz do AI / idź do linku).
- **bot_text** = deterministyczna treść pokazywana po kliknięciu (hybryda: bot_text + buttons). **ai_prompt** = ukryta instrukcja dla AI gdy liść prowadzi do rozmowy.
- Wzorzec liścia AI (z istniejącego seedu): `bot_text` + `buttons=[{"label":"Napisz czego szukasz","target":"ai"}]` + `ai_prompt` (ukryta instrukcja).
- Wzorzec hybrydy link (zwroty): `bot_text` + `buttons=[{"label":"Formularz...","target":"link:link_zwroty"},{"label":"Inne pytanie","target":"ai"}]`.

**Stan na PROD przed seedem (2026-06-28):** root + 5 płaskich L1: zwroty, serwis, wysylka, dobor, dobor_rozmiar. ŻADEN nie ma dzieci (Level 2/3 nie istnieje). `dobor` i `dobor_rozmiar` mają tylko przycisk `ai`. Seed 088f dobudowuje dzieci i koryguje L1.

---

## LEVEL 1 (5 chipów)

1. **Dobór sprzętu** → Level 2 (6 kategorii doboru)
2. **Pomoc w rozmiarze** → Level 2 (6 kategorii rozmiaru)
3. **Zaczynam nurkować** → liść AI (kompletowanie pierwszego zestawu po kursie/OWD)
4. **Maska i rurka (snorkeling)** → liść AI (snorkeling/wakacje/basen, często dzieci)
5. **Moje zamówienie** → Level 2 (status · dostępność · wysyłka i płatności · zwroty)

**Rozbicie „start/snorkeling" na dwa (decyzja P31a):** to dwie różne osoby — nurek po kursie kompletujący sprzęt (19 wzmianek o kursie/OWD) vs wakacjowicz/snorkeler (≈16 wzmianek, w tym 6× „rurka", 4× „snurkowanie" — termin „snorkeling" zna tylko część, dlatego label wiedzie językiem klienta „maska i rurka", a „(snorkeling)" w nawiasie domyka). 18 wzmianek o dzieciach rozkłada się na oba — `ai_prompt` pyta o wiek.

`serwis` USUNIĘTY z drzewa (3 wzmianki). Kontekst serwisu zostaje w SystemPrompt (`serwis@divezone.pl`).

---

## LEVEL 2 — Dobór sprzętu (6 chipów, kolejność wg częstości w rozmowach)

Każdy liść → AI. `ai_prompt` = ukryta instrukcja (klient jej nie widzi) pytająca o realne osie z danych: budżet, poziom, ewentualną markę/model, zastosowanie. Nigdzie „DIN".

### 1. Komputer nurkowy `[67]`
- **bot_text:** „Pomogę dobrać komputer. Powiedz: na jakim jesteś poziomie (kurs/rekreacja, nitrox, technika-trimix), jaki budżet i czy ma służyć też jako zegarek na co dzień."
- **→ AI** — `ai_prompt`: „Dobór komputera nurkowego. Realne osie z rozmów klientów: BUDŻET (najczęściej 1500–3000 zł), POZIOM (początkujący po OWD / nitrox / techniczny-trimix), STYL (zegarek codzienny vs konsola), MARKA jeśli klient ją poda (Suunto, Shearwater, Garmin, Mares — częste). Zapytaj o to, czego klient nie podał. Pokaż 2–3 modele z linkami. Nie moralizuj przy głębokościach/uprawnieniach — klient pyta o produkt."

### 2. Maska i fajka `[69]` → **Level 3**
- **bot_text:** „Pomogę dobrać maskę. Do czego ma być?"
- Level 3:
  - **Do nurkowania** → AI — `ai_prompt`: „Maska do nurkowania z butlą. Zapytaj o korekcję wzroku (są maski korekcyjne), jedno/dwuszybowa, budżet. Maski NIE mają rozmiarów S/M/L w obrębie modelu — dopasowanie = kształt twarzy, sugeruj przymiarkę. Pokaż 2–3 modele."
  - **Do snorkelingu** → AI — `ai_prompt`: „Maska do snorkelingu (najczęstsza intencja w rozmowach, 19 wzmianek). Zapytaj o budżet (często do 200–300 zł), wakacje/Egipt/Chorwacja, czy dla dziecka. Maska snorkelingowa ≠ pełnotwarzowa — przy pełnotwarzowej ostrzeż o ograniczeniach. Pokaż 2–3 modele."
  - **Zestaw maska z fajką** → AI — `ai_prompt`: „Klient chce KOMPLET maska+fajka (nie cały sprzęt na start — to osobny chip). Dobierz pasującą parę maska+fajka, zapytaj o budżet i snorkeling vs nurkowanie. Pokaż 1–2 gotowe zestawy lub pasującą parę."
  - **Korekcyjna** → AI — `ai_prompt`: „Maska korekcyjna (wada wzroku). Zapytaj o moc korekcji (dioptrie) i czy klient nurkuje, czy snorkeluje. Wyjaśnij, że soczewki korekcyjne dobiera się do modelu maski. Skieruj na konsultację 56 307 03 03 przy nietypowej korekcji."

### 3. Płetwy `[30]` → **Level 3**
- **bot_text:** „Pomogę dobrać płetwy. Na bose stopy czy na buty neoprenowe?"
- Level 3:
  - **Paskowe na but neoprenowy** → AI — `ai_prompt`: „Płetwy paskowe (na buty neoprenowe) = standard techniczny/zimna woda. Zapytaj o poziom, budżet, rozmiar buta neoprenowego. Pokaż modele."
  - **Kaloszowe na gołą stopę** → AI — `ai_prompt`: „Płetwy kaloszowe (na bosą stopę) = wakacje/ciepła woda/rekreacja. Zapytaj o rozmiar buta, budżet. Pokaż modele."

### 4. Automat oddechowy `[31]`
- **bot_text:** „Pomogę dobrać automat. Powiedz: pierwszy automat czy wymiana, jaki budżet i czy masz na oku konkretny model."
- **→ AI** — `ai_prompt`: „Dobór automatu. Realne osie z rozmów: MARKA/MODEL (klient często zna nazwę — Apeks XTX/ATX, Scubapro, Mares, Hollis), BUDŻET/PÓŁKA (do 1500 / średnia / premium — pada wprost), POZIOM (pierwszy automat / doświadczony). Zapytaj o to, czego nie podał. Octopus i manometr zaproponuj jako naturalny dodatek do zestawu. NIE pisz 'DIN' (wszystkie są DIN). NIE używaj osi zimna/ciepła woda jako kryterium doboru — w rozmowach to nie pada. Pokaż 2–3 modele."

### 5. Pianka mokra `[18]` → **Level 3**
- **bot_text:** „Pomogę dobrać piankę mokrą. Do jakiej wody?"
- Level 3:
  - **Cienka na ciepłe wody** → AI — `ai_prompt`: „Pianka cienka (2–3 mm) na ciepłe wody/wakacje. Zapytaj płeć, wzrost/wagę do rozmiaru, budżet. Rozmiar po wymiarach ciała, nie po ubraniach. Pokaż modele."
  - **Gruba na zimne wody** → AI — `ai_prompt`: „Pianka gruba (5–7 mm) na zimne/polskie wody. Zapytaj płeć, wymiary, czy z kapturem. Pokaż modele. Przy bardzo zimnej wodzie/twin wspomnij, że alternatywą jest suchy skafander."
  - **Krótka (shorty)** → AI — `ai_prompt`: „Pianka krótka shorty (basen/ciepłe wody/dodatkowa warstwa). Zapytaj płeć, wymiary, zastosowanie. Pokaż modele."

### 6. Jacket / skrzydło `[13]`
- **bot_text:** „Pomogę dobrać sprzęt wypornościowy. Jedna butla czy twin? Nurkujesz w suchym skafandrze?"
- **→ AI** — `ai_prompt`: „Dobór jacket/skrzydło (BCD). Zapytaj: pojedyncza butla vs twin, suchy skafander tak/nie, rekreacja vs technika, budżet. Jednobutlowe BCD 13–16 L wyporności; twin 18–22 L i ZAWSZE paruje się z suchym skafandrem (nie grubą pianką). Jacket = rekreacja/wygoda, skrzydło = technika/konfiguracja. Pokaż modele."

*Butla (18) i latarka (5): bez osobnego chipa — niska częstość na czacie. Obsługuje je AI z rozmowy lub przez „Sprzęt na start".*

---

## LEVEL 2 — Pomoc w rozmiarze (6 chipów)

Dobór rozmiaru = POMIAR (inny charakter niż dobór sprzętu). bot_text mówi WIĘCEJ (jakie wymiary podać), bo procedura jest stała. Wszystkie → AI (przyjmuje wymiary). Rozmiarówki marek dochodzą osobno (moduł atrybutów, ADR-100/102) — wtedy AI dobierze z tabeli.

### 1. Pianka mokra
- **bot_text:** „Rozmiar pianki dobieramy po wymiarach ciała, nie po rozmiarze ubrań. Podaj: wzrost, wagę, obwód klatki, pasa i bioder."
- **→ AI** — `ai_prompt`: „Rozmiar pianki mokrej. Zbierz: wzrost, waga, obwód klatki/pasa/bioder, płeć. Pianka ma leżeć ŚCIŚLE. Przy nietypowych proporcjach kieruj na konsultację 56 307 03 03."

### 2. Suchy skafander
- **bot_text:** „Suchy skafander to sprzęt na lata, dobieramy starannie. Nie mamy pełnej rozmiarówki do przymierzenia od ręki — najlepiej przyślij wymiary (wzrost, obwód klatki/pasa/bioder + nietypowe: biceps, łydki) na dive@divezone.pl lub zadzwoń 56 307 03 03, a ściągniemy najbliższe rozmiary na umówiony termin."
- **→ AI** — `ai_prompt`: „Rozmiar suchego skafandra. Zbierz wymiary jw. NIE obiecuj przymiarki od ręki. Kieruj na kontakt mailowy/telefon z wymiarami i umówienie terminu. Santi to najczęściej pytana marka."

### 3. Płetwy
- **bot_text:** „Rozmiar płetw zależy od rozmiaru buta (i czy na bose stopy, czy na buty neoprenowe). Podaj rozmiar buta."
- **→ AI** — `ai_prompt`: „Rozmiar płetw. Zapytaj o rozmiar buta i czy paskowe (na buty neoprenowe — wtedy uwzględnij grubość buta) czy kaloszowe (na bosą stopę). Dopasuj do rozmiarówki modelu."

### 4. Buty neoprenowe
- **bot_text:** „Rozmiar butów neoprenowych dobieramy zwykle wg rozmiaru obuwia, ale grubość neoprenu i krój mają znaczenie. Podaj swój rozmiar buta."
- **→ AI** — `ai_prompt`: „Rozmiar butów neoprenowych. Zapytaj o rozmiar obuwia. Przy grubszym neoprenie czasem +1 rozmiar. Buty do płetw paskowych = inny dobór niż do bosej stopy."

### 5. Kaptur / rękawice
- **bot_text:** „Kaptur dobieramy po obwodzie głowy, rękawice po rozmiarze dłoni. Powiedz, co Cię interesuje, i podaj wymiar."
- **→ AI** — `ai_prompt`: „Rozmiar kaptura (obwód głowy w cm) lub rękawic (zwykły rozmiar S/M/L/XL lub obwód dłoni). Dopasuj do tabeli modelu."

### 6. Nie wiem, co zmierzyć
- **bot_text:** „Napisz, jakiego sprzętu rozmiar Cię interesuje — powiem, co zmierzyć, i pomogę dobrać."
- **→ AI** — `ai_prompt`: „Klient nie wie, jaki wymiar podać. Ustal sprzęt, powiedz jakie wymiary potrzebne, zbierz je."

---

## LEVEL 2 — Moje zamówienie (4 chipy)

### 1. Status / gdzie moja paczka
- **DETERMINISTYCZNY** — formularz numer zamówienia + email → endpoint `/api/order/status` (już istnieje, ADR-063, walidacja PHP bez AI). bot_text: „Sprawdzę status. Podaj numer zamówienia i e-mail z zamówienia."

### 2. Dostępność produktu
- **bot_text:** „Sprawdzę dostępność. Napisz, który produkt Cię interesuje."
- **→ AI** — `ai_prompt`: „Klient pyta o dostępność konkretnego produktu. Sprawdź stan (uwaga: stan na rekordach kombinacji, nie na parencie). Trzy stany: dostępny od ręki / dostępny w magazynie 2–5 dni (out_of_stock pozwala zamówić) / niedostępny. Nie zmyślaj wiedzy o stanie magazynu stacjonarnego w Toruniu — kieruj na kontakt, jeśli klient pyta o sklep stacjonarny."

### 3. Wysyłka i płatności
- **DETERMINISTYCZNY** — bot_text z czasem, kosztami, formami płatności (treść do uzupełnienia z aktualnych danych sklepu, źródło: GetShopLinks / strona). Link do strony dostawy.

### 4. Zwroty i reklamacje
- **DETERMINISTYCZNY, ZERO AI.** Dwa tryby zwrotu + osobno odstąpienie od umowy (dyrektywa UE, od 19-06-2026 osobny formularz). Linki przez klucze GetShopLinks (P40a — CC weryfikuje/zakłada klucze: `link_zwroty`, `link_odstapienie` PL; `link_returns`, `link_withdrawal` EN). Wszystkie 4 URL zweryfikowane jako żywe (2026-06-28).
- **bot_text (PL):** „Towar możesz zwrócić na dwa sposoby. Tryb ustawowy: 14 dni od otrzymania, bez podania przyczyny. Nasza Gwarancja zwrotu 30 dni: dłuższy czas, ale towar musi być pełnowartościowy (bez uszkodzeń, rys i zabrudzeń), w oryginalnym opakowaniu, sprzęt mierzony tylko „na sucho", a paczka musi dotrzeć do naszej siedziby (nie obsługujemy zwrotów w paczkomatach ani punktach odbioru). Aby formalnie odstąpić od umowy, skorzystaj z osobnego formularza."
- **buttons (PL):** `[{label:"Zasady zwrotów", target:"link:link_zwroty"}, {label:"Odstąpienie od umowy", target:"link:link_odstapienie"}]`
- **bot_text (EN):** analogicznie — 14 dni ustawowe / 30-day return guarantee (pełnowartościowy, oryginalne opakowanie, „dry" try-on, dostawa do siedziby, bez paczkomatów).
- **buttons (EN):** `[{label:"Return policy", target:"link:link_returns"}, {label:"Withdrawal from contract", target:"link:link_withdrawal"}]`
- **UWAGA treść:** warunki 30-dniowe pochodzą wprost ze strony zwrotów (pełnowartościowy / oryginalne opakowanie / „na sucho" / dostawa do siedziby, bez paczkomatów) — NIE parafrazować luźno, to warunki regulaminowe. Tryb 14 dni = ustawowe prawo konsumenta. Reklamacja (rękojmia) — jeśli ma być wzmiankowana, osobnym zdaniem, nie mylić ze zwrotem.

---

## LEVEL 1 — liście AI (Zaczynam nurkować / Maska i rurka)

### Zaczynam nurkować (L1 #3)
- **bot_text:** „Pomogę skompletować sprzęt na start. Powiedz: po kursie OWD czy dopiero planujesz, gdzie zamierzasz nurkować i jaki masz budżet?"
- **→ AI** — `ai_prompt`: „Klient zaczyna NURKOWANIE z butlą (nie snorkeling — to osobny chip). Realny język z rozmów: 'dopiero zaczynam OWD', 'co kupić na start', 'pierwszy sprzęt'. Zapytaj: po kursie/w trakcie czy planuje, gdzie (Polska-zimna / wakacje-ciepła), budżet. Typowy pierwszy zakup to sprzęt osobisty (maska, płetwy, komputer), nie cały zestaw od razu — doradź kolejność. Jeśli dla DZIECKA/nastolatka — zapytaj o wiek (sprzęt juniorski, mniejsze rozmiary). Pokaż 2–3 propozycje z linkami."

### Maska i rurka / snorkeling (L1 #4)
- **bot_text:** „Pomogę dobrać sprzęt do pływania z maską i rurką. Powiedz: na wakacje (ciepłe wody) czy na basen, dla dorosłego czy dziecka, jaki budżet?"
- **→ AI** — `ai_prompt`: „Klient chce sprzęt do SNORKELINGU (pływanie z maską i rurką z powierzchni, BEZ butli). Realny język: 'snorkeling', 'snurkowanie', 'rurka', 'maska z rurką', wakacje/Egipt/Chorwacja/rafy. Zapytaj: wakacje vs basen, dorosły vs DZIECKO (jeśli dziecko — wiek, sprzęt juniorski), budżet (często do 200–300 zł). Podstawowy zestaw snorkel = maska + rurka (+ ewentualnie płetwy). NIE myl z 'Zestaw maska z fajką' w doborze maski (tam dobór konkretnej pary; tu kompletowanie sprzętu na aktywność). Pełnotwarzowa maska — wspomnij o ograniczeniach jeśli klient pyta. Pokaż 2–3 propozycje."

---

## Uwagi wdrożeniowe

1. **„Zestaw maska z fajką" (L3 maski) vs „Maska i rurka / snorkeling" (L1):** różne intencje — pierwszy dobiera konkretną parę maska+fajka w gałęzi doboru maski, drugi kompletuje sprzęt na aktywność snorkelingową. `ai_prompt` rozdzielone, by się nie biły.
2. **Level 3 tylko 3 gałęzie** (maska, płetwy, pianka) — tam rozgraniczenie realnie pada w rozmowach. Reszta płaska (liść AI).
3. **`ai_prompt` to nie treść dla klienta** — ukryta instrukcja dla modelu (ADR-096, „dwa światy": chip_context wstrzykiwany per-turn).
4. **Limit mobilny:** Level 1 = 5 chipów (mieści się, limit nie jest twarde 4). Level 2 = 6. Level 3 = 2–4.
5. **Po wdrożeniu rozmiarówek marek** (moduł atrybutów): liście „Pomoc w rozmiarze" zyskają dobór z tabeli zamiast samego kierowania na kontakt.
