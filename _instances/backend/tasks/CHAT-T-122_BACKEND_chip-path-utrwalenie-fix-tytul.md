# CHAT-T-122 — Backend: migracja `chip_path` + utrwalenie ścieżki + fix `first_user_message` w panelu

**Instancja:** backend | **Powiązane:** ADR-110, ADR-097, CHAT-T-121 (front dosyła `chip_path`), CHAT-T-123 (panel render). **Kontrakt:** `_instances/frontend/handoff/HANDOFF_chip_path_kontrakt.md`. **Decyzje:** 8b, 9a, fix tytułu.

## Kontekst
Front (CHAT-T-121) dosyła strukturalną ścieżkę chipów `chip_path` (tablica `{node_key,label,level}`) obok istniejącego `chip_context` (string, bez zmian). Backend utrwala ją w nowej kolumnie `divechat_conversations.chip_path jsonb`, raz na rozmowę (pierwsza wiadomość). Osobno: panel recenzji pokazuje zły tytuł, bo `first_user_message` bierze etykietę przycisku chipu z `messages[0]` — trzeba pominąć znane labele `target:ai`.

## Zakres
1. **Migracja PG:** `ALTER TABLE divechat_conversations ADD COLUMN chip_path jsonb;` (nullable, bez defaultu). Skrypt w `sql/` wg konwencji projektu (sprawdź istniejące migracje). STOP przed uruchomieniem na produkcji.
2. **`ChatController::resolveChipPath(mixed): ?array`** — walidacja wg handoffu §Kontrakt backendu: tablica; każdy element `node_key` (string `^[a-z0-9_]+$`, cap 64), `label` (string cap 120), `level` (int 1..6). Zły element pomijany, złe pole → `null`. Cap długości tablicy 8. Analogiczne do istniejącego `resolveChipContext`.
3. **Utrwalenie w `ConversationStore`/`ChatService`:** przy INSERT nowej rozmowy (lub pierwszej wiadomości, gdy `chip_path` rozmowy NULL) zapisz zwalidowaną ścieżkę do kolumny jsonb. Idempotent: na kolejnych turach NIE nadpisuj (warunek `chip_path IS NULL`). `chip_context` (string) — bez zmian, dalej tylko system prompt tej tury.
4. **Fix `first_user_message` — DYNAMICZNA lista (korekta 13a, decyzja 18a):** wykluczenie etykiet przycisków `target:ai` z pierwszej wiadomości user. Lista liczona DYNAMICZNIE z drzewa, NIE stała w kodzie. Powód: labele `target:ai` będą się zmieniać z rozwojem drzewa; stała lista (obecna klasa `ChipButtonLabels`) rozjeżdża się cicho przy każdej zmianie seeda. Historyczne rozmowy odpuszczamy (znikną z bieżącego widoku) — liczy się przyszłość.
   - **USUŃ klasę `DiveChat\Chip\ChipButtonLabels`** (stała lista) i jej test — zastąpiona zapytaniem.
   - **Jeden SELECT na żądanie panelu (18a):** `SELECT DISTINCT btn->>'label' FROM divechat_chip_nodes, jsonb_array_elements(buttons) btn WHERE btn->>'target'='ai' AND btn->>'label' IS NOT NULL`. Wynik pobrany RAZ na żądanie, trzymany w zmiennej/property, wstrzykiwany do każdego z 4 zapytań listujących jako parametr `AND m->>'content' <> ALL($labels)` (lub `NOT (m->>'content' = ANY($labels))`). NIE liczyć w pętli per wiersz, NIE podzapytanie skorelowane per wiersz.
   - **4 miejsca:** `ConversationReviewRepository::listByStatus`, `::listNewInbox`, `ConversationStore::list`, `CostAnalytics::topConversations`. Ta sama lista do wszystkich.
   - Gdy lista pusta (brak przycisków ai) → warunek pomijany (nie generuj `<> ALL('{}')`). Zachowaj sort `ORDER BY m.created_at, m.id`.
   - Round-trip: zapytanie panelu i tak uderza w PG (rozmowy tam są) — dodatkowy lekki SELECT labeli nie tworzy nowej zależności od Railway. Opcjonalny cache w pamięci procesu na czas żądania (nie międzyżądaniowy).
