# CHAT-T-122 — Backend: migracja `chip_path` + utrwalenie ścieżki + fix `first_user_message` w panelu

**Instancja:** backend | **Powiązane:** ADR-110, ADR-097, CHAT-T-121 (front dosyła `chip_path`), CHAT-T-123 (panel render). **Kontrakt:** `_instances/frontend/handoff/HANDOFF_chip_path_kontrakt.md`. **Decyzje:** 8b, 9a, fix tytułu.

## Kontekst
Front (CHAT-T-121) dosyła strukturalną ścieżkę chipów `chip_path` (tablica `{node_key,label,level}`) obok istniejącego `chip_context` (string, bez zmian). Backend utrwala ją w nowej kolumnie `divechat_conversations.chip_path jsonb`, raz na rozmowę (pierwsza wiadomość). Osobno: panel recenzji pokazuje zły tytuł, bo `first_user_message` bierze etykietę przycisku chipu z `messages[0]` — trzeba pominąć znane labele `target:ai`.

## Zakres
1. **Migracja PG:** `ALTER TABLE divechat_conversations ADD COLUMN chip_path jsonb;` (nullable, bez defaultu). Skrypt w `sql/` wg konwencji projektu (sprawdź istniejące migracje). STOP przed uruchomieniem na produkcji.
2. **`ChatController::resolveChipPath(mixed): ?array`** — walidacja wg handoffu §Kontrakt backendu: tablica; każdy element `node_key` (string `^[a-z0-9_]+$`, cap 64), `label` (string cap 120), `level` (int 1..6). Zły element pomijany, złe pole → `null`. Cap długości tablicy 8. Analogiczne do istniejącego `resolveChipContext`.
3. **Utrwalenie w `ConversationStore`/`ChatService`:** przy INSERT nowej rozmowy (lub pierwszej wiadomości, gdy `chip_path` rozmowy NULL) zapisz zwalidowaną ścieżkę do kolumny jsonb. Idempotent: na kolejnych turach NIE nadpisuj (warunek `chip_path IS NULL`). `chip_context` (string) — bez zmian, dalej tylko system prompt tej tury.
4. **Fix `first_user_message` (3 metody w `ConversationReviewRepository`):** podzapytanie `WHERE role='user' LIMIT 1` rozszerz o wykluczenie etykiet przycisków `target:ai`. Minimalnie: `AND m->>'content' NOT IN (<lista labeli target:ai z seeda>)`. Lepiej (rekomendacja): wyklucz przez dopasowanie do zbioru aktualnych labeli — pobierz je raz z `divechat_chip_nodes` (`buttons` gdzie `target='ai'`) albo utrzymaj stałą listę w repo z komentarzem „źródło: seed chipów". Zachowaj sort `ORDER BY m.created_at, m.id` tam gdzie jest. Dotyczy też `ConversationStore.php:211` i `CostAnalytics.php:140` jeśli te tytuły są pokazywane — zweryfikuj i ujednolić.
5. **STOP** przed migracją (pkt 1) i przed rsync deploy (ADR-089).

## Uwaga
- `chip_path` rozłączny z `chip_context` — nie mieszać. Utrwalamy TYLKO strukturalną ścieżkę.
- Zapis jsonb: `serialize_precision` bez znaczenia (int/string), ale trzymać poprawny json encode (bez zbędnych escapów unicode dla polskich znaków — sprawdź jak reszta jsonb jest kodowana).
- Fix tytułu chroni STARE rozmowy bez pisania do bazy (zero migracji danych `messages`).

## Definicja ukończenia
- Kolumna `chip_path` istnieje; nowa rozmowa przez chip ma utrwaloną ścieżkę; wolne pisanie → NULL.
- Panel `first_user_message` pokazuje realną wiadomość, nie „Napisz czego szukasz", też dla starych rozmów.
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
