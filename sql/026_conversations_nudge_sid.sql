-- ============================================
-- DIVEZONE CHAT AI - Migracja 026
-- Kolumna nudge_sid w divechat_conversations dla atrybucji konwersji (CHAT-T-085, ADR-091)
-- Data: 2026-06-08
--
-- Rozdzielenie: session_id (rozmowa, zmienny przez restore CHAT-T-059) vs
-- nudge_sid (atrybucja, staly od momentu ekspozycji nudge). Konwersja w
-- panelu (CHAT-T-084) liczona przez JOIN
--   divechat_nudge_events.session_id ↔ divechat_conversations.nudge_sid
-- zamiast po row session_id (psuly sie powracajacy klienci z restore).
--
-- Zapis: TYLKO przy INSERT nowej rozmowy (gałąź "session_id nie istnieje"
-- w ConversationStore::startOrResume, lub ownership mismatch generujacy
-- nowy effectiveSessionId). Przy resume istniejacej NIE nadpisujemy —
-- atrybucja należy do momentu powstania rozmowy.
--
-- VARCHAR(64) zgodne z divechat_conversations.session_id (UUID v4 = 36
-- znaków, legacy 32-hex = 32 znaki, oba mieszczą się). DEFAULT NULL =
-- stare rozmowy + rozmowy klientów bez ekspozycji nudge (launcher) maja
-- nudge_sid=NULL, swiadome (ADR-091 — brak atrybucji wstecz).
--
-- Partial index: WHERE nudge_sid IS NOT NULL — większość rozmów nie ma
-- atrybucji (wlasciciele launchera, stare rozmowy). Pelny indeks
-- marnowalby miejsce; partial JOIN-uje tylko po realnie wypelnionych.
-- ============================================

ALTER TABLE divechat_conversations
    ADD COLUMN IF NOT EXISTS nudge_sid VARCHAR(64) DEFAULT NULL;

CREATE INDEX IF NOT EXISTS idx_conversations_nudge_sid
    ON divechat_conversations (nudge_sid)
    WHERE nudge_sid IS NOT NULL;

COMMENT ON COLUMN divechat_conversations.nudge_sid IS
    'CHAT-T-085 / ADR-091: atrybucja zrodla rozmowy (sid ekspozycji nudge). JOIN z divechat_nudge_events.session_id dla konwersji w panelu CHAT-T-084. Stare rozmowy = NULL (brak atrybucji wstecz, swiadome). Klienci bez ekspozycji nudge = NULL.';
