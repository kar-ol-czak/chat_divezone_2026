# CHAT-T-123 — Panel recenzji: render ścieżki chipów w widoku rozmowy

**Instancja:** frontend (panel admina) | **Powiązane:** ADR-110, CHAT-T-122 (backend wystawia `chip_path`), CHAT-T-105 (panel recenzji). **Decyzje:** 9a.

## Kontekst
Po CHAT-T-122 rozmowa ma utrwaloną ścieżkę chipów `chip_path` (`[{node_key,label,level}]`). Panel recenzji ma ją pokazać, żeby recenzent wiedział, przez jaką ścieżkę klient trafił do rozmowy (np. „Dobór sprzętu › Komputer nurkowy"). Bez tego kontekst startowy jest niewidoczny (etykieta chipu nie jest już wiadomością — CHAT-T-121).

## Zakres
1. **Backend API panelu:** upewnij się, że endpoint szczegółu rozmowy zwraca `chip_path` (jeśli `ConversationReviewRepository`/detail go nie selektuje — dołóż do SELECT i do mapowania odpowiedzi). Mały dodatek do CHAT-T-122 jeśli pominięty.
2. **Render w `admin-conversation.js`:** nad listą wiadomości pokaż ścieżkę jako „ślad" (breadcrumb) `label › label › label` z `chip_path`. Gdy `chip_path` pusty/NULL → nie renderuj nic (rozmowa z wolnego pisania). Sanityzacja: `DiveAdmin.escHtml` na `label`.
3. **Styl:** dyskretny, nienachalny (mały tekst nad wątkiem, np. „Ścieżka: Dobór sprzętu › Komputer nurkowy"). Bez nowych zależności.
4. **Bez sendBeacon/JS-POST łamiącego ModSecurity** — to tylko render odczytu, brak zapisu.

## Uwaga
- ModSecurity blokuje `text/plain` (znany problem panelu) — tu tylko odczyt, ale trzymać wzorzec istniejących wywołań panelu.
- Nie mylić `chip_path` (nawigacja) z treścią wiadomości. To odrębny blok nad wątkiem.

## Definicja ukończenia
- Rozmowa startująca przez chip pokazuje ścieżkę nad wątkiem.
- Rozmowa z wolnego pisania nie pokazuje bloku ścieżki.
- Znaki polskie i HTML w `label` bezpiecznie zescapowane.
