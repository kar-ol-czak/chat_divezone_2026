-- ============================================
-- DIVEZONE CHAT AI - Migracja 012
-- D1 ETL: tabela aliasów PS_name -> NAZEWNICTWO SKLEPU
-- Data: 2026-05-15
-- ADR: ADR-057 (D1 ETL design)
-- TASK: T-010
--
-- Tabela edytowalna online (bez deploy). ETL (etl_d1_parent_category.py)
-- używa lower(unaccent(ps_name)) jako klucza lookup żeby case-only diff
-- "Maski i Fajki" vs "Maski i fajki" nie generował false-mismatches.
--
-- Seed: 14 aliasów pokrywających TOP rozjazdy z audytu T-009.
-- Idempotentna: INSERT ... ON CONFLICT (ps_name_normalized) DO UPDATE.
-- ============================================

CREATE EXTENSION IF NOT EXISTS unaccent;

CREATE TABLE IF NOT EXISTS divechat_category_aliases (
    id SERIAL PRIMARY KEY,
    ps_name_normalized TEXT UNIQUE NOT NULL,
    ps_name_original TEXT NOT NULL,
    model_facing_name TEXT NOT NULL,
    note TEXT,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_category_aliases_normalized
    ON divechat_category_aliases (ps_name_normalized);

-- Seed: 14 wpisów z TOP rozjazdów audytu T-009 (Strategia B)
INSERT INTO divechat_category_aliases (ps_name_normalized, ps_name_original, model_facing_name, note) VALUES
    (lower(unaccent('Skrzydła i jackety')),     'Skrzydła i jackety',     'Wypornościowe',     'T-009 TOP1 rozjazd, 289 produktów'),
    (lower(unaccent('Maski i Fajki')),          'Maski i Fajki',          'Maski i fajki',     'case-only diff, 205 produktów'),
    (lower(unaccent('Latarki nurkowe')),        'Latarki nurkowe',        'Oświetlenie',       '147 produktów'),
    (lower(unaccent('Butle nurkowe')),          'Butle nurkowe',          'Butle',             '76 produktów'),
    (lower(unaccent('Węże')),                   'Węże',                   'Automaty Oddechowe','68 produktów (węże = część automatów)'),
    (lower(unaccent('Instrumenty pomiarowe')),  'Instrumenty pomiarowe',  'Komputery Nurkowe', '63 produktów'),
    (lower(unaccent('Bojki i kołowrotki')),     'Bojki i kołowrotki',     'Bezpieczeństwo',    '59 produktów'),
    (lower(unaccent('Noże')),                   'Noże',                   'Bezpieczeństwo',    '47 produktów'),
    (lower(unaccent('Akcesoria')),              'Akcesoria',              'Akcesoria Nurkowe', '84 produktów (level=2 oficjalna nazwa PS)'),
    (lower(unaccent('Skafandry Na ZIMNE wody')),'Skafandry Na ZIMNE wody','Skafandry mokre',   '56 produktów'),
    (lower(unaccent('Skafandry Na CIEPŁE wody')),'Skafandry Na CIEPŁE wody','Skafandry mokre', '47 produktów'),
    (lower(unaccent('Płetwy Gumowe JET')),      'Płetwy Gumowe JET',      'Płetwy',            '39 produktów'),
    (lower(unaccent('Płetwy Paskowe na Buta')), 'Płetwy Paskowe na Buta', 'Płetwy',            '38 produktów'),
    (lower(unaccent('Side Mount')),             'Side Mount',             'Wypornościowe',     '34 produktów'),
    -- T-010 KROK 5d: pre-commit walidacja wykryła że D2 "Torby na Sprzęt" (42 prod)
    -- ginie po D1 (PS native to "Torby i Skrzynie"). Alias zachowuje ciągłość z NAZEWNICTWO SKLEPU.
    (lower(unaccent('Torby i Skrzynie')),       'Torby i Skrzynie',       'Torby na Sprzęt',   'T-010 5d, zachowanie pseudokategorii z NAZEWNICTWO SKLEPU')
ON CONFLICT (ps_name_normalized) DO UPDATE SET
    ps_name_original = EXCLUDED.ps_name_original,
    model_facing_name = EXCLUDED.model_facing_name,
    note = EXCLUDED.note,
    updated_at = NOW();
