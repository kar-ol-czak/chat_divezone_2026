-- ============================================
-- DIVEZONE CHAT AI - Migracja 020
-- Fix kategorii regulator_recreational: XTX50 2.st -> EVX200 zestaw (CHAT-T-036)
-- Data: 2026-06-01
-- TASK: CHAT-T-036 (pierwszy task z prefiksem CHAT-, konwencja anty-kolizyjna)
-- DECYZJA: 47a
--
-- Problem wykryty przez Karola na panelu LIVE (po CHAT-T-035 CZESC B):
-- kategoria regulator_recreational miala na prio 2 SAM 2. stopien (pid 25,
-- Apeks XTX50 1.stopien — wlasciwie pojedyncza czesc), miedzy dwoma kompletnymi
-- zestawami (prio 1: ATX40/DS4+Octopus, prio 3: Legend 3+Octopus). Polecanie
-- samej czesci miedzy kompletami jest bez sensu dla klienta pytajacego o
-- automat — bot to realnie poda.
--
-- Fix: pid 25 -> pid 7421 (Apeks EVX200 + Octopus EVX200, kompletny zestaw
-- wyzszej polki). Spojna gradacja kategorii: ATX40 (budzet) -> EVX200 (wyzsza
-- polka) -> Legend 3 (premium). Wszystkie trzy = kompletne zestawy.
--
-- pid 7421 zweryfikowany na prod MySQL: name="APEKS EVX200 + Octopus EVX200",
-- active=1, visibility=both, qty=4 (in_stock), price_netto=3752.68 zl.
--
-- Atomicznosc: DELETE+INSERT w jednej transakcji (BEGIN/COMMIT) — UNIQUE
-- (category_key, product_id) wyklucza prosty UPDATE product_id (drugi insert
-- zlamalby constraint przed delete starego). Rollback przywraca pid 25.
-- ============================================

BEGIN;

-- 1. Usun stary wpis (XTX50 2. stopien — sama czesc)
DELETE FROM divechat_curated_recommendations
WHERE category_key = 'regulator_recreational'
  AND product_id   = 25;

-- 2. Wstaw nowy wpis (EVX200 + Octopus — kompletny zestaw)
INSERT INTO divechat_curated_recommendations
    (category_key, category_label_pl, product_id, priority, rationale_pl, recheck_interval_days, verified_at)
VALUES
    ('regulator_recreational',
     'Dobor automatu oddechowego do nurkowania rekreacyjnego (cieple i umiarkowane wody, rekreacja do 40 m).',
     7421, 2,
     'Kompletny zestaw Apeksa z wyzszej polki dla nurka, ktory chce automatu z zapasem na lata, takze do zimniejszej wody. Apeks EVX200 to sprawdzona marka i solidna konstrukcja — krok wyzej niz ATX40, wciaz w rozsadnej cenie.',
     180,
     NOW());

COMMIT;
