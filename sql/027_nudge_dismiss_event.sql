-- ============================================
-- DIVEZONE CHAT AI - Migracja 027
-- Rozszerzenie CHECK divechat_nudge_events.event_type o 'nudge_dismiss' (CHAT-T-086)
-- Data: 2026-06-08
--
-- Trzecie zdarzenie ekspozycji: klik X na nudge (świadome odrzucenie).
-- Bez tego rozjazd "świadomie odrzucił" vs "zignorował" jest niemierzalny —
-- panel CHAT-T-084 potrzebuje rozdzielić te dwa scenariusze (sekcja 1 spec
-- _docs/22_spec_ab_ctr_nudge.md, decyzje 256b/257a).
--
-- PostgreSQL nie pozwala na ALTER CONSTRAINT CHECK in-place — trzeba DROP + ADD.
-- Operacja jest natychmiastowa (sprawdzenie istniejących wierszy: nie ma jeszcze
-- nudge_dismiss, więc dodanie wartości do whitelisty CHECK przejdzie bez skanu).
--
-- UNIQUE (session_id, event_type) ZOSTAJE bez zmian — dismiss to trzeci typ,
-- dedup "jeden dismiss per sesja" działa automatycznie z istniejącego UNIQUE.
-- Indeksy (bucket, event_type, created_at) i (session_id) też bez zmian.
-- ============================================

ALTER TABLE divechat_nudge_events
    DROP CONSTRAINT IF EXISTS divechat_nudge_events_event_type_chk;

ALTER TABLE divechat_nudge_events
    ADD CONSTRAINT divechat_nudge_events_event_type_chk
    CHECK (event_type IN ('nudge_shown', 'nudge_cta_click', 'nudge_dismiss'));

COMMENT ON COLUMN divechat_nudge_events.event_type IS
    '''nudge_shown'' (render karty/dymka), ''nudge_cta_click'' (otwarcie czatu z CTA), ''nudge_dismiss'' (CHAT-T-086: klik X = swiadome odrzucenie).';
