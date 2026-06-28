-- ============================================
-- DIVEZONE CHAT AI - Migracja 038 (seed)
-- Pelna struktura drzewa chipow Level 1-3 z analizy 772 realnych rozmow
-- Data: 2026-06-28 | CHAT-T-088f | ADR-103
-- Zrodlo prawdy: _docs/38_propozycja_chipow_level2.md (labele, bot_text, ai_prompt,
--   hierarchia, sort_order — trzymane 1:1).
-- Powiazane: ADR-071/096 (model wezla, "dwa swiaty"), ADR-097 (kontekst sciezki),
--   ADR-063 (modal:order /api/order/status), CHAT-T-088..088e (fundament + Level 1).
--
-- KONWENCJA SCHEMATU (wg migracji 029-033 — NIE ma kolumny action_type ani is_active):
--   - typ wezla wyrazony przez buttons JSONB (target: 'ai' | 'link:<klucz>' | 'modal:order')
--     oraz kolumne ai_prompt; widocznosc = kolumna `active` (boolean).
--   - drzewo budowane przez parent_id + sort_order (silnik CHAT-T-089). Kolumna `level`
--     jest informacyjna (NIE uzywana do renderu). Konwencja: root=1, ring1=2, ring2=3, ring3=4
--     (root=level 1, wiec "Level N" z _docs/38 = DB level N+1).
--   - lisc AI z bot_text+ai_prompt: bot_text + przycisk [{"label":"Napisz czego szukasz","target":"ai"}]
--     (wzorzec z zywego `dobor`; klik przycisku dostarcza ai_prompt liscia, ADR-097/65b).
--   - lisc AI bez bot_text (L3): brak bot_text/buttons — klik chipa od razu → AI (routeChipNode 114a).
--   - wezel nawigacyjny (hybryda): bot_text + dzieci (parent_id), buttons=[], ai_prompt=NULL.
--
-- ZMIANY vs zywy seed (6 wezlow: root, zwroty, serwis, wysylka, dobor, dobor_rozmiar):
--   - serwis  → active=FALSE (ukryty, NIE kasowany; P14b, odwracalne).
--   - dobor_rozmiar → przemianowany na node_key 'rozmiar' = "Pomoc w rozmiarze" (L1 #2);
--     zostaje na poziomie 2, dostaje 6 dzieci (zamiast tworzyc nowy wezel i osierocac stary).
--   - zwroty  → przepiety pod 'zamowienie' (level 3, sort 4); bot_text zachowany.
--   - wysylka → przepiety pod 'zamowienie' jako "Wysylka i platnosci" (level 3, sort 3); bot_text zachowany.
--   - dobor   → zostaje L1 (sort 1), staje sie wezlem nawigacyjnym (6 dzieci), bez przycisku ai.
--
-- Transakcyjna (BEGIN/COMMIT). Rollback: 038_chip_seed_level2_level3_rollback.sql.
-- INSERTy idempotentne (ON CONFLICT (node_key) DO UPDATE). Parent_id zawsze przez subselect
-- po node_key (NIGDY hardcode id). Kolejnosc: L1 → L2 → L3 (rodzic przed dzieckiem).
-- ============================================

BEGIN;

-- =====================================================================
-- 0. KLUCZE LINKOW (P40a) — odstapienie od umowy (osobny formularz od 19-06-2026)
--    + warianty EN. Zywy juz: link_zwroty. URL zweryfikowane jako zywe (2026-06-28).
--    Ten sam mechanizm co mig. 028 (divechat_shop_config, czytane przez get_shop_links
--    i ChipTreeService.loadLinkMap dla target link:<klucz>). NIE hardkodujemy URL w buttons.
-- =====================================================================
INSERT INTO divechat_shop_config (key, value, note) VALUES
    ('link_odstapienie', 'https://divezone.pl/odstapienie-od-umowy',        'Odstąpienie od umowy (osobny formularz, dyrektywa UE od 19-06-2026)'),
    ('link_returns',     'https://divezone.pl/en/returns-refunds',          'EN: Returns & refunds (odpowiednik link_zwroty)'),
    ('link_withdrawal',  'https://divezone.pl/en/withdrawal-from-contract', 'EN: Withdrawal from contract (odpowiednik link_odstapienie)')
ON CONFLICT (key) DO UPDATE SET
    value = EXCLUDED.value, note = EXCLUDED.note, updated_at = NOW();

-- =====================================================================
-- 1a. KOREKTA LEVEL 1
-- =====================================================================

-- serwis: ukryty (3 wzmianki w rozmowach). NIE DELETE — odwracalne (P14b).
UPDATE divechat_chip_nodes SET active = FALSE, updated_at = NOW()
WHERE node_key = 'serwis';

-- dobor: zostaje L1 (sort 1), wezel nawigacyjny do 6 kategorii. Usuwamy przycisk ai
-- (zejscie przez dzieci), bot_text = krotki wstep (dzieci same wymieniaja kategorie).
UPDATE divechat_chip_nodes
SET label = 'Dobór sprzętu',
    sort_order = 1,
    level = 2,
    bot_text = 'Pomogę dobrać sprzęt. Co Cię interesuje?',
    buttons = '[]'::jsonb,
    ai_prompt = NULL,
    active = TRUE,
    updated_at = NOW()
WHERE node_key = 'dobor';

-- dobor_rozmiar → 'rozmiar' = "Pomoc w rozmiarze" (L1 #2). Przemianowanie node_key
-- (brak FK po node_key — referencje ida przez id), bez osierocania.
UPDATE divechat_chip_nodes
SET node_key = 'rozmiar',
    label = 'Pomoc w rozmiarze',
    sort_order = 2,
    level = 2,
    bot_text = 'Pomogę dobrać rozmiar. Czego rozmiar Cię interesuje?',
    buttons = '[]'::jsonb,
    ai_prompt = NULL,
    active = TRUE,
    updated_at = NOW()
WHERE node_key = 'dobor_rozmiar';

-- zamowienie (L1 #5): wezel nawigacyjny do 4 dzieci (status/dostepnosc/wysylka/zwroty).
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, active)
VALUES (
    'zamowienie',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    2, 5,
    'Moje zamówienie',
    'Co chcesz sprawdzić w swoim zamówieniu?',
    '[]'::jsonb,
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    active = TRUE, updated_at = NOW();

-- zaczynam (L1 #3): lisc AI (kompletowanie pierwszego zestawu po kursie/OWD).
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'zaczynam',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    2, 3,
    'Zaczynam nurkować',
    'Pomogę skompletować sprzęt na start. Powiedz: po kursie OWD czy dopiero planujesz, gdzie zamierzasz nurkować i jaki masz budżet?',
    '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    'Klient zaczyna NURKOWANIE z butlą (nie snorkeling — to osobny chip). Realny język z rozmów: ''dopiero zaczynam OWD'', ''co kupić na start'', ''pierwszy sprzęt''. Zapytaj: po kursie/w trakcie czy planuje, gdzie (Polska-zimna / wakacje-ciepła), budżet. Typowy pierwszy zakup to sprzęt osobisty (maska, płetwy, komputer), nie cały zestaw od razu — doradź kolejność. Jeśli dla DZIECKA/nastolatka — zapytaj o wiek (sprzęt juniorski, mniejsze rozmiary). Pokaż 2–3 propozycje z linkami.',
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- snorkel (L1 #4): lisc AI (snorkeling/wakacje/basen, czesto dzieci). P31a — osobny chip.
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'snorkel',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    2, 4,
    'Maska i rurka (snorkeling)',
    'Pomogę dobrać sprzęt do pływania z maską i rurką. Powiedz: na wakacje (ciepłe wody) czy na basen, dla dorosłego czy dziecka, jaki budżet?',
    '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    'Klient chce sprzęt do SNORKELINGU (pływanie z maską i rurką z powierzchni, BEZ butli). Realny język: ''snorkeling'', ''snurkowanie'', ''rurka'', ''maska z rurką'', wakacje/Egipt/Chorwacja/rafy. Zapytaj: wakacje vs basen, dorosły vs DZIECKO (jeśli dziecko — wiek, sprzęt juniorski), budżet (często do 200–300 zł). Podstawowy zestaw snorkel = maska + rurka (+ ewentualnie płetwy). NIE myl z ''Zestaw maska z fajką'' w doborze maski (tam dobór konkretnej pary; tu kompletowanie sprzętu na aktywność). Pełnotwarzowa maska — wspomnij o ograniczeniach jeśli klient pyta. Pokaż 2–3 propozycje.',
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- =====================================================================
-- 1b. LEVEL 2 — Dobór sprzętu (parent=dobor), kolejnosc wg czestosci w rozmowach
-- =====================================================================

-- 1. Komputer nurkowy [67] → lisc AI
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'komputer',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'dobor'),
    3, 1,
    'Komputer nurkowy',
    'Pomogę dobrać komputer. Powiedz: na jakim jesteś poziomie (kurs/rekreacja, nitrox, technika-trimix), jaki budżet i czy ma służyć też jako zegarek na co dzień.',
    '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    'Dobór komputera nurkowego. Realne osie z rozmów klientów: BUDŻET (najczęściej 1500–3000 zł), POZIOM (początkujący po OWD / nitrox / techniczny-trimix), STYL (zegarek codzienny vs konsola), MARKA jeśli klient ją poda (Suunto, Shearwater, Garmin, Mares — częste). Zapytaj o to, czego klient nie podał. Pokaż 2–3 modele z linkami. Nie moralizuj przy głębokościach/uprawnieniach — klient pyta o produkt.',
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- 2. Maska i fajka [69] → wezel nawigacyjny (Level 3)
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'maska_fajka',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'dobor'),
    3, 2,
    'Maska i fajka',
    'Pomogę dobrać maskę. Do czego ma być?',
    '[]'::jsonb,
    NULL,
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- 3. Pletwy [30] → wezel nawigacyjny (Level 3)
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'pletwy',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'dobor'),
    3, 3,
    'Płetwy',
    'Pomogę dobrać płetwy. Na bose stopy czy na buty neoprenowe?',
    '[]'::jsonb,
    NULL,
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- 4. Automat oddechowy [31] → lisc AI
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'automat',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'dobor'),
    3, 4,
    'Automat oddechowy',
    'Pomogę dobrać automat. Powiedz: pierwszy automat czy wymiana, jaki budżet i czy masz na oku konkretny model.',
    '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    'Dobór automatu. Realne osie z rozmów: MARKA/MODEL (klient często zna nazwę — Apeks XTX/ATX, Scubapro, Mares, Hollis), BUDŻET/PÓŁKA (do 1500 / średnia / premium — pada wprost), POZIOM (pierwszy automat / doświadczony). Zapytaj o to, czego nie podał. Octopus i manometr zaproponuj jako naturalny dodatek do zestawu. NIE pisz ''DIN'' (wszystkie są DIN). NIE używaj osi zimna/ciepła woda jako kryterium doboru — w rozmowach to nie pada. Pokaż 2–3 modele.',
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- 5. Pianka mokra [18] → wezel nawigacyjny (Level 3)
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'pianka',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'dobor'),
    3, 5,
    'Pianka mokra',
    'Pomogę dobrać piankę mokrą. Do jakiej wody?',
    '[]'::jsonb,
    NULL,
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- 6. Jacket / skrzydlo [13] → lisc AI
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'jacket',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'dobor'),
    3, 6,
    'Jacket / skrzydło',
    'Pomogę dobrać sprzęt wypornościowy. Jedna butla czy twin? Nurkujesz w suchym skafandrze?',
    '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    'Dobór jacket/skrzydło (BCD). Zapytaj: pojedyncza butla vs twin, suchy skafander tak/nie, rekreacja vs technika, budżet. Jednobutlowe BCD 13–16 L wyporności; twin 18–22 L i ZAWSZE paruje się z suchym skafandrem (nie grubą pianką). Jacket = rekreacja/wygoda, skrzydło = technika/konfiguracja. Pokaż modele.',
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- =====================================================================
-- 1c. LEVEL 3 (lisc AI bez bot_text — klik chipa od razu → AI)
-- =====================================================================

