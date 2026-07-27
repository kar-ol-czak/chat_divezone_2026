-- ============================================
-- DIVEZONE CHAT AI - Migracja 019
-- Popularnosc sprzedazy produktow (Subiekt 12mc) — ADR-065 uzup.4
-- Data: 2026-06-01
-- TASK: T-035 CZESC A
--
-- Tabela divechat_product_sales_popularity trzyma agregat sprzedazy per
-- product_id (PrestaShop pr_product.id_product) za ostatnie 12 miesiecy
-- z Subiekta. Granularnosc per product_id (agregacja wszystkich wariantow/
-- SKU danego produktu, zgodnie z decyzja 39a / wzorcem T-031).
--
-- Docelowo zasilana przez pomost Subiekt -> czat (decyzja 173, mechanizm
-- odlozony); teraz jednorazowy seed z reports/sales_subiekt_12mcy.csv (T-035,
-- decyzja 42a). Mapowanie SKU -> product_id przez pr_product.reference +
-- fallback pr_product_attribute.reference (regula z T-031).
--
-- WAZNE (decyzja 38b): qty_12m + net_value_12m sa WRAZLIWE — panel obslugi
-- czatu NIGDY nie pokazuje surowych liczb, tylko wskaznik wzgledny w obrebie
-- kategorii rekomendacji (decyzja 41a). Dane trzymane w backendzie nie
-- opuszczaja go w surowej postaci.
--
-- Idempotentna: CREATE TABLE IF NOT EXISTS; UPSERT przy seedzie.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_product_sales_popularity (
    product_id      INTEGER PRIMARY KEY,
    qty_12m         INTEGER NOT NULL,
    net_value_12m   NUMERIC(12, 2),
    period_label    VARCHAR(32),
    source          VARCHAR(32) NOT NULL DEFAULT 'subiekt_csv',
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT qty_non_negative      CHECK (qty_12m >= 0),
    CONSTRAINT net_value_non_negative CHECK (net_value_12m IS NULL OR net_value_12m >= 0)
);

COMMENT ON TABLE divechat_product_sales_popularity IS
    'ADR-065 uzup.4 / T-035: agregat sprzedazy per product_id za 12mc. Zrodlo docelowe: pomost Subiekt (decyzja 173, odlozony). Teraz jednorazowy seed z CSV (T-035, decyzja 42a). qty_12m + net_value_12m WRAZLIWE — panel pokazuje tylko wskaznik wzgledny w kategorii (decyzja 38b/41a).';
COMMENT ON COLUMN divechat_product_sales_popularity.product_id IS
    'FK logiczna do pr_product.id_product (PrestaShop MySQL). Cross-db FK nieuzywane — walidacja istnienia po stronie aplikacji (regula T-031: pr_product.reference + fallback pr_product_attribute.reference).';
COMMENT ON COLUMN divechat_product_sales_popularity.qty_12m IS
    'Suma sztuk sprzedanych za ostatnich 12 miesiecy (agregat wszystkich wariantow/SKU danego produktu, granularnosc 39a). WRAZLIWE — nie eksponowac w API panelu.';
COMMENT ON COLUMN divechat_product_sales_popularity.net_value_12m IS
    'Wartosc netto sprzedazy za 12mc. WRAZLIWE — panel pokazuje tylko wskaznik wzgledny w kategorii (38b/41a), nigdy surowych liczb.';
COMMENT ON COLUMN divechat_product_sales_popularity.period_label IS
    'Etykieta okresu (np. "2025-06..2026-05" lub data eksportu CSV). Dla seedu 2026-06-01 = etykieta z CSV pliku zrodlowego.';
COMMENT ON COLUMN divechat_product_sales_popularity.source IS
    'Zrodlo danych: "subiekt_csv" dla seedu jednorazowego (T-035) lub "subiekt_bridge" dla docelowego pomostu (po realizacji decyzji 173).';

-- ============================================
-- Indeks: szybkie sortowanie po qty (dla wyznaczania bucketow popularnosci w kategorii)
-- ============================================
CREATE INDEX IF NOT EXISTS idx_popularity_qty
    ON divechat_product_sales_popularity(qty_12m DESC);

-- ============================================
-- BRAK seed product_id w tym pliku — patrz T-035 CZESC A KROK 2.
-- Seed dodawany po akceptacji Karola w osobnym pliku lub inline UPSERT-em.
-- ============================================
