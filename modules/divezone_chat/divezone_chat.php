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

class DivezoneChat extends Module
{
    const KEY_BACKEND_URL    = 'DIVEZONE_CHAT_BACKEND_URL';
    const KEY_SERVER_SECRET  = 'DIVEZONE_CHAT_SERVER_SECRET';
    const TAB_CLASS          = 'AdminDivezoneChat';
    // T-034: zlecenie Karola — tab pod menu "Moduly" zamiast "Zaawansowane".
    // PS 1.7+ kontener "Moduly" to AdminModulesSf (potwierdzone na prod sklepu:
    // id_tab=44, active=1; siblings AdminModulesManage/AdminModulesCatalog).
    // Fallback AdminAdvancedParameters zachowany z T-033 jako sensowny zapas
    // gdyby kontener "Moduly" byl nieobecny (np. starsza/zmodyfikowana instalka).
    // Wzorzec T-033: jesli zaden parent nie istnieje, installTab zwraca false +
    // error_log[divezone_chat], nie cichy id_parent=0 (wiesil AJAX instalatora).
    const TAB_PARENT_PRIMARY  = 'AdminModulesSf';
    const TAB_PARENT_FALLBACK = 'AdminAdvancedParameters';
    const DEFAULT_BACKEND     = 'https://chat.divezone.pl';
    const LOG_PREFIX          = '[divezone_chat]';

    public function __construct()
    {
        $this->name                   = 'divezone_chat';
        $this->tab                    = 'administration';
        $this->version                = '1.0.0';
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
            $backendUrl = trim((string)Tools::getValue('backend_url'));
            $newSecret  = trim((string)Tools::getValue('server_secret'));

            if ($backendUrl !== '') {
                Configuration::updateValue(self::KEY_BACKEND_URL, $backendUrl);
            }
            if ($newSecret !== '') {
                Configuration::updateValue(self::KEY_SERVER_SECRET, $newSecret);
            }
            $output .= $this->displayConfirmation($this->l('Zapisano konfiguracje.'));
        }

        $backendUrl = (string)Configuration::get(self::KEY_BACKEND_URL);
        $secretSet  = (string)Configuration::get(self::KEY_SERVER_SECRET) !== '';

        $secretHint = $secretSet
            ? $this->l('(sekret ustawiony — wpisz nowa wartosc tylko jesli chcesz go zmienic)')
            : $this->l('wpisz 64-hex-char IDENTYCZNY z DIVECHAT_SERVER_SECRET w .env backendu');

        $output .= '<form method="post">';
        $output .= '<fieldset><legend>' . $this->l('DiveZone Chat — konfiguracja') . '</legend>';
        $output .= '<p><label>' . $this->l('Backend URL') . '<br>';
        $output .= '<input type="text" name="backend_url" value="' . htmlspecialchars($backendUrl, ENT_QUOTES) . '" size="60"></label></p>';
        $output .= '<p><label>' . $this->l('Sekret kanalu serwerowego') . '<br>';
        $output .= '<input type="text" name="server_secret" value="" size="68" placeholder="' . htmlspecialchars($secretHint, ENT_QUOTES) . '" autocomplete="off"></label></p>';
        $output .= '<p><input type="submit" class="button" name="submitDivezoneChatConfig" value="' . $this->l('Zapisz') . '"></p>';
        $output .= '</fieldset></form>';

        $output .= '<p><em>' . $this->l('Po zapisaniu sekretu otworz menu Zaawansowane -> DiveZone Chat zeby zobaczyc test echo /api/admin/whoami.') . '</em></p>';

        return $output;
    }
}
