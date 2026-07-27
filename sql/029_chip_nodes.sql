-- ============================================
-- DIVEZONE CHAT AI - Migracja 029
-- Drzewo chipów (węzły hierarchiczne, węzeł hybrydowy) — fundament (CHAT-T-088)
-- Data: 2026-06-13
-- ADR: ADR-096 (model węzła: jawna hierarchia + treść inline + węzeł hybrydowy)
--
-- Schemat WIĄŻĄCY wg ADR-096 (NIE zmieniać bez nowego ADR):
--  - klucz hybrydowy (25a): id BIGSERIAL PK (stabilne referencje parent_id) +
--    node_key TEXT UNIQUE (czytelny w seedzie/logach/panelu).
--  - jawna hierarchia (29a): parent_id → rodzic (NULL = poziom 1), level, sort_order.
--    Dzieci-podchipy wynikają z parent_id (WHERE parent_id=X), NIE z buttons.
--  - węzeł hybrydowy (32b): bot_text OPCJONALNY ORAZ buttons/dzieci OPCJONALNE —
--    może mieć jedno, drugie lub OBA (np. "Serwis" = tekst + przyciski).
--  - treść inline (18a): bot_text to Markdown, treść statyczna żyje w węźle.
--  - buttons = TYLKO akcje nie-nawigacyjne [{label,target}],
--    target = link:<klucz_configu> | curated:<kat> | modal:<typ> | ai.
--
-- Seed treści (root + gałąź operacyjna) = OSOBNY plik 029_chip_nodes_seed.sql
-- (pytanie otwarte 1: errata treści nie wymaga re-migracji struktury).
--
-- Idempotentna: CREATE TABLE IF NOT EXISTS + CREATE INDEX IF NOT EXISTS.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_chip_nodes (
    id           BIGSERIAL   PRIMARY KEY,
    node_key     TEXT        NOT NULL UNIQUE,
    parent_id    BIGINT      NULL REFERENCES divechat_chip_nodes(id) ON DELETE CASCADE,
    level        INT         NOT NULL DEFAULT 1,
    sort_order   INT         NOT NULL DEFAULT 0,
    bot_text     TEXT        NULL,
    buttons      JSONB       NOT NULL DEFAULT '[]',
    context_hint TEXT        NULL,
    model_level  TEXT        NULL,
    active       BOOLEAN     NOT NULL DEFAULT TRUE,
    updated_at   TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT divechat_chip_nodes_model_level_chk
        CHECK (model_level IS NULL OR model_level IN ('basic', 'primary', 'escalation'))
);

-- Budowa poddrzew: dzieci węzła = WHERE parent_id=X ORDER BY sort_order.
CREATE INDEX IF NOT EXISTS idx_chip_nodes_parent_sort
    ON divechat_chip_nodes (parent_id, sort_order);

-- Filtr aktywnych węzłów (endpoint zwraca tylko active=true).
CREATE INDEX IF NOT EXISTS idx_chip_nodes_active
    ON divechat_chip_nodes (active) WHERE active = TRUE;

COMMENT ON TABLE divechat_chip_nodes IS
    'CHAT-T-088 / ADR-096: drzewo chipów (węzły hierarchiczne, węzeł hybrydowy tekst+dzieci+akcje). Widget pobiera całe aktywne drzewo endpointem GET /api/chip-tree.';
COMMENT ON COLUMN divechat_chip_nodes.node_key IS
    'Czytelny klucz naturalny (np. ''root'',''zwroty'',''serwis''). Referencje przez id (parent_id), nie przez node_key.';
COMMENT ON COLUMN divechat_chip_nodes.parent_id IS
    'Rodzic w drzewie (NULL = poziom 1). Dzieci-podchipy wynikają z parent_id, NIE z buttons. ON DELETE CASCADE — usunięcie rodzica kasuje poddrzewo (panel musi ostrzegać).';
COMMENT ON COLUMN divechat_chip_nodes.bot_text IS
    'Markdown; treść inline (18a). NULL gdy węzeł czysto nawigacyjny. Hybryda (32b): może współistnieć z buttons/dziećmi.';
COMMENT ON COLUMN divechat_chip_nodes.buttons IS
    'Akcje NIE-nawigacyjne: [{label,target}], target = link:<klucz_configu> | curated:<kat> | modal:<typ> | ai. Zejścia do dzieci wynikają z parent_id.';
COMMENT ON COLUMN divechat_chip_nodes.context_hint IS
    'Podpowiedź kontekstu dla liścia ai (ADR-071, na start NULL).';
COMMENT ON COLUMN divechat_chip_nodes.model_level IS
    'Poziom modelu dla routingu (basic|primary|escalation — osobny task). NULL = domyślny.';