-- pod maska_fajka (4)
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, ai_prompt, active)
VALUES
 ('maska_do_nurkowania', (SELECT id FROM divechat_chip_nodes WHERE node_key='maska_fajka'), 4, 1,
  'Do nurkowania',
  'Maska do nurkowania z butlą. Zapytaj o korekcję wzroku (są maski korekcyjne), jedno/dwuszybowa, budżet. Maski NIE mają rozmiarów S/M/L w obrębie modelu — dopasowanie = kształt twarzy, sugeruj przymiarkę. Pokaż 2–3 modele.',
  TRUE),
 ('maska_do_snorkelingu', (SELECT id FROM divechat_chip_nodes WHERE node_key='maska_fajka'), 4, 2,
  'Do snorkelingu',
  'Maska do snorkelingu (najczęstsza intencja w rozmowach, 19 wzmianek). Zapytaj o budżet (często do 200–300 zł), wakacje/Egipt/Chorwacja, czy dla dziecka. Maska snorkelingowa ≠ pełnotwarzowa — przy pełnotwarzowej ostrzeż o ograniczeniach. Pokaż 2–3 modele.',
  TRUE),
 ('zestaw_maska_fajka', (SELECT id FROM divechat_chip_nodes WHERE node_key='maska_fajka'), 4, 3,
  'Zestaw maska z fajką',
  'Klient chce KOMPLET maska+fajka (nie cały sprzęt na start — to osobny chip). Dobierz pasującą parę maska+fajka, zapytaj o budżet i snorkeling vs nurkowanie. Pokaż 1–2 gotowe zestawy lub pasującą parę.',
  TRUE),
 ('maska_korekcyjna', (SELECT id FROM divechat_chip_nodes WHERE node_key='maska_fajka'), 4, 4,
  'Korekcyjna',
  'Maska korekcyjna (wada wzroku). Zapytaj o moc korekcji (dioptrie) i czy klient nurkuje, czy snorkeluje. Wyjaśnij, że soczewki korekcyjne dobiera się do modelu maski. Skieruj na konsultację 56 307 03 03 przy nietypowej korekcji.',
  TRUE)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- pod pletwy (2)
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, ai_prompt, active)
VALUES
 ('pletwy_paskowe', (SELECT id FROM divechat_chip_nodes WHERE node_key='pletwy'), 4, 1,
  'Paskowe na but neoprenowy',
  'Płetwy paskowe (na buty neoprenowe) = standard techniczny/zimna woda. Zapytaj o poziom, budżet, rozmiar buta neoprenowego. Pokaż modele.',
  TRUE),
 ('pletwy_kaloszowe', (SELECT id FROM divechat_chip_nodes WHERE node_key='pletwy'), 4, 2,
  'Kaloszowe na gołą stopę',
  'Płetwy kaloszowe (na bosą stopę) = wakacje/ciepła woda/rekreacja. Zapytaj o rozmiar buta, budżet. Pokaż modele.',
  TRUE)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- pod pianka (3)
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, ai_prompt, active)
VALUES
 ('pianka_cienka', (SELECT id FROM divechat_chip_nodes WHERE node_key='pianka'), 4, 1,
  'Cienka na ciepłe wody',
  'Pianka cienka (2–3 mm) na ciepłe wody/wakacje. Zapytaj płeć, wzrost/wagę do rozmiaru, budżet. Rozmiar po wymiarach ciała, nie po ubraniach. Pokaż modele.',
  TRUE),
 ('pianka_gruba', (SELECT id FROM divechat_chip_nodes WHERE node_key='pianka'), 4, 2,
  'Gruba na zimne wody',
  'Pianka gruba (5–7 mm) na zimne/polskie wody. Zapytaj płeć, wymiary, czy z kapturem. Pokaż modele. Przy bardzo zimnej wodzie/twin wspomnij, że alternatywą jest suchy skafander.',
  TRUE),
 ('pianka_shorty', (SELECT id FROM divechat_chip_nodes WHERE node_key='pianka'), 4, 3,
  'Krótka (shorty)',
  'Pianka krótka shorty (basen/ciepłe wody/dodatkowa warstwa). Zapytaj płeć, wymiary, zastosowanie. Pokaż modele.',
  TRUE)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- =====================================================================
