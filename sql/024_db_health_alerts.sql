-- ============================================
-- DIVEZONE CHAT AI - Migracja 024
-- Tabela flag wyslanych alertow o awarii polaczenia DB (CHAT-T-079, ADR-088)
-- Data: 2026-06-07
--
-- Domyka dlug z ADR-088 (incydent 1045: ~18h niedostepnosci produktow
-- w czacie, niezauwazonej — bo nikt nie dostal sygnalu, ze MySQL padl).
-- ChatService::executeTool lapie KAZDY blad narzedzia (search_products,
-- get_expert_knowledge, get_shipping_info, order status) — jedyny
-- przechwytywacz pokrywajacy zarowno MySQL PS jak i pgvector. Tutaj
-- klasa DbHealthAlert rozpoznaje awarie polaczenia (MySQL 1045/2002/...,
-- PG SQLSTATE klasa 08, 57P01/57P03) i alertuje mailem.
--
-- Dedup w oknie 30 min (decyzja 245b — krotsze niz cost_alerts dobowe,
-- bo awaria infra wymaga szybkiego ponowienia alertu po nawrocie).
-- UNIQUE (alert_window, db_target) -> max 1 alert / 30min / baza.
-- INSERT ON CONFLICT DO NOTHING + rowCount() rozstrzyga race przy
-- rownoleglych workerach (tylko jeden wstawia + wysyla mail).
--
-- alert_window liczone SQL-em: to_timestamp(floor(EPOCH/1800)*1800).
-- Jedno zrodlo czasu (PG NOW()), zero driftu PHP <-> PG.
--
-- Powiazane:
--  - DbHealthAlert (standalone/src/Usage/DbHealthAlert.php) — pisarz.
--  - ChatService::executeTool (standalone/src/Chat/ChatService.php) — punkt
--    detekcji w catch (\Throwable).
--  - ADR-088 (_docs/10_decyzje_projektowe.md) — kontekst incydentu.
--  - CostGuard / divechat_cost_alerts (mig 021) — wzorzec dedup+mail
--    skopiowany 1:1 (decyzja 247a: OSOBNA klasa + OSOBNA tabela, NIE
--    rozszerzac CostGuard).
-- ============================================

CREATE TABLE IF NOT EXISTS divechat_db_alerts (
    id            SERIAL PRIMARY KEY,
    alert_window  TIMESTAMPTZ NOT NULL,
    db_target     VARCHAR(20) NOT NULL,
    sqlstate      VARCHAR(10),
    driver_code   INTEGER,
    error_excerpt TEXT,
    mail_ok       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (alert_window, db_target)
);

COMMENT ON TABLE divechat_db_alerts IS
    'CHAT-T-079 / ADR-088: dedup alertu o awarii polaczenia DB (max 1 mail / 30 min / baza).';
COMMENT ON COLUMN divechat_db_alerts.alert_window IS
    'Poczatek 30-min okna dedup (round down do wielokrotnosci 1800s). UNIQUE (window, target).';
COMMENT ON COLUMN divechat_db_alerts.db_target IS
    'Baza ktora padla: ''mysql'' (PrestaShop) lub ''pgsql'' (Railway/pgvector).';
COMMENT ON COLUMN divechat_db_alerts.sqlstate IS
    'SQLSTATE z PDOException::getCode() w momencie wykrycia awarii (5 znakow).';
COMMENT ON COLUMN divechat_db_alerts.driver_code IS
    'Driver-specific code z PDOException::$errorInfo[1] — dla MySQL 1045/2002/2003/2006/2013; dla PG zwykle NULL.';
COMMENT ON COLUMN divechat_db_alerts.error_excerpt IS
    'Skrocony komunikat bledu (max 240 znakow). password=/pwd= zredaktowane defensywnie. Bez DSN.';
COMMENT ON COLUMN divechat_db_alerts.mail_ok IS
    'true = mail() zwrocil true; false = mail() failed (nie probujemy ponownie w tym oknie).';
