-- ============================================
-- DIVEZONE CHAT AI - Migracja 025
-- Tabela zdarzen ekspozycji/klikow zachety (CHAT-T-082, ADR-090 faza 2)
-- Data: 2026-06-08
--
-- Pomiar CTR dla wariantow nudge (v1 dymek vs v2 karta) — sekcja 4 spec
-- _docs/22_spec_ab_ctr_nudge.md. Front (CHAT-T-083) wysyla beacon przy
-- 'nudge_shown' (render) i 'nudge_cta_click' (otwarcie czatu).
--
-- Dedup: UNIQUE (session_id, event_type) — front gwarantuje raz/sesje,
-- backend defensywnie ON CONFLICT DO NOTHING (przy retry beacona, podwojnym
-- renderze, race przy sendBeacon vs fetch keepalive).
--
-- bucket = 'v1'|'v2' (przydzial A/B sticky w localStorage 'dz_ab_bucket'
-- lub aktualny variant z panelu gdy A/B OFF — sekcja 6 spec).
-- ab_active = czy A/B byl wlaczony w momencie zdarzenia (rozdziela ruch
-- testowy od baseline CTR przy zwyklym przelaczniku panelu).
--
-- session_id = UUID v4 generowany przez front w momencie 'nudge_shown'
-- (decyzja 247a — client-supplied sessionId przez cala sciezke ekspozycja
-- → klik → rozmowa). JOIN z divechat_conversations.session_id daje
-- konwersje do rozmowy (sekcja 7 spec, panel CHAT-T-084).
--
-- Indeksy:
--  - (bucket, event_type, created_at) — agregacje panelu CTR per bucket.
--  - (session_id) — JOIN z rozmowami dla wskaznika konwersji.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_nudge_events (
    id          BIGSERIAL   PRIMARY KEY,
    session_id  TEXT        NOT NULL,
    event_type  TEXT        NOT NULL,
    bucket      TEXT        NOT NULL,
    ab_active   BOOLEAN     NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT divechat_nudge_events_event_type_chk
        CHECK (event_type IN ('nudge_shown', 'nudge_cta_click')),
    CONSTRAINT divechat_nudge_events_bucket_chk
        CHECK (bucket IN ('v1', 'v2')),
    CONSTRAINT divechat_nudge_events_session_event_uq
        UNIQUE (session_id, event_type)
);

CREATE INDEX IF NOT EXISTS idx_nudge_events_bucket_type_ts
    ON divechat_nudge_events (bucket, event_type, created_at);

CREATE INDEX IF NOT EXISTS idx_nudge_events_session
    ON divechat_nudge_events (session_id);

COMMENT ON TABLE divechat_nudge_events IS
    'CHAT-T-082 / ADR-090 faza 2: zdarzenia ekspozycji i klikow zachety (CTR per bucket v1/v2).';
COMMENT ON COLUMN divechat_nudge_events.session_id IS
    'UUID v4 z frontu (sticky przez cala sciezke). JOIN z divechat_conversations.session_id = konwersja do rozmowy.';
COMMENT ON COLUMN divechat_nudge_events.event_type IS
    '''nudge_shown'' (render karty/dymka) lub ''nudge_cta_click'' (otwarcie czatu z CTA).';
COMMENT ON COLUMN divechat_nudge_events.bucket IS
    'Wariant w momencie zdarzenia: ''v1'' (dymek) lub ''v2'' (karta).';
COMMENT ON COLUMN divechat_nudge_events.ab_active IS
    'true gdy A/B wlaczony (bucket z localStorage); false gdy obowiazuje variant z panelu.';
