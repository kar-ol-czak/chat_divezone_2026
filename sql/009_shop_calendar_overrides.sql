-- ============================================
-- DIVEZONE CHAT AI - Migracja 009
-- ShopCalendar override'y (urlopy, inwentaryzacje, przedłużone godziny)
-- Data: 2026-05-14
-- ADR: ADR-053 pkt 6
-- TASK: TASK-CHAT-007b
--
-- Idempotentna: CREATE TABLE IF NOT EXISTS, CREATE INDEX IF NOT EXISTS.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_shop_calendar_overrides (
    date DATE PRIMARY KEY,
    reason TEXT NOT NULL,
    is_working_day BOOLEAN NOT NULL,
    opens_at TIME,
    closes_at TIME,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

COMMENT ON TABLE divechat_shop_calendar_overrides IS
    'Override dla ShopCalendar: urlopy, inwentaryzacje, przedłużone godziny przed świętami. Ma pierwszeństwo nad stałą logiką dni roboczych i listą świąt.';

COMMENT ON COLUMN divechat_shop_calendar_overrides.is_working_day IS
    'TRUE jeśli ta data ma być traktowana jako dzień roboczy (nawet jeśli to weekend), FALSE jeśli ma być zamknięte.';

COMMENT ON COLUMN divechat_shop_calendar_overrides.opens_at IS
    'Niestandardowe godziny otwarcia (np. krótszy dzień przed świętami). NULL = użyj domyślnych 09:00.';

COMMENT ON COLUMN divechat_shop_calendar_overrides.closes_at IS
    'Niestandardowe godziny zamknięcia. NULL = użyj domyślnych 17:00.';

-- Przykładowy INSERT (zakomentowany, do ręcznego użycia przez admina):
-- INSERT INTO divechat_shop_calendar_overrides (date, reason, is_working_day, opens_at, closes_at)
-- VALUES ('2026-12-27', 'Urlop firmowy między świętami', FALSE, NULL, NULL);
-- INSERT INTO divechat_shop_calendar_overrides (date, reason, is_working_day, opens_at, closes_at)
-- VALUES ('2026-12-24', 'Wigilia — skrócone godziny', TRUE, '09:00', '13:00');
