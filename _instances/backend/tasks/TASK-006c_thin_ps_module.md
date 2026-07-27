# TASK-006c: Cienki moduł PrestaShop
# Data: 2026-02-20
# Instancja: backend
# Priorytet: WYSOKI
# Zależności: TASK-006a + TASK-006b (standalone musi działać)

## Kontekst
Przeczytaj:
- ../../_docs/10_decyzje_projektowe.md (ADR-016)
- ../../_docs/CONVENTIONS.md

Moduł PS robi TYLKO:
1. Hook displayFooter: wstrzykuje widget JS + kontekst klienta
2. getContent(): iframe do panelu admina standalone
3. Install/uninstall: rejestracja hooków, zapis shared_secret

PHP 7.2! BRAK typed properties, arrow functions, match, enums.
Kod w: ../../modules/divezone_chat/

## WAŻNE: PHP 7.2 ograniczenia
- NIE: private string $name; TAK: /** @var string */ private $name;
- NIE: fn($x) => $x + 1; TAK: function($x) { return $x + 1; }
- NIE: match($x) { ... }; TAK: switch ($x) { ... }
- DOZWOLONE: ?string, : void, : array w deklaracjach metod

## Struktura plików

```
modules/divezone_chat/
├── divezone_chat.php          # Klasa główna (~150 linii)
├── config.xml                 # Metadane
├── logo.png                   # Ikona 32x32
└── views/
    └── templates/
        └── hook/
            └── displayFooter.tpl
```

To CAŁY moduł. Żadnych classes/, controllers/, js/, css/.
Widget JS ładowany z chat.divezone.pl/widget/chat.js (serwowany przez standalone).

## Krok 1: divezone_chat.php

```php
if (!defined('_PS_VERSION_')) { exit; }

class Divezone_Chat extends Module
{
    public function __construct()
    {
        $this->name = 'divezone_chat';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'divezone.pl';
        $this->need_instance = 0;
        $this->bootstrap = true;
        parent::__construct();
        $this->displayName = $this->l('DiveZone AI Chat');
        $this->description = $this->l('Czat AI ze sprzętem nurkowym');
    }

    public function install()
    {
        // Generuj shared secret jeśli nie istnieje
        if (!Configuration::get('DIVECHAT_SECRET')) {
            Configuration::updateValue('DIVECHAT_SECRET', bin2hex(random_bytes(32)));
        }
        return parent::install()
            && $this->registerHook('displayFooter')
            && Configuration::updateValue('DIVECHAT_API_URL', 'https://chat.divezone.pl')
            && Configuration::updateValue('DIVECHAT_ENABLED', '0');
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Configuration::deleteByName('DIVECHAT_SECRET')
            && Configuration::deleteByName('DIVECHAT_API_URL')
            && Configuration::deleteByName('DIVECHAT_ENABLED');
    }
}
```

## Krok 2: hookDisplayFooter

Generuje HMAC token i wstrzykuje widget JS.

```php
public function hookDisplayFooter($params)
{
    if (!Configuration::get('DIVECHAT_ENABLED')) {
        return '';
    }

    $customerId = 0;
    if ($this->context->customer && $this->context->customer->isLogged()) {
        $customerId = (int)$this->context->customer->id;
    }

    $timestamp = time();
    $secret = Configuration::get('DIVECHAT_SECRET');
    $token = hash_hmac('sha256', $customerId . ':' . $timestamp, $secret);
    $apiUrl = Configuration::get('DIVECHAT_API_URL');

    $this->context->smarty->assign(array(
        'divechat_api_url' => $apiUrl,
        'divechat_token' => $token,
        'divechat_customer_id' => $customerId,
        'divechat_timestamp' => $timestamp,
    ));

    return $this->display(__FILE__, 'views/templates/hook/displayFooter.tpl');
}
```

## Krok 3: displayFooter.tpl

```smarty
<div id="divechat-container"></div>
<script>
    window.DiveChatConfig = {
        apiUrl: '{$divechat_api_url|escape:'javascript'}',
        token: '{$divechat_token|escape:'javascript'}',
        customerId: {$divechat_customer_id|intval},
        timestamp: {$divechat_timestamp|intval}
    };
</script>
<script src="{$divechat_api_url|escape:'htmlall'}/widget/chat.js" defer></script>
```

Widget JS (chat.js) jest serwowany przez standalone.
Odczytuje window.DiveChatConfig i inicjalizuje czat.
(chat.js to zadanie instancji FRONTEND, nie tego taska)

## Krok 4: getContent() - panel admina

