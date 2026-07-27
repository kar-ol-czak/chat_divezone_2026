-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 028
-- Usuwa seed numerów kont + linków sklepu z divechat_shop_config (CHAT-T-087)
-- Data: 2026-06-12
-- ADR: ADR-095
--
-- NIE usuwa free_shipping_threshold_pl (migracja 013) ani samej tabeli.
-- ============================================

DELETE FROM divechat_shop_config
WHERE key IN (
    'bank_account_pln',
    'bank_account_eur',
    'bank_swift',
    'link_kontakt',
    'link_regulamin',
    'link_polityka_prywatnosci',
    'link_blog',
    'link_encyklopedia',
    'link_zwroty',
    'link_platnosci',
    'link_serwis',
    'link_o_nas',
    'link_b2b',
    'link_filmy'
);
