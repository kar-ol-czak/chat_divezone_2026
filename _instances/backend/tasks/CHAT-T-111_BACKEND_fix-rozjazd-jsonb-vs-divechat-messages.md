# CHAT-T-111 — BACKEND: koniec rozjazdu transkryptu (panel „brak treści" mimo wiadomości w bazie)

**Instancja:** backend (PHP, standalone `chat.divezone.pl`).
**Powiązane:** ADR-102 (panel recenzji), CHAT-T-104/106 (ConversationReviewRepository), CHAT-T-059 (resume historii w widgecie), CHAT-T-107 (zrywanie Railway). Incydent danych 2026-06-29 (21 rozmów „(brak treści)" — diagnoza poniżej). Pamięć: `dual-write-jsonb-bug`.
**Status:** READY-FOR-CC — Karol wybrał **wariant A** (2026-06-29): panel admina przepięty na `divechat_messages` jako źródło prawdy; `jsonb messages` zostaje wyłącznie do resume widgetu (CHAT-T-059). Implementacja wg sekcji „IMPLEMENTACJA (po wyborze A)".

## PROBLEM
Wiadomości są zapisywane DUAL-WRITE:
- `divechat_messages` (tabela znormalizowana, per-wiadomość) — `ConversationStore::appendMessage` (src/Chat/ConversationStore.php:365),
- `divechat_conversations.messages` (jsonb, w kodzie „legacy") — `ConversationStore::updateConversation` (src/Chat/ConversationStore.php:131-153), zapisuje CAŁĄ historię naraz.

Panel recenzji (lista + podgląd) czyta **wyłącznie jsonb** (`ConversationReviewRepository` l.85-89: `jsonb_array_length(c.messages)`, `first_user_message` z `jsonb_array_elements`). Gdy jsonb jest `[]`, panel pokazuje „(brak treści) / 0 wiad." i „Brak wiadomości w tej rozmowie" — mimo że treść klienta JEST w `divechat_messages`.

**Skala (2026-06-29):** na 498 rozmów 21 miało pusty jsonb; **19 z nich miało realną treść w tabeli** (m.in. nieobsłużone pytanie EN o skrzydło XDEEP ZEOS zadane 3×, voucher, maska na okulary, dostępność Apeks XTX). To ukryte, nieobsłużone leady — wyglądające w panelu jak śmieci.

## HIPOTEZA ŹRÓDŁA (do potwierdzenia w KROK 0)
13 z 14 „pustych" rozmów z realną treścią to rozmowy **user-only — bot nigdy nie odpowiedział**. To wskazuje, że `appendMessage` (user → tabela) leci NATYCHMIAST, a `updateConversation` (pełna historia → jsonb) dopiero PO udanej odpowiedzi LLM/zakończeniu obsługi. Jeśli obsługa urwie się wcześniej (wyjątek, timeout, zrywanie Railway z CHAT-T-107, klient zamknął kartę w trakcie SSE) → `updateConversation` nigdy nie biegnie → jsonb zostaje `[]`, choć user-message już jest w tabeli.

Jeśli tak — to NIE jest „zgubiony pojedynczy zapis przy padzie", tylko **strukturalny rozjazd: jsonb aktualizowany jednorazowo na końcu, więc każda urwana rozmowa = pusty jsonb w panelu**. (Retry połączenia z CHAT-T-107 tego NIE naprawia — to kwestia kolejności/punktów zapisu, nie samego połączenia.)

## KROK 0 — diagnoza (potwierdź hipotezę)
1. Prześledź w `ChatService::handle` kolejność wywołań: kiedy `appendMessage('user', …)`, kiedy `appendMessage('assistant', …)`, kiedy `updateConversation(messages=…)`. Ustal, czy `updateConversation` jest wołane TYLKO po sukcesie LLM (a więc pomijane przy każdym wcześniejszym throwie/urwaniu).
2. Potwierdź na danych (read-only): czy wszystkie rozmowy z `jsonb=[]` mają `assistant`-rows = 0 w `divechat_messages` (tj. rozjazd występuje wyłącznie tam, gdzie bot nie dokończył). Jeśli są też przypadki z pełną odpowiedzią a pustym jsonb → druga ścieżka błędu (zbadać osobno).
3. Sprawdź, czy `divechat_messages` jest spójnie wypełniane dla WSZYSTKICH rozmów (czy to wiarygodne źródło prawdy do oparcia panelu).

## KIERUNEK FIKSU — DECYZJA KAROLA (A / B / hybryda)
**Uwaga — `jsonb messages` NIE jest tylko dla panelu:** czyta go też resume widgetu (CHAT-T-059) — `ConversationStore::getHistory`/`findActiveBySessionId` (l.324/340) i odpowiedź `/api/chat/history`. Nie można go ot tak wygasić bez ogarnięcia resume.

- **Wariant A (rekomendowany — hybryda ról):** panel admina (`ConversationReviewRepository` lista + podgląd, `ConversationStore::getConversationDetail`) przepiąć na **`divechat_messages` jako źródło prawdy** (pełny, niezawodny widok per-wiadomość: `message_count`, `first_user_message`, przebieg). `jsonb messages` ZOSTAJE wyłącznie do resume widgetu (działa, gdy rozmowa kompletna; urwane i tak nie wracają do resume). Czyste rozdzielenie: tabela = prawda dla admina, jsonb = cache resume dla widgetu.
- **Wariant B:** utrzymać dual-write, ale zapisywać user-message do jsonb OD RAZU (append, nie jednorazowy zapis na końcu) + idempotentnie dokładać assistant. Mniej zmian w odczytach, ale rozjazd nadal możliwy (dwa źródła do utrzymania w synchro).
- Rekomendacja: **A** — znormalizowana tabela już istnieje i jest spójna; eliminuje całą klasę „panel kłamie". `jsonb` schodzi do jednej, wąskiej roli (resume), gdzie pusty = brak czego wznawiać (poprawne).

## IMPLEMENTACJA (po wyborze A)
1. `ConversationReviewRepository::listByStatus`/`countsByStatus`-sąsiedztwo: `message_count` i `first_user_message` liczyć z `divechat_messages` (role IN ('user','assistant'); pierwszy `user` po `id`/`created_at`). Filtry/paginacja/sort bez zmian.
2. Podgląd rozmowy admina (`getConversationDetail`, l.262 i okolice / ewentualny `ConversationViewer`): „Przebieg rozmowy" budować z `divechat_messages` (user/assistant; `tool` opcjonalnie zwijane).
3. Resume widgetu (`/api/chat/history`, `findActiveBySessionId`, `getHistory`): BEZ zmian (dalej jsonb) — albo, jeśli przy okazji ujednolicamy, też z tabeli; decyzja w ramach A (mniejsze ryzyko: nie ruszać resume w tym tasku).
4. Komentarze/docblock: zaktualizować „legacy jsonb" → jasna rola (resume-cache).

## KROK testy
- Unit/integration: rozmowa user-only (bot nie odpowiedział) → panel pokazuje treść + `message_count`≥1 + `first_user_message` (dziś pokazywałby „brak treści").
- Rozmowa kompletna → panel jak dotąd (regresja CHAT-T-106 counts + CHAT-T-104 repo muszą przejść).
- Resume widgetu (CHAT-T-059) niezmieniony — historia kompletnej rozmowy wraca.

## KRYTERIA AKCEPTACJI
- [ ] Hipoteza źródła potwierdzona/obalona danymi (KROK 0).
- [ ] Panel NIE pokazuje „(brak treści)" dla rozmów mających wiadomości w `divechat_messages`.
- [ ] `message_count`/`first_user_message`/przebieg z wiarygodnego źródła (tabela wg A).
- [ ] Resume widgetu (CHAT-T-059) działa bez regresji.
- [ ] Regresja CHAT-T-104 (30/30) + CHAT-T-106 (11/11 counts) OK. php -l. STOP przed deploy (ADR-089), smoke panelu.

## NOTATKI / POZA ZAKRESEM
- **Backfill już wykonany 2026-06-29** (NIE powtarzać): 14 rozmów z realną treścią uzupełnione w jsonb, 7 śmieci (2 testowe + 5 trywialnych) skasowane. Backup: `_backups/empty_conversations_2026-06-29.json`. Po fiksie A backfill przestaje być potrzebny (panel i tak czyta tabelę).
- Retry/odporność połączenia = CHAT-T-107 (osobny problem — to jest kolejność zapisu, nie połączenie).
- Follow-up biznesowy (NIE kod): nieobsłużony klient EN (XDEEP ZEOS 38 PRO, 2026-06-23, pytał 3×) — ewentualny kontakt ze strony Karola.
