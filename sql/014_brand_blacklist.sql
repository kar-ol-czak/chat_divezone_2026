-- ============================================
-- DIVEZONE CHAT AI - Migracja 014
-- Blacklista marek wykluczanych z wyników wyszukiwania
-- Data: 2026-05-26
-- TASK: T-015
--
-- Tabela edytowalna online (bez deploy). ProductSearch filtruje
-- brand_name NOT IN (SELECT ...) we wszystkich 5 torach search.
--
-- Seed: Aquazone (test 15 pracowników — firma zniknęła z rynku,
-- ostatnie sztuki wadliwe, nie polecać).
-- Idempotentna: INSERT ... ON CONFLICT (brand_name) DO NOTHING.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_brand_blacklist (
    id SERIAL PRIMARY KEY,
    brand_name TEXT UNIQUE NOT NULL,
    reason TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO divechat_brand_blacklist (brand_name, reason) VALUES
    ('Aquazone', 'Firma zniknęła z rynku, wyprzedaż ostatnich wadliwych sztuk — nie polecać (test pracowników 15)')
ON CONFLICT (brand_name) DO NOTHING;
