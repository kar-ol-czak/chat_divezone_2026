-- ============================================
-- DIVEZONE CHAT AI - Migracja 040 (seed)
-- Usuniecie zbednych przyciskow target:ai z lisci drzewa chipow (ADR-110, decyzja 8a)
-- Data: 2026-07-01 | CHAT-T-121 | ADR-110
-- Powiazane: 038_chip_seed_level2_level3.sql (seed drzewa), ADR-097 (dwa swiaty),
--   CHAT-T-121 (frontend: target:ai = wejscie w pisanie, nie wiadomosc).
--
-- KONTEKST:
--   Po ADR-110 klik przycisku target:ai NIE wysyla wiadomosci — odslania pole
--   pisania (widget-bundle.js: enterWriteMode). Na wezlach-lisciach bot_text juz
--   zaprasza do pisania ("Pomogę dobrać... Powiedz: ..."), wiec przycisk
--   [{"label":"Napisz czego szukasz","target":"ai"}] jest ZBEDNY: wejscie na lisc
--   od razu renderuje bot_text i (dzieki frontendowi CHAT-T-121) input jest aktywny.
--   Usuwamy ten pojedynczy przycisk (buttons = []) z 12 lisci.
--
-- ZAKRES (12 lisci, buttons = pojedynczy target:ai "Napisz czego szukasz"):
--   L2: zaczynam, snorkel
--   L3 (dobor):   komputer, automat, jacket
--   L3 (rozmiar): pianka_rozmiar, suchy_rozmiar, pletwy_rozmiar, buty_rozmiar,
--                 kaptur_rekawice, nie_wiem_rozmiar
--   L3 (zamowienie): dostepnosc
--
-- POZA ZAKRESEM (hybrydy z innymi przyciskami — NIE ruszamy):
--   - wysylka (id 4): "Formy płatności" (link) + "Koszty i metody dostawy" (ai);
--     ai to konkretna akcja, nie zbedny invite. Zostaje.
--   - serwis  (id 3): linki + "Inne pytanie" (ai); wezel active=FALSE (ukryty). Zostaje.
--
-- IDEMPOTENCJA + BEZPIECZENSTWO:
--   Guard `buttons @> '[{"target":"ai"}]'` — czyscimy TYLKO gdy lisc dalej ma
--   przycisk target:ai. Ponowne uruchomienie = no-op. Match po node_key (nie id).
--   Transakcyjna. Rollback: 040_chip_remove_redundant_target_ai_rollback.sql.
-- ============================================

BEGIN;

UPDATE divechat_chip_nodes
SET buttons = '[]'::jsonb,
    updated_at = NOW()
WHERE node_key IN (
    'zaczynam', 'snorkel',
    'komputer', 'automat', 'jacket',
    'pianka_rozmiar', 'suchy_rozmiar', 'pletwy_rozmiar', 'buty_rozmiar',
    'kaptur_rekawice', 'nie_wiem_rozmiar',
    'dostepnosc'
)
AND buttons @> '[{"target": "ai"}]'::jsonb;

COMMIT;
