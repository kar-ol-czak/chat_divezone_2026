-- ============================================
-- DIVEZONE CHAT AI - Migracja 017
-- Seed kuratorowanych rekomendacji: automaty + komputery (ADR-065)
-- Data: 2026-06-01
-- TASK: T-031 Faza B
--
-- Wybor produktow, kategorii i rationale_pl = decyzja Karola (172c: Subiekt
-- to input rankingu, nie wyrocznia). Ranking kandydatow z T-031 Faza A
-- (Subiekt 12mc, mapowanie SKU->product_id przez pr_product.reference).
--
-- 3 kategorie:
--   regulator_recreational    — dobor automatu do rekreacji
--   computer_budget_first     — pierwszy/najtanszy komputer (klient cenowy)
--   computer_polish_waters    — komputer pod polska wode / czytelnosc (klient
--                               gotowy doplacic)
-- Shearwater Peregrine (5986) celowo w obu kategoriach komputerow
-- (UNIQUE per category_key+product_id to dopuszcza) — pasuje do obu intencji.
--
-- Cena/dostepnosc NIE sa tu zapisane — pobierane z zywego MySQL per request
-- (ADR-065 twardy staleness). Produkty active=false pominiete w doborze.
-- ============================================

INSERT INTO divechat_curated_recommendations
    (category_key, category_label_pl, product_id, priority, rationale_pl, recheck_interval_days)
VALUES
-- ---------- AUTOMATY ----------
('regulator_recreational',
 'Dobor automatu oddechowego do nurkowania rekreacyjnego (cieple i umiarkowane wody, rekreacja do 40 m).',
 2368, 1,
 'Nasz najczesciej wybierany automat od lat. Apeks ATX40/DS4 to sprawdzona konstrukcja zimnowodna, ktora sprzedalismy w setkach egzemplarzy. Niezawodny, latwy w serwisie, swietny stosunek jakosci do ceny.',
 180),
('regulator_recreational',
 'Dobor automatu oddechowego do nurkowania rekreacyjnego (cieple i umiarkowane wody, rekreacja do 40 m).',
 25, 2,
 'Wyzsza polka Apeksa dla nurka, ktory chce automatu z zapasem na lata i mysli o nurkowaniu w zimniejszej wodzie. Lepsza regulacja oddechu niz ATX40.',
 180),
('regulator_recreational',
 'Dobor automatu oddechowego do nurkowania rekreacyjnego (cieple i umiarkowane wody, rekreacja do 40 m).',
 5983, 3,
 'Topowy automat premium dla wymagajacych. Aqualung Legend to legenda klasy, komfort oddychania na kazdej glebokosci rekreacyjnej.',
 180),

-- ---------- KOMPUTERY: KLIENT CENOWY ----------
('computer_budget_first',
 'Pierwszy, mozliwie tani komputer nurkowy do rekreacji. Klient pyta o najtanszy sensowny start, liczy sie cena.',
 5060, 1,
 'Najrozsadniejszy budzetowy start. Wyswietlacz monochromatyczny, ale czytelny i niezawodny. Zoop Novo to klasyk pierwszego komputera, sprzedajemy go od lat. Gdy budzet jest priorytetem, pewny wybor.',
 90),
('computer_budget_first',
 'Pierwszy, mozliwie tani komputer nurkowy do rekreacji. Klient pyta o najtanszy sensowny start, liczy sie cena.',
 5706, 2,
 'Troche drozej, ale dostajesz kolorowy wyswietlacz i forme zegarka na co dzien. Dobry wybor, gdy chcesz cos ladniejszego niz Zoop bez wchodzenia w najwyzsza polke.',
 90),
('computer_budget_first',
 'Pierwszy, mozliwie tani komputer nurkowy do rekreacji. Klient pyta o najtanszy sensowny start, liczy sie cena.',
 5986, 3,
 'Jesli budzet pozwala odrobine wiecej, to nasz bestseller. Kolorowy ekran, banalna obsluga i komputer, ktory zostanie z Toba na lata.',
 90),

-- ---------- KOMPUTERY: POLSKA WODA / CZYTELNOSC ----------
('computer_polish_waters',
 'Komputer dla nurka w polskiej / krajowej wodzie albo dla kogos, kto ceni duzy czytelny wyswietlacz i jest gotow doplacic. Klient nie pyta o najtaniej, pyta o czytelnosc i jakosc.',
 5986, 1,
 'Duzy, kolorowy, swietnie czytelny wyswietlacz, idealny w ograniczonej widocznosci polskich wod. Nasz najczesciej polecany komputer dla nurka, ktory chce czytelnosc i niezawodnosc.',
 90),
('computer_polish_waters',
 'Komputer dla nurka w polskiej / krajowej wodzie albo dla kogos, kto ceni duzy czytelny wyswietlacz i jest gotow doplacic. Klient nie pyta o najtaniej, pyta o czytelnosc i jakosc.',
 7515, 2,
 'Najnowszy komputer Suunto z duzym, bardzo czytelnym ekranem, pomyslany pod nurkowanie w trudniejszych warunkach. Kosztuje wyraznie wiecej niz Zoop czy D5, ale to inna liga czytelnosci. Suunto, marka bez ryzyka.',
 90),
('computer_polish_waters',
 'Komputer dla nurka w polskiej / krajowej wodzie albo dla kogos, kto ceni duzy czytelny wyswietlacz i jest gotow doplacic. Klient nie pyta o najtaniej, pyta o czytelnosc i jakosc.',
 5463, 3,
 'Duzy kolorowy wyswietlacz, mocny komputer z zapasem na rozwoj (nitrox, tryby zaawansowane). Dla nurka, ktory mysli dlugofalowo i chce jednego komputera na wszystko.',
 90)

ON CONFLICT (category_key, product_id) DO UPDATE
    SET category_label_pl     = EXCLUDED.category_label_pl,
        priority              = EXCLUDED.priority,
        rationale_pl          = EXCLUDED.rationale_pl,
        recheck_interval_days = EXCLUDED.recheck_interval_days,
        verified_at           = NOW(),
        active                = TRUE;
