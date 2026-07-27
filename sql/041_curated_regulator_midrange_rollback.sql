-- Rollback 041: usuniecie seedu XTX50/DST i powrot EVX200 na prio 2

BEGIN;

DELETE FROM divechat_curated_recommendations
WHERE category_key = 'regulator_recreational' AND product_id = 3192;

UPDATE divechat_curated_recommendations
SET priority = 2
WHERE category_key = 'regulator_recreational' AND product_id = 7421;

COMMIT;
