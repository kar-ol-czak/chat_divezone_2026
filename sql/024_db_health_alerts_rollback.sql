-- Rollback migracji 024 (CHAT-T-079): tabela flag alertow o awarii polaczenia DB.
DROP TABLE IF EXISTS divechat_db_alerts;
