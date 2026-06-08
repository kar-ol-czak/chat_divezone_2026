-- Rollback migracji 025 (CHAT-T-082): tabela zdarzen nudge (CTR).
DROP TABLE IF EXISTS divechat_nudge_events;
