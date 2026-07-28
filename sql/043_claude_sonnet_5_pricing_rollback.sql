-- CHAT-T-177 (ADR-139) — rollback cennika claude-sonnet-5.
--
-- ⚠️ NAJPIERW przełącz produkcję z powrotem na 4.6, dopiero potem usuwaj wiersz:
--   UPDATE divechat_settings SET value = '"claude-sonnet-4-6"', updated_at = NOW()
--   WHERE key = 'model_primary';
--
-- Usunięcie cennika przy model_primary = claude-sonnet-5 NIE zatrzyma czatu
-- (PricingService degraduje do kosztu 0.00), ale po cichu wyzeruje raportowany
-- koszt rozmów i schowa model z panelu — czyli stan gorszy niż jawna awaria.
--
-- Sam rollback modelu NIE wymaga usunięcia tego wiersza. Kasuj tylko wtedy, gdy
-- rezygnujemy z Sonnet 5 na dobre i chcemy posprzątać cennik.

DELETE FROM divechat_model_pricing WHERE model_id = 'claude-sonnet-5';
