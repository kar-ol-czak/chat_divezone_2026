-- 002_extend_conversations.sql
-- Rozszerzenie schematu conversations + tabela ustawień
-- Data: 2026-02-20

-- Nowe kolumny w divechat_conversations
ALTER TABLE divechat_conversations
  ADD COLUMN IF NOT EXISTS model_used VARCHAR(64),
  ADD COLUMN IF NOT EXISTS response_times JSONB,
  ADD COLUMN IF NOT EXISTS search_diagnostics JSONB,
  ADD COLUMN IF NOT EXISTS knowledge_gap BOOLEAN DEFAULT false,
  ADD COLUMN IF NOT EXISTS admin_status VARCHAR(20) DEFAULT 'new',
  ADD COLUMN IF NOT EXISTS admin_notes TEXT;

-- Indeksy
CREATE INDEX IF NOT EXISTS idx_conversations_knowledge_gap
  ON divechat_conversations (knowledge_gap) WHERE knowledge_gap = true;
CREATE INDEX IF NOT EXISTS idx_conversations_admin_status
  ON divechat_conversations (admin_status);

-- Tabela ustawień czatu
CREATE TABLE IF NOT EXISTS divechat_settings (
  key VARCHAR(100) PRIMARY KEY,
  value JSONB NOT NULL,
  updated_at TIMESTAMPTZ DEFAULT NOW()
);

-- Domyślne ustawienia
INSERT INTO divechat_settings (key, value) VALUES
  ('ai_provider', '"openai"'),
  ('primary_model', '"gpt-4.1"'),
  ('escalation_model', '"gpt-5.2"'),
  ('temperature', '0.6'),
  ('max_tokens', '4096'),
  ('emoji_enabled', 'true'),
  ('knowledge_gap_threshold', '0.5')
ON CONFLICT (key) DO NOTHING;
