-- ============================================
-- DIVEZONE CHAT AI - Migracja 013
-- Dane wysylki (stawki kurierow + config sklepu)
-- Data: 2026-05-26
-- ADR: ADR-059
-- TASK: T-014
--
-- Tabela divechat_shipping_rates zastepuje hardcoded dane w
-- standalone/src/Tools/ShippingInfo.php (BLEDNE: DPD 15.99/InPost 14.99/
-- Paczkomat 12.99/prog 499/Odbior Warszawa - sklep w Toruniu).
-- Wzorzec edytowalnej online tabeli jak divechat_model_pricing (ADR-052).
--
-- Idempotentna: CREATE IF NOT EXISTS + INSERT ... ON CONFLICT DO UPDATE.
-- ============================================

-- ============================================
-- 1.1 Tabela: divechat_shipping_rates
-- Stawki dostawy per kurier x strefa (PL / EU)
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_shipping_rates (
    id SERIAL PRIMARY KEY,
    carrier_name TEXT NOT NULL,
    zone TEXT NOT NULL DEFAULT 'PL',
    price NUMERIC(7,2) NOT NULL,
    cod_price NUMERIC(7,2),
    delivery_days TEXT NOT NULL DEFAULT '1-2 dni robocze',
    max_weight_kg INTEGER NOT NULL DEFAULT 31,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    sort_order INTEGER NOT NULL DEFAULT 0,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE(carrier_name, zone)
);

CREATE INDEX IF NOT EXISTS idx_shipping_rates_zone
    ON divechat_shipping_rates(zone) WHERE active = TRUE;

-- ============================================
-- 1.2 Tabela: divechat_shop_config
-- Konfiguracja sklepu (key-value: prog darmowej dostawy, godziny, itp.)
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_shop_config (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL,
    note TEXT,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- ============================================
-- 2. Seed danych PL (ADR-059, dane Karola)
-- INSERT ... ON CONFLICT DO UPDATE - idempotentny.
-- Strefa EU NIE seedowana (Karol poda osobno; tool obsluguje brak gracefully).
-- ============================================
INSERT INTO divechat_shipping_rates (carrier_name, zone, price, cod_price, delivery_days, sort_order) VALUES
    ('Paczkomat InPost', 'PL', 13.00, NULL,  '1-2 dni robocze', 1),
    ('Kurier InPost',    'PL', 13.00, 26.00, '1-2 dni robocze', 2),
    ('Kurier DPD',       'PL', 21.99, 26.00, '1-2 dni robocze', 3),
    ('Odbior osobisty',  'PL',  0.00, NULL,  'Po umowieniu, ul. Storczykowa 5, Torun', 4)
ON CONFLICT (carrier_name, zone) DO UPDATE SET
    price         = EXCLUDED.price,
    cod_price     = EXCLUDED.cod_price,
    delivery_days = EXCLUDED.delivery_days,
    sort_order    = EXCLUDED.sort_order,
    updated_at    = NOW();

INSERT INTO divechat_shop_config (key, value, note) VALUES
    ('free_shipping_threshold_pl', '299', 'Prog darmowej dostawy w PLN dla strefy PL')
ON CONFLICT (key) DO UPDATE SET
    value      = EXCLUDED.value,
    note       = EXCLUDED.note,
    updated_at = NOW();
