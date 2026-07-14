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

## Zasada pracy z Trello (tablica "Projekty 2026")
Karty problemow czatu maja tytul "Chat - ...". Cykl:
  Backlog -> W trakcie (start) -> Do weryfikacji (wdrozone, czeka na sprawdzenie
  przez Karola) -> Zrobione (Karol potwierdza).
Instancja/CC przesuwa karte tylko do "Do weryfikacji" po potwierdzeniu na danych,
ze sprawa zalatwiona. "Zrobione" ustawia Karol.

## Zasada: nie ufaj pamieci, sprawdz baze
Liczby (ile otwartych, ktore zamkniete) zmieniaja sie na biezaco. Zawsze uruchom
narzedzie zamiast polegac na liczbach z poprzedniej sesji.

Pelna procedura: `_docs/42_weryfikacja_czatow_procedura.md`.