```php
public function getContent()
{
    $output = '';

    // Obsługa zapisu formularza
    if (Tools::isSubmit('submitDiveChatConfig')) {
        Configuration::updateValue('DIVECHAT_API_URL', Tools::getValue('DIVECHAT_API_URL'));
        Configuration::updateValue('DIVECHAT_ENABLED', (int)Tools::getValue('DIVECHAT_ENABLED'));
        // Secret: generuj nowy tylko jeśli user kliknie "regenerate"
        if (Tools::isSubmit('regenerateSecret')) {
            Configuration::updateValue('DIVECHAT_SECRET', bin2hex(random_bytes(32)));
        }
        $output .= $this->displayConfirmation($this->l('Ustawienia zapisane'));
    }

    // Formularz konfiguracji
    $output .= $this->renderConfigForm();

    // Iframe do panelu admina standalone
    $secret = Configuration::get('DIVECHAT_SECRET');
    $apiUrl = Configuration::get('DIVECHAT_API_URL');
    $employeeId = (int)$this->context->employee->id;
    $timestamp = time();
    $adminToken = hash_hmac('sha256', 'admin:' . $employeeId . ':' . $timestamp, $secret);
    $adminUrl = $apiUrl . '/admin?token=' . $adminToken . '&employee=' . $employeeId . '&ts=' . $timestamp;

    $output .= '<div style="margin-top:20px">';
    $output .= '<h3>' . $this->l('Panel zarządzania czatem') . '</h3>';
    $output .= '<iframe src="' . htmlspecialchars($adminUrl) . '" ';
    $output .= 'style="width:100%;height:800px;border:1px solid #ddd;border-radius:4px;" ';
    $output .= 'frameborder="0"></iframe>';
    $output .= '</div>';

    return $output;
}

private function renderConfigForm()
{
    $fields = array(
        'form' => array(
            'legend' => array('title' => $this->l('Konfiguracja DiveChat')),
            'input' => array(
                array(
                    'type' => 'switch',
                    'label' => $this->l('Włączony'),
                    'name' => 'DIVECHAT_ENABLED',
                    'values' => array(
                        array('id' => 'on', 'value' => 1, 'label' => $this->l('Tak')),
                        array('id' => 'off', 'value' => 0, 'label' => $this->l('Nie')),
                    ),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('URL API czatu'),
                    'name' => 'DIVECHAT_API_URL',
                    'desc' => $this->l('np. https://chat.divezone.pl'),
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Shared Secret'),
                    'name' => 'DIVECHAT_SECRET',
                    'readonly' => true,
                    'desc' => $this->l('Klucz współdzielony z API. Skopiuj do .env standalone.'),
                ),
            ),
            'submit' => array('title' => $this->l('Zapisz')),
        ),
    );

    $helper = new HelperForm();
    $helper->module = $this;
    $helper->name_controller = $this->name;
    $helper->token = Tools::getAdminTokenLite('AdminModules');
    $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
    $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
    $helper->fields_value = array(
        'DIVECHAT_ENABLED' => Configuration::get('DIVECHAT_ENABLED'),
        'DIVECHAT_API_URL' => Configuration::get('DIVECHAT_API_URL'),
        'DIVECHAT_SECRET' => Configuration::get('DIVECHAT_SECRET'),
    );

    return $helper->generateForm(array($fields));
}
```

## Krok 5: config.xml

```xml
<?xml version="1.0" encoding="UTF-8" ?>
<module>
    <name>divezone_chat</name>
    <displayName><![CDATA[DiveZone AI Chat]]></displayName>
    <version><![CDATA[1.0.0]]></version>
    <description><![CDATA[Czat AI ze sprzętem nurkowym]]></description>
    <author><![CDATA[divezone.pl]]></author>
    <tab><![CDATA[front_office_features]]></tab>
    <is_configurable>1</is_configurable>
    <need_instance>0</need_instance>
    <limited_countries></limited_countries>
</module>
```

## Krok 6: Test

1. Skopiuj moduł do PS dev:
   scp -r -P 5739 ../../modules/divezone_chat/ divezone@divezonededyk.smarthost.pl:/var/www/dev.divezone.pl/modules/
2. W panelu admina dev.divezone.pl: zainstaluj moduł
3. Sprawdź: konfiguracja się wyświetla, secret wygenerowany
4. Sprawdź: na frontendzie dev.divezone.pl widać div#divechat-container w źródle strony
5. Skopiuj DIVECHAT_SECRET do .env standalone

## Definition of Done
- [ ] Moduł instaluje się na dev.divezone.pl bez błędów
- [ ] Konfiguracja w panelu admina: switch on/off, URL API, secret
- [ ] Hook displayFooter wstrzykuje div + config JS + script tag
- [ ] getContent() wyświetla iframe do standalone admin
- [ ] HMAC token generowany poprawnie (weryfikowalny przez standalone)
