<?php
/**
 * Divezone_ChatTokenModuleFrontController — endpoint wydajacy swiezy
 * token klienta dla widgetu czatu (CHAT-T-069, ADR-084).
 *
 * Pierwszy front-controller modulu (dotad tylko admin). Logika tokenu
 * IDENTYCZNA z hookDisplayFooter (Divezone_Chat::hookDisplayFooter):
 *   hash_hmac('sha256', customerId . ':' . timestamp, CLIENT_SECRET)
 * — endpoint zwraca to samo, co PS i tak wstrzykuje w footer przy
 * reloadzie strony, ale na zywo, bez wymuszania reloadu (ADR-084 188a).
 *
 * Bezpieczenstwo (ADR-084):
 *  - customerId WYLACZNIE z $this->context->customer — NIGDY z requestu
 *    (anti-podszycie: inaczej kazdy moglby pobrac token na cudze id i
 *    czytac historie przez /api/chat/history weryfikujace ps_customer_id);
 *  - gating spojny z hookiem (canIssueToken() -> shouldShowWidget()) —
 *    endpoint dziedziczy drabine ekspozycji (CHAT-T-047), 403 gdy widget
 *    nie ma prawa sie pokazac;
 *  - czysty JSON + naglowki anty-cache, exit przed renderem layoutu PS.
 *
 * Sesja PS: widget na divezone.pl, endpoint na divezone.pl (ten sam origin),
 * przegladarka wysyla ciastko sesji automatycznie -> isLogged() rozpoznaje
 * zalogowanego klienta.
 *
 * PS 1.7.6 / PHP 7.2 (bez typed props, bez arrow functions).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Divezone_ChatTokenModuleFrontController extends ModuleFrontController
{
    /**
     * CHAT-T-069 Aneks A (decyzja 194a): whitelista origin dla profilaktycznego
     * CORS. Stan obecny: PS_SHOP_DOMAIN_SSL = `divezone.pl` (apex, PS_SSL_ENABLED=1)
     * -> refresh tokenu z widgetu jest SAME-ORIGIN, przegladarka nie wysyla
     * naglowka Origin dla zwyklego GET i CORS nie jest potrzebny. Whitelist
     * istnieje "na zapas" — odpornosc na przyszla zmiane domeny / migracje www.
     * Tylko https (PS_SSL_ENABLED=1). NIGDY '*' — bo wydajemy credentials.
     */
    private static $ALLOWED_ORIGINS = array(
        'https://divezone.pl',
        'https://www.divezone.pl',
    );

    public function initContent()
    {
        // Czysc bufory wyjscia (BOM, fragmenty layoutu PS) zanim wyemitujemy JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // CHAT-T-069 Aneks A: profilaktyczny CORS. Naglowki ustawiamy TYLKO gdy
        // Origin obecny i z whitelisty — same-origin (brak Origin) nie zmienia
        // zachowania endpointu (zero regresji), obcy origin nie dostaje Allow-Origin
        // (przegladarka zablokuje sama, my nie autoryzujemy obcych).
        $origin = isset($_SERVER['HTTP_ORIGIN']) ? (string)$_SERVER['HTTP_ORIGIN'] : '';
        $originAllowed = ($origin !== '' && in_array($origin, self::$ALLOWED_ORIGINS, true));
        if ($originAllowed) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }

        // Preflight CORS: front uzywa GET z credentials i prostym Accept — w wiekszosci
        // przypadkow preflight nie pojdzie, ale obslugujemy dla pewnosci. 204 bez body,
        // BEZ generowania tokenu (ani sekretu, ani gatingu). Naglowki preflightu tylko
        // gdy origin z whitelisty — inaczej przegladarka zablokuje, a my nie zdradzamy
        // wspieranych metod nieautoryzowanym.
        if (isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
            if ($originAllowed) {
                header('Access-Control-Allow-Methods: GET, OPTIONS');
                header('Access-Control-Allow-Headers: Accept');
                header('Access-Control-Max-Age: 600');
            }
            http_response_code(204);
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $clientSecret = (string)Configuration::get(Divezone_Chat::KEY_CLIENT_SECRET);
        if ($clientSecret === '') {
            http_response_code(500);
            echo json_encode(array('error' => 'config'));
            exit;
        }

        // ADR-087 (CHAT-T-078): gating drabiny ekspozycji przeniesiony z hooka
        // tutaj — endpoint jest jedynym miejscem decydujacym, kto widzi widget.
        // Loader (cache-safe BOOT) najpierw wola ten endpoint i rysuje launcher
        // tylko przy eligible:true. Non-eligible zwracamy jako 200 (NIE 403) —
        // to nie blad protokolu, to legitymna odpowiedz "ten odwiedzajacy nie
        // dostaje widgetu". 403 mogloby tez psuc transport.js (refresh-on-401):
        // brak token w odpowiedzi → settle(false) → cichy fail (graceful).
        $module = Module::getInstanceByName('divezone_chat');
        if (!$module || !method_exists($module, 'canIssueToken') || !$module->canIssueToken()) {
            echo json_encode(array('eligible' => false));
            exit;
        }

        // CustomerId wylacznie z kontekstu PS (ciastko sesji). NIGDY z requestu.
        $customerId = 0;
        if (isset($this->context->customer) && $this->context->customer->isLogged()) {
            $customerId = (int)$this->context->customer->id;
        }

        $timestamp = time();
        $token = hash_hmac('sha256', $customerId . ':' . $timestamp, $clientSecret);

        // ADR-087 (CHAT-T-078): pole `eligible:true` na poczatku — loader sprawdza
        // je przed rysowaniem launchera. token/customerId/time/expires_in jak dotad
        // (transport wysyla je w naglowkach HTTP; expires_in hint dla proaktywnego
        // refreshu, 191c: docelowy TTL backendu 900s).
        echo json_encode(array(
            'eligible'   => true,
            'token'      => $token,
            'customerId' => (string)$customerId,
            'time'       => (string)$timestamp,
            'expires_in' => 900,
        ), JSON_UNESCAPED_SLASHES);
        exit;
    }
}
