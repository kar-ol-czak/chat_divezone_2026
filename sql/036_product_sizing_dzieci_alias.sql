-- ============================================
-- DIVEZONE CHAT AI - Migracja 036 (CHAT-T-099b, ADR-099)
-- Domkniecie iteracji 1 doboru rozmiarow:
--   (E) rozszerzenie CHECK na divechat_size_charts.gender o 'DZIECI' (chart dzieciecy
--       Rebel — jedna skala wzrostowa, bez rozroznienia plci, decyzja 57a).
--   (C) warstwa aliasow etykiet rozmiarow: divechat_size_label_alias — JEDNO edytowalne
--       miejsce mapujace etykiete wariantu z PrestaShop na etykiete charta (decyzja 45c).
--       Wspolne zrodlo dla chatu i (docelowo) kalkulatora na stronie (ADR-099 pkt 5).
-- Data: 2026-06-18
--
-- HARD STOP (ADR-099): zmiana schematu na Railway PROD — wykonac po akceptacji Karola.
-- Idempotentna: DROP/ADD CONSTRAINT + CREATE TABLE IF NOT EXISTS.
-- ============================================

-- (E) gender: 'M' | 'K' | 'DZIECI' (bylo binarne M/K w migracji 035).
ALTER TABLE divechat_size_charts
    DROP CONSTRAINT IF EXISTS divechat_size_charts_gender_chk;
ALTER TABLE divechat_size_charts
    ADD CONSTRAINT divechat_size_charts_gender_chk
    CHECK (gender IN ('M', 'K', 'DZIECI'));

-- (C) Aliasy etykiet: wariant w PrestaShop != etykieta handlowa charta.
-- alias_label (jak w pr_product_attribute, np. 'M tall') -> canonical_label (jak w
-- divechat_size_chart_rows.size_label, np. 'MT'), w obrebie konkretnego charta.
CREATE TABLE IF NOT EXISTS divechat_size_label_alias (
    chart_id        BIGINT NOT NULL REFERENCES divechat_size_charts(id) ON DELETE CASCADE,
    alias_label     TEXT   NOT NULL,   -- etykieta wariantu z PrestaShop
    canonical_label TEXT   NOT NULL,   -- etykieta charta (size_label)
    note            TEXT   NULL,
    PRIMARY KEY (chart_id, alias_label)
);

COMMENT ON TABLE divechat_size_label_alias IS
    'CHAT-T-099b / ADR-099 (45c): mapa wariant_PrestaShop -> etykieta charta. Jedno edytowalne zrodlo (chat + kalkulator). Docelowa poprawka u zrodla = warstwa 3.';
COMMENT ON COLUMN divechat_size_label_alias.alias_label IS
    'Etykieta jak w pr_product_attribute (np. ''M tall'', ''XXL'', ''L short'').';
COMMENT ON COLUMN divechat_size_label_alias.canonical_label IS
    'Etykieta handlowa charta (divechat_size_chart_rows.size_label), np. ''MT'', ''2XL'', ''LS''.';