5. **STOP** przed migracją (pkt 1) i przed rsync deploy (ADR-089).

## Uwaga
- `chip_path` rozłączny z `chip_context` — nie mieszać. Utrwalamy TYLKO strukturalną ścieżkę.
- Zapis jsonb: `serialize_precision` bez znaczenia (int/string), ale trzymać poprawny json encode (bez zbędnych escapów unicode dla polskich znaków — sprawdź jak reszta jsonb jest kodowana).
- Fix tytułu chroni STARE rozmowy bez pisania do bazy (zero migracji danych `messages`).

## Definicja ukończenia
- Kolumna `chip_path` istnieje; nowa rozmowa przez chip ma utrwaloną ścieżkę; wolne pisanie → NULL.
- Panel `first_user_message` pomija labele `target:ai` z aktualnego drzewa (lista dynamiczna); nowe rozmowy pokazują realny tytuł. Historyczne rozmowy z wycofanymi labelami — świadomie poza zakresem.
- Smoke `/api/health` OK po deploy.

## Wynik (backend, 2026-07-01)
**Status: KOD GOTOWY — czeka na migrację PG + rsync (oba STOP-gated dla Karola).**

Zaimplementowano (commit `CHAT-T-122 backend: ...`):
1. **Migracja PG** — `sql/039_conversation_chip_path.sql` (+ rollback): `ADD COLUMN IF NOT EXISTS chip_path jsonb` (nullable, bez defaultu) + partial GIN index `idx_conv_chip_path WHERE chip_path IS NOT NULL` (pod statystyki klikalności) + COMMENT. **NIE uruchomiona** (STOP).
2. **`ChatController::resolveChipPath(mixed): ?array`** — walidacja wg kontraktu: tablica; element `node_key` (`^[a-z0-9_]{1,64}$`), `label` (cap 120, trim, polskie znaki OK), `level` (int lub numeryczny string, 1..6). Zły element pomijany; cap tablicy 8; pusto po walidacji → `null`. Wpięte w `handle()` i `stream()` (nowe pole body `chip_path`, osobne od `chip_context`).
3. **Utrwalenie w `ConversationStore::startOrResume(..., ?array $chipPath)`** — INSERT nowej rozmowy zapisuje `chip_path` (`json_encode` + `JSON_UNESCAPED_UNICODE`, `?::jsonb`); na ścieżce resume `persistChipPathIfEmpty()` robi `UPDATE ... WHERE chip_path IS NULL` (idempotent). `ChatService::handle` przekazuje `$chipPath` dalej. `chip_context` bez zmian.
4. **Fix `first_user_message`** — nowa klasa `DiveChat\Chip\ChipButtonLabels` (stała lista 3 etykiet target:ai ze seeda: „Napisz czego szukasz", „Inne pytanie", „Koszty i metody dostawy" + `notInSql()`). Wpięta w 4 zapytania: `ConversationReviewRepository::listByStatus` + `listNewInbox` (jsonb), `ConversationStore::list` + `CostAnalytics::topConversations` (tabela `divechat_messages`). Stała, NIE odczyt z `divechat_chip_nodes` — seed te przyciski usuwa (ADR-110 pkt 2), a fix chroni STARE rozmowy, zero round-tripu (CHAT-T-113).
5. **Testy:** `tests/Chip/ChipButtonLabelsTest.php` (7/7), `tests/Controller/ChatControllerChipPathTest.php` (15/15, reflection), regresja `tests/Admin/ConversationReviewRepositoryTest.php` real-Railway (35/35). `php -l` czysty na 6 plikach.

**KOLEJNOŚĆ WDROŻENIA (ważne):** migracja 039 MUSI iść PRZED rsync — kod INSERT-uje kolumnę `chip_path`, bez migracji każdy nowy INSERT rozmowy padnie. Po migracji: rsync `standalone/src` + smoke `/api/health`.

## KOREKTA pkt 4 — DO POPRAWY przed wdrożeniem (decyzja 18a, korekta 13a)
Sekcja „Wynik" powyżej opisuje PIERWSZĄ implementację (stała lista `ChipButtonLabels`). Karol rozstrzygnął: **historyczne rozmowy odpuszczamy, priorytet = odporność na przyszłe zmiany labeli.** Obowiązuje pkt 4 w §Zakres (DYNAMICZNA lista). Do zrobienia:
- USUŃ `DiveChat\Chip\ChipButtonLabels` + `tests/Chip/ChipButtonLabelsTest.php`.
- Zastąp dynamicznym `SELECT DISTINCT` labeli `target:ai` z `divechat_chip_nodes`, pobranym RAZ na żądanie, wstrzykniętym jako `<> ALL($labels)` do 4 zapytań (18a).
- Pusta lista → warunek pomijany. Zaktualizuj testy pod dynamiczne źródło.
- NIE wdrażać (039 + rsync) przed tą poprawką (STOP 17b).

## Wynik korekty 18a (backend, 2026-07-02)
**Status: KOD GOTOWY (korekta wdrożona w kodzie) — nadal STOP przed migracją 039 + rsync.**

Zrealizowano (commit `CHAT-T-122 backend: dynamiczna lista labeli target:ai ...`):
- **USUNIĘTO** `DiveChat\Chip\ChipButtonLabels` + `tests/Chip/ChipButtonLabelsTest.php` (stała lista).
- **NOWA klasa `DiveChat\Chip\ChipAiLabelProvider`** — `fetchLabels(PostgresConnection): list<string>` (`SELECT DISTINCT btn->>'label' … WHERE btn->>'target'='ai'`, odporne na `buttons` NULL/nie-tablicę przez `jsonb_typeof(buttons)='array'` w podzapytaniu) + `toPgTextArray(list): string` (literal PG `text[]` z eskejpem `\`/`"`, odporny na przecinki/polskie znaki).
- **4 zapytania** pobierają listę RAZ na żądanie i wstrzykują jako `AND …content <> ALL(?::text[])`; **pusta lista → warunek pomijany**; parametr labeli `array_unshift` na początek (placeholder podzapytania jest pierwszy przed WHERE/LIMIT). Miejsca: `ConversationReviewRepository::listByStatus` + `::listNewInbox` (jsonb `m->>'content'`, wspólny per-request cache `targetAiLabels()`), `ConversationStore::list` + `CostAnalytics::topConversations` (tabela `m.content`). Sort `ORDER BY m.created_at, m.id` zachowany.
- **Punkty 1–3 (chip_path: migracja 039, `resolveChipPath`, utrwalenie w `startOrResume`) BEZ ZMIAN.**
- **Testy:** `tests/Chip/ChipAiLabelProviderTest.php` (10/10 — serializacja PG-array czysta + `fetchLabels` real-Railway == DISTINCT z drzewa), `tests/Controller/ChatControllerChipPathTest.php` (15/15), regresja `tests/Admin/ConversationReviewRepositoryTest.php` real-Railway (35/35 — ćwiczy `<> ALL(?::text[])` w obu listach), smoke `ConversationStore::list` (508 rozmów, tytuł realny) + `CostAnalytics::topConversations` (5 wierszy, tytuł realny). `php -l` czysty.
- **Obserwacja z produkcji:** aktualne drzewo ma TYLKO 2 labele target:ai (`"Inne pytanie"`, `"Koszty i metody dostawy"`) — `"Napisz czego szukasz"` już usunięte z liści (ADR-110 pkt 2). Potwierdza sens korekty: stała lista z pierwszej implementacji już była nieaktualna.

**Kolejność wdrożenia bez zmian:** migracja 039 PRZED rsync (kod INSERT-uje kolumnę `chip_path`). Oba STOP-gated dla Karola.
