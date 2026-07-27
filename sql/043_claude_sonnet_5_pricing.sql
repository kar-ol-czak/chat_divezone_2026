-- CHAT-T-177 (ADR-139): cennik dla claude-sonnet-5.
--
-- PO CO: bez wiersza w `divechat_model_pricing` PricingService zwraca koszt 0.00
-- (świadoma degradacja, żeby nieznany model nie wywalił czatu), więc koszty rozmów
-- zapisywałyby się jako zerowe, a SettingsController NIE pokazałby modelu w panelu
-- (buildAvailableModels listuje wyłącznie modele obecne w cenniku).
--
-- KOLEJNOŚĆ PRZY DEPLOYU: ten INSERT idzie PO rsync kodu i smoke `/api/health`,
-- a PRZED przełączeniem `divechat_settings.model_primary` na claude-sonnet-5.
--
-- ⚠️ CENY INTRO, OBOWIĄZUJĄ DO 31.08.2026. Od 1.09.2026 Anthropic wraca do
-- stawek docelowych: input 3.00 / output 15.00 / cache_read 0.30 / cache_creation 3.75.
-- Korekta zaplanowana osobno (karta Trello „Chat - 73"). Bez niej od 1.09 raportowany
-- koszt będzie ZANIŻONY o jedną trzecią, cicho — nic w kodzie tego nie zasygnalizuje.
--
-- Flagi supports_* są celowo zgodne z DiveChat\Enum\AIModel dla claude-sonnet-5:
--   supports_temperature      = false  → AIModel::supportsTemperature()      === false
--   supports_reasoning_effort = true   → AIModel::supportsReasoningEffort()  === true
-- Panel czyta te flagi z CENNIKA, a ClaudeProvider z ENUMA — rozjazd między nimi
-- oznacza panel pokazujący co innego, niż robi kod. Zweryfikowane 2026-07-27.

INSERT INTO divechat_model_pricing (
    model_id,
    provider,
    label,
    input_price_per_million,
    output_price_per_million,
    cache_read_price_per_million,
    cache_creation_price_per_million,
    currency,
    is_active,
    is_escalation,
    supports_temperature,
    supports_reasoning_effort
) VALUES (
    'claude-sonnet-5',
    'claude',
    'Claude Sonnet 5',
    2.00,   -- INTRO do 31.08.2026 (docelowo 3.00)
    10.00,  -- INTRO do 31.08.2026 (docelowo 15.00)
    0.20,   -- INTRO, 0.1x input (docelowo 0.30)
    2.50,   -- INTRO, 1.25x input, cache 5-min (docelowo 3.75)
    'USD',
    true,
    false,  -- tier primary w AIModel, nie eskalacyjny
    false,
    true
);

-- Weryfikacja po wykonaniu:
-- SELECT model_id, input_price_per_million, output_price_per_million,
--        supports_temperature, supports_reasoning_effort
-- FROM divechat_model_pricing WHERE model_id = 'claude-sonnet-5';
