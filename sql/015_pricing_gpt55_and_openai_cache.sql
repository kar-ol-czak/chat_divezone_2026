-- ============================================
-- DIVEZONE CHAT AI - Migracja 015
-- Pricing gpt-5.5 + cache_read_price_per_million dla wszystkich OpenAI
-- Data: 2026-05-26
-- TASK: T-022 (decyzja 101b)
--
-- Co robi:
-- 1. INSERT gpt-5.5 (nowy model, $5/$30 per 1M, cache_read $0.5 = 10% input,
--    websearch 2026-05). is_active=true, is_escalation=false (per task spec —
--    dodajemy gotowy wpis, ale produkcyjne użycie czatu decydujemy osobno).
-- 2. UPDATE gpt-5.4: output 14.0 → 15.0 (websearch 2026-05 korekta, migracja
--    007 miała przestarzałą cenę).
-- 3. UPDATE cache_read_price_per_million dla wszystkich OpenAI z NULL → 10%
--    stawki input. Powód: w T-022 fix OpenAIProvider zaczyna czytać
--    prompt_tokens_details.cached_tokens (potwierdzone empirycznie: ~95%
--    promptu cached dla naszego profilu) — bez tej kolumny PricingService
--    nie wyceni cache_read (cost_cache = 0).
--
-- Konwencja Claude vs OpenAI w tej tabeli:
-- - Claude (już w migracji 007): cache_creation_price_per_million ≈ 1.25×input,
--   cache_read_price_per_million ≈ 0.1×input. Bot płaci osobno za TWORZENIE
--   i ODCZYT cache.
-- - OpenAI: cache_creation jest BEZPŁATNE (cache_creation_price = NULL pozostaje),
--   tylko odczyt jest tańszy (~10% input). OpenAIProvider zwraca cache_creation_tokens=0,
--   więc kolumna nieistotna ekonomicznie — zostaje NULL dla porządku.
--
-- Idempotentna (UPDATE WHERE + INSERT ... ON CONFLICT DO UPDATE).
-- Bezpieczna: nie zmienia żadnych historycznych wierszy w divechat_message_usage
-- (tylko forward — od deploy ceny będą używane do nowych zapisów).
-- ============================================

-- 1. INSERT gpt-5.5 (nowy snapshot 2026, najnowszy model OpenAI)
INSERT INTO divechat_model_pricing (
    model_id, provider, label,
    input_price_per_million, output_price_per_million,
    cache_read_price_per_million, cache_creation_price_per_million,
    currency, is_active, is_escalation,
    supports_temperature, supports_reasoning_effort,
    updated_at
) VALUES
    ('gpt-5.5', 'openai', 'GPT-5.5', 5.0000, 30.0000, 0.5000, NULL, 'USD', TRUE, FALSE, FALSE, TRUE, NOW())
ON CONFLICT (model_id) DO UPDATE SET
    provider                          = EXCLUDED.provider,
    label                             = EXCLUDED.label,
    input_price_per_million           = EXCLUDED.input_price_per_million,
    output_price_per_million          = EXCLUDED.output_price_per_million,
    cache_read_price_per_million      = EXCLUDED.cache_read_price_per_million,
    cache_creation_price_per_million  = EXCLUDED.cache_creation_price_per_million,
    currency                          = EXCLUDED.currency,
    is_active                         = EXCLUDED.is_active,
    is_escalation                     = EXCLUDED.is_escalation,
    supports_temperature              = EXCLUDED.supports_temperature,
    supports_reasoning_effort         = EXCLUDED.supports_reasoning_effort,
    updated_at                        = NOW();

-- 2. Korekta gpt-5.4 (output 14.0 → 15.0, websearch 2026-05)
UPDATE divechat_model_pricing
SET output_price_per_million = 15.0000,
    updated_at = NOW()
WHERE model_id = 'gpt-5.4' AND output_price_per_million = 14.0000;

-- 3. cache_read_price_per_million dla OpenAI (10% input) — T-022 cache fix
UPDATE divechat_model_pricing
SET cache_read_price_per_million = 0.2500, updated_at = NOW()
WHERE model_id = 'gpt-5.4' AND cache_read_price_per_million IS NULL;

UPDATE divechat_model_pricing
SET cache_read_price_per_million = 0.2000, updated_at = NOW()
WHERE model_id = 'gpt-4.1' AND cache_read_price_per_million IS NULL;

UPDATE divechat_model_pricing
SET cache_read_price_per_million = 0.0750, updated_at = NOW()
WHERE model_id = 'gpt-5.4-mini' AND cache_read_price_per_million IS NULL;

UPDATE divechat_model_pricing
SET cache_read_price_per_million = 0.1100, updated_at = NOW()
WHERE model_id = 'o3-mini' AND cache_read_price_per_million IS NULL;

UPDATE divechat_model_pricing
SET cache_read_price_per_million = 0.0250, updated_at = NOW()
WHERE model_id = 'gpt-5-mini' AND cache_read_price_per_million IS NULL;
