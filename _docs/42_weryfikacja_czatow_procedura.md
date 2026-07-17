# 42. Weryfikacja rozmow czatu — procedura

> **Nadrzedna instrukcja architekta: `_docs/46_instrukcja_architekta.md`** (mapa
> „zadanie -> dokument", wszystkie narzedzia, gdzie sekrety, pulapki narzedziowe).
> Ten dokument opisuje JEDNO zadanie: „przejrzyj czaty do weryfikacji".

Dokument trwaly. Opisuje, jak wykonac zadanie "przejrzyj czaty do weryfikacji
i zaproponuj naprawe". Cel: przy kazdym powtorzeniu tego zadania zaczynac od
gotowego, nie od zera. Powstal, bo narzedzia do tego byly budowane ad-hoc i ginely.

## 1. Gdzie sa dane

Stan rozmow siedzi w Railway PG (baza czatu), NIE w plikach.

- Polaczenie: `DATABASE_URL` z `.env` (host switchback). Railway to JEDYNA aktywna
  baza czatu. Laczność z VM jest bezposrednia (bez tunelu SSH). Przy wieczornych
  stratach pakietow (~15-22 CEST) ponow lub uzyj tunelu przez smarthost.
- Recenzja: tabela `divechat_conversation_review`, dwie NIEZALEZNE osie:
  - `status` (praca): `nowy` -> `do_weryfikacji` -> `w_trakcie` -> `zamkniety`
  - `verdict` (jakosc): `ok` / `problem_do_rozwiazania` / `problem_rozwiazany`
- "Czaty do naprawy" = `verdict='problem_do_rozwiazania'` o statusie NIEzamknietym
  (`nowy` lub `do_weryfikacji`). To potwierdzone przez zespol bledy bota.
- Przebieg rozmowy: `divechat_conversations.messages` (jsonb). To DOKLADNIE to
  zrodlo, ktore pokazuje panel recenzji w module PS przez `/api/conversations/{sid}`
  (ConversationStore -> json_decode kolumny messages). NIE czytaj z tabeli
  `divechat_messages` — to dual-write dla wygaszanego dashboardu standalone (ADR-070).

## 2. Narzedzia (trwale)

Katalog: `_diag_local/chat_verification/` (wersjonowany w git — `_diag_local`
NIE jest w .gitignore; skrypty czytaja sekrety z `.env`, ktory jest ignorowany).

- `_conn.py` — wspolne polaczenie (parser `.env` linia-po-linii, odporny na
  1 zepsuty klucz wg ADR-088). Zero sekretow na sztywno.
- `list_open_problems.py` — lista otwartych problemow (notatki + metadane;
  `--full` dokłada przebiegi, `--dump PLIK` zapisuje przebiegi do pliku).
- `show_conversation.py <conv_id>` — pelny przebieg jednej rozmowy (`--tools`
  pokazuje tez tool_result).

Uruchamianie: z katalogu `chat_verification/`, `python3 <skrypt>`.

## 3. Procedura krok po kroku

1. `python3 list_open_problems.py` — zobacz, ile jest i czego dotycza (notatki
   recenzenta sa najwazniejszym sygnalem — mowia, co konkretnie bylo zle).
2. Dla kazdego wzorca wejdz w przebieg: `show_conversation.py <conv_id>` (dodaj
   `--tools`, gdy problem dotyczy danych z narzedzi — np. cena, promocja, warianty).
3. Znajdz PRZYCZYNE w realnym kodzie/danych, nie w domysle. Cena/promocja ->
   `standalone/src/Tools/ProductDetails.php` + `MysqlProductEnrichmentService.php`
   + tabela `pr_specific_price` (MySQL, read-only). Prompt -> `SystemPrompt.php`.
   Rekomendacje -> `CuratedRecommendations.php` + tabela `divechat_curated_*`.
4. ZANIM uznasz cos za otwarte: sprawdz `_docs/21_STATUS_PROJEKTU.md` i
   `_docs/10_decyzje_projektowe.md` — czy fix nie zostal juz wdrozony. Czesty
   przypadek: problem naprawiony w kodzie, ale rekord recenzji nie przestawiony
   na `problem_rozwiazany` (patrz sekcja 5).
5. Zaproponuj fix / zapisz karte w Trello (sekcja 4).

## 4. Backlog w Trello

Tablica domyslna: "Projekty 2026". Listy: Backlog, W trakcie, Do weryfikacji, Zrobione.
Karty problemow czatu: tytul zaczyna sie od "Chat - ", potem krotka nazwa;
w opisie: czego dotyczy, dowod (conv_id rozmow, pliki/linie kodu), co zrobic.

Cykl zycia karty:
  Backlog -> W trakcie (gdy zaczynasz) -> Do weryfikacji (wdrozone, przed
  potwierdzeniem) -> Zrobione (fix zweryfikowany).
ZASADA (Karol, 2026-07-14): gdy fix jest ZWERYFIKOWANY (deploy potwierdzony md5
prod==local + test PROD przez realna sciezke), Claude/CC SAM domyka sprawe —
przesuwa karte do "Zrobione" ORAZ zamyka odpowiednie rozmowy w bazie recenzji
(sekcja 5). NIE czekaj z tym na Karola. "Do weryfikacji" jako przystanek dotyczy
tylko sytuacji, gdy weryfikacja jeszcze trwa lub zalezy od czegos poza kodem
(np. Karol musi zobaczyc efekt w widgecie).

Uwaga techniczna: serwer MCP Trello potrafi zwrocic timeout przy DLUGIM opisie
karty (utworzenie sie nie udaje). Trzymaj opisy zwiezle; po timeoucie sprawdz
`get_cards_by_list_id`, czy karta jednak nie powstala, zanim ponowisz.

## 5. Domykanie petli recenzji (czesty realny problem)

Fix trafia do kodu, ale rekord recenzji zostaje `problem_do_rozwiazania`, przez co
ta sama rozmowa wraca do przegladu. Przyklad: conv 598 (kierowanie na zewnatrz)
naprawiony przez CHAT-T-128, a rekord dlugo pozostawal `do_weryfikacji`.

Zasada: gdy problem z danej rozmowy jest zaadresowany ZWERYFIKOWANYM, wdrozonym
fixem, Claude/CC SAM zamyka rozmowe (nie czeka na Karola). Zapis do
`divechat_conversation_review`:
- `verdict='problem_rozwiazany'`, `status='zamkniety'`
- `updated_by=NULL` (zapis nie-osobowy; kolumna jest typu integer — NIE wpisuj
  tekstu typu 'claude', rzuci blad; autorstwo Claude idzie do `note`)
- marker DOPISANY do istniejacej `note` (nie nadpisuj):
  `[zamkniete przez Claude, CHAT-T-NNN, DATA: <powod>]`
- zapytanie z guardem `AND verdict='problem_do_rozwiazania'` (idempotentne).
Przyklad wykonany: CHAT-T-130 zamknal conv 641 i 649 (2026-07-14).

Uwaga Trello: `move_card` WYMAGA jawnego `boardId` (mimo ze opis mowi "opcjonalny")
— bez niego zwraca 400. Po niejednoznacznym wyniku (blad/timeout) NAJPIERW
`get_cards_by_list_id`, dopiero potem ewentualne ponowienie — nie ponawiaj na slepo.

## 6. Zasady

- Nie ufaj pamieci co do liczb (ile otwartych, co zamkniete) — sprawdz baze.
- Diagnozuj z realnych danych i kodu, nie z domyslu. Notatka recenzenta mowi CO
  bylo zle; przebieg + kod mowia DLACZEGO.
- Zanim ogłosisz problem za otwarty, sprawdz status projektu i ADR — moze byc juz
  naprawiony (a jedynie niezamkniety w bazie).
