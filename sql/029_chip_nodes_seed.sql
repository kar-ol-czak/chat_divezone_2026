-- ============================================
-- DIVEZONE CHAT AI - Seed 029 (drzewo chipów: gałąź operacyjna)
-- Data: 2026-06-13
-- ADR: ADR-096 (model węzła), _docs/37_tresc_chipow_operacyjnych.md (treść), ADR-095 (wysyłka)
--
-- Osobny od 029_chip_nodes.sql (struktura) — pytanie otwarte 1: errata treści
-- nie wymaga re-migracji struktury.
--
-- Seeduje: root (poziom 1) + 4 węzły operacyjne (poziom 2): zwroty, serwis,
-- wysylka, dobor (placeholder — pełna gałąź doboru PO sesji zespołu).
--
-- Hybryda (32b): zwroty/serwis MAJĄ bot_text ORAZ buttons jednocześnie.
-- Q231a: zwroty/serwis/wysylka = rdzeń deterministyczny (bot_text wprost, bez LLM);
-- tylko przyciski 'Inne pytanie'/'Napisz czego szukasz' (target=ai) schodzą do LLM.
--
-- Idempotentny: ON CONFLICT (node_key) DO UPDATE — re-seed nadpisuje treść (errata).
-- parent_id dzieci = id korzenia (subselect po node_key='root') — root MUSI być pierwszy.
-- ============================================

-- ---- Poziom 1: root ----
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, bot_text, buttons)
VALUES ('root', NULL, 1, 0, 'W czym mogę pomóc?', '[]'::jsonb)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id,
    level = EXCLUDED.level,
    sort_order = EXCLUDED.sort_order,
    bot_text = EXCLUDED.bot_text,
    buttons = EXCLUDED.buttons,
    updated_at = NOW();

-- ---- Poziom 2: zwroty (hybryda: tekst + przyciski) ----
-- Treść z _docs/37_ — WYGŁADZONA: urwane zdanie "...14 dniowego prawa do zwrotu,"
-- domknięte do pełnego ("...14-dniowego prawa do odstąpienia od umowy: wejdź w panel...").
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, bot_text, buttons)
VALUES (
    'zwroty',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    2, 1,
    'Masz 30 dni na zwrot towaru (nasza „Gwarancja zwrotu w ciągu 30 dni"), niezależnie od ustawowych 14 dni na odstąpienie od umowy.

**Jak zwrócić (30-dniowa gwarancja zwrotu):**
1. Wypełnij formularz zwrotu (był w paczce; jest też w mailu z potwierdzeniem i po zalogowaniu w historii zakupów) — podaj dane, numer konta i numer zamówienia.
2. Włóż formularz do paczki z towarem i dowodem zakupu, naklej naklejkę zwrotu z numerem zamówienia.
3. Nadaj na: Divezone.pl Sp. z o.o., ul. Storczykowa 5, 87-100 Toruń.

**Jeśli korzystasz z ustawowego 14-dniowego prawa do odstąpienia od umowy:** wejdź w panel swojego konta i wybierz opcję zwrotu produktu / odstąpienia od umowy sprzedaży.

Ważne: towar musi być pełnowartościowy (bez uszkodzeń, rys, zabrudzeń), sprzęt mierzymy „na sucho". Zwrotów NIE obsługujemy w paczkomatach ani punktach odbioru — paczka musi dotrzeć do siedziby. Koszt odesłania (do ok. 30 zł) po stronie klienta. Środki zwracamy zwykle szybko po otrzymaniu paczki.

Chcesz wymienić na inny rozmiar/kolor? Procedura jest taka sama: zwrot + nowy zakup. Możesz złożyć nowe zamówienie od razu, nie czekając aż paczka do nas wróci.',
    '[{"label":"Formularz i szczegóły","target":"link:link_zwroty"},{"label":"Inne pytanie","target":"ai"}]'::jsonb
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons, updated_at = NOW();

-- ---- Poziom 2: serwis (hybryda: tekst + przyciski) ----
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, bot_text, buttons)
VALUES (
    'serwis',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    2, 2,
    'Serwisujemy automaty oddechowe marek: Apeks, Aqualung, Scubapro, Scubatech, Tecline. Każdy automat czyścimy w myjce ultradźwiękowej i regulujemy na urządzeniu pomiarowym Magnahelic.

**Ceny usługi serwisu (1. + 2. stopień):**
- Apeks, Aqualung, Scubatech, Tecline — 190 zł
- Scubapro — 220 zł
- Octopus: Apeks/Aqualung/Scubatech/Tecline — 80 zł, Scubapro — 90 zł

Do ceny usługi doliczamy koszt oryginalnego kompletu serwisowego producenta (Service KIT). Stosujemy wyłącznie fabryczne zestawy Apeks, Aqualung, Scubapro, Scubatech.

Termin ustalamy indywidualnie — skontaktuj się wcześniej (serwis@divezone.pl), żeby umówić serwis. Automat zapakuj w karton, dołącz kartkę z danymi (imię, nazwisko, telefon, adres zwrotny) i wyślij dowolnym kurierem na adres sklepu.',
    '[{"label":"Pełny cennik","target":"link:link_serwis"},{"label":"Umów serwis","target":"link:link_kontakt"},{"label":"Inne pytanie","target":"ai"}]'::jsonb
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons, updated_at = NOW();

-- ---- Poziom 2: wysylka (ADR-095 dec.1 — komunikat probabilistyczny + asekuracja) ----
-- Koszty/metody NIE w tekście (zmienne) — przycisk 'Koszty i metody dostawy' → ai (get_shipping_info).
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, bot_text, buttons)
VALUES (
    'wysylka',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    2, 3,
    'Zamówienia złożone do 15:00 w dni robocze zwykle wysyłamy tego samego dnia, a większość paczek dociera następnego dnia roboczego. Nie gwarantujemy terminu — doręczenie jest po stronie kuriera. Po 100% pewność (np. przed wyjazdem) zadzwoń: 56 307 03 03.',
    '[{"label":"Koszty i metody dostawy","target":"ai"},{"label":"Inne pytanie","target":"ai"}]'::jsonb
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons, updated_at = NOW();

-- ---- Poziom 2: dobor (PLACEHOLDER — pełna gałąź doboru sprzętu PO sesji zespołu) ----
INSERT INTO divechat_chip_nodes (node_key, parent_id, level, sort_order, bot_text, buttons, context_hint)
VALUES (
    'dobor',
    (SELECT id FROM divechat_chip_nodes WHERE node_key = 'root'),
    2, 4,
    'Pomogę dobrać sprzęt. Co Cię interesuje?',
    '[{"label":"Napisz czego szukasz","target":"ai"}]'::jsonb,
    NULL
)
ON CONFLICT (node_key) DO UPDATE SET
    parent_id = EXCLUDED.parent_id, level = EXCLUDED.level, sort_order = EXCLUDED.sort_order,
    bot_text = EXCLUDED.bot_text, buttons = EXCLUDED.buttons, context_hint = EXCLUDED.context_hint,
    updated_at = NOW();
