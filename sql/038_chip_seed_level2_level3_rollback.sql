-- ============================================
-- DIVEZONE CHAT AI - Rollback migracji 038
-- Przywraca stan sprzed seeda pelnej struktury L1-L3 (CHAT-T-088f).
-- Stan docelowy = 6 wezlow: root, zwroty, serwis, wysylka, dobor, dobor_rozmiar
-- (jak po migracjach 029-033, przed 038).
--
-- WAZNE: najpierw odpinamy wysylka/zwroty spod 'zamowienie' z powrotem pod root,
-- INACZEJ DELETE 'zamowienie' skasowalby je przez ON DELETE CASCADE.
-- ============================================

BEGIN;

-- 1. Odpiecie wysylka/zwroty z powrotem pod root (PRZED DELETE zamowienie).
UPDATE divechat_chip_nodes
SET parent_id = (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    level = 2,
    sort_order = 3,
    label = 'Dostępność i wysyłka',
    buttons = '[{"label":"Koszty i metody dostawy","target":"ai"},{"label":"Inne pytanie","target":"ai"}]'::jsonb,
    updated_at = NOW()
WHERE node_key = 'wysylka';

UPDATE divechat_chip_nodes
SET parent_id = (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    level = 2,
    sort_order = 1,
    label = 'Zwroty i wymiana',
    bot_text = 'Masz 30 dni na zwrot towaru (nasza „Gwarancja zwrotu w ciągu 30 dni"), niezależnie od ustawowych 14 dni na odstąpienie od umowy.

**Jak zwrócić (30-dniowa gwarancja zwrotu):**
1. Wypełnij formularz zwrotu (był w paczce; jest też w mailu z potwierdzeniem i po zalogowaniu w historii zakupów) — podaj dane, numer konta i numer zamówienia.
2. Włóż formularz do paczki z towarem i dowodem zakupu, naklej naklejkę zwrotu z numerem zamówienia.
3. Nadaj na: Divezone.pl Sp. z o.o., ul. Storczykowa 5, 87-100 Toruń.

**Jeśli korzystasz z ustawowego 14-dniowego prawa do odstąpienia od umowy:** wejdź w panel swojego konta i wybierz opcję zwrotu produktu / odstąpienia od umowy sprzedaży.

Ważne: towar musi być pełnowartościowy (bez uszkodzeń, rys, zabrudzeń), sprzęt mierzymy „na sucho". Zwrotów NIE obsługujemy w paczkomatach ani punktach odbioru — paczka musi dotrzeć do siedziby. Koszt odesłania (do ok. 30 zł) po stronie klienta. Środki zwracamy zwykle szybko po otrzymaniu paczki.

Chcesz wymienić na inny rozmiar/kolor? Procedura jest taka sama: zwrot + nowy zakup. Możesz złożyć nowe zamówienie od razu, nie czekając aż paczka do nas wróci.

[Pełna procedura i formularz zwrotu](https://divezone.pl/zwroty-produktow)',
    buttons = '[{"label":"Formularz i szczegóły","target":"link:link_zwroty"},{"label":"Inne pytanie","target":"ai"}]'::jsonb,
    updated_at = NOW()
WHERE node_key = 'zwroty';

-- 2. Usuniecie wszystkich wezlow dodanych w 038 (FK ON DELETE CASCADE sprzata poddrzewa).
DELETE FROM divechat_chip_nodes WHERE node_key IN (
    -- L1 nowe
    'zamowienie', 'zaczynam', 'snorkel',
    -- L2 dobor
    'komputer', 'maska_fajka', 'pletwy', 'automat', 'pianka', 'jacket',
    -- L3
    'maska_do_nurkowania', 'maska_do_snorkelingu', 'zestaw_maska_fajka', 'maska_korekcyjna',
    'pletwy_paskowe', 'pletwy_kaloszowe',
    'pianka_cienka', 'pianka_gruba', 'pianka_shorty',
    -- L2 rozmiar (lisc)
    'pianka_rozmiar', 'suchy_rozmiar', 'pletwy_rozmiar', 'buty_rozmiar', 'kaptur_rekawice', 'nie_wiem_rozmiar',
    -- L2 zamowienie (lisc)
    'status', 'dostepnosc'
);

-- 3. Przywrocenie 'rozmiar' → 'dobor_rozmiar' (stan z migracji 032).
UPDATE divechat_chip_nodes
SET node_key = 'dobor_rozmiar',
    label = 'Dobór rozmiaru',
    sort_order = 5,
    level = 2,
    bot_text = 'Pomogę dobrać rozmiar. Czego rozmiar dobieramy i dla mężczyzny czy kobiety?',
    buttons = '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    ai_prompt = NULL,
    active = TRUE,
    updated_at = NOW()
WHERE node_key = 'rozmiar';

-- 4. Przywrocenie 'dobor' (stan z migracji 032).
UPDATE divechat_chip_nodes
SET label = 'Dobór sprzętu',
    sort_order = 4,
    level = 2,
    bot_text = 'Jasne, pomogę dobrać sprzęt. Co Cię interesuje — maska, płetwy, automat, komputer nurkowy, czy coś innego?',
    buttons = '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    ai_prompt = NULL,
    active = TRUE,
    updated_at = NOW()
WHERE node_key = 'dobor';

-- 5. Przywrocenie serwis (active=TRUE).
UPDATE divechat_chip_nodes SET active = TRUE, updated_at = NOW()
WHERE node_key = 'serwis';

-- 6. Usuniecie kluczy linkow dodanych w 038 (link_zwroty istnial wczesniej — NIE ruszamy).
DELETE FROM divechat_shop_config WHERE key IN ('link_odstapienie', 'link_returns', 'link_withdrawal');

COMMIT;
