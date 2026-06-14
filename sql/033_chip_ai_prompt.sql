-- ============================================
-- DIVEZONE CHAT AI - Migracja 033
-- Kolumna ai_prompt dla liści drzewa chipów (CHAT-T-088e)
-- Data: 2026-06-14
-- ADR: ADR-096 (model węzła), ADR-097 (kontekst ścieżki dla AI), decyzja 63c (hybryda)
--
-- "Dwa światy": co klient klika (label) ≠ co dostaje AI. Kontekst dla AI =
-- automatyczna ścieżka nawigacji (składana przez front z chipStack) + OPCJONALNY
-- ai_prompt liścia (instrukcja od pracownika, gdy potrzeba czegoś więcej niż sama
-- ścieżka). NULL = AI dostaje samą ścieżkę nawigacji.
--
-- Idempotentna: ADD COLUMN IF NOT EXISTS. Bez seedu (wartości wpisuje pracownik
-- w panelu edycji chipów — D).
-- ============================================

ALTER TABLE divechat_chip_nodes
    ADD COLUMN IF NOT EXISTS ai_prompt TEXT NULL;

COMMENT ON COLUMN divechat_chip_nodes.ai_prompt IS
    'Opcjonalna instrukcja dla AI gdy węzeł jest liściem (target ai) — doklejana do automatycznej ścieżki nawigacji składanej przez front. NULL = sama ścieżka. CHAT-T-088e / decyzja 63c.';
