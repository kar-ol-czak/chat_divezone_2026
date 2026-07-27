-- ============================================
-- DIVEZONE CHAT AI - Migracja 023
-- Sesje mobilnego panelu admina (CHAT-T-071, ADR-086)
-- Data: 2026-06-05
--
-- Tabela divechat_mobile_sessions — sesje PRACOWNIKA mobilnego widoku
-- (logowanie email + haslo PS). NIE myl z divechat_conversations.session_id,
-- ktore jest sesja ROZMOWY klienta czatu (orthogonal).
--
-- Token: bin2hex(random_bytes(32)) = 64 znaki hex, generowany w PHP.
-- Sliding TTL 12h: kazdy validate() przesuwa expires_at o 12h od NOW().
-- Brak ON DELETE CASCADE — employee_id to FK semantyczny do MySQL PS
-- (pr_employee), nie do PG. Wpis pozostaje do recznego logoutu lub
-- wygasniecia; cleanup expired wpisow opcjonalnie z crona.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_mobile_sessions (
    session_token VARCHAR(128) PRIMARY KEY,
    employee_id   INTEGER      NOT NULL,
    role          VARCHAR(32)  NOT NULL,
    created_at    TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
    expires_at    TIMESTAMPTZ  NOT NULL,
    last_seen_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_mobile_sessions_employee
    ON divechat_mobile_sessions (employee_id);

CREATE INDEX IF NOT EXISTS idx_mobile_sessions_expires
    ON divechat_mobile_sessions (expires_at);

COMMENT ON TABLE divechat_mobile_sessions IS
    'CHAT-T-071 (ADR-086): sesje pracownika mobilnego widoku admina. Server-side, cookie HttpOnly.';
COMMENT ON COLUMN divechat_mobile_sessions.session_token IS
    'bin2hex(random_bytes(32)) — 64 znaki hex. Wartosc cookie dz_madmin.';
COMMENT ON COLUMN divechat_mobile_sessions.employee_id IS
    'pr_employee.id_employee z MySQL PS (semantyczny FK, nie egzekwowany w PG).';
COMMENT ON COLUMN divechat_mobile_sessions.role IS
    'Snapshot roli z divechat_admin_roles przy logowaniu (admin|operator).';
COMMENT ON COLUMN divechat_mobile_sessions.expires_at IS
    'Sliding TTL — kazdy validate() przesuwa o 12h od NOW().';
