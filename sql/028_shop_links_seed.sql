-- ============================================
-- DIVEZONE CHAT AI - Migracja 028
-- Seed numerów kont + kluczowych linków sklepu do divechat_shop_config (CHAT-T-087, KROK C)
-- Data: 2026-06-12
-- ADR: ADR-095 (dec.2 + dec.2a — divechat_shop_config zamiast nowej tabeli)
--
-- BEZ zmian strukturalnych — divechat_shop_config już istnieje (migracja 013).
-- Tylko INSERT-y seed. Idempotentna: INSERT ... ON CONFLICT (key) DO UPDATE.
--
-- Tool get_shop_links czyta po whiteliście kluczy z prefiksami bank_ i link_.
-- URL-e zweryfikowane (HTTP 200, 2026-06-12). Wszystkie finalne.
-- ============================================

INSERT INTO divechat_shop_config (key, value, note) VALUES
    ('bank_account_pln',          '27 1600 1462 1829 3115 4000 0003',                                    'Numer konta PLN do przelewu (ADR-095)'),
    ('bank_account_eur',          'PL54 1600 1462 1829 3115 4000 0002',                                  'Numer konta EUR (IBAN) do przelewu zagranicznego (ADR-095)'),
    ('bank_swift',                'PPABPLPK',                                                            'Kod SWIFT/BIC banku (ADR-095)'),
    ('link_kontakt',              'https://divezone.pl/kontakt-z-nami',                                  'Kontakt / dane do przelewu / faktura / mapa'),
    ('link_regulamin',            'https://divezone.pl/regulamin',                                       'Regulamin sklepu'),
    ('link_polityka_prywatnosci', 'https://divezone.pl/polityka-prywatnosci',                            'Polityka prywatności'),
    ('link_blog',                 'https://divezone.pl/posts',                                           'Divezone BLOG'),
    ('link_encyklopedia',         'https://divezone.pl/encyklopedia',                                    'Encyklopedia Nurkowania'),
    ('link_zwroty',               'https://divezone.pl/zwroty-produktow',                                'Zwroty produktów (30 dni gwarancja + 14 dni ustawowe)'),
    ('link_platnosci',            'https://divezone.pl/formy-p%C5%82atno%C5%9Bci-',                       'Formy płatności'),
    ('link_serwis',               'https://divezone.pl/serwis-automatow-oddechowych-i-innego-sprzetu-nurkowego', 'Serwis sprzętu nurkowego'),
    ('link_o_nas',                'https://divezone.pl/o-nas-i-naszym-sklepie',                          'O nas i naszym sklepie'),
    ('link_b2b',                  'https://divezone.pl/b2b',                                             'Dla instruktorów / współpraca B2B'),
    ('link_filmy',                'https://divezone.pl/filmy-produktowe-o-sprz%C4%99cie-do-nurkowania',   'Filmy produktowe o sprzęcie')
ON CONFLICT (key) DO UPDATE SET
    value      = EXCLUDED.value,
    note       = EXCLUDED.note,
    updated_at = NOW();