-- 1d. LEVEL 2 — Pomoc w rozmiarze (parent=rozmiar) — 6 lisci AI (bot_text + ai_prompt)
--     node_key z sufiksem _rozmiar (kolizja z galezia doboru: pianka, pletwy).
-- =====================================================================

INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES
 ('pianka_rozmiar', (SELECT id FROM divechat_chip_nodes WHERE node_key='rozmiar'), 3, 1,
  'Pianka mokra',
  'Rozmiar pianki dobieramy po wymiarach ciała, nie po rozmiarze ubrań. Podaj: wzrost, wagę, obwód klatki, pasa i bioder.',
  '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
  'Rozmiar pianki mokrej. Zbierz: wzrost, waga, obwód klatki/pasa/bioder, płeć. Pianka ma leżeć ŚCIŚLE. Przy nietypowych proporcjach kieruj na konsultację 56 307 03 03.',
  TRUE),
 ('suchy_rozmiar', (SELECT id FROM divechat_chip_nodes WHERE node_key='rozmiar'), 3, 2,
  'Suchy skafander',
  'Suchy skafander to sprzęt na lata, dobieramy starannie. Nie mamy pełnej rozmiarówki do przymierzenia od ręki — najlepiej przyślij wymiary (wzrost, obwód klatki/pasa/bioder + nietypowe: biceps, łydki) na dive@divezone.pl lub zadzwoń 56 307 03 03, a ściągniemy najbliższe rozmiary na umówiony termin.',
  '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
  'Rozmiar suchego skafandra. Zbierz wymiary jw. NIE obiecuj przymiarki od ręki. Kieruj na kontakt mailowy/telefon z wymiarami i umówienie terminu. Santi to najczęściej pytana marka.',
  TRUE),
 ('pletwy_rozmiar', (SELECT id FROM divechat_chip_nodes WHERE node_key='rozmiar'), 3, 3,
  'Płetwy',
  'Rozmiar płetw zależy od rozmiaru buta (i czy na bose stopy, czy na buty neoprenowe). Podaj rozmiar buta.',
  '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
  'Rozmiar płetw. Zapytaj o rozmiar buta i czy paskowe (na buty neoprenowe — wtedy uwzględnij grubość buta) czy kaloszowe (na bosą stopę). Dopasuj do rozmiarówki modelu.',
  TRUE),
 ('buty_rozmiar', (SELECT id FROM divechat_chip_nodes WHERE node_key='rozmiar'), 3, 4,
  'Buty neoprenowe',
  'Rozmiar butów neoprenowych dobieramy zwykle wg rozmiaru obuwia, ale grubość neoprenu i krój mają znaczenie. Podaj swój rozmiar buta.',
  '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
  'Rozmiar butów neoprenowych. Zapytaj o rozmiar obuwia. Przy grubszym neoprenie czasem +1 rozmiar. Buty do płetw paskowych = inny dobór niż do bosej stopy.',
  TRUE),
 ('kaptur_rekawice', (SELECT id FROM divechat_chip_nodes WHERE node_key='rozmiar'), 3, 5,
  'Kaptur / rękawice',
  'Kaptur dobieramy po obwodzie głowy, rękawice po rozmiarze dłoni. Powiedz, co Cię interesuje, i podaj wymiar.',
  '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
  'Rozmiar kaptura (obwód głowy w cm) lub rękawic (zwykły rozmiar S/M/L/XL lub obwód dłoni). Dopasuj do tabeli modelu.',
  TRUE),
 ('nie_wiem_rozmiar', (SELECT id FROM divechat_chip_nodes WHERE node_key='rozmiar'), 3, 6,
  'Nie wiem, co zmierzyć',
  'Napisz, jakiego sprzętu rozmiar Cię interesuje — powiem, co zmierzyć, i pomogę dobrać.',
  '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
  'Klient nie wie, jaki wymiar podać. Ustal sprzęt, powiedz jakie wymiary potrzebne, zbierz je.',
  TRUE)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- =====================================================================
