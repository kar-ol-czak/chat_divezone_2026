<?php
/**
 * DiveZone Sidebar — zwijanie sekcji menu administracyjnego (T-035).
 *
 * Cel: niezalezne zwijanie/rozwijanie poszczegolnych top-level sekcji menu
 * admina (Ulepsz, Konfiguruj, Wiecej, custom DiveZone moduly), z zapamietywaniem
 * stanu w localStorage. Domyslny PS 1.7 akordion pozwala miec TYLKO jedna
 * sekcje otwarta na raz (auto-close pozostalych) i NIE persystuje stanu —
 * po reload strony otwarte jest tylko bieząca sekcja.
 *
 * Mechanizm: hook displayBackOfficeHeader wstrzykuje maly JS do kazdej strony
 * panelu admina. JS:
 *  1. Override domyslnego click handlera PS (jeden otwarty -> multi-open).
 *  2. Restore stanu z localStorage przy load strony (otworz zapisane sekcje).
 *  3. Persist stanu przy kazdym toggle.
 *
 * Zero modyfikacji plikow PS core (Twig templates / theme). Wszystko przez
 * standard hook PrestaShop = bezpieczne przy upgrade PS. PHP 7.2 + PS 1.7.6.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Divezone_Sidebar extends Module
{
    const LOG_PREFIX = '[divezone_sidebar]';

    public function __construct()
    {
        $this->name                   = 'divezone_sidebar';
        $this->tab                    = 'administration';
        $this->version                = '1.0.0';
        $this->author                 = 'DiveZone';
        $this->need_instance          = 0;
        $this->bootstrap              = true;
        $this->ps_versions_compliancy = array('min' => '1.7.6', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName     = $this->l('DiveZone Sidebar — zwijanie sekcji menu admina');
        $this->description     = $this->l('Niezalezne zwijanie/rozwijanie sekcji top-level menu admina, stan w localStorage. T-035.');
        $this->confirmUninstall = $this->l('Odinstalowac modul DiveZone Sidebar?');
    }

    public function install()
    {
        error_log(self::LOG_PREFIX . ' install: start');

        if (!parent::install()) {
            error_log(self::LOG_PREFIX . ' install: parent::install() failed');
            return false;
        }
        if (!$this->registerHook('displayBackOfficeHeader')) {
            error_log(self::LOG_PREFIX . ' install: registerHook(displayBackOfficeHeader) failed');
            return false;
        }

        error_log(self::LOG_PREFIX . ' install: OK');
        return true;
    }

    public function uninstall()
    {
        error_log(self::LOG_PREFIX . ' uninstall: start');

        if (!parent::uninstall()) {
            error_log(self::LOG_PREFIX . ' uninstall: parent::uninstall() failed');
            return false;
        }

        error_log(self::LOG_PREFIX . ' uninstall: OK');
        return true;
    }

    /**
     * Hook wstrzyknieciem JS do <head> kazdej strony admina (controller addJS).
     * JS ładowany jedynie w backofficie, frontend sklepu nieruszony.
     */
    public function hookDisplayBackOfficeHeader($params)
    {
        if ($this->context->controller instanceof AdminController) {
            $this->context->controller->addJS($this->_path . 'views/js/sidebar_persist.js');
        }
    }
}
