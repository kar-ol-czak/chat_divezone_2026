-- CHAT-T-136 (ADR-119): rejestracja hooka actionValidateOrder na ZYWYM module.
--
-- Uruchamiac RECZNIE NA KONCU deployu (po rsync kodu, cache i utworzeniu tabeli
-- pr_divechat_order_attribution) — hook zaczyna odpalac natychmiast po tym INSERT,
-- wiec kod i tabela musza juz byc na miejscu.
--
-- Wartosci zweryfikowane na PROD 2026-07-14 (bez podzapytan — twarde ID):
--   id_module = 204  (divezone_chat, aktywny)
--   id_hook   = 1    (actionValidateOrder)
--   id_shop   = 1
--   position  = 10   (MAX(position) na tym hooku = 9; wchodzimy PO przelewy24/
--                     stripe/supercheckout/kurierach — celowo, bezpiecznie)
--
-- Idempotentny: INSERT wykona sie tylko gdy wiersza jeszcze nie ma.
-- Prefix `pr_`.

INSERT INTO `pr_hook_module` (`id_module`, `id_shop`, `id_hook`, `position`)
SELECT 204, 1, 1, 10
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM `pr_hook_module`
    WHERE `id_module` = 204 AND `id_hook` = 1 AND `id_shop` = 1
);

-- Weryfikacja: powinien zwrocic dokladnie 1 wiersz z position=10.
SELECT hm.`id_module`, hm.`id_hook`, hm.`id_shop`, hm.`position`, h.`name`
FROM `pr_hook_module` hm
JOIN `pr_hook` h ON h.`id_hook` = hm.`id_hook`
WHERE hm.`id_module` = 204 AND hm.`id_hook` = 1 AND hm.`id_shop` = 1;

-- ROLLBACK (lagodny — hook przestaje odpalac, tabela nietknieta):
--   DELETE FROM `pr_hook_module` WHERE `id_module` = 204 AND `id_hook` = 1;
