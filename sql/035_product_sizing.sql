-- ============================================
-- DIVEZONE CHAT AI - Migracja 035
-- Deterministyczna tabela rozmiarow skafandrow mokrych (CHAT-T-099, ADR-099)
-- Data: 2026-06-18
-- Decyzje: ADR-099 (dane deterministyczne NIE embeddingi + long format + algorytm
--          przedzialowy + function calling), ADR-098 (size_variants dyskryminator).
--
-- UWAGA NA NAZWE INSTANCJI (ADR-099 pkt 1): zadanie realizuje instancja `embeddings`,
-- ale rozmiary NIE sa embeddingami. To deterministyczny ETL do tabel RELACYJNYCH —
-- lookup przez SQL BETWEEN, ZERO wektoryzacji.
--
-- MODEL LONG FORMAT (ADR-099 pkt 2): jeden wiersz = jeden wymiar dla jednego rozmiaru
-- danego charta. Typ wymiaru jest WARTOSCIA w wierszu (dimension), nie kolumna —
-- bo wymiary roznia sie per marka i plec (Bare damskie ma `leg`, meskie nie).
--
-- Trzy tabele:
--  - divechat_size_charts        chart per marka+plec (Scubapro/M, Scubapro/K, Bare/M, Bare/K)
--  - divechat_size_chart_rows    wiersze progow (chart_id, size_label, size_full, dimension, min/max, unit)
--  - divechat_product_size_chart mapowanie produkt->chart (system wspolny per marka)
--
-- To NOWE tabele (nie zmiana istniejacych) -> niskie ryzyko. Mimo to HARD STOP:
-- SQL pokazany Karolowi PRZED wykonaniem na Railway PROD (ADR-099 KROK 1).
-- ============================================

-- Chart per marka + plec. UNIQUE(brand, gender) — jeden chart na marke i plec.
CREATE TABLE IF NOT EXISTS divechat_size_charts (
    id          BIGSERIAL   PRIMARY KEY,
    brand       TEXT        NOT NULL,                 -- 'Scubapro' | 'Bare'
    gender      TEXT        NOT NULL,                 -- 'M' | 'K'
    source      TEXT        NOT NULL,                 -- 'dev_ocr_2026-06' (traceability, zero fabrykacji)
    note        TEXT        NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT divechat_size_charts_gender_chk CHECK (gender IN ('M', 'K')),
    CONSTRAINT divechat_size_charts_uq UNIQUE (brand, gender)
);

-- Wiersze progow (long format). Jeden wiersz = (chart, rozmiar, wymiar).
CREATE TABLE IF NOT EXISTS divechat_size_chart_rows (
    id          BIGSERIAL   PRIMARY KEY,
    chart_id    BIGINT      NOT NULL REFERENCES divechat_size_charts(id) ON DELETE CASCADE,
    size_label  TEXT        NOT NULL,                 -- etykieta handlowa: 'MT' (match z pr_product_attribute)
    size_full   TEXT        NULL,                     -- pelna nazwa z charta: 'MT - 98' (Scubapro); NULL gdy == label (Bare)
    dimension   TEXT        NOT NULL,                 -- 'chest'|'waist'|'hip'|'height'|'weight'|'leg'
    min_val     NUMERIC     NOT NULL,
    max_val     NUMERIC     NOT NULL,
    unit        TEXT        NOT NULL,                 -- 'cm' | 'kg'
    sort_order  INT         NOT NULL DEFAULT 0,
    CONSTRAINT divechat_size_chart_rows_dim_chk
        CHECK (dimension IN ('chest', 'waist', 'hip', 'height', 'weight', 'leg')),
    CONSTRAINT divechat_size_chart_rows_unit_chk CHECK (unit IN ('cm', 'kg')),
    CONSTRAINT divechat_size_chart_rows_range_chk CHECK (max_val >= min_val)
);

-- Lookup po klatce piersiowej (wymiar wiodacy, ADR-099 pkt 4) i po wymiarze ogolnie.
CREATE INDEX IF NOT EXISTS idx_size_chart_rows_chart
    ON divechat_size_chart_rows (chart_id);
CREATE INDEX IF NOT EXISTS idx_size_chart_rows_chart_dim
    ON divechat_size_chart_rows (chart_id, dimension);

-- Mapowanie produkt -> chart. System wspolny per marka -> wiele produktow na 1 chart.
-- Jeden produkt skafandra mokrego = jedna plec = jeden chart (PK product_id, chart_id
-- dopuszcza teoretycznie wiele, ale realnie 1; produkty bi-gender typu OneFlex
-- moga miec 2 wiersze — do decyzji przy akceptacji mapowania, ADR-099 KROK 3).
CREATE TABLE IF NOT EXISTS divechat_product_size_chart (
    product_id  BIGINT      NOT NULL,                 -- pr_product.id_product
    chart_id    BIGINT      NOT NULL REFERENCES divechat_size_charts(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    PRIMARY KEY (product_id, chart_id)
);

CREATE INDEX IF NOT EXISTS idx_product_size_chart_chart
    ON divechat_product_size_chart (chart_id);

COMMENT ON TABLE divechat_size_charts IS
    'CHAT-T-099 / ADR-099: chart rozmiarowy per marka+plec (Scubapro/Bare, M/K). System wspolny per marka.';
COMMENT ON TABLE divechat_size_chart_rows IS
    'CHAT-T-099 / ADR-099: progi w long format. Jeden wiersz = (chart, rozmiar, wymiar). Lookup BETWEEN, NIE embeddingi.';
COMMENT ON TABLE divechat_product_size_chart IS
    'CHAT-T-099 / ADR-099: mapowanie produkt PrestaShop -> chart (marka+plec). Akceptacja listy przez Karola (polautomat).';
COMMENT ON COLUMN divechat_size_chart_rows.size_label IS
    'Etykieta handlowa do matchu z pr_product_attribute (np. ''MT''). Bare: == size_label; Scubapro: czesc przed '' - ''.';
COMMENT ON COLUMN divechat_size_chart_rows.size_full IS
    'Pelna nazwa z charta (Scubapro ''MT - 98''). NULL gdy rowna size_label (Bare).';
COMMENT ON COLUMN divechat_size_chart_rows.dimension IS
    'Typ wymiaru: chest|waist|hip|height|weight|leg. leg = tylko Bare damskie (wartosc punktowa min==max).';
