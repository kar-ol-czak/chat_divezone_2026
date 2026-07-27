-- ============================================
-- DIVEZONE CHAT AI - Seed migracji 036 (CHAT-T-099b, ADR-099)
-- (D) chart dzieciecy Rebel: Scubapro/DZIECI — jeden wymiar height, wartosci PUNKTOWE
--     (min==max). Skala WSPOLNA dla dzieciecych pianek Rebel (decyzja 55a/57a).
--     Zrodlo: rebel_product_page_2026-06.
-- (C) 4 aliasy etykiet rozmiarow (decyzja 45c).
-- Data: 2026-06-18
--
-- HARD STOP (ADR-099): seed na Railway PROD — po akceptacji Karola.
-- Idempotentny: ON CONFLICT DO NOTHING (chart, wiersze przez puste zalozenie, aliasy PK).
-- ============================================

-- (D.1) Chart dzieciecy.
INSERT INTO divechat_size_charts (brand, gender, source, note) VALUES
    ('Scubapro', 'DZIECI', 'rebel_product_page_2026-06',
     'dzieciece pianki Rebel — jedna skala wzrostowa (wartosci punktowe), bez plci (57a)')
ON CONFLICT (brand, gender) DO NOTHING;

-- (D.2) Wiersze height (punktowe: min==max). sort_order rosnaco wg wzrostu.
INSERT INTO divechat_size_chart_rows
    (chart_id, size_label, size_full, dimension, min_val, max_val, unit, sort_order)
SELECT c.id, v.size_label, NULL, 'height', v.h, v.h, 'cm', v.sort
FROM divechat_size_charts c
JOIN (VALUES
    ('XXS', 104, 1),
    ('XS',  116, 2),
    ('S',   128, 3),
    ('M',   140, 4),
    ('L',   152, 5),
    ('XL',  164, 6)
) AS v(size_label, h, sort) ON TRUE
WHERE c.brand = 'Scubapro' AND c.gender = 'DZIECI';

-- (C) Aliasy: wariant PrestaShop -> etykieta charta, per chart (brand+gender).
INSERT INTO divechat_size_label_alias (chart_id, alias_label, canonical_label, note)
SELECT c.id, v.alias_label, v.canonical_label, v.note
FROM divechat_size_charts c
JOIN (VALUES
    ('Scubapro', 'K', 'M tall',  'MT',  'CHAT-T-099b 45c: [2278] Everflex 5/4 Damski'),
    ('Scubapro', 'M', 'XXL',     '2XL', 'CHAT-T-099b 45c: [5075] Everflex 5/4 Meski'),
    ('Scubapro', 'M', 'L short', 'LS',  'CHAT-T-099b 45c: [5373] Hybrid Vest Meski'),
    ('Scubapro', 'M', 'L tall',  'LT',  'CHAT-T-099b 45c: [5373] Hybrid Vest Meski')
) AS v(brand, gender, alias_label, canonical_label, note)
  ON c.brand = v.brand AND c.gender = v.gender
ON CONFLICT (chart_id, alias_label) DO NOTHING;
