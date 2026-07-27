-- ============================================
-- ROLLBACK Migracji 040 — przywrocenie przyciskow target:ai na lisciach
-- Data: 2026-07-01 | CHAT-T-121 | ADR-110
-- Odwraca 040_chip_remove_redundant_target_ai.sql: przywraca pojedynczy przycisk
-- [{"label":"Napisz czego szukasz","target":"ai"}] na 12 lisciach.
--
-- Guard: przywracamy TYLKO gdy lisc ma dzis puste buttons ([]), zeby nie nadpisac
-- ewentualnych pozniejszych, celowych zmian buttons. Idempotentne. Match po node_key.
-- ============================================

BEGIN;

UPDATE divechat_chip_nodes
SET buttons = '[{"label": "Napisz czego szukasz", "target": "ai"}]'::jsonb,
    updated_at = NOW()
WHERE node_key IN (
    'zaczynam', 'snorkel',
    'komputer', 'automat', 'jacket',
    'pianka_rozmiar', 'suchy_rozmiar', 'pletwy_rozmiar', 'buty_rozmiar',
    'kaptur_rekawice', 'nie_wiem_rozmiar',
    'dostepnosc'
)
AND buttons = '[]'::jsonb;

COMMIT;
