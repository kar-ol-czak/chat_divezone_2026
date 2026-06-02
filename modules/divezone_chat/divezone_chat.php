<?php
/**
 * DiveZone Chat — modul administracyjny PrestaShop (ADR-067/068, T-032).
 *
 * Cel fazy 1: kregoslup end-to-end (auth/role/kanal/render) na echo endpoincie
 * /api/admin/whoami backendu. Pelny CRUD rekomendacji + koszty + ustawienia
 * doloza sie w kolejnym tasku na sprawdzonym fundamencie.
 *
 * Architektura (ADR-068):
 *  - kanal serwerowy: HMAC sha256 sekretem serwerowym (KEY_SERVER_SECRET w
 *    Configuration), ladunek employee_id+timestamp, anti-replay (backend ±300s);
 *  - render UI: natywny AdminDivezoneChatController (NIE iframe);
 *  - role z divechat_admin_roles (PG, lookup po stronie backendu).
 *
 * Sekret IDENTYCZNY z DIVECHAT_SERVER_SECRET w .env backendu — generowac raz
 * (openssl rand -hex 32), wprowadzic w obu miejscach. Bez sekretu modul tylko
 * konfiguruje, NIE komunikuje sie z backendem.
 *
 * Cel kompatybilnosci: PrestaShop 1.7.6 + PHP 7.2; unikamy konstrukcji
 * wywalonych w PS 9 (executeS lowercase, addConfirmation itd.).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Divezone_Chat extends Module
{
    const KEY_BACKEND_URL    = 'DIVEZONE_CHAT_BACKEND_URL';
    const KEY_SERVER_SECRET  = 'DIVEZONE_CHAT_SERVER_SECRET';
    // CHAT-T-037 / ADR-069: kliencki HMAC widgetu, INNY sekret niz SERVER_SECRET.
    // CLIENT_SECRET = DIVECHAT_SECRET z .env backendu (uzywany przez HmacVerifier
    // na /api/chat/stream). SERVER_SECRET = DIVECHAT_SERVER_SECRET (panel admin).
    // Dwa rozne sekrety w .env, dwa rozne pola w Configuration — nie mylic.
    const KEY_CLIENT_SECRET  = 'DIVEZONE_CHAT_CLIENT_SECRET';
    const KEY_ALLOWED_IPS    = 'DIVEZONE_CHAT_ALLOWED_IPS';
    const TAB_CLASS          = 'AdminDivezoneChat';
    // T-034: zlecenie Karola — tab w sidebar Ulepsz, pomiedzy "Moduly" a "Wyglad".
    // Wybor T-034 (AdminModulesSf, id=44) byl bledny — to kontener Module Manager UI,
    // ktorego dzieci pojawiaja sie jako TABKI tabowe w widoku /improve/modules/manage
    // (obok "Moduly/Powiadomienia/Aktualizacje"), NIE jako sidebar entry.
    // Wzorzec dzialajacy (potwierdzony na prod, modul divezonegpt): id_parent=42
    // (IMPROVE), klasa rodzica jako 'IMPROVE'. Daje sidebar entry: Ulepsz -> DiveZone Chat.
    // Fallback AdminAdvancedParameters zachowany z T-033 jako sensowny zapas gdyby
    // IMPROVE byl nieobecny (np. starsza/zmodyfikowana instalka PS).
    const TAB_PARENT_PRIMARY  = 'IMPROVE';
    const TAB_PARENT_FALLBACK = 'AdminAdvancedParameters';
    const DEFAULT_BACKEND     = 'https://chat.divezone.pl';
    const LOG_PREFIX          = '[divezone_chat]';

    public function __construct()
    {
        $this->name                   = 'divezone_chat';
        $this->tab                    = 'administration';
        $this->version                = '1.0.1';
        $this->author                 = 'DiveZone';
        $this->need_instance          = 0;
        $this->bootstrap              = true;
        $this->ps_versions_compliancy = array('min' => '1.7.6', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName     = $this->l('DiveZone Chat — panel obslugi');
        $this->description     = $this->l('Panel obslugi czatu AI: rekomendacje, koszty, ustawienia. Faza 1 = echo kregoslupa kanalu serwerowego (T-032).');
        $this->confirmUninstall = $this->l('Na pewno odinstalowac modul DiveZone Chat?');
    }

    public function install()
    {
        error_log(self::LOG_PREFIX . ' install: start');

        if (!parent::install()) {
            error_log(self::LOG_PREFIX . ' install: parent::install() failed');
            return false;
        }
        if (!Configuration::updateValue(self::KEY_BACKEND_URL, self::DEFAULT_BACKEND)) {
            error_log(self::LOG_PREFIX . ' install: Configuration::updateValue BACKEND_URL failed');
            return false;
        }
        if (!Configuration::updateValue(self::KEY_SERVER_SECRET, '')) {
            error_log(self::LOG_PREFIX . ' install: Configuration::updateValue SERVER_SECRET failed');
            return false;
        }
        if (!$this->installTab()) {
            error_log(self::LOG_PREFIX . ' install: installTab() failed');
            return false;
        }

        error_log(self::LOG_PREFIX . ' install: OK');
        return true;
    }

    public function uninstall()
    {
        error_log(self::LOG_PREFIX . ' uninstall: start');

        if (!$this->uninstallTab()) {
            error_log(self::LOG_PREFIX . ' uninstall: uninstallTab() failed');
            return false;
        }
        Configuration::deleteByName(self::KEY_BACKEND_URL);
        Configuration::deleteByName(self::KEY_SERVER_SECRET);
        Configuration::deleteByName(self::KEY_CLIENT_SECRET);
        Configuration::deleteByName(self::KEY_ALLOWED_IPS);

        if (!parent::uninstall()) {
            error_log(self::LOG_PREFIX . ' uninstall: parent::uninstall() failed');
            return false;
        }

        error_log(self::LOG_PREFIX . ' uninstall: OK');
        return true;
    }

    /**
     * Idempotentne dodanie zakladki menu administracyjnego.
     * - Jesli tab class_name juz istnieje (np. z poprzedniej proby): return true.
     * - Rodzic z fallback chain (AdminAdvancedParameters primary, AdminTools legacy).
     * - Gdy zaden parent kandydatu nie istnieje: error_log + return false (eksplicytna
     *   awaria zamiast cichego id_parent=0 ktore wiesza AJAX instalatora w PS 1.7).
     */
    private function installTab()
    {
        $existingId = (int)Tab::getIdFromClassName(self::TAB_CLASS);
        if ($existingId > 0) {
            error_log(self::LOG_PREFIX . ' installTab: tab ' . self::TAB_CLASS . ' juz istnieje (id_tab=' . $existingId . ') — skip');
            return true;
        }

        $parentId = (int)Tab::getIdFromClassName(self::TAB_PARENT_PRIMARY);
        if ($parentId <= 0) {
            error_log(self::LOG_PREFIX . ' installTab: brak parent ' . self::TAB_PARENT_PRIMARY . ', proba fallback ' . self::TAB_PARENT_FALLBACK);
            $parentId = (int)Tab::getIdFromClassName(self::TAB_PARENT_FALLBACK);
        }
        if ($parentId <= 0) {
            error_log(self::LOG_PREFIX . ' installTab: nie znalazlem zadnego rodzica menu (AdminAdvancedParameters ani AdminTools) — instalacja przerwana');
            return false;
        }

        $tab             = new Tab();
        $tab->class_name = self::TAB_CLASS;
        $tab->id_parent  = $parentId;
        $tab->module     = $this->name;
        $tab->active     = 1;
        $tab->name       = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $this->l('DiveZone Chat');
        }

        if (!$tab->add()) {
            error_log(self::LOG_PREFIX . ' installTab: Tab::add() failed');
            return false;
        }

        error_log(self::LOG_PREFIX . ' installTab: OK (id_parent=' . $parentId . ', id_tab=' . (int)$tab->id . ')');
        return true;
    }

    private function uninstallTab()
    {
        $idTab = (int)Tab::getIdFromClassName(self::TAB_CLASS);
        if ($idTab <= 0) {
            return true;
        }
        $tab = new Tab($idTab);
        return (bool)$tab->delete();
    }

    /**
     * Strona konfiguracji modulu (link "Konfiguruj" w liscie modulow).
     * Pozwala wprowadzic backend URL + sekret serwerowy IDENTYCZNY z .env.
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitDivezoneChatConfig')) {
            $backendUrl    = trim((string)Tools::getValue('backend_url'));
            $newSecret     = trim((string)Tools::getValue('server_secret'));
            $newClient     = trim((string)Tools::getValue('client_secret'));
            $allowedIpsRaw = trim((string)Tools::getValue('allowed_ips'));

            if ($backendUrl !== '') {
                Configuration::updateValue(self::KEY_BACKEND_URL, $backendUrl);
            }
            if ($newSecret !== '') {
                Configuration::updateValue(self::KEY_SERVER_SECRET, $newSecret);
            }
            if ($newClient !== '') {
                Configuration::updateValue(self::KEY_CLIENT_SECRET, $newClient);
            }
            // ALLOWED_IPS — normalizacja: rozdziel po przecinkach/spacjach/srednikach,
            // wytnij puste, oczysc bialy znak, zapisz CSV. Pusty submit czysci liste.
            $cleanedIps = array();
            foreach (preg_split('/[\s,;]+/', $allowedIpsRaw) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate !== '') {
                    $cleanedIps[] = $candidate;
                }
            }
            Configuration::updateValue(self::KEY_ALLOWED_IPS, implode(',', $cleanedIps));

            $output .= $this->displayConfirmation($this->l('Zapisano konfiguracje.'));
        }

        $backendUrl    = (string)Configuration::get(self::KEY_BACKEND_URL);
        $serverSecretSet = (string)Configuration::get(self::KEY_SERVER_SECRET) !== '';
        $clientSecretSet = (string)Configuration::get(self::KEY_CLIENT_SECRET) !== '';
        $allowedIps    = (string)Configuration::get(self::KEY_ALLOWED_IPS);

        $serverHint = $serverSecretSet
            ? $this->l('(sekret ustawiony — wpisz nowa wartosc tylko jesli chcesz go zmienic)')
            : $this->l('wpisz IDENTYCZNY z DIVECHAT_SERVER_SECRET w .env backendu');

        $clientHint = $clientSecretSet
            ? $this->l('(sekret ustawiony — wpisz nowa wartosc tylko jesli chcesz go zmienic)')
            : $this->l('wpisz IDENTYCZNY z DIVECHAT_SECRET w .env backendu');

        $output .= '<form method="post">';
        $output .= '<fieldset><legend>' . $this->l('DiveZone Chat — konfiguracja') . '</legend>';

        $output .= '<p><label>' . $this->l('Backend URL') . '<br>';
        $output .= '<input type="text" name="backend_url" value="' . htmlspecialchars($backendUrl, ENT_QUOTES) . '" size="60"></label><br>';
        $output .= '<small>' . $this->l('Adres standalone API czatu (np. https://chat.divezone.pl). Uzywany przez panel admin ORAZ widget czatu na froncie sklepu.') . '</small></p>';

        $output .= '<hr><h3 style="margin-bottom:4px">' . $this->l('Dwa rozne sekrety — NIE mylic') . '</h3>';
        $output .= '<p style="margin-top:0;color:#666"><em>' . $this->l('Backend ma dwa kanaly z dwoma roznymi sekretami w .env. Tu wypelniasz oba — kazdy w swoim polu.') . '</em></p>';

        // Pole 1: SERVER_SECRET (panel admin — kanal serwerowy)
        $output .= '<p><label><strong>' . $this->l('1. Sekret SERWEROWY (panel admin)') . '</strong><br>';
        $output .= '<small style="color:#666">= <code>DIVECHAT_SERVER_SECRET</code> ' . $this->l('z .env backendu. Uzywany TYLKO przez panel admin (ten ekran + /api/admin/whoami).') . '</small><br>';
        $output .= '<input type="text" name="server_secret" value="" size="68" placeholder="' . htmlspecialchars($serverHint, ENT_QUOTES) . '" autocomplete="off">';
        $output .= ' <span>' . ($serverSecretSet ? $this->l('[ustawiony]') : '<strong style="color:#c00">' . $this->l('[BRAK]') . '</strong>') . '</span></label></p>';

        // Pole 2: CLIENT_SECRET (widget — kanal kliencki HMAC)
        $output .= '<p><label><strong>' . $this->l('2. Sekret KLIENCKI (widget czatu)') . '</strong><br>';
        $output .= '<small style="color:#666">= <code>DIVECHAT_SECRET</code> ' . $this->l('z .env backendu (BEZ "_SERVER_"). Uzywany przez widget na froncie sklepu do podpisu naglowka X-DiveChat-Token (HMAC, ADR-069).') . '</small><br>';
        $output .= '<input type="text" name="client_secret" value="" size="68" placeholder="' . htmlspecialchars($clientHint, ENT_QUOTES) . '" autocomplete="off">';
        $output .= ' <span>' . ($clientSecretSet ? $this->l('[ustawiony]') : '<strong style="color:#c00">' . $this->l('[BRAK]') . '</strong>') . '</span></label></p>';

        $output .= '<hr>';

        // Pole 3: ALLOWED_IPS (gating widoczosci widgetu)
        $output .= '<p><label><strong>' . $this->l('IP dozwolone do widzenia widgetu (etap 1)') . '</strong><br>';
        $output .= '<small style="color:#666">' . $this->l('Lista IP (oddzielone przecinkami) — widget pokaze sie TYLKO odwiedzajacym z tych adresow. Pusta lista = widget niewidoczny dla nikogo (bezpieczny default). Sklep jest za Cloudflare, modul czyta CF-Connecting-IP.') . '</small><br>';
        $output .= '<input type="text" name="allowed_ips" value="' . htmlspecialchars($allowedIps, ENT_QUOTES) . '" size="68" placeholder="np. 83.10.20.30, 91.150.x.x">';
        $output .= '</label></p>';

        $output .= '<p><input type="submit" class="button" name="submitDivezoneChatConfig" value="' . $this->l('Zapisz') . '"></p>';
        $output .= '</fieldset></form>';

        $output .= '<p><em>' . $this->l('Po zapisaniu sekretu serwerowego otworz menu Ulepsz -> DiveZone Chat zeby zobaczyc test echo /api/admin/whoami. Widget czatu pojawi sie na froncie sklepu po wpisaniu sekretu klienckiego ORAZ Twojego IP.') . '</em></p>';

        return $output;
    }

    /**
     * Hook displayFooter — emisja stub fasady widgetu czatu (CHAT-T-037, ADR-069).
     *
     * Dziala TYLKO gdy IP odwiedzajacego (CF-Connecting-IP -> XFF -> REMOTE_ADDR)
     * znajduje sie na liscie DIVEZONE_CHAT_ALLOWED_IPS i wszystkie wymagane sekrety
     * (CLIENT_SECRET, BACKEND_URL) sa ustawione. Pusta lista IP / brak sekretu
     * = milcze (zwraca pusty string). NIGDY nie loguje sekretow ani nie ujawnia
     * IP atakujacego w response — tylko emisja JS bootstrappingu.
     *
     * Etap 1 jest ekspozycyjnie po IP — to UX gating, nie security. Backend i tak
     * weryfikuje HMAC niezaleznie od tego, kto zobaczy launcher.
     */
    public function hookDisplayFooter($params)
    {
        $clientSecret = (string)Configuration::get(self::KEY_CLIENT_SECRET);
        $backendUrl   = (string)Configuration::get(self::KEY_BACKEND_URL);
        $allowedRaw   = (string)Configuration::get(self::KEY_ALLOWED_IPS);

        if ($clientSecret === '' || $backendUrl === '' || $allowedRaw === '') {
            return '';
        }

        $visitorIp = $this->resolveVisitorIp();
        if ($visitorIp === '') {
            return '';
        }

        $allowedList = array();
        foreach (preg_split('/[\s,;]+/', $allowedRaw) as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $allowedList[] = $ip;
            }
        }
        if (!in_array($visitorIp, $allowedList, true)) {
            return '';
        }

        // CustomerId: zalogowany -> id_customer, gosc -> 0 (ADR-069 payload 0:timestamp).
        $customerId = 0;
        if (isset($this->context->customer) && $this->context->customer->isLogged()) {
            $customerId = (int)$this->context->customer->id;
        }

        $timestamp = time();
        $token     = hash_hmac('sha256', $customerId . ':' . $timestamp, $clientSecret);

        // URL loadera (stub) — staly wzgledem modulu, niezalezny od front controllera.
        $loaderUrl = rtrim(__PS_BASE_URI__, '/')
            . '/modules/' . $this->name . '/views/js/widget-loader.js';
        $bundleUrl = rtrim(__PS_BASE_URI__, '/')
            . '/modules/' . $this->name . '/views/js/widget-bundle.js';
        $cssUrl = rtrim(__PS_BASE_URI__, '/')
            . '/modules/' . $this->name . '/views/css/widget.css';
        $transportUrl = rtrim(__PS_BASE_URI__, '/')
            . '/modules/' . $this->name . '/views/js/transport.js';

        $boot = array(
            'token'        => $token,
            'customerId'   => (string)$customerId,
            'time'         => (string)$timestamp,
            'backendUrl'   => rtrim($backendUrl, '/'),
            'streamPath'   => '/api/chat/stream',
            'sessionId'    => null,
            'assets'       => array(
                'bundle'    => $bundleUrl,
                'transport' => $transportUrl,
                'css'       => $cssUrl,
            ),
            'version'      => '1.0.1',
        );

        $bootJson = json_encode(
            $boot,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        // Stub fasady: ustawia window.DIVEZONE_CHAT_BOOT, dociaga loader async.
        // Inline maly snippet (<1 KB), faktyczny rendering w widget-loader.js.
        $html  = "\n<!-- divezone_chat widget (CHAT-T-037, etap 1: po IP, HMAC) -->\n";
        $html .= '<script>(function(){'
            . 'window.DIVEZONE_CHAT_BOOT=' . $bootJson . ';'
            . 'var s=document.createElement("script");'
            . 's.src=' . json_encode($loaderUrl) . ';'
            . 's.async=true;s.defer=true;'
            . 'document.head.appendChild(s);'
            . '})();</script>' . "\n";

        return $html;
    }

    /**
     * Odczytuje realne IP odwiedzajacego z uwzglednieniem proxy/CDN.
     *
     * Sklep divezone.pl JEST za Cloudflare (potwierdzone 2026-06-02:
     * "server: cloudflare", cf-ray w naglowkach). REMOTE_ADDR to IP edge CF,
     * NIE IP klienta. Kolejnosc: CF-Connecting-IP -> X-Forwarded-For (pierwszy)
     * -> REMOTE_ADDR (dev/brak proxy).
     *
     * UWAGA bezpieczenstwa: te naglowki da sie sfalszowac jesli ktos ominie CF
     * i wejdzie na origin bezposrednio. Dla etapu 1 (UX gating launchera —
     * widget i tak chroniony HMAC na backendzie) akceptowalne. Etap publiczny
     * (ADR-064 rate-limit po IP) wymaga CF Authenticated Origin Pulls /
     * allowlist IP CF na nginx.
     */
    private function resolveVisitorIp()
    {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return trim((string)$_SERVER['HTTP_CF_CONNECTING_IP']);
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            $first = trim($parts[0]);
            if ($first !== '') {
                return $first;
            }
        }
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            return trim((string)$_SERVER['REMOTE_ADDR']);
        }
        return '';
    }
}
