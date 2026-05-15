-- ============================================
-- DIVEZONE CHAT AI - Migracja 011
-- Editorial Picks — manualny boost rankingu produktów
-- Data: 2026-05-14
-- ADR: ADR-054
-- TASK: T-008
--
-- Tabela divechat_editorial_picks z TTL i UNIQUE(product_id, category_hint).
-- Boost factor 1.0-2.5 aplikowany w ProductSearch::mergeRRF() po RRF fusion:
--   final_score = base_rrf_score * boost_factor
-- Aktywne picki: active=TRUE AND (expires_at IS NULL OR expires_at > NOW()).
-- Auto-expire: cron godzinowy uruchamia EditorialPicksService::expireDue().
--
-- Idempotentna: CREATE TABLE IF NOT EXISTS, CREATE INDEX IF NOT EXISTS.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_editorial_picks (
    id SERIAL PRIMARY KEY,
    product_id INTEGER NOT NULL,
    product_name TEXT NOT NULL,
    category_hint TEXT,
    boost_factor NUMERIC(3,2) NOT NULL DEFAULT 1.5 CHECK (boost_factor BETWEEN 1.0 AND 2.5),
    reason TEXT NOT NULL,
    added_by TEXT NOT NULL,
    added_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    expires_at TIMESTAMPTZ,
    last_review_at TIMESTAMPTZ,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    UNIQUE(product_id, category_hint)
);

CREATE INDEX IF NOT EXISTS idx_editorial_picks_active_expires
    ON divechat_editorial_picks(active, expires_at) WHERE active = TRUE;

CREATE INDEX IF NOT EXISTS idx_editorial_picks_product
    ON divechat_editorial_picks(product_id) WHERE active = TRUE;

COMMENT ON TABLE divechat_editorial_picks IS
    'Editorial Picks (ADR-054): manualny boost rankingu produktów. boost_factor mnoży RRF score w ProductSearch.';
COMMENT ON COLUMN divechat_editorial_picks.category_hint IS
    'NULL = boost we wszystkich kategoriach. Wartość = boost tylko gdy klient pyta o tę kategorię.';
COMMENT ON COLUMN divechat_editorial_picks.expires_at IS
    'NULL = bezterminowo. Cron godzinowy ustawia active=FALSE gdy expires_at < NOW().';
COMMENT ON COLUMN divechat_editorial_picks.last_review_at IS
    'Ostatni "Mark as reviewed" w panelu admina. NULL = nigdy nie przejrzane.';
