-- ============================================
-- ROLLBACK Migracji 042 (CHAT-T-148, ADR-126 + nota nr 1)
-- Data: 2026-07-17
--
-- Przywraca knowledge_gap = true dla zbioru objetego migracja (scope 191: rozmowy
-- z PELNA diagnostyka — te same warunki WHERE co migracja forward). Odtwarza STAN
-- ZASTANY, NIE stan "poprawny": przed migracja 042 wszystkie rozmowy z torem
-- search_products mialy knowledge_gap=true przez blad skali (prog 0,5 na rrf_score).
--
-- DOKLADNOSC — UWAGA (dowod z pomiaru 2026-07-17, korekta zalozenia z ADR nota nr 1):
-- scope 191 NIE byl w calosci true przed migracja — 31 rozmow bylo JUZ false
-- (encyklopedia-only, prog cosine 0,5 dzialal poprawnie). Migracja ich nie zmienia
-- (zostaja false). Ten UPDATE ustawia CALY scope na true, wiec OVER-RESTORE'uje te
-- 31 na true. Rule-based rollback nie odroznia ich od 143 faktycznie zmienionych
-- (po migracji oba zbiory maja knowledge_gap=false i identyczny "odcisk" reguly).
-- Kierunek bledu jest bezpieczny dla narzedzia recenzji (falszywy alarm = jedno
-- klikniecie; zgaszona luka = niewidzialna) — ale to NIE jest odtworzenie 1:1.
--
-- AUTORYTATYWNY ROLLBACK = pg_dump tabeli divechat_conversations wykonany w KROK 4.1
-- taska CHAT-T-148 (przed migracja). Jesli masz dump — uzyj dumpa. Ten plik to
-- awaryjne przyblizenie.
--
-- Zmierzone: scope 191 (przed: 160 true / 31 false); migracja zmienila 143 true→false,
-- 0 false→true. Ten rollback dotknie 191 wierszy (143 poprawnie→true, 17 no-op juz true,
-- 31 over-restore false→true).
-- ============================================

BEGIN;

UPDATE divechat_conversations c
SET knowledge_gap = true
WHERE c.search_diagnostics IS NOT NULL
  AND jsonb_array_length(c.search_diagnostics) > 0
  AND jsonb_array_length(c.search_diagnostics) = (
      SELECT count(*)
      FROM jsonb_array_elements(c.messages) AS m,
           jsonb_array_elements(COALESCE(m->'tool_calls', '[]'::jsonb)) AS tc
      WHERE tc->>'name' IN ('search_products', 'get_expert_knowledge')
  );

COMMIT;
