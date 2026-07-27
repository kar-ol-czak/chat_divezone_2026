-- ============================================
-- DIVEZONE CHAT AI - Migracja 022
-- Tabela licznikow rate-limitu per zrodlo (CHAT-T-066, ADR-064/082)
-- Data: 2026-06-03
--
-- Sliding window licznikowy w PostgreSQL: PK=key ('sess:{sessionId}'
-- lub 'ip:{ip}'), window_start = poczatek aktualnego okna, count =
-- liczba requestow w oknie. Atomowy UPSERT (INSERT ON CONFLICT DO
-- UPDATE) z warunkiem reset/inkrement — race-safe przy rownoleglych
-- requestach (analogicznie do CostGuard alert idempotency, T-064).
--
-- Indeks na window_start dla potencjalnego cleanupu starych wpisow
-- (DELETE WHERE window_start < now() - interval). Tabela mala
-- (1 wiersz / aktywny visitor) — cleanup opcjonalny.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_rate_limit (
    key          TEXT        PRIMARY KEY,
    window_start TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    count        INTEGER     NOT NULL DEFAULT 1
);

CREATE INDEX IF NOT EXISTS idx_rate_limit_window
    ON divechat_rate_limit (window_start);

COMMENT ON TABLE divechat_rate_limit IS
    'CHAT-T-066: liczniki rate-limit per sessionId i per IP (sliding window). Race-safe UPSERT.';
COMMENT ON COLUMN divechat_rate_limit.key IS
    '"sess:{sessionId}" lub "ip:{ip}" — jeden wiersz per aktywny visitor.';
COMMENT ON COLUMN divechat_rate_limit.window_start IS
    'Poczatek okna; jesli starszy niz windowSec -> reset (count=1, window_start=NOW).';
