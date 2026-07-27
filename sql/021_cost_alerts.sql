-- ============================================
-- DIVEZONE CHAT AI - Migracja 021
-- Tabela flag wyslanych alertow kosztow (CHAT-T-064, ADR-064)
-- Data: 2026-06-03
--
-- Idempotencja alertu o przekroczeniu progu kosztow (default 5 USD/dobe).
-- Klucz glowny = date -> max JEDEN alert na dobe. Wstawienie wiersza =
-- "alert wlasnie zostal wystrzelony przez tego workera". INSERT ON CONFLICT
-- DO NOTHING z rowCount sprawdzeniem rozwiazuje race (rownolegle requesty).
--
-- Powiazane:
--  - CostGuard (standalone/src/Usage/CostGuard.php) — pisarz/czytelnik.
--  - divechat_message_usage (mig 007/008) — zrodlo SUM(cost_total_usd).
--  - ChatController::handle + ::stream — punkt sprawdzenia po HMAC.
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_cost_alerts (
    alert_date  DATE        PRIMARY KEY,
    sent_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    cost_usd    NUMERIC(10, 6) NOT NULL,
    mail_ok     BOOLEAN     NOT NULL DEFAULT TRUE
);

COMMENT ON TABLE divechat_cost_alerts IS
    'CHAT-T-064: idempotencja alertu dziennego (max 1 mail/dobe). PK=date.';
COMMENT ON COLUMN divechat_cost_alerts.cost_usd IS
    'Koszt z divechat_message_usage w momencie wyslania alertu (snapshot).';
COMMENT ON COLUMN divechat_cost_alerts.mail_ok IS
    'true = mail() zwrocil true; false = mail() failed (wpis i tak utrwalony, nie probujemy ponownie tego dnia).';
