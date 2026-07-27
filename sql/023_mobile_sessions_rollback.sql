-- Rollback migracji 023 (CHAT-T-071, ADR-086): sesje mobilnego panelu admina.
DROP TABLE IF EXISTS divechat_mobile_sessions;