-- 1e. LEVEL 2 — Moje zamówienie (parent=zamowienie) — 4 wezly
--     status (modal:order, ADR-063) · dostepnosc (ai) · wysylka (przepiety, det.) · zwroty (przepiety, det.)
-- =====================================================================

-- status: deterministyczny formularz → modal:order → /api/order/status (front obsluguje modal:order)
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, active)
VALUES (
    'status',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'zamowienie'),
    3, 1,
    'Status / gdzie moja paczka',
    'Sprawdzę status. Podaj numer zamówienia i e-mail z zamówienia.',
    '[{"label":"Sprawdź status zamówienia","target":"modal:order"}]'::jsonb,
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = NULL, active = TRUE, updated_at = NOW();

-- dostepnosc: lisc AI
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, label, bot_text, buttons, ai_prompt, active)
VALUES (
    'dostepnosc',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'zamowienie'),
    3, 2,
    'Dostępność produktu',
    'Sprawdzę dostępność. Napisz, który produkt Cię interesuje.',
    '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    'Klient pyta o dostępność konkretnego produktu. Sprawdź stan (uwaga: stan na rekordach kombinacji, nie na parencie). Trzy stany: dostępny od ręki / dostępny w magazynie 2–5 dni (out_of_stock pozwala zamówić) / niedostępny. Nie zmyślaj wiedzy o stanie magazynu stacjonarnego w Toruniu — kieruj na kontakt, jeśli klient pyta o sklep stacjonarny.',
    TRUE
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    label = EXCLUDED.label, bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons,
    ai_prompt = EXCLUDED.ai_prompt, active = TRUE, updated_at = NOW();

