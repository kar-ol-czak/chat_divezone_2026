-- ============================================
-- DIVEZONE CHAT AI - Migracja 016
-- Kuratorowane rekomendacje produktowe (ADR-065 MVP)
-- Data: 2026-06-01
-- TASK: T-029
--
-- Warstwa miedzy zywa baza produktow a wiedza zespolu. Kategoria doboru
-- (np. 'computer_beginner', 'regulator_by_destination_europe') -> 1-3
-- RECZNIE wybrane produkty (product_id -> pr_product przez MySQL
-- enrichment) z uzasadnieniem (rationale_pl, czemu polecamy).
--
-- Bot przy pytaniu o dobor ("jaki komputer na start", "automat na Malediwy")
-- siega po kuratorowana liste przez narzedzie get_curated_recommendations,
-- NIE zgaduje. Cena/dostepnosc pobierane z zywego MySQL per request
-- (TWARDY staleness ADR-065: produkt nieaktywny -> bot pomija).
--
-- Idempotentna: CREATE IF NOT EXISTS + brak seedu produktow.
-- Seed product_id dodawany manualnie po akceptacji Karola (T-029 KROK 6 STOP) —
-- product_id to realne produkty PrestaShop, NIE wstawiamy zgadywanych.
-- ============================================

-- ============================================
-- 1. Tabela: divechat_curated_recommendations
-- ============================================
CREATE TABLE IF NOT EXISTS divechat_curated_recommendations (
    id SERIAL PRIMARY KEY,
    category_key VARCHAR(64) NOT NULL,
    category_label_pl TEXT NOT NULL,
    product_id INTEGER NOT NULL,
    priority SMALLINT NOT NULL DEFAULT 1,
    rationale_pl TEXT NOT NULL,
    verified_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    recheck_interval_days INTEGER NOT NULL DEFAULT 90,
    active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT priority_range CHECK (priority BETWEEN 1 AND 3),
    CONSTRAINT recheck_interval_positive CHECK (recheck_interval_days > 0),
    UNIQUE(category_key, product_id)
);

COMMENT ON TABLE divechat_curated_recommendations IS
    'ADR-065 MVP: kategoria doboru -> 1-3 reczne rekomendacje produktow z uzasadnieniem ekspertem zespolu.';
COMMENT ON COLUMN divechat_curated_recommendations.category_key IS
    'Stabilny identyfikator kategorii (np. computer_beginner). Uzywany przez bota w get_curated_recommendations.category.';
COMMENT ON COLUMN divechat_curated_recommendations.category_label_pl IS
    'Opis dla bota: KIEDY uzywac tej kategorii. Bot widzi to przy wyborze kategorii (rozpoznanie intencji klienta).';
COMMENT ON COLUMN divechat_curated_recommendations.product_id IS
    'FK do pr_product.id_product (PrestaShop MySQL). Walidacja przez MysqlProductEnrichmentService — produkt active=false -> bot pomija.';
COMMENT ON COLUMN divechat_curated_recommendations.priority IS
    'Kolejnosc prezentacji 1-3 (1=najwyzszy). Pokazywane klientowi w tej kolejnosci.';
COMMENT ON COLUMN divechat_curated_recommendations.rationale_pl IS
    'Uzasadnienie dla klienta: czemu polecamy ten produkt w tej kategorii (bot moze przytoczyc).';
COMMENT ON COLUMN divechat_curated_recommendations.verified_at IS
    'Ostatnia weryfikacja ekspercka. ADR-065 miekki staleness: gdy NOW() - verified_at > recheck_interval_days -> kandydat na przeglad (panel admina w pelnej wersji).';
COMMENT ON COLUMN divechat_curated_recommendations.recheck_interval_days IS
    'Interwal recheck eksperckiego: 30/90/180/365 zaleznie od kategorii (komputery=krotki przez szybka ewolucje modeli, maski=dlugi).';

-- ============================================
-- 2. Indeks: szybki lookup po category_key (warunek WHERE active=TRUE)
-- ============================================
CREATE INDEX IF NOT EXISTS idx_curated_active_category
    ON divechat_curated_recommendations(category_key, priority)
    WHERE active = TRUE;

-- ============================================
-- 3. Trigger updated_at na UPDATE
-- ============================================
CREATE OR REPLACE FUNCTION divechat_curated_set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_curated_updated_at ON divechat_curated_recommendations;
CREATE TRIGGER trg_curated_updated_at
    BEFORE UPDATE ON divechat_curated_recommendations
    FOR EACH ROW EXECUTE FUNCTION divechat_curated_set_updated_at();

-- ============================================
-- BRAK seed product_id w tym pliku.
-- Seed dodawany manualnie po akceptacji Karola (T-029 KROK 6 STOP).
-- Wzorzec INSERT (dla referencji):
--
-- INSERT INTO divechat_curated_recommendations
--     (category_key, category_label_pl, product_id, priority, rationale_pl, recheck_interval_days)
-- VALUES
--     ('computer_beginner',
--      'Komputer dla poczatkujacego nurka: prosty interfejs, kolorowy wyswietlacz, nie najtanszy (przyszla rezerwa zaawansowania).',
--      <product_id z pr_product>,
--      1,
--      '<dlaczego polecamy: ergonomia, intuicyjne menu, opcje dla rozwoju>',
--      90);
-- ============================================
