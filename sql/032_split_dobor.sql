-- ============================================
-- DIVEZONE CHAT AI - Migracja 032 (seed)
-- Rozdzielenie "Dobór sprzętu" / "Dobór rozmiaru" na dwa węzły Level 1 (CHAT-T-088d)
-- Data: 2026-06-14
-- ADR: ADR-096. Decyzje: 57a (dwa osobne węzły, teksty deterministyczne),
--      58a (limit mobilny → 6, osobno we froncie).
--
-- PROBLEM: węzeł dobor ma label "Dobór sprzętu" ale bot_text o ROZMIARZE —
-- zlepione dwie ścieżki. Rozdzielamy:
--   dobor          = doradztwo zakupowe (jaką maskę/automat/komputer kupić)
--   dobor_rozmiar  = pomiar (jaki rozmiar pianki/kaptura/skafandra)
--
-- Czysty seed (UPDATE + INSERT divechat_chip_nodes) — bez zmian kodu/struktury.
-- Idempotentny: UPDATE deterministyczny, INSERT z ON CONFLICT DO UPDATE.
-- Oba bot_text DETERMINISTYCZNE (klik → gotowy tekst, ZERO LLM). LLM dopiero
-- gdy klient kliknie "Napisz czego szukasz" (→ai) lub wpisze w input.
-- ============================================

-- 1. Korekta istniejącego węzła dobor → CZYSTO sprzętowy (57a)
UPDATE divechat_chip_nodes
SET bot_text = 'Jasne, pomogę dobrać sprzęt. Co Cię interesuje — maska, płetwy, automat, komputer nurkowy, czy coś innego?',
    label = 'Dobór sprzętu',
    updated_at = NOW()
WHERE node_key = 'dobor';

-- 2. Nowy węzeł dobor_rozmiar (Level 1, po dobor). ON CONFLICT = idempotentny.
--    parent_id = id węzła root (pobrany podzapytaniem, NIE hardcode).
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, active)
VALUES (
  'dobor_rozmiar',
  (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
  2,
  5,
  'Dobór rozmiaru',
  'Pomogę dobrać rozmiar. Czego rozmiar dobieramy i dla mężczyzny czy kobiety?',
  '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
  TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
  label = EXCLUDED.label,
  bot_text = EXCLUDED.bot_text,
  buttons = EXCLUDED.buttons,
  sort_order = EXCLUDED.sort_order,
  updated_at = NOW();
