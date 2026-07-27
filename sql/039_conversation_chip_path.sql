-- ============================================
-- DIVEZONE CHAT AI - Migracja 039
-- Utrwalenie strukturalnej sciezki chipow w rozmowie (CHAT-T-122, ADR-110 pkt 3/4)
-- Data: 2026-07-01
-- Decyzje ADR-110: 8b (sciezka STRUKTURALNA, nie string), 9a (moment utrwalenia
--          = pierwsza realna wiadomosc rozmowy). Decyzje Karola 42b/43a.
--
-- Nowa kolumna chip_path jsonb (nullable, bez defaultu): tablica wezlow zejscia
-- do liscia, z ktorego klient wszedl w pisanie, w postaci
--   [{"node_key": "...", "label": "...", "level": N}, ...]
-- (kolejnosc od Level 2, root Level 1 pominiety — jak buildChipContext na froncie).
--
-- ROZLACZNE z chip_context (string dla LLM, ADR-097): chip_context POZOSTAJE
-- efemeryczny w system prompcie tej tury i NIE jest utrwalany. Tu utrwalamy TYLKO
-- strukturalna reprezentacje do analityki klikalnosci (liczonej czystym SQL po
-- node_key z rozbicia jsonb — bez osobnej tabeli zdarzen).
--
-- Nullable bez defaultu: rozmowy z wolnego pisania (bez chipow) maja chip_path
-- NULL. Idempotencja utrwalenia (zapis TYLKO gdy dotad NULL) egzekwowana w
-- warstwie aplikacji (ConversationStore::startOrResume, warunek chip_path IS NULL).
--
-- Indeks GIN pod przyszle statystyki (jsonb_array_elements + GROUP BY node_key).
-- Partial (WHERE chip_path IS NOT NULL) — wiekszosc rozmow to wolne pisanie,
-- indeksujemy tylko wiersze z realna sciezka.
-- ============================================

ALTER TABLE divechat_conversations
    ADD COLUMN IF NOT EXISTS chip_path jsonb;

CREATE INDEX IF NOT EXISTS idx_conv_chip_path
    ON divechat_conversations USING GIN (chip_path)
    WHERE chip_path IS NOT NULL;

COMMENT ON COLUMN divechat_conversations.chip_path IS
    'CHAT-T-122 / ADR-110 (8b/9a): strukturalna sciezka chipow do liscia wejscia w pisanie: [{node_key,label,level}]. Utrwalana RAZ na rozmowe (pierwsza wiadomosc, gdy dotad NULL). Rozlaczna z chip_context (string efemeryczny dla LLM, ADR-097). NULL = wolne pisanie bez chipow.';
