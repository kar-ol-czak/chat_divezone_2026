-- 041: CHAT-T-131 (ADR-114) — seed srodka przedzialu cenowego dla regulator_recreational
-- Dziura cenowa w curated: ATX40 2390 zl (prio 1) -> Legend 3776 zl / EVX200 4616 zl.
-- Klient z budzetem ~3000-3600 zl nie mial pozycji blisko gornej granicy budzetu.
-- Dodajemy APEKS XTX50/DST + Octopus XTX40 (pid 3192, 3566 zl brutto, od reki) jako prio 2;
-- EVX200 (7421) schodzi na prio 3 (razem z Legend 5983 — kolejnosc w ramach prio: id ASC).
-- UWAGA: wykonac na Railway PG (DATABASE_URL z .env) — ADR-089 STOP przed zapisem.

BEGIN;

INSERT INTO divechat_curated_recommendations
    (category_key, category_label_pl, product_id, priority, rationale_pl)
VALUES (
    'regulator_recreational',
    (SELECT category_label_pl FROM divechat_curated_recommendations
     WHERE category_key = 'regulator_recreational' LIMIT 1),
    3192,
    2,
    'Zloty srodek dla nurka rekreacyjnego z wiekszym budzetem. XTX50 na pierwszym stopniu DST to wyzsza polka niz ATX40 — lepsza praca w zimnej wodzie, regulacja oporu oddechowego, komfort na lata. Zestaw bez manometru — manometr dobieramy osobno i montujemy przy odbiorze.'
);

UPDATE divechat_curated_recommendations
SET priority = 3
WHERE category_key = 'regulator_recreational' AND product_id = 7421;

COMMIT;
