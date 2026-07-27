-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 036 (CHAT-T-099b)
-- Cofa: tabele aliasow + przywraca binarny CHECK gender (M/K).
-- Data: 2026-06-18 | ADR: ADR-099
--
-- UWAGA: przywrocenie binarnego CHECK NIE powiedzie sie, jesli istnieje chart
-- gender='DZIECI' (najpierw cofnij seed 036). Idempotentny w granicach tego zalozenia.
-- ============================================

DROP TABLE IF EXISTS divechat_size_label_alias;

ALTER TABLE divechat_size_charts
    DROP CONSTRAINT IF EXISTS divechat_size_charts_gender_chk;
ALTER TABLE divechat_size_charts
    ADD CONSTRAINT divechat_size_charts_gender_chk
    CHECK (gender IN ('M', 'K'));
