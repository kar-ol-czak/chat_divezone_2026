-- ============================================
-- DIVEZONE CHAT AI - Migracja 003
-- Tabela synonimów nurkowych
-- Data: 2026-02-23
-- Źródło: TASK-011a krok 4
-- ============================================

-- Tabela divechat_synonyms
-- Mapuje synonimy na termin kanoniczny (np. "pianka" → "skafander mokry").
-- Używana przez wyszukiwanie do query expansion:
--   1. Klient wpisuje "pianka" → szukamy w synonym → canonical = "skafander mokry"
--   2. Rozszerzamy wyszukiwanie o canonical + wszystkie synonimy z grupy

CREATE TABLE IF NOT EXISTS divechat_synonyms (
    id SERIAL PRIMARY KEY,
    canonical_term VARCHAR(100) NOT NULL,
    synonym VARCHAR(100) NOT NULL,
    language CHAR(2) DEFAULT 'pl',
    category VARCHAR(50),
    created_at TIMESTAMPTZ DEFAULT NOW(),
    UNIQUE(canonical_term, synonym)
);

-- Indeks na synonym (główny lookup: klient wpisuje synonim, szukamy canonical)
CREATE INDEX IF NOT EXISTS idx_synonyms_synonym
    ON divechat_synonyms (LOWER(synonym));

-- Indeks na canonical (odwrotny lookup: mamy canonical, szukamy wszystkich synonimów)
CREATE INDEX IF NOT EXISTS idx_synonyms_canonical
    ON divechat_synonyms (LOWER(canonical_term));

-- Indeks trigram do fuzzy matching (opcjonalny, wymaga pg_trgm)
-- CREATE EXTENSION IF NOT EXISTS pg_trgm;
-- CREATE INDEX IF NOT EXISTS idx_synonyms_synonym_trgm
--     ON divechat_synonyms USING gin (synonym gin_trgm_ops);
