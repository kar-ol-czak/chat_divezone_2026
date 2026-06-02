<?php
/**
 * Upgrade 1.0.0 -> 1.0.1 (CHAT-T-037 etap 1).
 *
 * Dodaje warstwe widgetu czatu na froncie sklepu:
 *  - rejestracja hooka displayFooter (emisja stub fasady),
 *  - dwa nowe klucze Configuration:
 *      DIVEZONE_CHAT_CLIENT_SECRET — sekret KLIENCKI (= DIVECHAT_SECRET z .env
 *        backendu), uzywany przez shim PHP do podpisu tokenu HMAC w naglowku
 *        X-DiveChat-Token. UWAGA: INNY niz DIVEZONE_CHAT_SERVER_SECRET (panel
 *        admin, ADR-068). ADR-069 KONSEKWENCJE: dwa rozne sekrety, nie mylic.
 *      DIVEZONE_CHAT_ALLOWED_IPS — CSV lista IP odwiedzajacych, ktorym widget
 *        ma byc widoczny. Pusta lista (default) = widget niewidoczny dla
 *        nikogo (bezpieczny default ADR-069/CHAT-T-037).
 *
 * Idempotentne: ponowne odpalenie upgrade'u nie nadpisze juz ustawionych
 * wartosci sekretu/IP ani nie zlamie tabu admin (installTab byl wczesniej OK).
 *
 * PrestaShop 1.7.6 / PHP 7.2 — bez typed properties, enumow ani matcha.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_0_1($module)
{
    $log = '[divezone_chat upgrade-1.0.1]';

    // 1. Hook displayFooter (front widgetu). registerHook jest idempotentne
    //    w PrestaShop (sprawdza alias przed insertem).
    if (!$module->registerHook('displayFooter')) {
        error_log($log . ' registerHook(displayFooter) failed');
        return false;
    }
    error_log($log . ' registerHook(displayFooter) OK');

    // 2. Nowe klucze Configuration (zachowaj istniejaca wartosc jesli juz ustawiona).
    if (Configuration::get('DIVEZONE_CHAT_CLIENT_SECRET') === false) {
        Configuration::updateValue('DIVEZONE_CHAT_CLIENT_SECRET', '');
        error_log($log . ' init DIVEZONE_CHAT_CLIENT_SECRET=""');
    }
    if (Configuration::get('DIVEZONE_CHAT_ALLOWED_IPS') === false) {
        Configuration::updateValue('DIVEZONE_CHAT_ALLOWED_IPS', '');
        error_log($log . ' init DIVEZONE_CHAT_ALLOWED_IPS=""');
    }

    error_log($log . ' OK');
    return true;
}
