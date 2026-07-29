-- CHAT-T-180: rate-limit ponownej wysylki informacji o zamowieniu (karta Chat-68).
--
-- Do RECZNEGO uruchomienia na juz-zainstalowanym module (install() modulu NIE
-- wykona sie ponownie bez reinstall/upgrade). Idempotentne (IF NOT EXISTS).
-- Prefix `pr_` = prefiks tabel sklepu divezone.pl. Jesli inny — dostosuj.
--
-- Powiazanie: front controller resend_order_info.php zapisuje tu date_add per
-- id_order i odmawia ponownej wysylki czesciej niz raz na 10 minut (anti-spam,
-- ochrona skrzynki klienta). Bliznaczy CREATE w Divezone_Chat::createResendLogTable().

CREATE TABLE IF NOT EXISTS `pr_divechat_resend_log` (
    `id_order` INT(11) NOT NULL,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
