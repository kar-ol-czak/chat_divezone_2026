-- ============================================
-- DIVEZONE CHAT AI - Rollback Migracja 017
-- Seed kuratorowanych rekomendacji: automaty + komputery (ADR-065, T-031)
--
-- Usuwa WYLACZNIE wiersze zaseedowane w 017 (3 kategorie). Nie rusza
-- struktury tabeli ani innych kategorii dodanych pozniej (np. przez panel).
-- ============================================

DELETE FROM divechat_curated_recommendations
WHERE category_key IN (
    'regulator_recreational',
    'computer_budget_first',
    'computer_polish_waters'
);
