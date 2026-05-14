-- ============================================
-- DIVEZONE CHAT AI - Migracja 010
-- D2-hybrid mapping pseudokategorii NAZEWNICTWO SKLEPU
-- Data: 2026-05-14
-- ADR: ADR-055
-- TASK: T-002 (kontynuacja TASK-CHAT-012)
--
-- Wypełnia parent_category_name dla ~2194 produktów (86% bazy).
-- ADR-027 fallback w ProductSearch::buildFilters() zaczyna matchować
-- gdy model wysyła pseudokategorię zbiorczą (np. "Skafandry suche",
-- "Komputery Nurkowe", "Wypornościowe").
--
-- Idempotentna: można uruchomić wielokrotnie, deterministyczny wynik.
-- Każda kategoria ma DOKŁADNIE JEDEN parent (per ADR-055).
-- ============================================

-- ============================================
-- 1. Automaty Oddechowe (322 produkty)
-- Zawiera brand-only kategorie (TECLINE/SCUBAPRO/APEKS/...) zweryfikowane
-- jako subkategorie "Automaty Oddechowe" w PrestaShop pr_category (id_parent=286).
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Automaty Oddechowe'
WHERE category_name IN (
    '1 stopnie', '1 STOPNIE', '2 stopnie', '2 STOPNIE i OCTOPUSY',
    'Automaty stage', 'ZESTAWY Apeks', 'Zestawy rekreacyjne', 'Zestawy Stage',
    'Akcesoria do automatów', 'Węże do Automatów',
    'APEKS', 'AQUALUNG', 'ATOMIC', 'MARES', 'POSEIDON',
    'SCUBAPRO', 'SCUBATECH', 'TECLINE', 'XDEEP'
);

-- ============================================
-- 2. Komputery Nurkowe (215 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Komputery Nurkowe'
WHERE category_name IN (
    'Komputery SHEARWATER', 'Komputery SUUNTO', 'Komputery SCUBAPRO',
    'Komputery MARES', 'Komputery Garmin', 'Komputery RATIO',
    'Komputery AQUALUNG', 'Komputery Halcyon', 'Komputery TUSA',
    'Konsole', 'Manometry', 'Kompasy', 'Interfejsy',
    'Węże do Manometrów', 'Analizatory tlenowe'
);

-- ============================================
-- 3. Skafandry suche (140 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Skafandry suche'
WHERE category_name IN (
    'SUCHE Trylaminat, Cordura', 'SUCHE Neoprenowe',
    'Ocieplacze do Suchych', 'Buty do suchego',
    'Zawory do suchego skafandra', 'Torby na Suche i Ocieplacze',
    'Manszety i inne'
);

-- ============================================
-- 4. Skafandry mokre (121 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Skafandry mokre'
WHERE category_name IN (
    'Skafandry mokre i akcesoria',
    'Skafandry Na CIEPŁE wody', 'Skafandry Na ZIMNE wody',
    'Komplety Pianek do nurkowania'
);

-- ============================================
-- 5. Maski i fajki (205 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Maski i fajki'
WHERE category_name IN (
    'Maski jednoszybowe', 'Maski dwuszybowe',
    'Maski korekcyjne', 'Maski panoramiczne',
    'Fajki', 'Zestawy Maska+Fajka'
);

-- ============================================
-- 6. Płetwy (95 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Płetwy'
WHERE category_name IN (
    'Płetwy Paskowe na Buta', 'Płetwy Gumowe JET', 'Płetwy Kaloszowe na Stopę'
);

-- ============================================
-- 7. Wypornościowe (337 produktów)
-- KLASYCZNE + TURYSTYCZNE, LEKKIE = podkategorie jacketów (Karol potwierdził)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Wypornościowe'
WHERE category_name IN (
    'Skrzydła', 'Skrzydła z uprzężą do Poj. Butli', 'Skrzydła z uprzężą do Twina',
    'Jackety (BCD)', 'Side Mount', 'Płyty i uprzęże',
    'Systemy Balastowe', 'Balast',
    'SKRZYDŁA, HYBRYDY', 'Skrzydła i jackety', 'Jackety i skrzydła',
    'Uprzęże i Mocowania',
    'KLASYCZNE', 'TURYSTYCZNE, LEKKIE'
);

-- ============================================
-- 8. Oświetlenie (182 produkty)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Oświetlenie'
WHERE category_name IN (
    'Małe i do Ręki', 'Duże z Głowicą',
    'Oświetlenia Video', 'Baterie i akcesoria'
);

-- ============================================
-- 9. Butle (81 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Butle'
WHERE category_name IN (
    'Butle Stalowe', 'Butle Aluminiowe', 'Butle do Argonu',
    'Twinsety', 'Manifoldy i Obejmy',
    'Zawory do butli', 'Akcesoria do butli', 'Butle nurkowe'
);

-- ============================================
-- 10. Bezpieczeństwo (156 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Bezpieczeństwo'
WHERE category_name IN (
    'Bojki dekompresyjne', 'Bojki i kołowrotki',
    'Noże', 'Szpulki', 'Kołowrotki',
    'Karabinki nurkowe', 'Sygnalizatory', 'Retraktory'
);

-- ============================================
-- 11. Akcesoria Nurkowe (216 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Akcesoria Nurkowe'
WHERE category_name IN (
    'Akcesoria', 'Akcesoria Chemiczne', 'Akcesoria pływackie',
    'Narzędzia i inne', 'Naklejki', 'Tabliczki', 'Logbooki',
    'Gadżety', 'Fotografia i Video'
);

-- ============================================
-- 12. Torby na Sprzęt (42 produkty)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Torby na Sprzęt'
WHERE category_name IN (
    'Torby podróżne', 'Torby na Automaty', 'Torby i Skrzynie'
);

-- ============================================
-- 13. Odzież nurkowa (67 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Odzież nurkowa'
WHERE category_name IN (
    'Bluzy i kurtki', 'Koszulki', 'Czapki', 'Spodnie'
);

-- ============================================
-- 14. Rękawice (15 produktów)
-- ============================================
UPDATE divechat_product_embeddings
SET parent_category_name = 'Rękawice'
WHERE category_name IN (
    'Rękawice i Pierścienie'
);

-- ============================================
-- WYPRZEDAŻE (24) zostaje NULL — decyzja Karol: nie indeksujemy.
-- Literal-only kategorie (Buty, Kaptury, Książki nurkowe, Skrzynie transportowe,
-- Odzież Termoaktywna, Ogrzewanie nurkowe, Morsowanie, Vouchery prezentowe,
-- Zestawy do nurkowania) zostają NULL — działają przez ADR-027 first half OR.
-- ============================================