-- wysylka: PRZEPIECIE z L1 pod zamowienie (zachowany bot_text), relabel "Wysylka i platnosci".
-- _docs/38 #3 = deterministyczny + link do platnosci. Pelna tresc kosztow/platnosci
-- "do uzupelnienia z aktualnych danych sklepu" (_docs/38) — przycisk ''Koszty i metody
-- dostawy''→ai zostawiony, bo koszty sa zmienne (get_shipping_info) do czasu wpisania tabeli.
UPDATE divechat_chip_nodes
SET parent_id = (SELECT id FROM divechat_chip_nodes WHERE node_key = 'zamowienie'),
    level = 3,
    sort_order = 3,
    label = 'Wysyłka i płatności',
    buttons = '[{"label":"Formy płatności","target":"link:link_platnosci"},{"label":"Koszty i metody dostawy","target":"ai"}]'::jsonb,
    active = TRUE,
    updated_at = NOW()
WHERE node_key = 'wysylka';

-- zwroty: PRZEPIECIE z L1 pod zamowienie. _docs/38 #4 (P38a/P40a) = DETERMINISTYCZNY, ZERO AI,
-- ALE Z PRZYCISKAMI. bot_text = dwa tryby (14 dni ustawowe + Gwarancja 30 dni; warunki 1:1
-- z _docs/38) + odstapienie. buttons = 2 LINKI (link_zwroty + link_odstapienie), ZERO target ai.
UPDATE divechat_chip_nodes
SET parent_id = (SELECT id FROM divechat_chip_nodes WHERE node_key = 'zamowienie'),
    level = 3,
    sort_order = 4,
    label = 'Zwroty i reklamacje',
    bot_text = 'Towar możesz zwrócić na dwa sposoby. Tryb ustawowy: 14 dni od otrzymania, bez podania przyczyny. Nasza Gwarancja zwrotu 30 dni: dłuższy czas, ale towar musi być pełnowartościowy (bez uszkodzeń, rys i zabrudzeń), w oryginalnym opakowaniu, sprzęt mierzony tylko „na sucho", a paczka musi dotrzeć do naszej siedziby (nie obsługujemy zwrotów w paczkomatach ani punktach odbioru). Aby formalnie odstąpić od umowy, skorzystaj z osobnego formularza.',
    buttons = '[{"label":"Zasady zwrotów","target":"link:link_zwroty"},{"label":"Odstąpienie od umowy","target":"link:link_odstapienie"}]'::jsonb,
    ai_prompt = NULL,
    active = TRUE,
    updated_at = NOW()
WHERE node_key = 'zwroty';

COMMIT;
