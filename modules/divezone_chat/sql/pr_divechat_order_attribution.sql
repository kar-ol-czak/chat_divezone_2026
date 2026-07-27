-- CHAT-T-136 (ADR-119): atrybucja deterministyczna czatu — tabela w MySQL PrestaShop.
--
-- Do RECZNEGO uruchomienia na juz-zainstalowanym module (install() modulu NIE
-- wykona sie ponownie bez reinstall/upgrade). Idempotentne (IF NOT EXISTS).
-- Prefix `pr_` = prefiks tabel sklepu divezone.pl. Jesli inny — dostosuj.
--
-- Powiazanie: hook actionValidateOrder w divezone_chat.php zapisuje tu pare
-- id_order <-> chat_session_id przy zatwierdzeniu zamowienia. Zrodlo cookie:
-- widget-bundle.js setAttributionCookies (domena sklepu, Consent Mode nie blokuje).
-- Spec: _docs/12_atrybucja_czatu.md sekcja 4.

CREATE TABLE IF NOT EXISTS `pr_divechat_order_attribution` (
    `id_attribution` INT(11) NOT NULL AUTO_INCREMENT,
    `id_order` INT(11) NOT NULL,
    `chat_session_id` VARCHAR(64) NOT NULL,
    `attribution_type` ENUM('last_touch','assist') NOT NULL DEFAULT 'assist',
    `conversation_last_at` DATETIME NULL DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_attribution`),
    UNIQUE KEY `uniq_id_order` (`id_order`),
    KEY `idx_chat_session_id` (`chat_session_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
