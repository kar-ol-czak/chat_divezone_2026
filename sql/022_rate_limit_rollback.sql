-- Rollback migracji 022 (CHAT-T-066): tabela licznikow rate-limitu.
DROP TABLE IF EXISTS divechat_rate_limit;
