-- ============================================
-- DIVEZONE CHAT AI - Migracja 037
-- Tabela recenzji rozmow (status pracy + werdykt jakosci) (CHAT-T-104, ADR-102)
-- Data: 2026-06-28
-- Decyzje ADR-102: P13a (osobna tabela na Railway), P16b (jedno pole notatki
--          nadpisywane), P17a/D2 (id_employee z sesji PS, zaufany), D3 (brak
--          wiersza = stan "nowy" implicytny — NIE seedujemy istniejacych rozmow).
--
-- Narzedzie do regularnego, delegowalnego przegladu rozmow z botem. Dwie
-- niezalezne osie (ADR-102 pkt 1):
--  - status  — os pracy recenzenta: nowy -> do_weryfikacji -> w_trakcie -> zamkniety
--  - verdict — os jakosci czatu (nadawana przy domykaniu): ok / problem_do_rozwiazania
--              / problem_rozwiazany (ostatni nadaje Karol po wdrozeniu fixu)
--
-- DLACZEGO OSOBNA TABELA (P13a, NIE kolumny na divechat_conversations):
--  - nie mieszamy danych operacyjnych bota z metadanymi pracy ludzkiej (recenzji);
--  - wiekszosc rozmow nigdy nie bedzie recenzowana — brak wiersza = brak narzutu (D3).
--
-- UNIQUE(conversation_id): jeden wiersz recenzji na rozmowe (upsert ON CONFLICT).
-- Walidacja enumow rownolegle w warstwie aplikacji (ConversationReviewRepository)
-- ORAZ jako CHECK constraint (obrona w glab — odrzuca smieci na poziomie DB).
--
-- Indeksy:
--  - UNIQUE(conversation_id) — lookup per rozmowa + gwarancja jednego wiersza.
--  - (status, updated_at DESC) — lista robocza filtrowana po statusie, sort po dacie.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_conversation_review (
    id              SERIAL      PRIMARY KEY,
    conversation_id INTEGER     NOT NULL UNIQUE
                        REFERENCES divechat_conversations (id) ON DELETE CASCADE,
    status          TEXT        NOT NULL DEFAULT 'do_weryfikacji',
    verdict         TEXT        NULL,
    note            TEXT        NULL,
    updated_by      INTEGER     NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT divechat_conversation_review_status_chk
        CHECK (status IN ('nowy', 'do_weryfikacji', 'w_trakcie', 'zamkniety')),
    CONSTRAINT divechat_conversation_review_verdict_chk
        CHECK (verdict IS NULL OR verdict IN ('ok', 'problem_do_rozwiazania', 'problem_rozwiazany'))
);

CREATE INDEX IF NOT EXISTS idx_conv_review_status
    ON divechat_conversation_review (status, updated_at DESC);

COMMENT ON TABLE divechat_conversation_review IS
    'CHAT-T-104 / ADR-102: recenzje rozmow z botem. Dwie osie: status (praca) + verdict (jakosc). Brak wiersza = stan "nowy" implicytny (D3, NIE seedujemy).';
COMMENT ON COLUMN divechat_conversation_review.conversation_id IS
    'FK -> divechat_conversations.id. UNIQUE — jeden wiersz recenzji na rozmowe.';
COMMENT ON COLUMN divechat_conversation_review.status IS
    'Os pracy recenzenta: nowy / do_weryfikacji / w_trakcie / zamkniety. Default do_weryfikacji przy flagowaniu.';
COMMENT ON COLUMN divechat_conversation_review.verdict IS
    'Os jakosci (nullable): ok / problem_do_rozwiazania / problem_rozwiazany. NULL dopoki recenzent nie domknie.';
COMMENT ON COLUMN divechat_conversation_review.note IS
    'Pojedyncze pole notatki nadpisywane przy kazdym zapisie (P16b — bez historii wpisow).';
COMMENT ON COLUMN divechat_conversation_review.updated_by IS
    'id_employee z sesji PS (pr_employee). Zaufany — kanal DIVECHAT_SERVER_SECRET (P17a/D2). Mapowanie na nazwe robi PS.';
