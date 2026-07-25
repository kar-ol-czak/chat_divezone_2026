# Narzedzia weryfikacji rozmow czatu (chat_verification)

Trwaly zestaw do zadania "zweryfikuj czaty do naprawy". Zastepuje jednorazowe
sondy, ktore wczesniej powstawaly ad-hoc i ginely po sesji.

## Kontekst danych
- Baza czatu: Railway PG (`DATABASE_URL` z `.env`, host switchback). JEDYNA aktywna.
- Laczność z tej maszyny (VM) do Railway jest BEZPOSREDNIA — bez tunelu SSH.
  (Railway miewa wieczorne straty pakietow ~15-22 CEST; jak polaczenie zawodzi,
  ponow lub sprobuj przez tunel smarthost.)
- Recenzja rozmow: tabela `divechat_conversation_review`
  - os `status`: nowy -> do_weryfikacji -> w_trakcie -> zamkniety
  - os `verdict`: ok / problem_do_rozwiazania / problem_rozwiazany
- Przebieg rozmowy: `divechat_conversations.messages` (jsonb) — TO SAMO zrodlo,
  ktore pokazuje panel recenzji w module PS (/api/conversations/{sid}).
  NIE czytaj z `divechat_messages` (to dual-write dla wygaszanego dashboardu).

## Narzedzia
- `list_open_problems.py` — wszystkie otwarte problemy (verdict=problem_do_rozwiazania,
  status nowy/do_weryfikacji): notatki recenzenta + metadane.
    python3 list_open_problems.py            # podsumowanie
    python3 list_open_problems.py --full     # + pelne przebiegi
    python3 list_open_problems.py --dump out.txt   # przebiegi do pliku
- `show_conversation.py <conv_id>` — pelny przebieg jednej rozmowy.
    python3 show_conversation.py 634
    python3 show_conversation.py 634 --tools # z tool_result
- `sql.py` — dowolny SQL na Railway BEZ pulapki apostrofow (SSH->zsh->bash->psql).
  Domyslnie READ-ONLY: bez --write transakcja jest wycofywana.
    python3 sql.py -c "SELECT count(*) FROM divechat_conversations"
    python3 sql.py --file zapytanie.sql
    python3 sql.py -c "SELECT ..." --csv     # do dalszej obrobki
    python3 sql.py -c "UPDATE ..." --write   # zapis WYMAGA jawnej flagi
- `replay.py` — odtworzenie rozmowy na PROD przez POST /api/chat (HMAC jak widget).
  Weryfikacje robi architekt/CC, nie Karol klikajacy w widget. Sekret DIVECHAT_SECRET
  pobierany przez SSH + Config::load() (ADR-088), zyje tylko w pamieci procesu.
  Domyslnie prefiks "[REPLAY] " oznacza rozmowe w panelu recenzji (--no-marker wylacza).
  Jedno wywolanie modelu na uruchomienie (kosztuje tokeny prod).
    python3 replay.py -m "tresc pytania"                    # nowa rozmowa
    python3 replay.py -m "..." --session <uuid>             # kontynuacja
    python3 replay.py -m "..." --show-tools                 # + pelny przebieg (show_conversation.py --tools)
    python3 replay.py --from-conversation 829 --show-tools  # powtorz 1. pytanie user z rozmowy 829
  Wyjscie: session_id, conv_id, narzedzia (tools_used), zapytania (search_diagnostics), odpowiedz.
- `check_deploy.py <sciezka_w_repo>` — kontrola wdrozenia jednym poleceniem:
  md5 local<->prod + php -l (ea-php84) + smoke /api/health. Sam mapuje repo->serwer
  (standalone/ -> chat.divezone.pl BEZ prefiksu; modules/ -> newtmp2 = PRODUKCJA).
    python3 check_deploy.py standalone/src/Chat/ChatService.php
    python3 check_deploy.py standalone/src/Chat/X.php --grep "ADR-126"  # marker taska
    python3 check_deploy.py --smoke-only

## Zasada pracy z Trello (tablica "Projekty 2026")
Karty problemow czatu maja tytul "Chat - NN - opis [T-NNN]". Cykl:
  Backlog -> W trakcie (start) -> Do weryfikacji -> Zrobione.

ZASADA (Karol, 2026-07-14): gdy fix jest ZWERYFIKOWANY (md5 prod==local + test PROD
przez realna sciezke), Claude/CC SAM domyka sprawe — przesuwa karte do "Zrobione"
ORAZ zamyka rozmowy w bazie recenzji (patrz `_docs/42` sekcja 5). NIE czekaj na Karola.
"Do weryfikacji" to przystanek TYLKO wtedy, gdy weryfikacja jeszcze trwa albo zalezy
od czegos poza kodem (np. Karol musi zobaczyc efekt w widgecie/panelu).

Uwaga: `move_card` WYMAGA jawnego `boardId` (mimo ze opis mowi "opcjonalny").
Po timeoucie/bledzie NAJPIERW `get_cards_by_list_id` — nie ponawiaj na slepo.

## Zasada: nie ufaj pamieci, sprawdz baze
Liczby (ile otwartych, ktore zamkniete) zmieniaja sie na biezaco. Zawsze uruchom
narzedzie zamiast polegac na liczbach z poprzedniej sesji.

Pelna procedura: `_docs/42_weryfikacja_czatow_procedura.md`.
