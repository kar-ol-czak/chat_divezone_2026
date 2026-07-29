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
    // CHAT-T-047: drabina ekspozycji. Lazy init (115b) — klucze nie istnieja w
    // install/upgrade, Configuration::get zwraca '' = OFF (bezpieczny default).
    // Bez bumpa wersji — wgrywanie na PROD samym rsync, bez "Aktualizuj".
    const KEY_SHOW_CUSTOMERS  = 'DIVEZONE_CHAT_SHOW_CUSTOMERS';
    const KEY_SHOW_POLAND     = 'DIVEZONE_CHAT_SHOW_POLAND';
    const KEY_SHOW_ALL        = 'DIVEZONE_CHAT_SHOW_ALL';
    const KEY_FILTER_BOTS     = 'DIVEZONE_CHAT_FILTER_BOTS';
    const KEY_ACK_PUBLIC_RISK = 'DIVEZONE_CHAT_ACK_PUBLIC_RISK';
    // CHAT-T-056: proaktywny dymek (nudge) — 3 pola configu, default OFF, lazy init.
    const KEY_NUDGE_ENABLED   = 'DIVEZONE_CHAT_NUDGE_ENABLED';
    const KEY_NUDGE_DELAY     = 'DIVEZONE_CHAT_NUDGE_DELAY';
    const KEY_NUDGE_TEXT      = 'DIVEZONE_CHAT_NUDGE_TEXT';
    const DEFAULT_NUDGE_DELAY = 20;
    const DEFAULT_NUDGE_TEXT  = 'Hej! 🤿 Nie wiesz, jaki sprzęt wybrać? Zapytaj naszych specjalistów.';
    // CHAT-T-081 (ADR-090): wariant wygladu zachety. v1 = klasyczny dymek (renderNudge),
    // v2 = karta z gradientem (renderNudgeCard). Lazy init, default 'v1' (rollback-safe).
    const KEY_NUDGE_VARIANT   = 'DIVEZONE_CHAT_NUDGE_VARIANT';
    const DEFAULT_NUDGE_VARIANT = 'v1';
    // CHAT-T-083 (faza 2 ADR-090): tryb A/B v1 vs v2. Gdy ON — front losuje bucket
    // 50/50 sticky (localStorage), nadpisuje variant z panelu. Lazy default OFF.
    const KEY_NUDGE_AB        = 'DIVEZONE_CHAT_NUDGE_AB';
    // Sciezka endpointu zdarzen nudge na standalone backendzie (CHAT-T-082).
    const NUDGE_EVENT_PATH    = '/api/widget/event';
    // CHAT-T-059: persystencja sesji czatu miedzy stronami sklepu (lazy init).
    // TTL = przez ile dni front zapamietuje sessionId w localStorage. Po TTL
    // (lub kliku "Nowa rozmowa") startujemy swieza sesje. Backend trzyma
    // rozmowy bezterminowo (closed_at IS NULL); TTL to UX limit po stronie klienta.
    const KEY_PERSIST_TTL_DAYS    = 'DIVEZONE_CHAT_PERSIST_TTL_DAYS';
    const DEFAULT_PERSIST_TTL_DAYS = 30;
    // CHAT-T-136 (ADR-119): atrybucja deterministyczna. Tabela (bez prefiksu —
    // Db dokleja _DB_PREFIX_) + nazwy cookie ustawianych przez widget w domenie
    // sklepu (widget-bundle.js setAttributionCookies). Hook actionValidateOrder
    // czyta cookie z $_COOKIE (raw JS cookie, NIE szyfrowane PS Cookie) i zapisuje
    // pare id_order <-> chat_session_id.
    const ATTRIBUTION_TABLE  = 'divechat_order_attribution';
    // CHAT-T-180: rate-limit ponownej wysylki informacji o zamowieniu (front
    // controller resend_order_info). Tabela w MySQL PS (prefix pr_).
    const RESEND_LOG_TABLE   = 'divechat_resend_log';
    const COOKIE_SESSION_ID  = 'divechat_session_id';
    const COOKIE_LAST_AT     = 'divechat_last_at';
    const COOKIE_VISIT       = 'divechat_visit';
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
        // CHAT-T-136 (ADR-119): tabela atrybucji + hook zamowienia. Idempotentne
        // (IF NOT EXISTS / registerHook zwraca true gdy juz zarejestrowany).
        if (!$this->createAttributionTable()) {
            error_log(self::LOG_PREFIX . ' install: createAttributionTable() failed');
            return false;
        }
        // CHAT-T-180: tabela rate-limit ponownej wysylki. Idempotentne (IF NOT EXISTS).
        if (!$this->createResendLogTable()) {
            error_log(self::LOG_PREFIX . ' install: createResendLogTable() failed');
            return false;
        }
        if (!$this->registerHook('actionValidateOrder')) {
            error_log(self::LOG_PREFIX . ' install: registerHook(actionValidateOrder) failed');
            return false;
        }

        error_log(self::LOG_PREFIX . ' install: OK');
        return true;
    }

    /**
     * CHAT-T-136 (ADR-119): tabela atrybucji deterministycznej w MySQL PrestaShop
     * (prefix pr_ przez _DB_PREFIX_). Idempotentna (IF NOT EXISTS) — ponowny
     * install / reinstall nie wywala bledu. Schema wg _docs/12_atrybucja_czatu.md
     * sekcja 4. Bliznaczy plik do recznego uruchomienia:
     * modules/divezone_chat/sql/pr_divechat_order_attribution.sql
     */
    private function createAttributionTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::ATTRIBUTION_TABLE . '` (
            `id_attribution` INT(11) NOT NULL AUTO_INCREMENT,
            `id_order` INT(11) NOT NULL,
            `chat_session_id` VARCHAR(64) NOT NULL,
            `attribution_type` ENUM(\'last_touch\',\'assist\') NOT NULL DEFAULT \'assist\',
            `conversation_last_at` DATETIME NULL DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_attribution`),
            UNIQUE KEY `uniq_id_order` (`id_order`),
            KEY `idx_chat_session_id` (`chat_session_id`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        try {
            return (bool)Db::getInstance()->execute($sql);
        } catch (Exception $e) {
            error_log(self::LOG_PREFIX . ' createAttributionTable: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * CHAT-T-180: tabela rate-limit ponownej wysylki informacji o zamowieniu.
     * Idempotentna (IF NOT EXISTS). Bliznaczy plik do recznego uruchomienia na
     * juz-zainstalowanym module (bez upgrade):
     * modules/divezone_chat/sql/pr_divechat_resend_log.sql
     */
    private function createResendLogTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::RESEND_LOG_TABLE . '` (
            `id_order` INT(11) NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        try {
            return (bool)Db::getInstance()->execute($sql);
        } catch (Exception $e) {
            error_log(self::LOG_PREFIX . ' createResendLogTable: ' . $e->getMessage());
            return false;
        }
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
     * Thin wrapper (CHAT-T-047, decyzja 114a): wspoldzielimy formularz i submit
     * z zakladka "Konfiguracja" w AdminDivezoneChatController. Logika w publicznych
     * metodach renderConfigForm() + handleConfigSubmit().
     */
    public function getContent()
    {
        $output       = '';
        $useSubmitted = false;

        if (Tools::isSubmit('submitDivezoneChatConfig')) {
            $result       = $this->handleConfigSubmit();
            $output      .= $result['messages_html'];
            $useSubmitted = $result['validation_failed'];
        }

        $output .= $this->renderConfigForm($useSubmitted);
        return $output;
    }

    /**
     * Renderuje HTML formularza konfiguracji. Wspolne zrodlo dla:
     *  - getContent() (Moduly -> Konfiguruj),
     *  - AdminDivezoneChatController::renderConfigSection() (zakladka "Konfiguracja").
     *
     * $useSubmittedRiskValues = true: PL/Wszyscy/ack renderujemy z POST (zachowanie
     * stanu po validation fail, decyzja 114a). false: czytamy z Configuration.
     */
    public function renderConfigForm($useSubmittedRiskValues = false)
    {
        // === Wartosci formularza ===
        $backendUrl      = (string)Configuration::get(self::KEY_BACKEND_URL);
        $serverSecretSet = (string)Configuration::get(self::KEY_SERVER_SECRET) !== '';
        $clientSecretSet = (string)Configuration::get(self::KEY_CLIENT_SECRET) !== '';
        $allowedIps      = (string)Configuration::get(self::KEY_ALLOWED_IPS);

        $showCustomers = (string)Configuration::get(self::KEY_SHOW_CUSTOMERS) === '1';
        $filterBots    = (string)Configuration::get(self::KEY_FILTER_BOTS) === '1';

        // CHAT-T-056: nudge config (lazy init — Configuration::get '' = OFF/default).
        $nudgeEnabled = (string)Configuration::get(self::KEY_NUDGE_ENABLED) === '1';
        $nudgeDelay   = (int)Configuration::get(self::KEY_NUDGE_DELAY);
        if ($nudgeDelay < 3 || $nudgeDelay > 300) {
            $nudgeDelay = self::DEFAULT_NUDGE_DELAY;
        }
        $nudgeText = (string)Configuration::get(self::KEY_NUDGE_TEXT);
        if ($nudgeText === '') {
            $nudgeText = self::DEFAULT_NUDGE_TEXT;
        }
        // CHAT-T-081 (ADR-090): wariant wygladu zachety, default v1 (rollback-safe).
        $nudgeVariant = (string)Configuration::get(self::KEY_NUDGE_VARIANT);
        if ($nudgeVariant !== 'v1' && $nudgeVariant !== 'v2') {
            $nudgeVariant = self::DEFAULT_NUDGE_VARIANT;
        }
        // CHAT-T-083 (faza 2 ADR-090): tryb A/B (lazy default OFF). Gdy ON,
        // bucket frontu nadpisuje variant z panelu — losowanie 50/50 sticky.
        $nudgeAb = (string)Configuration::get(self::KEY_NUDGE_AB) === '1';

        // CHAT-T-059: persystencja sesji — TTL w dniach (lazy init, default 30).
        $persistTtl = (int)Configuration::get(self::KEY_PERSIST_TTL_DAYS);
        if ($persistTtl < 1 || $persistTtl > 365) {
            $persistTtl = self::DEFAULT_PERSIST_TTL_DAYS;
        }

        if ($useSubmittedRiskValues) {
            // Po validation fail: pokaz uzytkownikowi to, co probowal zapisac.
            $showPoland = (int)Tools::getValue('show_poland', 0) === 1;
            $showAll    = (int)Tools::getValue('show_all', 0) === 1;
            $ackRisk    = (int)Tools::getValue('ack_public_risk', 0) === 1;
        } else {
            $showPoland = (string)Configuration::get(self::KEY_SHOW_POLAND) === '1';
            $showAll    = (string)Configuration::get(self::KEY_SHOW_ALL) === '1';
            $ackRisk    = (string)Configuration::get(self::KEY_ACK_PUBLIC_RISK) === '1';
        }

        $cfCountryAvailable = $this->cfIpCountryAvailable();

        $serverHint = $serverSecretSet
            ? $this->l('(sekret ustawiony — wpisz nowa wartosc tylko jesli chcesz go zmienic)')
            : $this->l('wpisz IDENTYCZNY z DIVECHAT_SERVER_SECRET w .env backendu');

        $clientHint = $clientSecretSet
            ? $this->l('(sekret ustawiony — wpisz nowa wartosc tylko jesli chcesz go zmienic)')
            : $this->l('wpisz IDENTYCZNY z DIVECHAT_SECRET w .env backendu');

        $output  = '<form method="post" action="">';
        $output .= '<fieldset><legend>' . $this->l('DiveZone Chat — konfiguracja') . '</legend>';

        // === SEKCJA 1: Backend URL ===
        $output .= '<p><label>' . $this->l('Backend URL') . '<br>';
        $output .= '<input type="text" name="backend_url" value="' . htmlspecialchars($backendUrl, ENT_QUOTES) . '" size="60"></label><br>';
        $output .= '<small>' . $this->l('Adres standalone API czatu (np. https://chat.divezone.pl). Uzywany przez panel admin ORAZ widget czatu na froncie sklepu.') . '</small></p>';

        // === SEKCJA 2: Dwa sekrety ===
        $output .= '<hr><h3 style="margin-bottom:4px">' . $this->l('Dwa rozne sekrety — NIE mylic') . '</h3>';
        $output .= '<p style="margin-top:0;color:#666"><em>' . $this->l('Backend ma dwa kanaly z dwoma roznymi sekretami w .env. Tu wypelniasz oba — kazdy w swoim polu.') . '</em></p>';

        $output .= '<p><label><strong>' . $this->l('1. Sekret SERWEROWY (panel admin)') . '</strong><br>';
        $output .= '<small style="color:#666">= <code>DIVECHAT_SERVER_SECRET</code> ' . $this->l('z .env backendu. Uzywany TYLKO przez panel admin (ten ekran + /api/admin/whoami).') . '</small><br>';
        $output .= '<input type="text" name="server_secret" value="" size="68" placeholder="' . htmlspecialchars($serverHint, ENT_QUOTES) . '" autocomplete="off">';
        $output .= ' <span>' . ($serverSecretSet ? $this->l('[ustawiony]') : '<strong style="color:#c00">' . $this->l('[BRAK]') . '</strong>') . '</span></label></p>';

        $output .= '<p><label><strong>' . $this->l('2. Sekret KLIENCKI (widget czatu)') . '</strong><br>';
        $output .= '<small style="color:#666">= <code>DIVECHAT_SECRET</code> ' . $this->l('z .env backendu (BEZ "_SERVER_"). Uzywany przez widget na froncie sklepu do podpisu naglowka X-DiveChat-Token (HMAC, ADR-069).') . '</small><br>';
        $output .= '<input type="text" name="client_secret" value="" size="68" placeholder="' . htmlspecialchars($clientHint, ENT_QUOTES) . '" autocomplete="off">';
        $output .= ' <span>' . ($clientSecretSet ? $this->l('[ustawiony]') : '<strong style="color:#c00">' . $this->l('[BRAK]') . '</strong>') . '</span></label></p>';

        // === SEKCJA 3: Drabina ekspozycji ===
        $output .= '<hr><h3 style="margin-bottom:4px">' . $this->l('Drabina ekspozycji widgetu') . '</h3>';
        $output .= '<p style="margin-top:0;color:#666"><em>' . $this->l('Widget czatu pokaze sie odwiedzajacym z DOWOLNEJ z aktywnych grup PONIZEJ, LUB ktorych IP jest na liscie "zawsze pokaz". Bezpieczny default: wszystko wylaczone + lista IP pusta = widget niewidoczny dla nikogo.') . '</em></p>';

        // Lista IP (zostaje, "zawsze pokaz")
        $output .= '<p><label><strong>' . $this->l('Lista IP (zawsze pokaz — rownolegla sciezka)') . '</strong><br>';
        $output .= '<small style="color:#666">' . $this->l('IP rozdzielone przecinkami. Odwiedzajacy z tych IP widza widget NIEZALEZNIE od ustawien grup. Sklep jest za Cloudflare, modul czyta CF-Connecting-IP. Cel: zespol/Karol zawsze ma dostep do testow.') . '</small><br>';
        $output .= '<input type="text" name="allowed_ips" value="' . htmlspecialchars($allowedIps, ENT_QUOTES) . '" size="68" placeholder="np. 83.10.20.30, 91.150.x.x">';
        $output .= '</label></p>';

        // 3 grupy widocznosci
        $output .= '<p style="margin-bottom:6px"><strong>' . $this->l('Grupy widocznosci (logika OR — wystarczy jedna pasujaca)') . '</strong></p>';

        $output .= '<p id="dz_group_customers_row" style="margin:4px 0">';
        $output .= '<label><input type="checkbox" id="dz_show_customers" name="show_customers" value="1"' . ($showCustomers ? ' checked' : '') . '> ';
        $output .= $this->l('Zalogowani klienci sklepu') . '</label>';
        $output .= '</p>';

        $output .= '<p id="dz_group_poland_row" style="margin:4px 0">';
        $output .= '<label><input type="checkbox" id="dz_show_poland" name="show_poland" value="1"' . ($showPoland ? ' checked' : '') . '> ';
        $output .= $this->l('Wszyscy odwiedzajacy z Polski (geolokalizacja Cloudflare CF-IPCountry)') . '</label>';
        $output .= '</p>';

        $output .= '<p id="dz_group_all_row" style="margin:4px 0">';
        $output .= '<label><input type="checkbox" id="dz_show_all" name="show_all" value="1"' . ($showAll ? ' checked' : '') . '> ';
        $output .= '<strong>' . $this->l('Wszyscy odwiedzajacy (otwarcie publiczne)') . '</strong></label>';
        $output .= '</p>';

        // Ostrzezenie: CF nie podaje IPCountry (gdy PL chcemy aktywowac)
        $cfWarningDisplay = (!$cfCountryAvailable && $showPoland) ? 'block' : 'none';
        $output .= '<div id="dz_cf_country_warning" style="display:' . $cfWarningDisplay . ';background:#fcf8e3;border:1px solid #faebcc;color:#8a6d3b;padding:10px 12px;margin:10px 0;border-radius:3px;">';
        $output .= '<strong>' . $this->l('Cloudflare nie przekazuje naglowka CF-IPCountry.') . '</strong> ';
        $output .= $this->l('Grupa "PL" NIE aktywuje sie (graceful degradation). Aby wlaczyc: panel Cloudflare -> Network -> IP Geolocation -> ON.');
        $output .= '</div>';

        // Ostrzezenie publiczne (PL lub Wszyscy) + ack risk
        $riskWarningDisplay = ($showPoland || $showAll) ? 'block' : 'none';
        $output .= '<div id="dz_public_risk_warning" style="display:' . $riskWarningDisplay . ';background:#f2dede;border:1px solid #ebccd1;color:#a94442;padding:12px 14px;margin:14px 0;border-radius:3px;">';
        $output .= '<strong>' . $this->l('Ostrzezenie (decyzja 106c, ADR-064)') . '</strong>';
        $output .= '<p style="margin:8px 0;color:#a94442">' . $this->l('Otwarcie czatu dla szerokiej publicznosci (PL/Wszyscy) wymaga ochrony backendu (rate-limit + Turnstile, ADR-064), ktora NIE jest jeszcze wdrozona. Bez niej ryzyko naduzyc i kosztow LLM po stronie standalone API.') . '</p>';
        $output .= '<label style="display:block;margin-top:8px"><input type="checkbox" id="dz_ack_public_risk" name="ack_public_risk" value="1"' . ($ackRisk ? ' checked' : '') . '> ';
        $output .= '<strong>' . $this->l('Rozumiem ryzyko i akceptuje swiadomie') . '</strong></label>';
        $output .= '<small style="display:block;margin-top:4px">' . $this->l('Wymagane do aktywacji grupy "PL" lub "Wszyscy". Bez zaznaczenia submit nie zapisze tych grup.') . '</small>';
        $output .= '</div>';

        // === SEKCJA 4: Filtry (osobno od grup) ===
        $output .= '<hr><h3 style="margin-bottom:4px">' . $this->l('Filtry') . '</h3>';
        $output .= '<p style="margin-top:0;color:#666"><em>' . $this->l('Filtry stosowane PO ustaleniu, ze grupa pasuje (zmniejszaja zbior, nie zwiekszaja).') . '</em></p>';
        $output .= '<p style="margin:4px 0">';
        $output .= '<label><input type="checkbox" name="filter_bots" value="1"' . ($filterBots ? ' checked' : '') . '> ';
        $output .= $this->l('Odfiltruj znane boty (Googlebot, bingbot, Slurp, DuckDuckBot, facebookexternalhit, Twitterbot, AhrefsBot, SemrushBot, MJ12bot, dotbot, applebot, ia_archiver)') . '</label><br>';
        $output .= '<small style="color:#666">' . $this->l('Detekcja po naglowku User-Agent. Latwe do podrobienia (NIE security), redukuje koszty LLM przy crawlerach.') . '</small>';
        $output .= '</p>';

        // === SEKCJA 5: Proaktywny dymek (nudge) — CHAT-T-056 ===
        $output .= '<hr><h3 style="margin-bottom:4px">' . $this->l('Proaktywny dymek (nudge)') . '</h3>';
        $output .= '<p style="margin-top:0;color:#666"><em>' . $this->l('Wysuwany dymek przy launcherze po N sekundach od wejscia na strone — zacheta do rozmowy. Dziedziczy gating launchera (drabina ekspozycji). Pokazany RAZ na sesje, do zamkniecia (×) lub otwarcia czatu.') . '</em></p>';
        // CHAT-T-081 (ADR-090): wybor wygladu — v1 (obecny dymek) vs v2 (karta z gradientem).
        $output .= '<p style="margin:6px 0">';
        $output .= '<label>' . $this->l('Wyglad zachety') . ':<br>';
        $output .= '<select name="nudge_variant" style="min-width:280px">';
        $output .= '<option value="v1"' . ($nudgeVariant === 'v1' ? ' selected' : '') . '>' . $this->l('Klasyczny dymek (v1)') . '</option>';
        $output .= '<option value="v2"' . ($nudgeVariant === 'v2' ? ' selected' : '') . '>' . $this->l('Karta premium (v2)') . '</option>';
        $output .= '</select></label><br>';
        $output .= '<small style="color:#666">' . $this->l('v1 = obecny prosty dymek (tresc z pola "Tresc zachety" ponizej). v2 = karta z gradientem glebi wody i staym copy z draftu (pole "Tresc zachety" w v2 ignorowane).') . '</small>';
        $output .= '</p>';
        // CHAT-T-083 (faza 2 ADR-090): tryb A/B v1 vs v2 — losowanie sticky per przegladarka.
        $output .= '<p style="margin:6px 0">';
        $output .= '<label><input type="checkbox" name="nudge_ab" value="1"' . ($nudgeAb ? ' checked' : '') . '> <strong>' . $this->l('Test A/B v1 vs v2 (50/50)') . '</strong></label><br>';
        $output .= '<small style="color:#666">' . $this->l('Gdy wlaczony: wariant losowany 50/50 per przegladarka (sticky w localStorage), przelacznik wygladu wyzej jest ignorowany. Pomiar CTR (nudge_shown / nudge_cta_click) dziala ZAWSZE, niezaleznie od tego trybu — porownanie wynikow w panelu raportu.') . '</small>';
        $output .= '</p>';
        $output .= '<p style="margin:6px 0">';
        $output .= '<label><input type="checkbox" name="nudge_enabled" value="1"' . ($nudgeEnabled ? ' checked' : '') . '> <strong>' . $this->l('Wlacz dymek') . '</strong></label><br>';
        $output .= '<small style="color:#666">' . $this->l('Default OFF. Bez wlaczenia dymek nie pojawi sie nawet jak launcher jest widoczny.') . '</small>';
        $output .= '</p>';
        $output .= '<p style="margin:6px 0">';
        $output .= '<label>' . $this->l('Opoznienie (sekundy, 3-300)') . ':<br>';
        $output .= '<input type="number" name="nudge_delay" value="' . (int)$nudgeDelay . '" min="3" max="300" step="1" style="width:100px"></label><br>';
        $output .= '<small style="color:#666">' . $this->l('Po ilu sekundach od zaladowania strony pokazac dymek. Default 20s. Wartosci poza zakresem = default.') . '</small>';
        $output .= '</p>';
        $output .= '<p style="margin:6px 0">';
        $output .= '<label>' . $this->l('Tresc zachety') . ':<br>';
        $output .= '<textarea name="nudge_text" rows="3" cols="60" style="width:100%;max-width:540px;">' . htmlspecialchars($nudgeText, ENT_QUOTES) . '</textarea></label><br>';
        $output .= '<small style="color:#666">' . $this->l('Krotki, przyjazny tekst zachecajacy do rozmowy. Emoji OK. HTML NIE — tresc renderowana jako tekst (anty-XSS). Puste = domyslna tresc.') . '</small>';
        $output .= '</p>';

        // === SEKCJA 6: Persystencja sesji czatu — CHAT-T-059 ===
        $output .= '<hr><h3 style="margin-bottom:4px">' . $this->l('Persystencja sesji czatu') . '</h3>';
        $output .= '<p style="margin-top:0;color:#666"><em>' . $this->l('Czas pamietania trwajacej rozmowy w przegladarce uzytkownika (localStorage). W tym okresie odwiedzajacy widzi wczesniejsze wiadomosci po nawigacji miedzy stronami sklepu lub powrocie. Po TTL (lub kliku "Nowa rozmowa") rozmowa startuje na swiezo.') . '</em></p>';
        $output .= '<p style="margin:6px 0">';
        $output .= '<label>' . $this->l('Czas pamietania rozmowy (dni, 1-365)') . ':<br>';
        $output .= '<input type="number" name="persist_ttl_days" value="' . (int)$persistTtl . '" min="1" max="365" step="1" style="width:100px"></label><br>';
        $output .= '<small style="color:#666">' . $this->l('Default 30 dni. Wartosci poza zakresem = default. W przegladarce trzymamy tylko sessionId + timestamp (zero tresci rozmowy — historia z backendu).') . '</small>';
        $output .= '</p>';

        $output .= '<p style="margin-top:18px"><input type="submit" class="button" name="submitDivezoneChatConfig" value="' . $this->l('Zapisz') . '"></p>';
        $output .= '</fieldset></form>';

        // Inline JS: "Wszyscy" wyszarza pozostale grupy + toggle banera ostrzezenia + CF warning.
        $output .= '<script>(function(){'
            . 'function $(id){return document.getElementById(id);}'
            . 'var elAll=$("dz_show_all"),elPl=$("dz_show_poland"),elCust=$("dz_show_customers"),'
            . 'rowCust=$("dz_group_customers_row"),rowPl=$("dz_group_poland_row"),'
            . 'warnRisk=$("dz_public_risk_warning"),warnCf=$("dz_cf_country_warning");'
            . 'var cfAvail=' . ($cfCountryAvailable ? 'true' : 'false') . ';'
            . 'function upd(){'
            . 'var all=elAll&&elAll.checked,pl=elPl&&elPl.checked;'
            . 'if(rowCust)rowCust.style.opacity=all?0.5:1;'
            . 'if(rowPl)rowPl.style.opacity=all?0.5:1;'
            . 'if(warnRisk)warnRisk.style.display=(pl||all)?"block":"none";'
            . 'if(warnCf)warnCf.style.display=(pl&&!cfAvail)?"block":"none";'
            . '}'
            . '["dz_show_all","dz_show_poland","dz_show_customers"].forEach(function(id){'
            . 'var el=$(id);if(el)el.addEventListener("change",upd);'
            . '});'
            . 'upd();'
            . '})();</script>';

        $output .= '<p><em>' . $this->l('Po zapisaniu sekretu serwerowego otworz menu Ulepsz -> DiveZone Chat zeby zobaczyc test echo /api/admin/whoami. Konfiguracja jest tez dostepna jako zakladka w panelu obslugi (decyzja 108a).') . '</em></p>';

        return $output;
    }

    /**
     * Przetwarza POST formularza konfiguracji. Wspolne wejscie dla obu miejsc
     * (Moduly->Konfiguruj + zakladka). Zwraca tablice:
     *   ['messages_html' => string, 'validation_failed' => bool]
     *
     * Logika walidacji (CHAT-T-047): grupy PL/Wszyscy wymagaja zaznaczenia
     * ack_public_risk (106c). Jesli ack brak -> PL/Wszyscy/ack NIE sa zapisywane
     * (pozostale pola tak), zwracamy validation_failed=true zeby renderConfigForm
     * pokazal POSTowane wartosci (a nie odswiezone z Configuration).
     */
    public function handleConfigSubmit()
    {
        $messagesHtml     = '';
        $validationFailed = false;

        // === Pola "safe" — zapisz zawsze ===
        $backendUrl = trim((string)Tools::getValue('backend_url'));
        if ($backendUrl !== '') {
            Configuration::updateValue(self::KEY_BACKEND_URL, $backendUrl);
        }

        $newSecret = trim((string)Tools::getValue('server_secret'));
        if ($newSecret !== '') {
            Configuration::updateValue(self::KEY_SERVER_SECRET, $newSecret);
        }

        $newClient = trim((string)Tools::getValue('client_secret'));
        if ($newClient !== '') {
            Configuration::updateValue(self::KEY_CLIENT_SECRET, $newClient);
        }

        // ALLOWED_IPS — normalizacja CSV. Pusty submit czysci liste.
        $allowedIpsRaw = trim((string)Tools::getValue('allowed_ips'));
        $cleanedIps    = array();
        foreach (preg_split('/[\s,;]+/', $allowedIpsRaw) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate !== '') {
                $cleanedIps[] = $candidate;
            }
        }
        Configuration::updateValue(self::KEY_ALLOWED_IPS, implode(',', $cleanedIps));

        // SHOW_CUSTOMERS i FILTER_BOTS — safe, brak walidacji.
        Configuration::updateValue(self::KEY_SHOW_CUSTOMERS, (int)Tools::getValue('show_customers', 0) === 1 ? '1' : '0');
        Configuration::updateValue(self::KEY_FILTER_BOTS,    (int)Tools::getValue('filter_bots', 0)    === 1 ? '1' : '0');

        // CHAT-T-056: nudge — 3 pola (enabled / delay / text). Walidacja delay 3-300.
        Configuration::updateValue(self::KEY_NUDGE_ENABLED, (int)Tools::getValue('nudge_enabled', 0) === 1 ? '1' : '0');
        $nudgeDelayRaw = (int)Tools::getValue('nudge_delay', self::DEFAULT_NUDGE_DELAY);
        if ($nudgeDelayRaw < 3 || $nudgeDelayRaw > 300) {
            $nudgeDelayRaw = self::DEFAULT_NUDGE_DELAY;
        }
        Configuration::updateValue(self::KEY_NUDGE_DELAY, (string)$nudgeDelayRaw);
        $nudgeTextRaw = trim((string)Tools::getValue('nudge_text', ''));
        // Pusty submit -> wracamy do default (UX: czyszczenie pola = reset).
        if ($nudgeTextRaw === '') {
            $nudgeTextRaw = self::DEFAULT_NUDGE_TEXT;
        }
        Configuration::updateValue(self::KEY_NUDGE_TEXT, $nudgeTextRaw);
        // CHAT-T-081 (ADR-090): wariant wygladu — 'v2' lub fallback 'v1' (rollback-safe).
        $nudgeVariantClean = (Tools::getValue('nudge_variant') === 'v2') ? 'v2' : 'v1';
        Configuration::updateValue(self::KEY_NUDGE_VARIANT, $nudgeVariantClean);
        // CHAT-T-083 (faza 2 ADR-090): tryb A/B (checkbox, default OFF).
        Configuration::updateValue(self::KEY_NUDGE_AB, (int)Tools::getValue('nudge_ab', 0) === 1 ? '1' : '0');

        // CHAT-T-059: persystencja sesji — TTL w dniach, walidacja 1-365 → default 30.
        $persistTtlRaw = (int)Tools::getValue('persist_ttl_days', self::DEFAULT_PERSIST_TTL_DAYS);
        if ($persistTtlRaw < 1 || $persistTtlRaw > 365) {
            $persistTtlRaw = self::DEFAULT_PERSIST_TTL_DAYS;
        }
        Configuration::updateValue(self::KEY_PERSIST_TTL_DAYS, (string)$persistTtlRaw);

        // === Pola "risk" — wymagaja ack przy aktywacji PL/Wszyscy ===
        $wantsPoland = (int)Tools::getValue('show_poland', 0) === 1;
        $wantsAll    = (int)Tools::getValue('show_all', 0) === 1;
        $hasAck      = (int)Tools::getValue('ack_public_risk', 0) === 1;

        if (($wantsPoland || $wantsAll) && !$hasAck) {
            // Validation fail: NIE zapisuj PL/Wszyscy/ack. Stare wartosci zostaja w Configuration.
            $validationFailed = true;
            $messagesHtml    .= $this->displayError(
                $this->l('Aby aktywowac grupe "PL" lub "Wszyscy" musisz zaznaczyc "Rozumiem ryzyko (ADR-064)". Pozostale ustawienia zostaly zapisane; grupy PL/Wszyscy oraz ack pozostaly bez zmian.')
            );
        } else {
            Configuration::updateValue(self::KEY_SHOW_POLAND,     $wantsPoland ? '1' : '0');
            Configuration::updateValue(self::KEY_SHOW_ALL,        $wantsAll    ? '1' : '0');
            Configuration::updateValue(self::KEY_ACK_PUBLIC_RISK, $hasAck      ? '1' : '0');
            $messagesHtml .= $this->displayConfirmation($this->l('Zapisano konfiguracje.'));
        }

        return array(
            'messages_html'     => $messagesHtml,
            'validation_failed' => $validationFailed,
        );
    }

    /**
     * Hook displayFooter — emisja stub fasady widgetu czatu (CHAT-T-037, ADR-069).
     *
     * ADR-087 (CHAT-T-078): hook wstrzykuje BEZWARUNKOWO loader + minimalny BOOT
     * (backendUrl, tokenUrl, assets, nudge, persist) — BEZ tokenu, BEZ gatingu.
     * HTML identyczny dla WSZYSTKICH odwiedzajacych -> cache'owalny spojnie przez
     * LiteSpeed. Gating (drabina ekspozycji + filtr botow) przeniesiony do
     * front-controllera /token: loader najpierw fetchuje endpoint, otrzymuje
     * {eligible:true, token, ...} i dopiero wtedy rysuje launcher. Token NIGDY
     * w cache'owanym HTML — wyciek tozsamosci miedzy odwiedzajacymi (ADR-087 Q234c).
     *
     * Backend weryfikuje HMAC niezaleznie od tego, kto zobaczy launcher (UX gating
     * != security). Ochrona publiczna (rate-limit/Turnstile) to ADR-064 — JESZCZE
     * NIE wdrozona; UI panelu modulu pokazuje ostrzezenie przy PL/Wszyscy.
     *
     * shouldShowWidget()/helpers POZOSTAJA — uzywane teraz przez canIssueToken()
     * w front-controllerze /token (logika identyczna, tylko miejsce inne).
     */
    public function hookDisplayFooter($params)
    {
        $clientSecret = (string)Configuration::get(self::KEY_CLIENT_SECRET);
        $backendUrl   = (string)Configuration::get(self::KEY_BACKEND_URL);

        // Brak konfiguracji = nic nie wstrzykujemy. To gating konfiguracyjny
        // (admin nie wpisal sekretu), NIE per-odwiedzajacy — wynik identyczny
        // dla wszystkich w danym sklepie, wiec cache-safe.
        if ($clientSecret === '' || $backendUrl === '') {
            return '';
        }

        // ADR-087: tokenu nie generujemy w hooku (w cache'owanym HTML = wyciek).
        // Front-controller /token wydaje token tylko gdy widget ma prawo sie pokazac
        // (canIssueToken -> shouldShowWidget) + na zywo (niecache'owany endpoint).

        // CHAT-T-061 (decyzja 148a): cache-busting ?v=md5_8 per asset. Po ADR-087
        // hash (md5_file I/O) liczy sie dla KAZDEJ wizyty z poprawnym configiem —
        // koszt znikomy (4 male pliki na FS sklepu), a HTML identyczny dla wszystkich
        // = cache LiteSpeed serwuje raz, md5_file pada raz na TTL strony.
        $loaderUrl    = $this->assetUrl('views/js/widget-loader.js');
        $bundleUrl    = $this->assetUrl('views/js/widget-bundle.js');
        $cssUrl       = $this->assetUrl('views/css/widget.css');
        $transportUrl = $this->assetUrl('views/js/transport.js');

        // CHAT-T-056: galaz nudge dla loadera (proaktywny dymek). Dziedziczy gating
        // launchera — jesli shouldShowWidget() przepuscil, loader sprawdza nudge.enabled.
        $nudgeEnabled = (string)Configuration::get(self::KEY_NUDGE_ENABLED) === '1';
        $nudgeDelay   = (int)Configuration::get(self::KEY_NUDGE_DELAY);
        if ($nudgeDelay < 3 || $nudgeDelay > 300) {
            $nudgeDelay = self::DEFAULT_NUDGE_DELAY;
        }
        $nudgeText = (string)Configuration::get(self::KEY_NUDGE_TEXT);
        if ($nudgeText === '') {
            $nudgeText = self::DEFAULT_NUDGE_TEXT;
        }
        // CHAT-T-081 (ADR-090): wariant wygladu zachety, default v1. Jedna stala
        // wartosc identyczna dla wszystkich -> cache-safe LiteSpeed (ADR-087).
        $nudgeVariant = (string)Configuration::get(self::KEY_NUDGE_VARIANT);
        if ($nudgeVariant !== 'v1' && $nudgeVariant !== 'v2') {
            $nudgeVariant = self::DEFAULT_NUDGE_VARIANT;
        }
        // CHAT-T-083 (faza 2 ADR-090): tryb A/B + sciezka endpointu zdarzen. Obie
        // wartosci stale dla wszystkich -> cache-safe (ADR-087). Front losuje
        // bucket sticky w localStorage gdy ab=true.
        $nudgeAb = (string)Configuration::get(self::KEY_NUDGE_AB) === '1';

        // CHAT-T-059: persystencja sesji — TTL z configu (lazy init, default 30).
        $persistTtl = (int)Configuration::get(self::KEY_PERSIST_TTL_DAYS);
        if ($persistTtl < 1 || $persistTtl > 365) {
            $persistTtl = self::DEFAULT_PERSIST_TTL_DAYS;
        }

        $boot = array(
            // ADR-087 (CHAT-T-078): BRAK token/customerId/time w cache'owanym HTML.
            // Loader pobiera je z BOOT.tokenUrl (niecache'owany endpoint) w runtime.
            'backendUrl'   => rtrim($backendUrl, '/'),
            'streamPath'   => '/api/chat/stream',
            // CHAT-T-069 / ADR-084: URL endpointu modulu wydajacego swieze tokeny.
            // ADR-087 (CHAT-T-078): endpoint zwraca {eligible:bool, token?, customerId?,
            // time?, expires_in?}. Loader sprawdza eligible -> rysuje launcher lub no-op.
            // Front-controller na tym samym originie co sklep (divezone.pl) — transport
            // wola go z credentials:'include', odbiera {token,customerId,time,expires_in}
            // i aktualizuje wspoldzielony BOOT. Logika tokenu IDENTYCZNA z hookiem
            // (a w ADR-087 — to jedyne miejsce wydajace token w ogole).
            'tokenUrl'     => $this->context->link->getModuleLink('divezone_chat', 'token', array(), true),
            'sessionId'    => null,
            'assets'       => array(
                'bundle'    => $bundleUrl,
                'transport' => $transportUrl,
                'css'       => $cssUrl,
            ),
            'nudge'        => array(
                'enabled'   => $nudgeEnabled,
                'delay'     => $nudgeDelay,
                'text'      => $nudgeText,
                'variant'   => $nudgeVariant,
                // CHAT-T-083: tryb A/B + sciezka endpointu beacona (CHAT-T-082).
                'ab'        => $nudgeAb,
                'eventPath' => self::NUDGE_EVENT_PATH,
            ),
            // CHAT-T-059: persystencja sesji miedzy stronami — TTL + sciezka history.
            // Query param `sid` (NIE `session_id`) — LiteSpeed WAF na hostingu
            // blokuje query stringi z `session_id=` (regula PHPSESSID-like, 403).
            'persist'      => array(
                'ttl_days'    => $persistTtl,
                'historyPath' => '/api/chat/history',
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
     * CHAT-T-136 (ADR-119): hook zamowienia — strumien deterministyczny atrybucji.
     *
     * Przy zatwierdzeniu zamowienia czyta cookie ustawione przez widget w domenie
     * sklepu i zapisuje powiazanie id_order <-> chat_session_id. Brak cookie =
     * zamowienie bez czatu -> nic nie zapisujemy (nie smiecimy tabeli).
     *
     * attribution_type:
     *  - last_touch — cookie sesyjne `divechat_visit` obecne = rozmowa w tej samej
     *    wizycie przegladarki co zakup.
     *  - assist — brak cookie sesyjnego (znikneło po zamknieciu przegladarki),
     *    tylko cookie persistent z wczesniejszej wizyty.
     *
     * Cookie czytamy z $_COOKIE (raw JS cookie), NIE z $this->context->cookie
     * (to szyfrowana Cookie PrestaShopa — nie zawiera naszych surowych cookie).
     *
     * Odporny: kazdy wyjatek lapiemy — atrybucja to funkcja poboczna, NIGDY nie
     * moze wywrocic zatwierdzenia zamowienia.
     */
    public function hookActionValidateOrder($params)
    {
        try {
            $sessionId = $this->readRawCookie(self::COOKIE_SESSION_ID);
            if ($sessionId === '') {
                return; // zamowienie bez czatu — brak atrybucji
            }
            // Sanity: chat_session_id max 64 znaki (kolumna VARCHAR(64)).
            $sessionId = Tools::substr($sessionId, 0, 64);

            $order = isset($params['order']) ? $params['order'] : null;
            $idOrder = ($order && isset($order->id)) ? (int)$order->id : 0;
            if ($idOrder <= 0) {
                error_log(self::LOG_PREFIX . ' actionValidateOrder: brak id_order w params — skip');
                return;
            }

            $visit = $this->readRawCookie(self::COOKIE_VISIT);
            $type  = ($visit === '1') ? 'last_touch' : 'assist';

            $conversationLastAt = $this->epochMsCookieToDatetime(self::COOKIE_LAST_AT);

            $data = array(
                'id_order'         => $idOrder,
                'chat_session_id'  => pSQL($sessionId),
                'attribution_type' => pSQL($type),
                'date_add'         => date('Y-m-d H:i:s'),
            );
            // Kolumna NULLABLE — dokladamy tylko gdy znamy wartosc (inaczej DEFAULT NULL).
            if ($conversationLastAt !== null) {
                $data['conversation_last_at'] = pSQL($conversationLastAt);
            }

            $ok = Db::getInstance()->insert(self::ATTRIBUTION_TABLE, $data);
            if (!$ok) {
                error_log(self::LOG_PREFIX . ' actionValidateOrder: insert failed (id_order=' . $idOrder . ')');
            } else {
                error_log(self::LOG_PREFIX . ' actionValidateOrder: atrybucja zapisana (id_order=' . $idOrder . ', type=' . $type . ')');
            }
        } catch (Throwable $e) {
            // Atrybucja NIGDY nie moze zablokowac zamowienia — lapiemy wszystko
            // (Throwable = Exception + Error/TypeError; catch(Exception) by ich nie zlapal).
            error_log(self::LOG_PREFIX . ' actionValidateOrder: wyjatek — ' . $e->getMessage());
        }
    }

    /**
     * CHAT-T-136: odczyt surowego cookie ($_COOKIE) ustawionego przez widget JS.
     * Zwraca '' gdy brak. PHP dekoduje URL-encoding wartosci automatycznie.
     */
    private function readRawCookie($name)
    {
        if (!isset($_COOKIE[$name])) {
            return '';
        }
        return trim((string)$_COOKIE[$name]);
    }

    /**
     * CHAT-T-136: konwersja cookie z epoch ms (Date.now() z widgetu) na DATETIME
     * czasu serwera. Zwraca null gdy brak / nieprawidlowa wartosc. Uwaga: to czas
     * z zegara klienta (mozliwy dryf) — traktowany jako przyblizony znacznik
     * conversation_last_at; decyzja last_touch/assist NIE zalezy od tego czasu
     * (opiera sie na cookie sesyjnym divechat_visit), wiec dryf jest nieszkodliwy.
     */
    private function epochMsCookieToDatetime($cookieName)
    {
        $raw = $this->readRawCookie($cookieName);
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $seconds = (int)floor(((float)$raw) / 1000.0);
        if ($seconds <= 0) {
            return null;
        }
        return date('Y-m-d H:i:s', $seconds);
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

    // ============================================================================
    // CHAT-T-047: drabina ekspozycji widgetu
    //
    // Logika: pokaz = ( IP_na_liscie OR g_customers OR g_poland OR g_all )
    //              AND NOT ( filter_bots AND is_bot )
    //
    // Bezpieczny default: wszystkie grupy OFF i lista IP pusta -> NIEWIDOCZNY.
    // Grupy/IP odczytane lazy z Configuration::get (brak klucza = ''  = OFF).
    // ============================================================================

    /**
     * Publiczny wrapper gating widgetu — uzywany przez front-controller `token`
     * (CHAT-T-069, ADR-084 188a), zeby nie odslaniac wszystkich helperow
     * (isLoggedCustomer/resolveVisitorIp/isFromPoland/...). Endpoint tokenu
     * dziedziczy drabine ekspozycji: zwraca token TYLKO gdy widget ma prawo
     * sie pokazac (spojnie z hookDisplayFooter).
     */
    public function canIssueToken()
    {
        return $this->shouldShowWidget();
    }

    private function shouldShowWidget()
    {
        $allowedRaw    = (string)Configuration::get(self::KEY_ALLOWED_IPS);
        $showCustomers = (string)Configuration::get(self::KEY_SHOW_CUSTOMERS) === '1';
        $showPoland    = (string)Configuration::get(self::KEY_SHOW_POLAND) === '1';
        $showAll       = (string)Configuration::get(self::KEY_SHOW_ALL) === '1';
        $filterBots    = (string)Configuration::get(self::KEY_FILTER_BOTS) === '1';

        $ipListEmpty = (trim($allowedRaw) === '');
        if (!$showCustomers && !$showPoland && !$showAll && $ipListEmpty) {
            // Bezpieczny default: nic nie wlaczone -> nikomu.
            return false;
        }

        // Filtr botow — odsiewa od razu, niezaleznie od grupy. UA = UX, nie security.
        if ($filterBots && $this->isBot($this->getUserAgent())) {
            return false;
        }

        // "Wszyscy" - krotki obwod (oszczedza wywolan resolveVisitorIp itp.).
        if ($showAll) {
            return true;
        }

        // OR pozostalych warstw.
        $visitorIp = $this->resolveVisitorIp();
        if ($this->isOnAllowedIpList($visitorIp, $allowedRaw)) {
            return true;
        }
        if ($showCustomers && $this->isLoggedCustomer()) {
            return true;
        }
        if ($showPoland && $this->isFromPoland()) {
            return true;
        }

        return false;
    }

    private function isLoggedCustomer()
    {
        return isset($this->context->customer) && $this->context->customer->isLogged();
    }

    /**
     * Geolokalizacja przez Cloudflare CF-IPCountry (decyzja 113 + ADR-069 ground).
     * Karol wlacza w panelu CF (Network -> IP Geolocation -> On). Graceful: brak
     * naglowka -> false (NIE pokazuje wszystkim przez pomylke). UI panelu modulu
     * pokazuje wtedy zolte ostrzezenie ("CF nie przekazuje geolokalizacji").
     */
    private function isFromPoland()
    {
        if (empty($_SERVER['HTTP_CF_IPCOUNTRY'])) {
            return false;
        }
        return Tools::strtoupper(trim((string)$_SERVER['HTTP_CF_IPCOUNTRY'])) === 'PL';
    }

    private function isOnAllowedIpList($visitorIp, $allowedRaw)
    {
        if ($visitorIp === '' || trim($allowedRaw) === '') {
            return false;
        }
        foreach (preg_split('/[\s,;]+/', $allowedRaw) as $ip) {
            $ip = trim($ip);
            if ($ip !== '' && $ip === $visitorIp) {
                return true;
            }
        }
        return false;
    }

    private function getUserAgent()
    {
        return isset($_SERVER['HTTP_USER_AGENT']) ? (string)$_SERVER['HTTP_USER_AGENT'] : '';
    }

    /**
     * UA-based bot detection. Whitelist (case-insensitive substring) — UX filter
     * tylko (latwe do podrobienia). Cel: redukcja kosztow LLM przy crawlerach.
     */
    private function isBot($userAgent)
    {
        if ($userAgent === '') {
            return false;
        }
        $signatures = array(
            'Googlebot', 'bingbot', 'Slurp', 'DuckDuckBot',
            'facebookexternalhit', 'Twitterbot', 'AhrefsBot',
            'SemrushBot', 'MJ12bot', 'dotbot', 'applebot', 'ia_archiver',
        );
        foreach ($signatures as $sig) {
            if (stripos($userAgent, $sig) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sprawdza czy w biezacym requescie (np. admin context formularza konfiguracji)
     * Cloudflare przekazuje CF-IPCountry. Uzywane przez renderConfigForm do
     * pokazania ostrzezenia, gdy grupa "PL" jest aktywna a CF nie podaje kraju.
     */
    private function cfIpCountryAvailable()
    {
        return !empty($_SERVER['HTTP_CF_IPCOUNTRY']);
    }

    /**
     * CHAT-T-061 (decyzja 148a): URL assetu widgetu z cache-bust ?v=md5_8.
     *
     * Wolane WYLACZNIE z hookDisplayFooter PO early-return shouldShowWidget,
     * wiec md5_file (I/O na dysk) odpala sie tylko dla wizyt, ktore faktycznie
     * dostaja widget — koszt zerowy dla wizyt botow / grup wykluczonych.
     *
     * Sciezka do md5_file uzywa dirname(__FILE__) — tj. katalogu samego modulu,
     * niezalezna od stalej _PS_MODULE_DIR_ (pewniejsze, dziala identycznie
     * w kazdym kontekscie wywolania hooka).
     *
     * Graceful: md5_file zwraca false (plik usuniety / brak uprawnien) -> URL
     * bez ?v (widget dalej dziala, tracimy tylko cache-bust w tym hicie).
     * Suppression @ celowo — chcemy zerowych warningow w PHP error log podczas
     * normalnego dzialania (md5_file false trafi do return false ponizej).
     *
     * @param string $relativePath ścieżka od katalogu modulu, np. 'views/js/widget-loader.js'
     * @return string URL z ?v=md5_8 lub bez (graceful fallback)
     */
    private function assetUrl($relativePath)
    {
        $url      = rtrim(__PS_BASE_URI__, '/') . '/modules/' . $this->name . '/' . $relativePath;
        $filePath = dirname(__FILE__) . '/' . $relativePath;
        $hash     = @md5_file($filePath);
        if ($hash === false) {
            return $url;
        }
        return $url . '?v=' . substr($hash, 0, 8);
    }
}
