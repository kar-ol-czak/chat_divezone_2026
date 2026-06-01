-- ============================================
-- DIVEZONE CHAT AI - Rollback Migracja 020
-- Przywraca pid 25 (Apeks XTX50 2.st) na prio 2 w regulator_recreational.
-- CHAT-T-036 — rollback do stanu pre-fix.
--
-- UWAGA: rationale_pl + interval z pierwotnego seedu sql/017
-- (zwraca dokladnie zapisany wczesniej).
-- ============================================

BEGIN;

DELETE FROM divechat_curated_recommendations
WHERE category_key = 'regulator_recreational'
  AND product_id   = 7421;

INSERT INTO divechat_curated_recommendations
    (category_key, category_label_pl, product_id, priority, rationale_pl, recheck_interval_days, verified_at)
VALUES
    ('regulator_recreational',
     'Dobor automatu oddechowego do nurkowania rekreacyjnego (cieple i umiarkowane wody, rekreacja do 40 m).',
     25, 2,
     'Wyzsza polka Apeksa dla nurka, ktory chce automatu z zapasem na lata i mysli o nurkowaniu w zimniejszej wodzie. Lepsza regulacja oddechu niz ATX40.',
     180,
     NOW());

COMMIT;
