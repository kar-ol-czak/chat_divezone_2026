<?php
/**
 * AdminDivezoneChatController — natywny AdminController panelu obslugi.
 *
 * ADR-068 decyzja 175a: render natywny w PS (NIE iframe), dane ciagniete z
 * backendu kanalem serwerowym (HMAC + employee_id w ladunku, decyzja 174a).
 * ADR-070: panel PS jako jedyny front administracyjny — start struktury
 * zakladek przy CHAT-T-045. Kazda zakladka wola wlasny endpoint przez kanal
 * serwerowy.
 *
 * Whoami u gory (pasek diagnostyki kanalu) + zakladki:
 *  - "Rekomendacje" (T-035 CZESC B, read-only, ADR-065)
 *  - "Modele" (CHAT-T-045, ADR-070, konfiguracja AI: model primary/escalation,
 *    reasoning, max_tokens). UI ma STRUKTURE pod 3 poziomy (basic/primary/
 *    escalation), ale obecnie buduje 2 — basic wymaga routingu w ChatService
 *    (osobny task).
 *
 * Ekran "Konfiguruj" (Divezone_Chat::getContent — sekrety/IP/URL) ZOSTAJE
 * OSOBNO; ADR-070 decyzja 98a: lokalny Configuration::updateValue w glownym
 * pliku modulu, nie w tym kontrolerze.
 *
 * Cel kompatybilnosci: PrestaShop 1.7.6 + PHP 7.2. Unikamy konstrukcji
 * wywalonych w PS 9; brak typed props i match — explicit type hints w sygnaturach.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminDivezoneChatController extends ModuleAdminController
{
    const HTTP_TIMEOUT_SEC = 10;
    const ENDPOINT_WHOAMI          = '/api/admin/whoami';
    const ENDPOINT_RECOMMENDATIONS = '/api/admin/recommendations';
    const ENDPOINT_SETTINGS        = '/api/settings';
    // CHAT-T-048: lista/szczegoly/status rozmow (any-role, CHAT-T-046 backend).
    // Szczegoly: ENDPOINT_CONVERSATIONS . '/' . rawurlencode($sessionId).
    // Status:    ENDPOINT_CONVERSATIONS . '/' . rawurlencode($sessionId) . '/status'.
    const ENDPOINT_CONVERSATIONS   = '/api/conversations';
    // CHAT-T-050: Analityka (admin-only, CHAT-T-049 backend, ADR-074).
    const ENDPOINT_COST_KPI          = '/api/admin/cost/kpi';
    const ENDPOINT_COST_TREND        = '/api/admin/cost/trend';
    const ENDPOINT_COST_BY_MODEL     = '/api/admin/cost/by-model';
    const ENDPOINT_CONVERSATIONS_TOP = '/api/admin/conversations/top';
    // Chart.js z CDN — graceful degradation gdy CSP/siec blokuje (typeof Chart guard).
    const CHARTJS_CDN = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';
    // CHAT-T-055: Editorial Picks (any-role, CHAT-T-054 backend, ADR-076).
    // Aliasy POST (decyzja 128a): update na POST /editorial-picks/{id}, delete na
    // POST /editorial-picks/{id}/delete (callBackend wysyla body tylko dla POST).
    const ENDPOINT_EDITORIAL         = '/api/admin/editorial-picks';
    const ENDPOINT_EDITORIAL_PENDING = '/api/admin/editorial-picks/pending-reviews';
    const ENDPOINT_PRODUCTS_SEARCH   = '/api/admin/products/search';

    // Aktywna zakladka — CHAT-T-048 (106a): DEFAULT = Rozmowy.
    const TAB_CONVERSATIONS   = 'conversations';
    const TAB_RECOMMENDATIONS = 'recommendations';
    const TAB_MODELS          = 'models';
    // CHAT-T-047 (decyzja 108a): konfiguracja jako zakladka obok Moduly->Konfiguruj.
    // renderConfigSection() wola publiczne metody Divezone_Chat (decyzja 114a OPCJA B):
    // renderConfigForm() + handleConfigSubmit() — zero duplikacji formularza.
    const TAB_CONFIG          = 'config';
    // CHAT-T-050: analityka (admin-only).
    const TAB_ANALYTICS       = 'analytics';
    // CHAT-T-055: editorial picks (any-role, 127b).
    const TAB_EDITORIAL       = 'editorial';

    /** @var string komunikat flashowy do wyswietlenia na gorze ekranu Modele */
    private $modelsFlash = '';
    /** @var string typ komunikatu: success | error */
    private $modelsFlashType = 'success';
    /** @var string komunikat flashowy do wyswietlenia na gorze widoku Rozmow (CHAT-T-048) */
    private $convFlash = '';
    /** @var string typ komunikatu: success | error */
    private $convFlashType = 'success';
    /** @var string komunikat flashowy do wyswietlenia na gorze widoku Editorial (CHAT-T-055) */
    private $epFlash = '';
    /** @var string typ komunikatu: success | error */
    private $epFlashType = 'success';

    public function __construct()
    {
        $this->bootstrap = true;
        $this->display   = 'view';
        parent::__construct();
        $this->meta_title = $this->l('DiveZone Chat — panel obslugi');
    }

    public function initContent()
    {
        parent::initContent();

        $employeeId = (int) $this->context->employee->id;

        // 1. Aktywna zakladka — z querystring lub form submit, default Rozmowy (CHAT-T-048, 106a).
        $activeTab = (string) Tools::getValue('tab', self::TAB_CONVERSATIONS);
        if (!in_array($activeTab, array(self::TAB_CONVERSATIONS, self::TAB_RECOMMENDATIONS, self::TAB_MODELS, self::TAB_CONFIG, self::TAB_ANALYTICS, self::TAB_EDITORIAL), true)) {
            $activeTab = self::TAB_CONVERSATIONS;
        }

        // 2. Submit ekranu Modele -> POST /api/settings przed odczytem.
        if (Tools::isSubmit('submitDivezoneChatModels')) {
            $this->handleModelsSave($employeeId);
            $activeTab = self::TAB_MODELS; // zostan na zakladce po zapisie
        }

        // 2b. Submit ekranu Konfiguracja -> zostan na zakladce po zapisie (CHAT-T-047).
        if (Tools::isSubmit('submitDivezoneChatConfig')) {
            $activeTab = self::TAB_CONFIG;
        }

        // 2c. Submit zmiany statusu rozmowy -> POST .../status, zostan na Rozmowach (CHAT-T-048).
        if (Tools::isSubmit('submitDivezoneChatConvStatus')) {
            $this->handleConvStatusSave($employeeId);
            $activeTab = self::TAB_CONVERSATIONS;
        }

        // 2d. Submity Editorial Picks (CHAT-T-055) — add/update/delete -> POST kanalem
        // serwerowym, zostan na zakladce Editorial po zapisie.
        if (Tools::isSubmit('submitDivezoneChatEpAdd')) {
            $this->handleEpAdd($employeeId);
            $activeTab = self::TAB_EDITORIAL;
        }
        if (Tools::isSubmit('submitDivezoneChatEpUpdate')) {
            $this->handleEpUpdate($employeeId);
            $activeTab = self::TAB_EDITORIAL;
        }
        if (Tools::isSubmit('submitDivezoneChatEpDelete')) {
            $this->handleEpDelete($employeeId);
            $activeTab = self::TAB_EDITORIAL;
        }

        // 3. Whoami zawsze (maly pasek u gory). CHAT-T-050: rola sluzy tez do ukrycia
        // linka "Analityka" w nav dla nie-adminow (Analityka jest admin-only).
        $whoami = $this->callBackend(self::ENDPOINT_WHOAMI, $employeeId);
        $role   = (isset($whoami['role']) && is_string($whoami['role'])) ? $whoami['role'] : '';

        // 4. Tresc aktywnej zakladki — pobieramy TYLKO te dane, ktorych potrzebujemy.
        $tabContent = '';
        if ($activeTab === self::TAB_CONVERSATIONS) {
            $tabContent = $this->renderConversationsSection($employeeId);
        } elseif ($activeTab === self::TAB_MODELS) {
            $settings = $this->callBackend(self::ENDPOINT_SETTINGS, $employeeId);
            $tabContent = $this->renderModelsSection($settings);
        } elseif ($activeTab === self::TAB_CONFIG) {
            $tabContent = $this->renderConfigSection();
        } elseif ($activeTab === self::TAB_ANALYTICS) {
            $tabContent = $this->renderAnalyticsSection($employeeId);
        } elseif ($activeTab === self::TAB_EDITORIAL) {
            $tabContent = $this->renderEditorialSection($employeeId);
        } else {
            $recommendations = $this->callBackend(self::ENDPOINT_RECOMMENDATIONS, $employeeId);
            $tabContent = $this->renderRecommendationsSection($recommendations);
        }

        $html  = $this->renderTabsStyles();
        $html .= $this->renderWhoamiBar($whoami, $employeeId);
        $html .= $this->renderTabsNav($activeTab, $role);
        $html .= $tabContent;
        $html .= $this->renderTabsScript();

        $this->context->smarty->assign('content', $html);
    }

    // ============================================================================
    // ZAKLADKI: nawigacja, style, JS
    // ============================================================================

    private function renderTabsStyles()
    {
        // Style scoped do panelu modulu — krotkie, inline. Bez konfliktu z theme PS.
        $css  = '<style>';
        $css .= '.dz-tabs-nav{display:flex;gap:2px;margin:14px 0 0;border-bottom:2px solid #ddd;}';
        $css .= '.dz-tab-link{display:inline-block;padding:9px 18px;background:#f5f5f5;border:1px solid #ddd;border-bottom:0;border-radius:4px 4px 0 0;color:#555;text-decoration:none;font-weight:600;font-size:13px;}';
        $css .= '.dz-tab-link:hover{background:#ececec;color:#333;text-decoration:none;}';
        $css .= '.dz-tab-link.is-active{background:#fff;color:#1a5e5a;border-color:#ddd;position:relative;top:2px;border-bottom:2px solid #fff;}';
        $css .= '.dz-whoami-bar{display:flex;align-items:center;gap:14px;padding:8px 14px;background:#f7f9fa;border:1px solid #e2e6e8;border-radius:4px;font-size:12px;color:#555;margin-bottom:0;}';
        $css .= '.dz-whoami-bar .dot{display:inline-block;width:9px;height:9px;border-radius:50%;}';
        $css .= '.dz-whoami-bar .dot.ok{background:#5cb85c;}';
        $css .= '.dz-whoami-bar .dot.err{background:#d9534f;}';
        $css .= '.dz-whoami-bar code{background:#fff;padding:1px 5px;border:1px solid #e2e6e8;border-radius:3px;font-size:11px;}';
        $css .= '.dz-flash{padding:10px 14px;border-radius:4px;margin:0 0 14px;font-size:13px;}';
        $css .= '.dz-flash.success{background:#dff0d8;border:1px solid #d6e9c6;color:#3c763d;}';
        $css .= '.dz-flash.error{background:#f2dede;border:1px solid #ebccd1;color:#a94442;}';
        $css .= '.dz-models-form .field-row{display:grid;grid-template-columns:200px 1fr;gap:14px;align-items:center;margin-bottom:14px;}';
        $css .= '.dz-models-form label{font-weight:600;color:#444;}';
        $css .= '.dz-models-form select,.dz-models-form input[type=number]{width:100%;max-width:540px;padding:7px 10px;border:1px solid #ccc;border-radius:4px;background:#fff;font-size:13px;}';
        $css .= '.dz-models-form .field-hint{font-size:11px;color:#888;font-weight:400;display:block;margin-top:3px;}';
        $css .= '.dz-models-form .submit-row{margin-top:18px;}';
        $css .= '.dz-models-form button{padding:9px 22px;background:#1a5e5a;color:#fff;border:0;border-radius:4px;font-weight:600;cursor:pointer;font-size:13px;}';
        $css .= '.dz-models-form button:hover{background:#155050;}';
        $css .= '.dz-models-form .price-tag{color:#888;font-weight:400;}';
        $css .= '.dz-todo-basic{padding:10px 14px;background:#fcf8e3;border:1px dashed #d6c87a;color:#8a6d3b;border-radius:4px;margin-top:18px;font-size:12px;}';
        // CHAT-T-048: Rozmowy — babelki, statusy, filtry, paginacja, meta, koszty.
        $css .= '.dz-conv-filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:12px;padding:10px;background:#f7f9fa;border:1px solid #e2e6e8;border-radius:4px;}';
        $css .= '.dz-conv-filters label{font-size:12px;color:#555;display:block;font-weight:600;margin-bottom:3px;}';
        $css .= '.dz-conv-filters input[type=text],.dz-conv-filters select{padding:6px 10px;border:1px solid #ccc;border-radius:3px;background:#fff;font-size:13px;}';
        $css .= '.dz-conv-filters .check-row{display:flex;align-items:center;gap:6px;padding:8px 0;font-size:13px;}';
        $css .= '.dz-conv-pager{margin-top:14px;display:flex;gap:14px;align-items:center;color:#666;font-size:13px;}';
        $css .= '.dz-conv-meta{background:#f7f9fa;padding:10px 14px;border:1px solid #e2e6e8;border-radius:4px;margin-bottom:14px;font-size:12px;}';
        $css .= '.dz-conv-meta dl{display:grid;grid-template-columns:auto 1fr;gap:4px 14px;margin:0;}';
        $css .= '.dz-conv-meta dt{color:#777;}';
        $css .= '.dz-conv-meta dd{margin:0;}';
        $css .= '.dz-conv-cost{background:#fffdf5;border:1px solid #e8d96a;padding:10px 14px;border-radius:4px;margin-bottom:14px;font-size:12px;}';
        $css .= '.dz-conv-thread{display:flex;flex-direction:column;gap:6px;max-width:880px;margin:8px 0;}';
        $css .= '.dz-conv-bubble{padding:10px 14px;border-radius:8px;max-width:78%;font-size:13px;line-height:1.45;}';
        $css .= '.dz-conv-bubble--user{background:#e8f0fe;align-self:flex-end;margin-left:auto;border:1px solid #c9d7f0;}';
        $css .= '.dz-conv-bubble--ai{background:#f5f5f5;align-self:flex-start;margin-right:auto;border:1px solid #e2e2e2;}';
        $css .= '.dz-conv-bubble .role{font-size:11px;color:#666;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;}';
        $css .= '.dz-status-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:0.85em;color:#fff;font-weight:600;}';
        $css .= '.dz-status-new{background:#5680b8;}';
        $css .= '.dz-status-reviewed{background:#5cb85c;}';
        $css .= '.dz-status-knowledge_created{background:#1a5e5a;}';
        $css .= '.dz-status-ignored{background:#999;}';
        // CHAT-T-051: master-detail layout (113a) + pozycje listy + placeholder + formatowanie bubli.
        $css .= '.dz-conv-layout{display:flex;gap:14px;align-items:flex-start;}';
        $css .= '.dz-conv-list-col{width:340px;flex-shrink:0;max-height:75vh;overflow-y:auto;}';
        $css .= '.dz-conv-detail-col{flex:1;min-width:0;max-height:75vh;overflow-y:auto;}';
        // CHAT-T-052 (poprawka 3): filtry w jednej linii — row z wrap fallback dla
        // bardzo waskiej kolumny. Search elastyczny (flex-grow), pozostale auto.
        $css .= '.dz-conv-list-col .dz-conv-filters{flex-direction:row;align-items:center;gap:6px;flex-wrap:wrap;padding:8px;}';
        $css .= '.dz-conv-list-col .dz-conv-filters > div{width:auto;margin:0;}';
        $css .= '.dz-conv-list-col .dz-conv-filters input[type=text]{flex:1 1 110px;min-width:100px;width:auto;box-sizing:border-box;padding:6px 8px;}';
        $css .= '.dz-conv-list-col .dz-conv-filters select{flex:0 0 auto;width:auto;box-sizing:border-box;padding:6px 8px;}';
        $css .= '.dz-conv-list-col .dz-conv-filters .check-row{font-size:12px;padding:0;}';
        $css .= '.dz-conv-list-col .dz-conv-filters button{padding:6px 12px;font-size:12px;}';
        // CHAT-T-052 (poprawka 4): meta + koszty obok siebie (2 kolumny, wrap na waskim).
        $css .= '.dz-conv-meta-row{display:flex;gap:14px;align-items:stretch;flex-wrap:wrap;margin-bottom:14px;}';
        $css .= '.dz-conv-meta-row > .dz-conv-meta,.dz-conv-meta-row > .dz-conv-cost{flex:1 1 280px;min-width:280px;margin-bottom:0;}';
        // CHAT-T-050: Analityka — filtry, karty KPI, wykres-wrapper, tabele.
        $css .= '.dz-analytics-filters{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;padding:10px;background:#f7f9fa;border:1px solid #e2e6e8;border-radius:4px;}';
        $css .= '.dz-analytics-filters label{font-size:12px;color:#555;display:block;font-weight:600;margin-bottom:3px;}';
        $css .= '.dz-analytics-filters select{padding:6px 10px;border:1px solid #ccc;border-radius:3px;background:#fff;font-size:13px;}';
        $css .= '.dz-analytics-kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin-bottom:18px;}';
        $css .= '.dz-analytics-kpi-card{background:#fff;border:1px solid #e2e6e8;border-left:4px solid #1a5e5a;border-radius:4px;padding:14px 16px;}';
        $css .= '.dz-analytics-kpi-card .dz-kpi-title{font-size:12px;color:#777;text-transform:uppercase;letter-spacing:0.5px;font-weight:600;margin-bottom:6px;}';
        $css .= '.dz-analytics-kpi-card .dz-kpi-main{font-size:22px;font-weight:700;color:#1a5e5a;line-height:1.1;}';
        $css .= '.dz-analytics-kpi-card .dz-kpi-sub{font-size:12px;color:#999;margin-top:2px;}';
        $css .= '.dz-analytics-kpi-card .dz-kpi-meta{font-size:12px;color:#666;margin-top:8px;border-top:1px solid #f0f0f0;padding-top:6px;}';
        $css .= '.dz-analytics-kpi-card--resolution{border-left-color:#0066cc;background:#f7faff;}';
        $css .= '.dz-analytics-kpi-card--resolution .dz-kpi-main{color:#0066cc;}';
        $css .= '.dz-analytics-section{margin-bottom:24px;}';
        $css .= '.dz-analytics-section h3{margin:0 0 10px;border-bottom:1px solid #ddd;padding-bottom:6px;font-size:14px;color:#444;}';
        $css .= '.dz-analytics-chart-wrap{position:relative;height:320px;background:#fff;border:1px solid #e2e6e8;border-radius:4px;padding:10px;}';
        $css .= '.dz-analytics-empty{padding:30px;text-align:center;color:#999;background:#f7f9fa;border:1px dashed #ccc;border-radius:4px;}';
        $css .= '.dz-analytics-table{width:100%;font-size:12px;border-collapse:collapse;}';
        $css .= '.dz-analytics-table th{background:#f7f9fa;text-align:left;padding:8px 10px;border-bottom:2px solid #e2e6e8;color:#555;font-weight:600;}';
        $css .= '.dz-analytics-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;}';
        $css .= '.dz-analytics-table tr:hover td{background:#fafbfc;}';
        $css .= '.dz-analytics-table .num{text-align:right;font-variant-numeric:tabular-nums;}';
        $css .= '.dz-analytics-top-row{cursor:pointer;}';
        $css .= '.dz-analytics-top-row a{color:#1a5e5a;text-decoration:none;display:block;}';
        $css .= '.dz-analytics-top-row a:hover{text-decoration:underline;}';
        $css .= '.dz-analytics-forbidden{padding:20px;background:#fcf8e3;border:1px solid #d6c87a;color:#8a6d3b;border-radius:4px;font-size:13px;}';
        // CHAT-T-055: Editorial Picks — pending pasek, wyszukiwarka, formularz add, tabela, akcje wiersza.
        $css .= '.dz-ep-pending{padding:10px 14px;background:#fffdf5;border:1px solid #e8d96a;color:#6b5a00;border-radius:4px;font-size:12px;margin-bottom:14px;}';
        $css .= '.dz-ep-pending--empty{background:#f7f9fa;border-color:#e2e6e8;color:#888;}';
        $css .= '.dz-ep-search{display:flex;gap:8px;align-items:center;margin-bottom:10px;}';
        $css .= '.dz-ep-search input[type=text]{padding:7px 10px;border:1px solid #ccc;border-radius:3px;font-size:13px;}';
        $css .= '.dz-ep-results{padding:10px;background:#f7f9fa;border:1px solid #e2e6e8;border-radius:4px;margin-bottom:14px;font-size:13px;}';
        $css .= '.dz-ep-results--empty{font-style:italic;color:#888;}';
        $css .= '.dz-ep-results ul{list-style:none;margin:8px 0 0;padding:0;}';
        $css .= '.dz-ep-result-item{display:flex;gap:14px;align-items:center;padding:8px 10px;border-bottom:1px solid #eee;color:#333;text-decoration:none;}';
        $css .= '.dz-ep-result-item:hover{background:#fff;color:#1a5e5a;text-decoration:none;}';
        $css .= '.dz-ep-result-item .name{font-weight:600;flex:1 1 auto;}';
        $css .= '.dz-ep-result-item .id{color:#999;font-size:11px;}';
        $css .= '.dz-ep-result-item .price{color:#1a5e5a;font-weight:600;}';
        $css .= '.dz-ep-result-item .stock.in{color:#5cb85c;font-size:11px;}';
        $css .= '.dz-ep-result-item .stock.out{color:#d9534f;font-size:11px;}';
        $css .= '.dz-ep-result-item .pick{color:#0066cc;font-size:11px;font-weight:600;}';
        $css .= '.dz-ep-add-form{background:#fff;border:1px solid #e2e6e8;border-radius:4px;padding:14px;margin-bottom:18px;}';
        $css .= '.dz-ep-selected{padding:8px 10px;background:#f7faff;border:1px solid #c9d7f0;border-radius:3px;margin-bottom:10px;font-size:13px;}';
        $css .= '.dz-ep-add-empty{padding:10px;font-style:italic;color:#888;font-size:13px;}';
        $css .= '.dz-ep-field{margin-bottom:10px;}';
        $css .= '.dz-ep-field label{display:block;font-size:12px;color:#555;margin-bottom:3px;font-weight:600;}';
        $css .= '.dz-ep-field input[type=text],.dz-ep-field input[type=number],.dz-ep-field textarea{width:100%;max-width:540px;padding:7px 10px;border:1px solid #ccc;border-radius:3px;font-size:13px;box-sizing:border-box;}';
        $css .= '.dz-ep-filters{display:flex;gap:8px;align-items:center;margin-bottom:12px;padding:8px;background:#f7f9fa;border:1px solid #e2e6e8;border-radius:4px;}';
        $css .= '.dz-ep-filters select{padding:6px 10px;border:1px solid #ccc;border-radius:3px;font-size:13px;background:#fff;}';
        $css .= '.dz-ep-row-actions{font-size:11px;min-width:200px;}';
        $css .= '.dz-ep-row-actions .quick{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px;}';
        $css .= '.dz-ep-edit-details{margin:0 0 4px;}';
        $css .= '.dz-ep-edit-details summary{cursor:pointer;color:#0066cc;font-weight:600;font-size:11px;}';
        $css .= '.dz-ep-edit-form{padding:8px;background:#f7f9fa;margin-top:4px;border-radius:3px;display:grid;grid-template-columns:auto 1fr;gap:4px 8px;align-items:center;font-size:11px;}';
        $css .= '.dz-ep-edit-form label{font-weight:600;color:#555;}';
        $css .= '.dz-ep-edit-form input[type=text],.dz-ep-edit-form input[type=number],.dz-ep-edit-form select{padding:4px 6px;border:1px solid #ccc;border-radius:3px;font-size:11px;width:100%;box-sizing:border-box;background:#fff;}';
        $css .= '.dz-ep-edit-form .submit-row{grid-column:1 / 3;margin-top:4px;}';
        $css .= '.dz-ep-status{display:inline-block;padding:2px 8px;border-radius:3px;font-size:0.85em;font-weight:600;}';
        $css .= '.dz-ep-status-active{background:#5cb85c;color:#fff;}';
        $css .= '.dz-ep-status-inactive{background:#999;color:#fff;}';
        $css .= '.dz-conv-items{list-style:none;padding:0;margin:8px 0;}';
        $css .= '.dz-conv-items li{margin:0;padding:0;}';
        $css .= '.dz-conv-item{display:block;padding:10px 12px;border-bottom:1px solid #eee;color:#333;text-decoration:none;}';
        $css .= '.dz-conv-item:hover{background:#f5f5f5;color:#1a5e5a;text-decoration:none;}';
        $css .= '.dz-conv-item.is-active{background:#e8f0fe;border-left:3px solid #1a5e5a;padding-left:9px;}';
        $css .= '.dz-conv-item-msg{font-size:13px;font-weight:600;color:#222;margin-bottom:4px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.3;max-height:36px;}';
        $css .= '.dz-conv-item-meta{font-size:11px;color:#777;margin-top:2px;display:flex;justify-content:space-between;gap:8px;align-items:center;}';
        $css .= '.dz-conv-placeholder{background:#f7f9fa;border:1px dashed #ccc;padding:40px;text-align:center;color:#888;border-radius:6px;}';
        $css .= '.dz-conv-bubble a{color:#1a5e5a;text-decoration:underline;word-break:break-all;}';
        $css .= '.dz-conv-bubble a:hover{color:#155050;}';
        $css .= '.dz-conv-bubble strong{font-weight:700;}';
        $css .= '</style>';
        return $css;
    }

    private function renderTabsScript()
    {
        // Stan zakladek persistujemy przez ?tab=X w URL (server-side, ladne refreshe).
        // Klik linka = pelen reload; bez JS toggle (prostszy, bez stanu klienta).
        return '';
    }

    private function renderTabsNav($activeTab, $role = '')
    {
        // CHAT-T-048 (118a) + CHAT-T-050 (107a) + CHAT-T-055: kolejnosc wg czestosci uzycia.
        // Analityka tylko dla 'admin' (admin-only endpoints) — nie-admin nie widzi linka.
        // Editorial any-role (127b): widoczne dla operatora i admina.
        // Bezpieczny default: gdy rola pusta/nieznana (blad whoami) -> Analityka NIE pokazana.
        $baseUrl = $this->context->link->getAdminLink('AdminDivezoneChat');
        $conv = $baseUrl . '&tab=' . self::TAB_CONVERSATIONS;
        $rec  = $baseUrl . '&tab=' . self::TAB_RECOMMENDATIONS;
        $ana  = $baseUrl . '&tab=' . self::TAB_ANALYTICS;
        $edi  = $baseUrl . '&tab=' . self::TAB_EDITORIAL;
        $mod  = $baseUrl . '&tab=' . self::TAB_MODELS;
        $cfg  = $baseUrl . '&tab=' . self::TAB_CONFIG;

        $clsConv = $activeTab === self::TAB_CONVERSATIONS   ? ' is-active' : '';
        $clsRec  = $activeTab === self::TAB_RECOMMENDATIONS ? ' is-active' : '';
        $clsAna  = $activeTab === self::TAB_ANALYTICS       ? ' is-active' : '';
        $clsEdi  = $activeTab === self::TAB_EDITORIAL       ? ' is-active' : '';
        $clsMod  = $activeTab === self::TAB_MODELS          ? ' is-active' : '';
        $clsCfg  = $activeTab === self::TAB_CONFIG          ? ' is-active' : '';

        $isAdmin = ($role === 'admin');

        $html  = '<nav class="dz-tabs-nav" role="tablist">';
        $html .= '<a href="' . htmlspecialchars($conv, ENT_QUOTES) . '" class="dz-tab-link' . $clsConv . '" role="tab">' . $this->l('Rozmowy') . '</a>';
        $html .= '<a href="' . htmlspecialchars($rec, ENT_QUOTES) . '" class="dz-tab-link' . $clsRec . '" role="tab">' . $this->l('Rekomendacje') . '</a>';
        if ($isAdmin) {
            $html .= '<a href="' . htmlspecialchars($ana, ENT_QUOTES) . '" class="dz-tab-link' . $clsAna . '" role="tab">' . $this->l('Analityka') . '</a>';
        }
        $html .= '<a href="' . htmlspecialchars($edi, ENT_QUOTES) . '" class="dz-tab-link' . $clsEdi . '" role="tab">' . $this->l('Editorial') . '</a>';
        $html .= '<a href="' . htmlspecialchars($mod, ENT_QUOTES) . '" class="dz-tab-link' . $clsMod . '" role="tab">' . $this->l('Modele') . '</a>';
        $html .= '<a href="' . htmlspecialchars($cfg, ENT_QUOTES) . '" class="dz-tab-link' . $clsCfg . '" role="tab">' . $this->l('Konfiguracja') . '</a>';
        $html .= '</nav>';
        return $html;
    }

    // ============================================================================
    // WHOAMI: maly pasek diagnostyczny u gory (zamiast osobnego panelu).
    // ============================================================================
    private function renderWhoamiBar($result, $employeeId)
    {
        $html = '<div class="dz-whoami-bar">';

        if (isset($result['error'])) {
            $html .= '<span class="dot err" title="' . $this->l('Kanal serwerowy ma blad') . '"></span>';
            $html .= '<span><strong>' . $this->l('Kanal serwerowy:') . '</strong> '
                  . htmlspecialchars((string) $result['error'], ENT_QUOTES) . '</span>';
            $html .= '<span style="margin-left:auto;color:#888;">'
                  . $this->l('Sprawdz') . ' <a href="' . htmlspecialchars($this->context->link->getAdminLink('AdminModules') . '&configure=divezone_chat', ENT_QUOTES) . '">'
                  . $this->l('Konfiguracje modulu') . '</a></span>';
        } else {
            $role   = isset($result['role'])        ? (string) $result['role']        : '?';
            $status = isset($result['status'])      ? (string) $result['status']      : '?';
            $emp    = isset($result['employee_id']) ? (int)    $result['employee_id'] : 0;
            $html .= '<span class="dot ok" title="' . $this->l('Kanal serwerowy dziala') . '"></span>';
            $html .= '<span>' . $this->l('Status') . ': <code>' . htmlspecialchars($status, ENT_QUOTES) . '</code></span>';
            $html .= '<span>' . $this->l('Employee') . ': <code>' . $emp . '</code></span>';
            $html .= '<span>' . $this->l('Rola') . ': <code>' . htmlspecialchars($role, ENT_QUOTES) . '</code></span>';
            $html .= '<span style="margin-left:auto;color:#888;">' . $this->l('Kontekst sklepu') . ': <code>' . (int) $employeeId . '</code></span>';
        }

        $html .= '</div>';
        return $html;
    }

    // ============================================================================
    // SEKCJA: Kuratorowane rekomendacje (T-035 CZESC B, read-only)
    // ============================================================================
    private function renderRecommendationsSection($result)
    {
        $html  = '<div class="panel" style="border-top-left-radius:0;">';
        $html .= '<div class="panel-heading"><i class="icon-star"></i> ' . $this->l('Kuratorowane rekomendacje (read-only)') . '</div>';
        $html .= '<div style="padding:18px;">';

        if (isset($result['error'])) {
            $html .= '<p style="color:#8a6d3b;background:#fcf8e3;padding:10px;border:1px solid #faebcc;border-radius:3px;">';
            $html .= '<strong>' . $this->l('Brak danych:') . '</strong> ' . htmlspecialchars((string) $result['error'], ENT_QUOTES);
            $html .= '</p>';
            $html .= '</div></div>';
            return $html;
        }

        $cats = isset($result['categories']) && is_array($result['categories']) ? $result['categories'] : array();
        if (empty($cats)) {
            $html .= '<p>' . $this->l('Brak kuratorowanych rekomendacji.') . '</p>';
            $html .= '</div></div>';
            return $html;
        }

        $html .= '<p style="color:#666;margin-bottom:18px;">' . $this->l('Widok read-only. Wskaznik popularnosci jest wzgledny w obrebie kategorii (nie pokazuje surowych liczb sprzedazowych — decyzja 38b).') . '</p>';

        foreach ($cats as $cat) {
            $catKey   = isset($cat['category_key']) ? (string) $cat['category_key'] : '?';
            $catLabel = isset($cat['category_label_pl']) ? (string) $cat['category_label_pl'] : '';
            $items    = isset($cat['items']) && is_array($cat['items']) ? $cat['items'] : array();

            $html .= '<div style="margin-bottom:24px;">';
            $html .= '<h4 style="margin:0 0 4px;border-bottom:1px solid #ddd;padding-bottom:6px;">';
            $html .= htmlspecialchars($catKey, ENT_QUOTES);
            $html .= ' <span style="font-weight:normal;color:#999;font-size:0.85em;">(' . count($items) . ')</span>';
            $html .= '</h4>';
            $html .= '<p style="margin:4px 0 12px;color:#666;font-size:0.9em;">' . htmlspecialchars($catLabel, ENT_QUOTES) . '</p>';

            if (empty($items)) {
                $html .= '<p><em>' . $this->l('Brak wpisow w tej kategorii.') . '</em></p>';
                $html .= '</div>';
                continue;
            }

            $html .= '<table class="table" style="margin:0;">';
            $html .= '<thead><tr>';
            $html .= '<th style="width:48px;">' . $this->l('#') . '</th>';
            $html .= '<th>' . $this->l('Produkt') . '</th>';
            $html .= '<th style="width:120px;">' . $this->l('Cena') . '</th>';
            $html .= '<th style="width:170px;">' . $this->l('Dostepnosc') . '</th>';
            $html .= '<th style="width:170px;">' . $this->l('Popularnosc') . '</th>';
            $html .= '<th style="width:140px;">' . $this->l('Status bota') . '</th>';
            $html .= '</tr></thead><tbody>';

            foreach ($items as $item) {
                $html .= $this->renderRecommendationRow($item);
            }

            $html .= '</tbody></table>';
            $html .= '</div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private function renderRecommendationRow($item)
    {
        $priority      = isset($item['priority']) ? (int) $item['priority'] : 0;
        $productName   = isset($item['product_name']) && $item['product_name'] !== null ? (string) $item['product_name'] : $this->l('(brak nazwy)');
        $productId     = isset($item['product_id']) ? (int) $item['product_id'] : 0;
        $rationale     = isset($item['rationale_pl']) ? (string) $item['rationale_pl'] : '';
        $price         = isset($item['price']) ? $item['price'] : null;
        $priceBefore   = isset($item['price_before_discount']) ? $item['price_before_discount'] : null;
        $availability  = isset($item['availability']) ? (string) $item['availability'] : null;
        $hiddenFromBot = !empty($item['hidden_from_bot']);
        $itemActive    = !empty($item['active']);
        $popBucket     = isset($item['popularity_bucket']) ? (string) $item['popularity_bucket'] : 'no_data';
        $popPercent    = isset($item['popularity_percent']) ? $item['popularity_percent'] : null;

        $rowStyle = $hiddenFromBot || !$itemActive ? ' style="background:#fdf3f4;"' : '';

        $html  = '<tr' . $rowStyle . '>';
        $html .= '<td><strong>' . $priority . '</strong></td>';

        $html .= '<td>';
        $html .= '<a href="' . htmlspecialchars($this->context->link->getAdminLink('AdminProducts') . '&id_product=' . $productId . '&updateproduct', ENT_QUOTES) . '" target="_blank" style="font-weight:600;">';
        $html .= htmlspecialchars($productName, ENT_QUOTES);
        $html .= '</a>';
        $html .= ' <span style="color:#999;font-size:0.85em;">#' . $productId . '</span>';
        if ($rationale !== '') {
            $html .= '<div style="margin-top:4px;color:#666;font-size:0.9em;font-style:italic;">' . htmlspecialchars($rationale, ENT_QUOTES) . '</div>';
        }
        $html .= '</td>';

        $html .= '<td>';
        if ($price !== null) {
            $html .= '<strong>' . number_format((float) $price, 2, ',', ' ') . ' zł</strong>';
            if ($priceBefore !== null && (float) $priceBefore > (float) $price) {
                $html .= '<br><span style="text-decoration:line-through;color:#999;font-size:0.85em;">' . number_format((float) $priceBefore, 2, ',', ' ') . ' zł</span>';
            }
        } else {
            $html .= '<span style="color:#999;">—</span>';
        }
        $html .= '</td>';

        $html .= '<td>' . $this->formatAvailability($availability) . '</td>';
        $html .= '<td>' . $this->renderPopularity($popBucket, $popPercent) . '</td>';

        $html .= '<td>';
        if (!$itemActive) {
            $html .= '<span style="display:inline-block;padding:2px 8px;background:#777;color:#fff;border-radius:3px;font-size:0.85em;">' . $this->l('Wpis nieaktywny') . '</span>';
        } elseif ($hiddenFromBot) {
            $html .= '<span style="display:inline-block;padding:2px 8px;background:#d9534f;color:#fff;border-radius:3px;font-size:0.85em;" title="' . $this->l('Bot pomija ten produkt (nieaktywny / niewidoczny / niedostepny w sklepie).') . '">' . $this->l('Ukryty przed botem') . '</span>';
        } else {
            $html .= '<span style="display:inline-block;padding:2px 8px;background:#5cb85c;color:#fff;border-radius:3px;font-size:0.85em;">' . $this->l('Aktywny') . '</span>';
        }
        $html .= '</td>';

        $html .= '</tr>';
        return $html;
    }

    private function formatAvailability($availability)
    {
        if ($availability === null) {
            return '<span style="color:#999;">' . $this->l('brak danych') . '</span>';
        }
        if ($availability === 'in_stock') {
            return '<span style="color:#3c763d;">' . $this->l('dostepny od reki') . '</span>';
        }
        if ($availability === 'available_to_order') {
            return '<span style="color:#8a6d3b;">' . $this->l('na zamowienie 2-5 dni') . '</span>';
        }
        if ($availability === 'unavailable') {
            return '<span style="color:#a94442;">' . $this->l('niedostepny') . '</span>';
        }
        return '<span style="color:#999;">' . htmlspecialchars((string) $availability, ENT_QUOTES) . '</span>';
    }

    private function renderPopularity($bucket, $percent)
    {
        if ($bucket === 'no_data') {
            return '<span style="color:#999;">' . $this->l('brak danych') . '</span>';
        }
        $colors = array('low' => '#d9534f', 'mid' => '#f0ad4e', 'high' => '#5cb85c');
        $labels = array('low' => $this->l('rzadko'), 'mid' => $this->l('srednio'), 'high' => $this->l('czesto'));
        $color = isset($colors[$bucket]) ? $colors[$bucket] : '#999';
        $label = isset($labels[$bucket]) ? $labels[$bucket] : (string) $bucket;
        $pct   = $percent !== null ? (int) $percent : 0;
        $html  = '<div style="display:flex;align-items:center;gap:8px;">';
        $html .= '<div style="background:#e0e0e0;width:90px;height:8px;border-radius:4px;overflow:hidden;">';
        $html .= '<div style="background:' . $color . ';width:' . max(2, $pct) . '%;height:100%;"></div>';
        $html .= '</div>';
        $html .= '<span style="font-size:0.9em;color:' . $color . ';">' . $label . '</span>';
        $html .= '</div>';
        return $html;
    }

    // ============================================================================
    // SEKCJA: Modele (CHAT-T-045, ADR-070).
    //
    // Struktura pod 3 poziomy (basic/primary/escalation, pamiec #10):
    //   poziomy renderowane z TABLICY, nie z hardkodu. Dodanie 3. poziomu basic
    //   = wpisanie kolejnej pozycji do $tiers + routing w ChatService.
    //   Backend (decyzja 93c) na razie obsluguje 2 poziomy.
    // ============================================================================
    private function renderModelsSection($result)
    {
        $html  = '<div class="panel" style="border-top-left-radius:0;">';
        $html .= '<div class="panel-heading"><i class="icon-cogs"></i> ' . $this->l('Modele AI — konfiguracja') . '</div>';
        $html .= '<div style="padding:18px;">';

        // Flash z handleModelsSave (jezeli byl POST przed render).
        if ($this->modelsFlash !== '') {
            $html .= '<div class="dz-flash ' . htmlspecialchars($this->modelsFlashType, ENT_QUOTES) . '">'
                  . htmlspecialchars($this->modelsFlash, ENT_QUOTES) . '</div>';
        }

        if (isset($result['error'])) {
            $html .= '<p style="color:#a94442;background:#f2dede;padding:10px;border:1px solid #ebccd1;border-radius:3px;">';
            $html .= '<strong>' . $this->l('Blad pobrania ustawien:') . '</strong> ' . htmlspecialchars((string) $result['error'], ENT_QUOTES);
            $html .= '</p>';
            $html .= '</div></div>';
            return $html;
        }

        $settings        = isset($result['settings']) && is_array($result['settings']) ? $result['settings'] : array();
        $availableModels = isset($result['available_models']) && is_array($result['available_models']) ? $result['available_models'] : array();
        $rateUsdPln      = isset($result['exchange_rate_usd_pln']) ? (float) $result['exchange_rate_usd_pln'] : 0.0;

        if (empty($availableModels)) {
            $html .= '<p style="color:#8a6d3b;background:#fcf8e3;padding:10px;border:1px solid #faebcc;border-radius:3px;">';
            $html .= $this->l('Backend nie zwrocil listy dostepnych modeli (available_models). Sprawdz konfiguracje cennika.');
            $html .= '</p>';
            $html .= '</div></div>';
            return $html;
        }

        // Lista poziomow — STRUKTURA POD 3 POZIOMY.
        // TODO 3. poziom: basic — wymaga routingu w ChatService (osobny task).
        // Po dodaniu routingu w ChatService dorzucic tu np.:
        //   array('key' => 'model_basic', 'label' => $this->l('Model basic (proste pytania)'), 'tier' => 'basic'),
        $tiers = array(
            array(
                'key'   => 'model_primary',
                'label' => $this->l('Model primary (podstawowy)'),
                'tier'  => 'primary',
            ),
            array(
                'key'   => 'model_escalation',
                'label' => $this->l('Model escalation (eskalacja)'),
                'tier'  => 'escalation',
            ),
        );

        $formAction = $this->context->link->getAdminLink('AdminDivezoneChat') . '&tab=' . self::TAB_MODELS;

        $html .= '<form method="post" class="dz-models-form" action="' . htmlspecialchars($formAction, ENT_QUOTES) . '">';

        // 1. Poziomy modeli — generowane z tablicy.
        foreach ($tiers as $tier) {
            $currentValue = isset($settings[$tier['key']]) ? (string) $settings[$tier['key']] : '';
            $html .= '<div class="field-row">';
            $html .= '<label for="dz-' . htmlspecialchars($tier['key'], ENT_QUOTES) . '">' . $tier['label'] . '</label>';
            $html .= '<div>';
            $html .= $this->renderModelSelect($tier['key'], $tier['tier'], $availableModels, $currentValue, $rateUsdPln);
            $html .= '<span class="field-hint">' . $this->l('Cena: input / output za 1 mln tokenow USD (kurs USD/PLN podany z backendu).') . '</span>';
            $html .= '</div>';
            $html .= '</div>';
        }

        // 2. reasoning_effort — single field, dotyczy modeli ktore go wspieraja.
        $reasoningCurrent = isset($settings['reasoning_effort']) ? (string) $settings['reasoning_effort'] : 'minimal';
        $reasoningOpts = array('minimal', 'low', 'medium', 'high');
        $html .= '<div class="field-row">';
        $html .= '<label for="dz-reasoning_effort">' . $this->l('Reasoning effort') . '</label>';
        $html .= '<div>';
        $html .= '<select id="dz-reasoning_effort" name="reasoning_effort">';
        foreach ($reasoningOpts as $opt) {
            $sel = $opt === $reasoningCurrent ? ' selected' : '';
            $html .= '<option value="' . $opt . '"' . $sel . '>' . $opt . '</option>';
        }
        $html .= '</select>';
        $html .= '<span class="field-hint">' . $this->l('Dziala tylko dla modeli ktore wspieraja reasoning (np. claude-opus-4-7, gpt-5*). Dla pozostalych modeli pole jest ignorowane.') . '</span>';
        $html .= '</div>';
        $html .= '</div>';

        // 3. max_tokens — number input.
        $maxTokensCurrent = isset($settings['max_tokens']) ? (int) $settings['max_tokens'] : 1500;
        $html .= '<div class="field-row">';
        $html .= '<label for="dz-max_tokens">' . $this->l('Max tokens (output)') . '</label>';
        $html .= '<div>';
        $html .= '<input type="number" id="dz-max_tokens" name="max_tokens" value="' . $maxTokensCurrent . '" min="64" max="16000" step="64">';
        $html .= '<span class="field-hint">' . $this->l('Limit dlugosci odpowiedzi (sufit). Wieksze wartosci = dluzsze odpowiedzi + wyzszy koszt output.') . '</span>';
        $html .= '</div>';
        $html .= '</div>';

        // Submit.
        $html .= '<div class="submit-row">';
        $html .= '<button type="submit" name="submitDivezoneChatModels">' . $this->l('Zapisz modele') . '</button>';
        $html .= '</div>';

        $html .= '</form>';

        // Notka o 3. poziomie (basic) — informacja dla operatora ze brak nie jest bugiem.
        $html .= '<div class="dz-todo-basic">';
        $html .= '<strong>' . $this->l('Planowane:') . '</strong> ' . $this->l('Trzeci poziom "basic" (najtanszy model do prostych pytan) wymaga routingu w ChatService — zostanie dodany w osobnym tasku. UI zostal zaprojektowany tak, by 3. poziom doszedl bez przebudowy.');
        $html .= '</div>';

        $html .= '</div></div>';
        return $html;
    }

    /**
     * Render <select> dla jednego poziomu (primary/escalation/basic).
     * Modele pogrupowane optgroup po providerze, label z cena.
     */
    private function renderModelSelect($fieldName, $tier, $availableModels, $currentValue, $rateUsdPln)
    {
        $html = '<select id="dz-' . htmlspecialchars($fieldName, ENT_QUOTES) . '" name="' . htmlspecialchars($fieldName, ENT_QUOTES) . '">';

        foreach ($availableModels as $provider => $tiers) {
            if (!is_array($tiers) || !isset($tiers[$tier]) || !is_array($tiers[$tier])) {
                continue;
            }
            $html .= '<optgroup label="' . htmlspecialchars(strtoupper((string) $provider), ENT_QUOTES) . '">';
            foreach ($tiers[$tier] as $model) {
                $value = isset($model['value']) ? (string) $model['value'] : '';
                $label = isset($model['label']) ? (string) $model['label'] : $value;
                $inPrice  = isset($model['input_price'])  ? (float) $model['input_price']  : null;
                $outPrice = isset($model['output_price']) ? (float) $model['output_price'] : null;
                $priceStr = '';
                if ($inPrice !== null && $outPrice !== null) {
                    $priceStr = sprintf(' — $%.2f/$%.2f', $inPrice, $outPrice);
                }
                $sel = $value === $currentValue ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars($value, ENT_QUOTES) . '"' . $sel . '>'
                      . htmlspecialchars($label . $priceStr, ENT_QUOTES)
                      . '</option>';
            }
            $html .= '</optgroup>';
        }

        $html .= '</select>';
        return $html;
    }

    /**
     * Handler zapisu ekranu Modele: POST /api/settings z body {settings:{...}}.
     * Wynik (sukces/blad) ustawia $this->modelsFlash + $this->modelsFlashType
     * dla wyswietlenia w renderModelsSection.
     */
    private function handleModelsSave($employeeId)
    {
        // Zbieranie pol z formularza — TYLKO znane klucze (whitelist).
        $payload = array('settings' => array());

        $modelPrimary    = trim((string) Tools::getValue('model_primary', ''));
        $modelEscalation = trim((string) Tools::getValue('model_escalation', ''));
        $reasoning       = trim((string) Tools::getValue('reasoning_effort', ''));
        $maxTokens       = (int) Tools::getValue('max_tokens', 0);

        if ($modelPrimary !== '')    { $payload['settings']['model_primary']    = $modelPrimary; }
        if ($modelEscalation !== '') { $payload['settings']['model_escalation'] = $modelEscalation; }
        if ($reasoning !== '')       { $payload['settings']['reasoning_effort'] = $reasoning; }
        if ($maxTokens > 0)          { $payload['settings']['max_tokens']       = $maxTokens; }

        if (empty($payload['settings'])) {
            $this->modelsFlash     = $this->l('Brak pol do zapisania.');
            $this->modelsFlashType = 'error';
            return;
        }

        $body = json_encode($payload);
        $resp = $this->callBackend(self::ENDPOINT_SETTINGS, $employeeId, 'POST', $body);

        if (isset($resp['error'])) {
            // Wykryj klasy bledow z http_status / treci.
            $httpStatus = isset($resp['http_status']) ? (int) $resp['http_status'] : 0;
            if ($httpStatus === 403) {
                $err = (string) $resp['error'];
                if (strpos($err, 'admin') !== false) {
                    $this->modelsFlash = $this->l('Tylko administrator moze zmieniac modele AI. Twoja rola nie ma uprawnien.');
                } else {
                    $this->modelsFlash = $this->l('Brak roli (no_role): konto nie ma przypisanej roli w divechat_admin_roles.');
                }
            } elseif ($httpStatus === 401) {
                $this->modelsFlash = $this->l('Brak/nieprawidlowy token kanalu serwerowego. Sprawdz konfiguracje modulu (Sekret SERWEROWY).');
            } else {
                $this->modelsFlash = $this->l('Blad zapisu:') . ' ' . (string) $resp['error'];
            }
            $this->modelsFlashType = 'error';
            return;
        }

        if (isset($resp['success']) && $resp['success']) {
            $this->modelsFlash     = $this->l('Modele zapisane.');
            $this->modelsFlashType = 'success';
        } else {
            $this->modelsFlash     = $this->l('Zapisano, ale backend nie potwierdzil success.');
            $this->modelsFlashType = 'success';
        }
    }

    // ============================================================================
    // SEKCJA: Konfiguracja (CHAT-T-047, decyzja 108a).
    //
    // Zero duplikacji formularza — uzywamy publicznych metod Divezone_Chat
    // (renderConfigForm + handleConfigSubmit). To samo zrodlo HTML co
    // Moduly -> Konfiguruj (getContent). Decyzja 114a OPCJA B.
    // ============================================================================
    private function renderConfigSection()
    {
        $module = Module::getInstanceByName('divezone_chat');
        if (!$module) {
            return '<div class="alert alert-danger">' . $this->l('Modul divezone_chat niedostepny (Module::getInstanceByName zwrocil falsz).') . '</div>';
        }

        $html  = '<div class="panel" style="border-top-left-radius:0;">';
        $html .= '<div class="panel-heading"><i class="icon-cog"></i> ' . $this->l('Konfiguracja modulu') . '</div>';
        $html .= '<div style="padding:18px;">';

        $useSubmitted = false;
        if (Tools::isSubmit('submitDivezoneChatConfig')) {
            $result        = $module->handleConfigSubmit();
            $html         .= $result['messages_html'];
            $useSubmitted  = $result['validation_failed'];
        }

        $html .= $module->renderConfigForm($useSubmitted);

        $html .= '</div></div>';
        return $html;
    }

    // ============================================================================
    // SEKCJA: Rozmowy (CHAT-T-048, decyzje 104b/105a/106a, ADR-070).
    //
    // Lista + szczegoly + zmiana statusu. Backend gotowy (/api/conversations/*,
    // CHAT-T-046, any-role). Bez JS — pelen reload przez ?tab/?session_id.
    // Render wiadomosci wg history.js: user/assistant -> babelki; tool_result pomijamy.
    // ============================================================================

    /**
     * CHAT-T-051 (113a): master-detail server-side. Jeden ekran, dwie kolumny.
     * LEWA (waska, ~340px, wlasny scroll): lista rozmow (zawsze).
     * PRAWA (elastyczna, wlasny scroll): wybrana rozmowa wg ?session_id
     *   ALBO placeholder gdy brak ?session_id. Klik w pozycje listy = pelen reload
     *   z nowym session_id (filtry/page zachowane w linku).
     */
    private function renderConversationsSection($employeeId)
    {
        $sessionId = trim((string) Tools::getValue('session_id', ''));

        $listHtml = $this->renderConversationsList($employeeId, $sessionId);

        if ($sessionId !== '') {
            $detailHtml = $this->renderConversationDetail($employeeId, $sessionId);
        } else {
            $detailHtml = $this->renderConvPlaceholder();
        }

        $html  = '<div class="dz-conv-layout">';
        $html .= '<aside class="dz-conv-list-col">' . $listHtml . '</aside>';
        $html .= '<section class="dz-conv-detail-col">' . $detailHtml . '</section>';
        $html .= '</div>';
        return $html;
    }

    private function renderConversationsList($employeeId, $activeSessionId = '')
    {
        $page         = max(1, (int) Tools::getValue('page', 1));
        $perPage      = (int) Tools::getValue('per_page', 20);
        if ($perPage <= 0 || $perPage > 100) {
            $perPage = 20;
        }
        $search       = trim((string) Tools::getValue('search', ''));
        $adminStatus  = trim((string) Tools::getValue('admin_status', ''));
        $knowledgeGap = (int) Tools::getValue('knowledge_gap', 0) === 1;

        // Whitelist statusu w queryString — nieznane wartosci -> ignorowane lokalnie.
        if ($adminStatus !== '' && !array_key_exists($adminStatus, $this->convStatusOptions())) {
            $adminStatus = '';
        }

        $filters = array(
            'page'     => $page,
            'per_page' => $perPage,
        );
        if ($search !== '') {
            $filters['search'] = $search;
        }
        if ($adminStatus !== '') {
            $filters['admin_status'] = $adminStatus;
        }
        if ($knowledgeGap) {
            $filters['knowledge_gap'] = 'true';
        }

        $resp = $this->callBackend(
            self::ENDPOINT_CONVERSATIONS . '?' . http_build_query($filters),
            $employeeId
        );

        $html  = '<div class="panel" style="border-top-left-radius:0;">';
        $html .= '<div class="panel-heading"><i class="icon-comments"></i> ' . $this->l('Rozmowy klientow') . '</div>';
        // CHAT-T-052 (poprawka 2): wrapper padding=0 — lista styka sie z krawedziami panelu.
        // Flash/filtry/error/pager dostaja wlasne ramki paddingu, bo by nie wygladaly
        // dobrze przy edge-to-edge. Pozycje .dz-conv-item maja juz wlasny padding (10px 12px).
        $html .= '<div style="padding:0;">';

        if ($this->convFlash !== '') {
            $html .= '<div style="padding:14px 14px 0 14px;">';
            $html .= '<div class="dz-flash ' . htmlspecialchars($this->convFlashType, ENT_QUOTES) . '">'
                  . htmlspecialchars($this->convFlash, ENT_QUOTES) . '</div>';
            $html .= '</div>';
        }

        $html .= $this->renderConvFilters($search, $adminStatus, $knowledgeGap);

        if (isset($resp['error'])) {
            $html .= '<p style="color:#a94442;background:#f2dede;padding:10px;margin:14px;border:1px solid #ebccd1;border-radius:3px;">';
            $html .= '<strong>' . $this->l('Blad pobrania listy:') . '</strong> ' . htmlspecialchars((string) $resp['error'], ENT_QUOTES);
            $html .= '</p>';
            $html .= '</div></div>';
            return $html;
        }

        $convs = isset($resp['conversations']) && is_array($resp['conversations']) ? $resp['conversations'] : array();
        $total = isset($resp['total']) ? (int) $resp['total'] : 0;

        if (empty($convs)) {
            $html .= '<p style="padding:14px;">' . $this->l('Brak rozmow.') . '</p>';
            $html .= '</div></div>';
            return $html;
        }

        // CHAT-T-051 (113a + spec listy): waska kolumna z pozycjami zamiast tabeli.
        // Kazda pozycja: pierwsza wiadomosc (skrocona) + data + "Klient | Status".
        $html .= '<ul class="dz-conv-items">';
        foreach ($convs as $conv) {
            $html .= $this->renderConvListItem($conv, $activeSessionId);
        }
        $html .= '</ul>';

        $html .= '<div style="padding:0 14px 14px 14px;">' . $this->renderConvPager($page, $perPage, $total, $filters) . '</div>';

        $html .= '</div></div>';
        return $html;
    }

    private function renderConvFilters($search, $adminStatus, $knowledgeGap)
    {
        // GET form: empty action -> obecny URL. Hidden controller+token+tab
        // sa potrzebne zeby PS routowal prawidlowo (GET form strippuje query string z action).
        $token = Tools::getAdminTokenLite('AdminDivezoneChat');

        // CHAT-T-052 (poprawka 3): jedna linia bez labeli, placeholder w inpucie,
        // pierwsza opcja "— Wyświetlane wszystkie —". CSS w renderTabsStyles nadpisuje
        // domyslny flex-direction:column dla .dz-conv-list-col .dz-conv-filters.
        $html  = '<form method="get" class="dz-conv-filters" action="">';
        $html .= '<input type="hidden" name="controller" value="AdminDivezoneChat">';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
        $html .= '<input type="hidden" name="tab" value="' . self::TAB_CONVERSATIONS . '">';

        $html .= '<div><input type="text" id="dz-conv-search" name="search" value="' . htmlspecialchars($search, ENT_QUOTES) . '" placeholder="' . $this->l('Szukaj konwersacji') . '"></div>';

        $html .= '<div><select id="dz-conv-status" name="admin_status">';
        $html .= '<option value="">' . $this->l('— Wyswietlane wszystkie —') . '</option>';
        foreach ($this->convStatusOptions() as $k => $label) {
            $sel = $adminStatus === $k ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($k, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div class="check-row"><label><input type="checkbox" name="knowledge_gap" value="1"' . ($knowledgeGap ? ' checked' : '') . '> ' . $this->l('Luki wiedzy') . '</label></div>';

        $html .= '<div><button type="submit" class="btn btn-primary">' . $this->l('Filtruj') . '</button></div>';

        $html .= '</form>';
        return $html;
    }

    /**
     * Pozycja listy (waska kolumna, CHAT-T-051) — DOKLADNIE 3 pola wg decyzji Karola:
     *  1. Pierwsza wiadomosc (skrocona ~80 znakow + ellipsis; fallback "(brak tresci)").
     *  2. Data rozpoczecia (formatConvDate Y-m-d H:i).
     *  3. "Klient | Status (badge)" + opcjonalnie ikonka luki wiedzy.
     * Klik = pelen reload zakladki z session_id (filtry/page zachowane w linku).
     */
    private function renderConvListItem($conv, $activeSessionId)
    {
        $sessionId    = isset($conv['session_id']) ? (string) $conv['session_id'] : '';
        $customerId   = isset($conv['customer_id']) ? (int) $conv['customer_id'] : 0;
        $startedAt    = isset($conv['started_at']) ? (string) $conv['started_at'] : '';
        $firstMessage = isset($conv['first_message']) ? $conv['first_message'] : null;
        $knowledgeGap = !empty($conv['knowledge_gap']);
        $adminStatus  = (isset($conv['admin_status']) && $conv['admin_status'] !== null && $conv['admin_status'] !== '')
            ? (string) $conv['admin_status']
            : 'new';

        $url = $this->context->link->getAdminLink('AdminDivezoneChat')
            . '&tab=' . self::TAB_CONVERSATIONS
            . '&session_id=' . rawurlencode($sessionId);

        // Zachowaj filtry i strone w linkach — klik nie zeruje kontekstu wyszukiwania.
        foreach (array('page', 'per_page', 'search', 'admin_status', 'knowledge_gap') as $k) {
            $v = Tools::getValue($k, '');
            if ($v !== '' && $v !== null) {
                $url .= '&' . $k . '=' . rawurlencode((string) $v);
            }
        }

        $activeClass = ($sessionId !== '' && $sessionId === $activeSessionId) ? ' is-active' : '';
        $msgPreview  = $this->truncateFirstMessage($firstMessage);

        // CHAT-T-052 (poprawka 1): JEDEN div meta — data lewo, klient|status|⚠ prawo
        // (klasa .dz-conv-item-meta ma justify-content:space-between).
        $html  = '<li><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="dz-conv-item' . $activeClass . '">';
        $html .= '<div class="dz-conv-item-msg">' . htmlspecialchars($msgPreview, ENT_QUOTES) . '</div>';
        $html .= '<div class="dz-conv-item-meta">';
        $html .= '<span>' . htmlspecialchars($this->formatConvDate($startedAt), ENT_QUOTES) . '</span>';
        $html .= '<span>';
        $html .= ($customerId > 0 ? '#' . $customerId : '<em>' . $this->l('gosc') . '</em>') . ' | ' . $this->renderStatusBadge($adminStatus);
        if ($knowledgeGap) {
            $html .= ' <span title="' . $this->l('luka wiedzy') . '" style="color:#d9534f;font-weight:bold;">&#9888;</span>';
        }
        $html .= '</span>';
        $html .= '</div>';
        $html .= '</a></li>';
        return $html;
    }

    /**
     * Skraca pierwsza wiadomosc do ~80 znakow + ellipsis (wzorzec admin-tables.js).
     * Fallback "(brak tresci)" gdy puste/null (decyzja 115a). Multibyte-safe.
     */
    private function truncateFirstMessage($msg)
    {
        if ($msg === null) {
            return $this->l('(brak tresci)');
        }
        $msg = trim((string) $msg);
        if ($msg === '') {
            return $this->l('(brak tresci)');
        }
        $maxLen = 80;
        if (function_exists('mb_strlen')) {
            if (mb_strlen($msg, 'UTF-8') > $maxLen) {
                return mb_substr($msg, 0, $maxLen, 'UTF-8') . '…';
            }
        } else {
            if (strlen($msg) > $maxLen) {
                return substr($msg, 0, $maxLen) . '…';
            }
        }
        return $msg;
    }

    /**
     * Prawa kolumna gdy brak ?session_id (CHAT-T-051).
     */
    private function renderConvPlaceholder()
    {
        $html  = '<div class="panel" style="border-top-left-radius:0;">';
        $html .= '<div class="panel-heading"><i class="icon-comment"></i> ' . $this->l('Rozmowa') . '</div>';
        $html .= '<div style="padding:18px;">';
        $html .= '<div class="dz-conv-placeholder">' . $this->l('Wybierz rozmowe z listy po lewej, aby zobaczyc szczegoly.') . '</div>';
        $html .= '</div></div>';
        return $html;
    }

    private function renderConvPager($page, $perPage, $total, $filters)
    {
        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
        if ($totalPages <= 1) {
            return '<div class="dz-conv-pager">' . $this->l('Liczba wpisow:') . ' <strong>' . (int) $total . '</strong></div>';
        }

        $baseUrl = $this->context->link->getAdminLink('AdminDivezoneChat') . '&tab=' . self::TAB_CONVERSATIONS;

        // Bez 'page' — kazdy link sam dokleja swoja strone.
        $qFilters = $filters;
        unset($qFilters['page']);
        $qString = !empty($qFilters) ? '&' . http_build_query($qFilters) : '';

        $html  = '<div class="dz-conv-pager">';
        if ($page > 1) {
            $html .= '<a href="' . htmlspecialchars($baseUrl . '&page=' . ($page - 1) . $qString, ENT_QUOTES) . '">&laquo; ' . $this->l('Poprzednia') . '</a>';
        }
        $html .= '<span>' . $this->l('Strona') . ' <strong>' . (int) $page . '</strong> ' . $this->l('z') . ' <strong>' . (int) $totalPages . '</strong>';
        $html .= ' &middot; ' . $this->l('Wpisow:') . ' <strong>' . (int) $total . '</strong></span>';
        if ($page < $totalPages) {
            $html .= '<a href="' . htmlspecialchars($baseUrl . '&page=' . ($page + 1) . $qString, ENT_QUOTES) . '">' . $this->l('Nastepna') . ' &raquo;</a>';
        }
        $html .= '</div>';
        return $html;
    }

    private function renderConversationDetail($employeeId, $sessionId)
    {
        $endpoint = self::ENDPOINT_CONVERSATIONS . '/' . rawurlencode($sessionId);
        $resp     = $this->callBackend($endpoint, $employeeId);

        $html  = '<div class="panel" style="border-top-left-radius:0;">';
        $html .= '<div class="panel-heading"><i class="icon-comment"></i> ' . $this->l('Rozmowa') . '</div>';
        $html .= '<div style="padding:18px;">';

        // CHAT-T-051: brak "wroc do listy" — master-detail, lista jest stale widoczna po lewej.

        if ($this->convFlash !== '') {
            $html .= '<div class="dz-flash ' . htmlspecialchars($this->convFlashType, ENT_QUOTES) . '">'
                  . htmlspecialchars($this->convFlash, ENT_QUOTES) . '</div>';
        }

        if (isset($resp['error'])) {
            $html .= '<p style="color:#a94442;background:#f2dede;padding:10px;border:1px solid #ebccd1;border-radius:3px;">';
            $html .= '<strong>' . $this->l('Blad pobrania szczegolow:') . '</strong> ' . htmlspecialchars((string) $resp['error'], ENT_QUOTES);
            $html .= '</p>';
            $html .= '</div></div>';
            return $html;
        }

        $customerId   = isset($resp['customer_id']) ? (int) $resp['customer_id'] : 0;
        $model        = isset($resp['model_used']) ? (string) $resp['model_used'] : '';
        $startedAt    = isset($resp['started_at']) ? (string) $resp['started_at'] : '';
        $updatedAt    = isset($resp['updated_at']) ? (string) $resp['updated_at'] : '';
        $closedAt     = isset($resp['closed_at']) ? (string) $resp['closed_at'] : '';
        $knowledgeGap = !empty($resp['knowledge_gap']);
        $adminStatus  = (isset($resp['admin_status']) && $resp['admin_status'] !== null && $resp['admin_status'] !== '')
            ? (string) $resp['admin_status']
            : 'new';
        $adminNotes   = isset($resp['admin_notes']) && $resp['admin_notes'] !== null ? (string) $resp['admin_notes'] : '';

        // CHAT-T-052 (poprawka 4): meta i koszty obok siebie — 2 kolumny w jednym wierszu.
        $html .= '<div class="dz-conv-meta-row">';
        $html .= '<div class="dz-conv-meta"><dl>';
        $html .= '<dt>' . $this->l('Session ID') . '</dt><dd><code>' . htmlspecialchars($sessionId, ENT_QUOTES) . '</code></dd>';
        $html .= '<dt>' . $this->l('Klient') . '</dt><dd>' . ($customerId > 0 ? '#' . $customerId : '<em>' . $this->l('gosc') . '</em>') . '</dd>';
        $html .= '<dt>' . $this->l('Model') . '</dt><dd><code>' . htmlspecialchars($model, ENT_QUOTES) . '</code></dd>';
        $html .= '<dt>' . $this->l('Rozpoczeto') . '</dt><dd>' . htmlspecialchars($this->formatConvDate($startedAt), ENT_QUOTES) . '</dd>';
        $html .= '<dt>' . $this->l('Aktualizacja') . '</dt><dd>' . htmlspecialchars($this->formatConvDate($updatedAt), ENT_QUOTES) . '</dd>';
        if ($closedAt !== '') {
            $html .= '<dt>' . $this->l('Zamknieto') . '</dt><dd>' . htmlspecialchars($this->formatConvDate($closedAt), ENT_QUOTES) . '</dd>';
        }
        $html .= '<dt>' . $this->l('Luka wiedzy') . '</dt><dd>' . ($knowledgeGap ? '<strong style="color:#d9534f">TAK</strong>' : 'nie') . '</dd>';
        $html .= '<dt>' . $this->l('Status') . '</dt><dd>' . $this->renderStatusBadge($adminStatus) . '</dd>';
        $html .= '</dl></div>';

        $html .= $this->renderConvCosts($resp);
        $html .= '</div>';

        $html .= $this->renderConvStatusForm($sessionId, $adminStatus, $adminNotes);

        $messages = isset($resp['messages']) && is_array($resp['messages']) ? $resp['messages'] : array();
        $html .= '<h3 style="margin:24px 0 8px;border-bottom:1px solid #ddd;padding-bottom:6px;">' . $this->l('Przebieg rozmowy') . '</h3>';
        $html .= $this->renderConvMessages($messages);

        $html .= $this->renderConvDiagnostics($resp);

        $html .= '</div></div>';
        return $html;
    }

    private function renderConvCosts($resp)
    {
        $tokensIn    = isset($resp['tokens_input']) ? (int) $resp['tokens_input'] : 0;
        $tokensOut   = isset($resp['tokens_output']) ? (int) $resp['tokens_output'] : 0;
        $cacheRead   = isset($resp['cache_read_tokens']) ? (int) $resp['cache_read_tokens'] : 0;
        $cacheCreate = isset($resp['cache_creation_tokens']) ? (int) $resp['cache_creation_tokens'] : 0;
        $convCost    = isset($resp['conversation_cost']) && is_array($resp['conversation_cost']) ? $resp['conversation_cost'] : null;

        // CHAT-T-052 (poprawka 6): usunieto " · estimated_cost: $X USD" — backend
        // getConversationCost() czyta dokladnie kolumne estimated_cost i dokleja PLN,
        // wiec ta sama wartosc co conversation_cost.total_usd. Linia byla nadmiarowa.
        $html  = '<div class="dz-conv-cost">';
        $html .= '<strong>' . $this->l('Koszty i tokeny') . ':</strong> ';
        $html .= $this->l('input') . ' ' . number_format($tokensIn) . ', ';
        $html .= $this->l('output') . ' ' . number_format($tokensOut);
        if ($cacheRead > 0 || $cacheCreate > 0) {
            $html .= ', ' . $this->l('cache read') . ' ' . number_format($cacheRead);
            $html .= ', ' . $this->l('cache create') . ' ' . number_format($cacheCreate);
        }

        if ($convCost !== null) {
            $totalUsd = isset($convCost['total_usd']) ? (float) $convCost['total_usd'] : 0.0;
            $totalPln = isset($convCost['total_pln']) ? (float) $convCost['total_pln'] : 0.0;
            $html .= '<br><strong>' . $this->l('Sumaryczny koszt rozmowy') . ': $' . number_format($totalUsd, 4, '.', '') . ' USD';
            if ($totalPln > 0) {
                $html .= ' / ' . number_format($totalPln, 2, ',', ' ') . ' zl';
            }
            $html .= '</strong>';
        }

        $html .= '</div>';
        return $html;
    }

    private function renderConvStatusForm($sessionId, $currentStatus, $currentNotes)
    {
        // POST form — action zachowuje query string (POST nie strippuje action params jak GET).
        $action = $this->context->link->getAdminLink('AdminDivezoneChat')
            . '&tab=' . self::TAB_CONVERSATIONS
            . '&session_id=' . rawurlencode($sessionId);

        // CHAT-T-052 (poprawka 5): 2 KOLUMNY obok siebie.
        //  LEWA: Status (select) + przycisk "Zapisz status" pod selectem.
        //  PRAWA: Notatki (textarea, rows=2 — nizej, dzieki przyciskowi w lewej kolumnie).
        $html  = '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '" style="margin:14px 0;padding:14px;background:#f7f9fa;border:1px solid #e2e6e8;border-radius:4px;">';
        $html .= '<input type="hidden" name="session_id" value="' . htmlspecialchars($sessionId, ENT_QUOTES) . '">';

        $html .= '<div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start;">';

        // LEWA kolumna: Status + przycisk
        $html .= '<div>';
        $html .= '<label style="font-weight:600;display:block;margin-bottom:4px;font-size:13px;">' . $this->l('Status') . '</label>';
        $html .= '<select name="status" style="width:100%;padding:7px 10px;border:1px solid #ccc;border-radius:3px;background:#fff;font-size:13px;">';
        foreach ($this->convStatusOptions() as $k => $label) {
            $sel = $k === $currentStatus ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($k, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($label, ENT_QUOTES) . '</option>';
        }
        $html .= '</select>';
        $html .= '<div style="margin-top:10px;"><button type="submit" name="submitDivezoneChatConvStatus" class="btn btn-primary" style="padding:9px 22px;background:#1a5e5a;color:#fff;border:0;border-radius:4px;font-weight:600;cursor:pointer;font-size:13px;">' . $this->l('Zapisz status') . '</button></div>';
        $html .= '</div>';

        // PRAWA kolumna: Notatki (nizsze pole, rows=2)
        $html .= '<div>';
        $html .= '<label style="font-weight:600;display:block;margin-bottom:4px;font-size:13px;">' . $this->l('Notatki') . '</label>';
        $html .= '<textarea name="notes" rows="2" style="width:100%;box-sizing:border-box;padding:7px 10px;border:1px solid #ccc;border-radius:3px;background:#fff;font-size:13px;">';
        $html .= htmlspecialchars($currentNotes, ENT_QUOTES);
        $html .= '</textarea>';
        $html .= '</div>';

        $html .= '</div></form>';
        return $html;
    }

    private function renderConvMessages($messages)
    {
        if (empty($messages)) {
            return '<p><em>' . $this->l('Brak wiadomosci w tej rozmowie.') . '</em></p>';
        }

        $html = '<div class="dz-conv-thread">';
        foreach ($messages as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $role    = isset($msg['role']) ? (string) $msg['role'] : '';
            $content = isset($msg['content']) ? (string) $msg['content'] : '';

            if ($role === 'user') {
                $html .= '<div class="dz-conv-bubble dz-conv-bubble--user">';
                $html .= '<div class="role">' . $this->l('Klient') . '</div>';
                $html .= '<div>' . $this->formatConvBubbleText($content) . '</div>';
                $html .= '</div>';
            } elseif ($role === 'assistant' && $content !== '') {
                $html .= '<div class="dz-conv-bubble dz-conv-bubble--ai">';
                $html .= '<div class="role">' . $this->l('AI') . '</div>';
                $html .= '<div>' . $this->formatConvBubbleText($content) . '</div>';
                $html .= '</div>';
            }
            // tool_result -> POMIJAMY (wzorzec history.js linie 195-196).
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * CHAT-T-051 (Problem B): bezpieczne formatowanie tresci bubla.
     * KOLEJNOSC KRYTYCZNA dla bezpieczenstwa:
     *  1. htmlspecialchars NAJPIERW — zero surowego HTML z tresci rozmowy.
     *  2. **bold** -> <strong> (regex na sparowanych ** — htmlspecialchars
     *     nie zmienia gwiazdek, wiec dziala na zescapowanym tekscie).
     *  3. URL http(s) -> <a target=_blank rel="noopener noreferrer nofollow">.
     *     Po escape & w URL jest juz &amp; — budujac href uzywamy tej formy
     *     bez odwracania escape (odwracanie = wektor XSS).
     *  4. nl2br NA KONCU — po wszystkich podstawieniach.
     * Zakres minimalny: bold + linki. Bez markdown-parsera, obrazkow, list.
     */
    private function formatConvBubbleText($raw)
    {
        $escaped = htmlspecialchars((string) $raw, ENT_QUOTES, 'UTF-8');

        // 2. **bold** — non-greedy, bez gwiazdek wewnatrz.
        $escaped = preg_replace('/\*\*([^*]+?)\*\*/u', '<strong>$1</strong>', $escaped);

        // 3. URL — konserwatywnie: trim konczacych znakow interpunkcyjnych ktore
        //    zwykle nie sa czesc URL.
        $escaped = preg_replace_callback(
            '#\b(https?://[^\s<>"\']+)#i',
            function ($m) {
                $url = rtrim($m[1], '.,;:!?)]}>');
                return '<a href="' . $url . '" target="_blank" rel="noopener noreferrer nofollow">' . $url . '</a>';
            },
            $escaped
        );

        // 4. nl2br — na koncu, po linkach (gdyby URL byl wewnatrz nowej linii).
        return nl2br($escaped);
    }

    private function renderConvDiagnostics($resp)
    {
        $diags = isset($resp['search_diagnostics']) && is_array($resp['search_diagnostics']) ? $resp['search_diagnostics'] : array();
        $times = isset($resp['response_times']) && is_array($resp['response_times']) ? $resp['response_times'] : array();
        $tools = isset($resp['tools_used']) && is_array($resp['tools_used']) ? $resp['tools_used'] : array();

        if (empty($diags) && empty($times) && empty($tools)) {
            return '';
        }

        $html  = '<details style="margin-top:18px;padding:10px;background:#f7f9fa;border:1px solid #e2e6e8;border-radius:4px;font-size:12px;">';
        $html .= '<summary style="cursor:pointer;font-weight:600;">' . $this->l('Diagnostyka (tools / response_times / search_diagnostics)') . '</summary>';

        if (!empty($tools)) {
            $toolsFlat = array();
            foreach ($tools as $t) {
                $toolsFlat[] = is_scalar($t) ? (string) $t : json_encode($t, JSON_UNESCAPED_UNICODE);
            }
            $html .= '<p style="margin:8px 0 4px;"><strong>tools_used:</strong> ' . htmlspecialchars(implode(', ', $toolsFlat), ENT_QUOTES) . '</p>';
        }
        if (!empty($times)) {
            $html .= '<p style="margin:8px 0 4px;"><strong>response_times:</strong> <code>' . htmlspecialchars(json_encode($times, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '</code></p>';
        }
        if (!empty($diags)) {
            $html .= '<p style="margin:8px 0 4px;"><strong>search_diagnostics:</strong></p>';
            $html .= '<pre style="white-space:pre-wrap;max-height:300px;overflow:auto;background:#fff;padding:8px;border:1px solid #ddd;margin:0;">';
            $html .= htmlspecialchars(json_encode($diags, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), ENT_QUOTES);
            $html .= '</pre>';
        }

        $html .= '</details>';
        return $html;
    }

    /**
     * Handler zapisu statusu rozmowy: POST /api/conversations/{sid}/status z body
     * {status, notes}. Wynik (sukces/blad) ustawia $this->convFlash + Type dla
     * wyswietlenia w renderConversationDetail (oraz fallback w renderConversationsList).
     *
     * Whitelist 4 statusow lokalnie (decyzja 105a) — odrzucamy zanim poleci POST.
     */
    private function handleConvStatusSave($employeeId)
    {
        $sessionId = trim((string) Tools::getValue('session_id', ''));
        $status    = trim((string) Tools::getValue('status', ''));
        $notesRaw  = Tools::getValue('notes', null);
        $notes     = null;
        if (is_string($notesRaw)) {
            $notesTrim = trim($notesRaw);
            if ($notesTrim !== '') {
                $notes = $notesTrim;
            }
        }

        if ($sessionId === '') {
            $this->convFlash     = $this->l('Brak session_id — zmiana statusu nie wykonana.');
            $this->convFlashType = 'error';
            return;
        }

        if (!array_key_exists($status, $this->convStatusOptions())) {
            $this->convFlash     = $this->l('Nieprawidlowy status (dozwolone: new, reviewed, knowledge_created, ignored).');
            $this->convFlashType = 'error';
            return;
        }

        $body     = json_encode(array('status' => $status, 'notes' => $notes));
        $endpoint = self::ENDPOINT_CONVERSATIONS . '/' . rawurlencode($sessionId) . '/status';
        $resp     = $this->callBackend($endpoint, $employeeId, 'POST', $body);

        if (isset($resp['error'])) {
            $httpStatus = isset($resp['http_status']) ? (int) $resp['http_status'] : 0;
            if ($httpStatus === 401) {
                $this->convFlash = $this->l('Brak/nieprawidlowy token kanalu serwerowego. Sprawdz konfiguracje modulu (Sekret SERWEROWY).');
            } elseif ($httpStatus === 403) {
                $this->convFlash = $this->l('Brak roli (no_role): konto nie ma roli w divechat_admin_roles. Operator/admin moze zmieniac status rozmow.');
            } else {
                $this->convFlash = $this->l('Blad zapisu statusu:') . ' ' . (string) $resp['error'];
            }
            $this->convFlashType = 'error';
            return;
        }

        if (isset($resp['success']) && $resp['success']) {
            $this->convFlash     = $this->l('Status rozmowy zapisany.');
            $this->convFlashType = 'success';
        } else {
            $this->convFlash     = $this->l('Zapisano, ale backend nie potwierdzil success.');
            $this->convFlashType = 'success';
        }
    }

    /**
     * Mapowanie statusow (decyzja 105a): wartosci EN = klucz wysylany do backendu,
     * etykiety PL = wyswietlane operatorowi. NIE zmieniac wartosci EN — backend
     * waliduje whitelist ['new','reviewed','knowledge_created','ignored'].
     */
    private function convStatusOptions()
    {
        return array(
            'new'               => $this->l('nowa'),
            'reviewed'          => $this->l('przejrzana'),
            'knowledge_created' => $this->l('wiedza utworzona'),
            'ignored'           => $this->l('zignorowana'),
        );
    }

    private function renderStatusBadge($status)
    {
        $opts  = $this->convStatusOptions();
        $label = isset($opts[$status]) ? $opts[$status] : $status;
        // Klucz CSS = oczyszczony status (only a-z + _).
        $cssKey = preg_replace('/[^a-z_]/', '', strtolower((string) $status));
        $class  = 'dz-status-badge dz-status-' . ($cssKey !== '' ? $cssKey : 'new');
        return '<span class="' . $class . '">' . htmlspecialchars($label, ENT_QUOTES) . '</span>';
    }

    private function formatConvDate($iso)
    {
        if ($iso === '' || $iso === null) {
            return '—';
        }
        $t = @strtotime((string) $iso);
        if ($t === false || $t <= 0) {
            return (string) $iso;
        }
        return date('Y-m-d H:i', $t);
    }

    // ============================================================================
    // SEKCJA: Analityka (CHAT-T-050, ADR-074).
    //
    // Architektura 120a: PHP wola backend kanalem serwerowym (admin-only, CHAT-T-049),
    // osadza dane (KPI/by-model/top server-side; trend jako JSON dla Chart.js). JS
    // tylko rysuje wykres — ZERO fetch z przegladarki (sekret HMAC zostaje na serwerze).
    //
    // 403 — komunikat "tylko dla administratorow", nie bialy ekran.
    // CSP/CDN: Chart.js z jsdelivr; jesli CSP/siec blokuje -> wykres degraduje
    // gracefully (typeof Chart guard), reszta sekcji dziala.
    // ============================================================================

    private function renderAnalyticsSection($employeeId)
    {
        // Whitelist filtrow.
        $days   = (int) Tools::getValue('days', 30);
        if (!in_array($days, array(7, 30, 90), true)) {
            $days = 30;
        }
        $period = (string) Tools::getValue('period', 'daily');
        if (!in_array($period, array('daily', 'weekly', 'monthly'), true)) {
            $period = 'daily';
        }

        // 4 wywolania endpointow.
        $kpi     = $this->callBackend(self::ENDPOINT_COST_KPI, $employeeId);
        $trend   = $this->callBackend(self::ENDPOINT_COST_TREND . '?period=' . rawurlencode($period) . '&days=' . $days, $employeeId);
        $byModel = $this->callBackend(self::ENDPOINT_COST_BY_MODEL . '?days=' . $days, $employeeId);
        $top     = $this->callBackend(self::ENDPOINT_CONVERSATIONS_TOP . '?limit=10&days=' . $days, $employeeId);

        $html  = '<div class="panel" style="border-top-left-radius:0;">';
        $html .= '<div class="panel-heading"><i class="icon-bar-chart"></i> ' . $this->l('Analityka — koszty i wykorzystanie') . '</div>';
        $html .= '<div style="padding:18px;">';

        // 403 z dowolnego endpointu -> komunikat, nie bialy ekran.
        if ($this->anyResponseIs403(array($kpi, $trend, $byModel, $top))) {
            $html .= '<div class="dz-analytics-forbidden"><strong>' . $this->l('Brak uprawnien.') . '</strong> ';
            $html .= $this->l('Analityka dostepna tylko dla administratorow. Twoje konto ma role operatora lub nie ma roli w divechat_admin_roles.');
            $html .= '</div>';
            $html .= '</div></div>';
            return $html;
        }

        // Filtry (sekcja a)
        $html .= $this->renderAnalyticsFilters($days, $period);

        // KPI (sekcja b)
        $html .= '<section class="dz-analytics-section">';
        $html .= $this->renderAnalyticsKpiGrid($kpi);
        $html .= '</section>';

        // Wykres trendu (sekcja c)
        $html .= '<section class="dz-analytics-section">';
        $html .= '<h3>' . $this->l('Trend wydatkow') . ' (' . htmlspecialchars($period, ENT_QUOTES) . ', ' . $days . ' ' . $this->l('dni') . ')</h3>';
        $html .= $this->renderAnalyticsTrendChart($trend);
        $html .= '</section>';

        // By-model (sekcja d)
        $html .= '<section class="dz-analytics-section">';
        $html .= '<h3>' . $this->l('Per model AI') . ' (' . $days . ' ' . $this->l('dni') . ')</h3>';
        $html .= $this->renderAnalyticsByModelTable($byModel);
        $html .= '</section>';

        // Top rozmow (sekcja e, 109a)
        $html .= '<section class="dz-analytics-section">';
        $html .= '<h3>' . $this->l('TOP 10 najdrozszych rozmow') . ' (' . $days . ' ' . $this->l('dni') . ')</h3>';
        $html .= $this->renderAnalyticsTopConversations($top);
        $html .= '</section>';

        $html .= '</div></div>';
        return $html;
    }

    private function anyResponseIs403($responses)
    {
        foreach ($responses as $r) {
            if (!is_array($r)) {
                continue;
            }
            if (isset($r['error']) && isset($r['http_status']) && (int) $r['http_status'] === 403) {
                return true;
            }
        }
        return false;
    }

    private function renderAnalyticsFilters($days, $period)
    {
        $token = Tools::getAdminTokenLite('AdminDivezoneChat');

        $html  = '<form method="get" class="dz-analytics-filters" action="">';
        $html .= '<input type="hidden" name="controller" value="AdminDivezoneChat">';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
        $html .= '<input type="hidden" name="tab" value="' . self::TAB_ANALYTICS . '">';

        $html .= '<div><label for="dz-ana-days">' . $this->l('Okres (dni)') . '</label>';
        $html .= '<select id="dz-ana-days" name="days">';
        foreach (array(7, 30, 90) as $d) {
            $sel = $d === $days ? ' selected' : '';
            $html .= '<option value="' . $d . '"' . $sel . '>' . $d . '</option>';
        }
        $html .= '</select></div>';

        $periodLabels = array(
            'daily'   => $this->l('dziennie'),
            'weekly'  => $this->l('tygodniowo'),
            'monthly' => $this->l('miesiecznie'),
        );
        $html .= '<div><label for="dz-ana-period">' . $this->l('Granularnosc') . '</label>';
        $html .= '<select id="dz-ana-period" name="period">';
        foreach ($periodLabels as $k => $lbl) {
            $sel = $k === $period ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($k, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars($lbl, ENT_QUOTES) . '</option>';
        }
        $html .= '</select></div>';

        $html .= '<div><button type="submit" class="btn btn-primary">' . $this->l('Pokaz') . '</button></div>';
        $html .= '</form>';
        return $html;
    }

    private function renderAnalyticsKpiGrid($kpi)
    {
        if (isset($kpi['error'])) {
            return '<div class="dz-analytics-empty">' . $this->l('Brak danych KPI:') . ' ' . htmlspecialchars((string) $kpi['error'], ENT_QUOTES) . '</div>';
        }

        $today      = isset($kpi['today']) && is_array($kpi['today']) ? $kpi['today'] : array();
        $thisWeek   = isset($kpi['this_week']) && is_array($kpi['this_week']) ? $kpi['this_week'] : array();
        $thisMonth  = isset($kpi['this_month']) && is_array($kpi['this_month']) ? $kpi['this_month'] : array();
        $cpr        = isset($kpi['cost_per_resolution']) && is_array($kpi['cost_per_resolution']) ? $kpi['cost_per_resolution'] : array();

        $html  = '<div class="dz-analytics-kpi-grid">';
        $html .= $this->renderAnalyticsKpiCard($this->l('Dzis'),     $today);
        $html .= $this->renderAnalyticsKpiCard($this->l('Tydzien'),  $thisWeek);
        $html .= $this->renderAnalyticsKpiCard($this->l('Miesiac'),  $thisMonth);
        $html .= $this->renderAnalyticsKpiResolutionCard($cpr);
        $html .= '</div>';
        return $html;
    }

    private function renderAnalyticsKpiCard($title, $data)
    {
        $costPln = isset($data['cost_pln']) ? (float) $data['cost_pln'] : 0.0;
        $costUsd = isset($data['cost_usd']) ? (float) $data['cost_usd'] : 0.0;
        $conv    = isset($data['conversations']) ? (int) $data['conversations'] : 0;
        $msg     = isset($data['messages']) ? (int) $data['messages'] : 0;

        $html  = '<div class="dz-analytics-kpi-card">';
        $html .= '<div class="dz-kpi-title">' . htmlspecialchars($title, ENT_QUOTES) . '</div>';
        $html .= '<div class="dz-kpi-main">' . number_format($costPln, 2, ',', ' ') . ' zl</div>';
        $html .= '<div class="dz-kpi-sub">$' . number_format($costUsd, 4, '.', '') . ' USD</div>';
        $html .= '<div class="dz-kpi-meta">' . $conv . ' ' . $this->l('rozm.') . ' &middot; ' . $msg . ' ' . $this->l('wiad.') . '</div>';
        $html .= '</div>';
        return $html;
    }

    private function renderAnalyticsKpiResolutionCard($cpr)
    {
        $thisMonthUsd = isset($cpr['this_month_usd']) ? (float) $cpr['this_month_usd'] : 0.0;
        $thisMonthPln = isset($cpr['this_month_pln']) ? (float) $cpr['this_month_pln'] : 0.0;
        $benchmark    = isset($cpr['industry_benchmark_usd']) ? (float) $cpr['industry_benchmark_usd'] : 0.0;
        $vsHuman      = isset($cpr['vs_human_agent_usd']) ? (float) $cpr['vs_human_agent_usd'] : 0.0;

        $html  = '<div class="dz-analytics-kpi-card dz-analytics-kpi-card--resolution">';
        $html .= '<div class="dz-kpi-title">' . $this->l('Koszt na rozmowe (miesiac)') . '</div>';
        $html .= '<div class="dz-kpi-main">' . number_format($thisMonthPln, 4, ',', ' ') . ' zl</div>';
        $html .= '<div class="dz-kpi-sub">$' . number_format($thisMonthUsd, 4, '.', '') . ' USD</div>';
        $meta = array();
        if ($benchmark > 0) {
            $meta[] = $this->l('benchmark branzy') . ': $' . number_format($benchmark, 4, '.', '');
        }
        if ($vsHuman > 0) {
            $meta[] = $this->l('vs. agent ludzki') . ': $' . number_format($vsHuman, 2, '.', '');
        }
        if (!empty($meta)) {
            $html .= '<div class="dz-kpi-meta">' . htmlspecialchars(implode(' · ', $meta), ENT_QUOTES) . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function renderAnalyticsTrendChart($trend)
    {
        if (isset($trend['error'])) {
            return '<div class="dz-analytics-empty">' . $this->l('Brak danych trendu:') . ' ' . htmlspecialchars((string) $trend['error'], ENT_QUOTES) . '</div>';
        }

        $data = isset($trend['data']) && is_array($trend['data']) ? $trend['data'] : array();

        // Pusta tablica -> komunikat zamiast wykresu.
        if (empty($data)) {
            return '<div class="dz-analytics-empty">' . $this->l('Brak danych w wybranym okresie.') . '</div>';
        }

        // Sanityzowane minimalne pola dla JS — tylko to czego uzywa wykres.
        $points = array();
        foreach ($data as $row) {
            if (!is_array($row)) {
                continue;
            }
            $points[] = array(
                'date'          => isset($row['date']) ? (string) $row['date'] : '',
                'cost_pln'      => isset($row['cost_pln']) ? (float) $row['cost_pln'] : 0.0,
                'cost_usd'      => isset($row['cost_usd']) ? (float) $row['cost_usd'] : 0.0,
                'conversations' => isset($row['conversations']) ? (int) $row['conversations'] : 0,
            );
        }

        $jsonStr = json_encode($points, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonStr === false) {
            $jsonStr = '[]';
        }

        // Osadzony JSON w <script type="application/json"> — bezpieczne (nie wykonywane),
        // wystarczy zamknac </script tag wewnatrz: zastapic by json wiarygodnie escape'owac.
        // JSON_HEX_TAG + tweak: zamiana < zeby uniknac wczesnego </script>.
        $jsonSafe = str_replace('<', '\\u003c', $jsonStr);

        $html  = '<script type="application/json" id="dz-trend-data">' . $jsonSafe . '</script>';
        $html .= '<div class="dz-analytics-chart-wrap"><canvas id="dz-trend-chart"></canvas></div>';
        $html .= '<div id="dz-trend-empty" class="dz-analytics-empty" style="display:none;margin-top:10px;"></div>';

        // Chart.js z CDN — jesli CSP/siec blokuje, guard typeof Chart pokaze fallback.
        $html .= '<script src="' . self::CHARTJS_CDN . '"></script>';
        $html .= '<script>(function(){'
            . 'function init(){'
            . 'var dataEl=document.getElementById("dz-trend-data");'
            . 'var canvas=document.getElementById("dz-trend-chart");'
            . 'var emptyEl=document.getElementById("dz-trend-empty");'
            . 'if(!dataEl||!canvas)return;'
            . 'var data;try{data=JSON.parse(dataEl.textContent||"[]");}catch(e){return;}'
            . 'if(typeof Chart==="undefined"){'
            . 'if(emptyEl){emptyEl.style.display="block";emptyEl.textContent="' . $this->jsEscape($this->l('Chart.js niedostepny (CSP/siec) — wykres pominiety.')) . '";}'
            . 'return;'
            . '}'
            . 'if(!data.length){if(emptyEl){emptyEl.style.display="block";emptyEl.textContent="' . $this->jsEscape($this->l('Brak danych w okresie.')) . '";}return;}'
            . 'var labels=data.map(function(d){return d.date;});'
            . 'var pln=data.map(function(d){return d.cost_pln;});'
            . 'var usd=data.map(function(d){return d.cost_usd;});'
            . 'var conv=data.map(function(d){return d.conversations;});'
            . 'new Chart(canvas.getContext("2d"),{'
            . 'type:"line",'
            . 'data:{labels:labels,datasets:[{label:"' . $this->jsEscape($this->l('Koszt (PLN)')) . '",data:pln,borderColor:"#0066cc",backgroundColor:"rgba(0,102,204,0.08)",borderWidth:2,tension:0.3,fill:true,pointRadius:3,pointHoverRadius:5}]},'
            . 'options:{responsive:true,maintainAspectRatio:false,'
            . 'plugins:{legend:{display:false},tooltip:{callbacks:{label:function(ctx){var i=ctx.dataIndex;return [pln[i].toFixed(2)+" PLN",usd[i].toFixed(4)+" USD",conv[i]+" ' . $this->jsEscape($this->l('rozm.')) . '"];}}}},'
            . 'scales:{y:{beginAtZero:true,ticks:{callback:function(v){return v.toFixed(2)+" PLN";}}},x:{ticks:{maxRotation:45,minRotation:0}}}'
            . '}'
            . '});'
            . '}'
            . 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",init);}'
            . 'else{init();}'
            . '})();</script>';

        return $html;
    }

    private function renderAnalyticsByModelTable($byModel)
    {
        if (isset($byModel['error'])) {
            return '<div class="dz-analytics-empty">' . $this->l('Brak danych per model:') . ' ' . htmlspecialchars((string) $byModel['error'], ENT_QUOTES) . '</div>';
        }

        $models = isset($byModel['models']) && is_array($byModel['models']) ? $byModel['models'] : array();
        if (empty($models)) {
            return '<div class="dz-analytics-empty">' . $this->l('Brak danych.') . '</div>';
        }

        $html  = '<table class="dz-analytics-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . $this->l('Model') . '</th>';
        $html .= '<th>' . $this->l('Provider') . '</th>';
        $html .= '<th class="num">' . $this->l('Uzycia') . '</th>';
        $html .= '<th class="num">' . $this->l('Tokeny in') . '</th>';
        $html .= '<th class="num">' . $this->l('Tokeny out') . '</th>';
        $html .= '<th class="num">' . $this->l('Cache read') . '</th>';
        $html .= '<th class="num">' . $this->l('Koszt PLN') . '</th>';
        $html .= '<th class="num">' . $this->l('Koszt USD') . '</th>';
        $html .= '<th class="num">' . $this->l('Sr. koszt/uzycie') . '</th>';
        $html .= '<th class="num">' . $this->l('Sr. latencja [ms]') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($models as $m) {
            if (!is_array($m)) {
                continue;
            }
            $label    = isset($m['label']) ? (string) $m['label'] : (isset($m['model_id']) ? (string) $m['model_id'] : '?');
            $provider = isset($m['provider']) ? (string) $m['provider'] : '';
            $uses     = isset($m['uses']) ? (int) $m['uses'] : 0;
            $tokIn    = isset($m['input_tokens']) ? (int) $m['input_tokens'] : 0;
            $tokOut   = isset($m['output_tokens']) ? (int) $m['output_tokens'] : 0;
            $cache    = isset($m['cache_read_tokens']) ? (int) $m['cache_read_tokens'] : 0;
            $costPln  = isset($m['cost_pln']) ? (float) $m['cost_pln'] : 0.0;
            $costUsd  = isset($m['cost_usd']) ? (float) $m['cost_usd'] : 0.0;
            $avgCost  = isset($m['avg_cost_per_use_usd']) ? (float) $m['avg_cost_per_use_usd'] : 0.0;
            $avgLat   = isset($m['avg_latency_ms']) ? (float) $m['avg_latency_ms'] : 0.0;

            $html .= '<tr>';
            $html .= '<td><code style="font-size:11px;">' . htmlspecialchars($label, ENT_QUOTES) . '</code></td>';
            $html .= '<td>' . htmlspecialchars($provider, ENT_QUOTES) . '</td>';
            $html .= '<td class="num">' . number_format($uses) . '</td>';
            $html .= '<td class="num">' . number_format($tokIn) . '</td>';
            $html .= '<td class="num">' . number_format($tokOut) . '</td>';
            $html .= '<td class="num">' . number_format($cache) . '</td>';
            $html .= '<td class="num">' . number_format($costPln, 4, ',', ' ') . '</td>';
            $html .= '<td class="num">$' . number_format($costUsd, 4, '.', '') . '</td>';
            $html .= '<td class="num">$' . number_format($avgCost, 6, '.', '') . '</td>';
            $html .= '<td class="num">' . number_format($avgLat, 0) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function renderAnalyticsTopConversations($top)
    {
        if (isset($top['error'])) {
            return '<div class="dz-analytics-empty">' . $this->l('Brak danych top rozmow:') . ' ' . htmlspecialchars((string) $top['error'], ENT_QUOTES) . '</div>';
        }

        $convs = isset($top['conversations']) && is_array($top['conversations']) ? $top['conversations'] : array();
        if (empty($convs)) {
            return '<div class="dz-analytics-empty">' . $this->l('Brak rozmow w okresie.') . '</div>';
        }

        $html  = '<table class="dz-analytics-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . $this->l('Rozpoczeto') . '</th>';
        $html .= '<th>' . $this->l('Model') . '</th>';
        $html .= '<th class="num">' . $this->l('Wiad.') . '</th>';
        $html .= '<th class="num">' . $this->l('Koszt PLN') . '</th>';
        $html .= '<th class="num">' . $this->l('Koszt USD') . '</th>';
        $html .= '<th>' . $this->l('Pierwsza wiadomosc') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($convs as $c) {
            if (!is_array($c)) {
                continue;
            }
            $sid       = isset($c['session_id']) ? (string) $c['session_id'] : '';
            $startedAt = isset($c['started_at']) ? (string) $c['started_at'] : '';
            $model     = isset($c['model_used']) ? (string) $c['model_used'] : '';
            $msgCount  = isset($c['messages_count']) ? (int) $c['messages_count'] : 0;
            $costUsd   = isset($c['cost_usd']) ? (float) $c['cost_usd'] : 0.0;
            $costPln   = isset($c['cost_pln']) ? (float) $c['cost_pln'] : 0.0;
            $firstMsg  = isset($c['first_user_message']) ? $c['first_user_message'] : null;

            // 109a: wiersz LINKUJE do zakladki Rozmowy po session_id (NIE osobny widok).
            $url = $this->context->link->getAdminLink('AdminDivezoneChat')
                . '&tab=' . self::TAB_CONVERSATIONS
                . '&session_id=' . rawurlencode($sid);

            $msgPreview = $this->truncateFirstMessage($firstMsg);

            $html .= '<tr class="dz-analytics-top-row">';
            $html .= '<td><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($this->formatConvDate($startedAt), ENT_QUOTES) . '</a></td>';
            $html .= '<td><a href="' . htmlspecialchars($url, ENT_QUOTES) . '"><code style="font-size:11px;">' . htmlspecialchars($model, ENT_QUOTES) . '</code></a></td>';
            $html .= '<td class="num"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $msgCount . '</a></td>';
            $html .= '<td class="num"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . number_format($costPln, 4, ',', ' ') . '</a></td>';
            $html .= '<td class="num"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">$' . number_format($costUsd, 4, '.', '') . '</a></td>';
            $html .= '<td><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars($msgPreview, ENT_QUOTES) . '</a></td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';
        return $html;
    }

    /**
     * Bezpieczny escape stringu PHP -> wewnatrz "..." w JS (po htmlspecialchars HTML w outer
     * markupie). Zamienia \\, ", newline, < (dla zamkniecia </script>) i ' tag.
     */
    private function jsEscape($str)
    {
        $str = (string) $str;
        $str = str_replace(
            array('\\', '"', "\r", "\n", '<', "'"),
            array('\\\\', '\\"', '', '\\n', '\\u003c', "\\'"),
            $str
        );
        return $str;
    }

    // ============================================================================
    // SEKCJA: Editorial Picks (CHAT-T-055, ADR-076, decyzje 127b/128a/131a/132a).
    //
    // Architektura 132a: pelny server-side CRUD. Wyszukiwarka produktow przez
    // reload zakladki z ?q=... (zero JS, zero proxy, zero live-fetch). Akcje
    // (add/update/delete) jako POST formularze z hidden controller/token/tab.
    // Aliasy POST (128a): update na POST /editorial-picks/{id}, delete na
    // POST /editorial-picks/{id}/delete (callBackend wysyla body tylko dla POST).
    // ANY-ROLE (127b): operator i admin maja dostep (NIE admin-only).
    // ============================================================================

    private function renderEditorialSection($employeeId)
    {
        // Filtry / parametry GET.
        $active = (string) Tools::getValue('active', '');
        if ($active !== '' && $active !== '0' && $active !== '1') {
            $active = '';
        }

        $orderBy        = (string) Tools::getValue('order_by', 'added_at');
        $allowedOrderBy = array('added_at', 'expires_at', 'boost_factor', 'product_name', 'last_review_at');
        if (!in_array($orderBy, $allowedOrderBy, true)) {
            $orderBy = 'added_at';
        }

        $q                   = trim((string) Tools::getValue('q', ''));
        $selectedProductId   = (int) Tools::getValue('ep_product_id', 0);
        $selectedProductName = trim((string) Tools::getValue('ep_product_name', ''));

        // Build list query.
        $listParams = array('order_by' => $orderBy);
        if ($active !== '') {
            $listParams['active'] = $active;
        }

        $list    = $this->callBackend(self::ENDPOINT_EDITORIAL . '?' . http_build_query($listParams), $employeeId);
        $pending = $this->callBackend(self::ENDPOINT_EDITORIAL_PENDING, $employeeId);
        $search  = null;
        if ($q !== '') {
            $search = $this->callBackend(self::ENDPOINT_PRODUCTS_SEARCH . '?q=' . rawurlencode($q), $employeeId);
        }

        $html  = '<div class="panel" style="border-top-left-radius:0;">';
        $html .= '<div class="panel-heading"><i class="icon-star"></i> ' . $this->l('Editorial Picks') . '</div>';
        $html .= '<div style="padding:18px;">';

        // 403 guard (any-role: no_role / blad konfiguracji).
        $checked = array($list, $pending);
        if ($search !== null) {
            $checked[] = $search;
        }
        if ($this->anyResponseIs403($checked)) {
            $html .= '<div class="dz-analytics-forbidden"><strong>' . $this->l('Brak uprawnien.') . '</strong> ';
            $html .= $this->l('Twoje konto nie ma roli w divechat_admin_roles. Editorial Picks wymaga roli operatora lub admina.');
            $html .= '</div></div></div>';
            return $html;
        }

        // Flash po POST.
        if ($this->epFlash !== '') {
            $html .= '<div class="dz-flash ' . htmlspecialchars($this->epFlashType, ENT_QUOTES) . '">'
                  . htmlspecialchars($this->epFlash, ENT_QUOTES) . '</div>';
        }

        // a) Pasek pending reviews.
        $html .= $this->renderEpPending($pending);

        // b) Sekcja dodawania: wyszukiwarka + wyniki + formularz add.
        $html .= '<section class="dz-analytics-section">';
        $html .= '<h3>' . $this->l('Dodaj nowy pick') . '</h3>';
        $html .= $this->renderEpSearchBar($q);
        if ($search !== null) {
            $html .= $this->renderEpSearchResults($search, $q);
        }
        $html .= $this->renderEpAddForm($selectedProductId, $selectedProductName, $q);
        $html .= '</section>';

        // c) Filtry + d) tabela z akcjami inline.
        $html .= '<section class="dz-analytics-section">';
        $html .= '<h3>' . $this->l('Lista pickow') . '</h3>';
        $html .= $this->renderEpFilters($active, $orderBy);
        $html .= $this->renderEpTable($list);
        $html .= '</section>';

        $html .= '</div></div>';
        return $html;
    }

    private function renderEpPending($pending)
    {
        if (!is_array($pending) || isset($pending['error'])) {
            return '';
        }
        $expired   = isset($pending['expired_this_week']) ? (int) $pending['expired_this_week'] : 0;
        $longUnrev = isset($pending['long_unreviewed']) ? (int) $pending['long_unreviewed'] : 0;
        $total     = isset($pending['total']) ? (int) $pending['total'] : 0;

        if ($total === 0) {
            return '<div class="dz-ep-pending dz-ep-pending--empty">' . $this->l('Brak pickow do przegladu.') . '</div>';
        }

        $html  = '<div class="dz-ep-pending">';
        $html .= '<strong>' . $this->l('Do przegladu') . ':</strong> ';
        $html .= $this->l('wygaslo w tym tygodniu') . ': <strong>' . $expired . '</strong>';
        $html .= ' &middot; ' . $this->l('dlugo nieweryfikowane') . ': <strong>' . $longUnrev . '</strong>';
        $html .= ' &middot; ' . $this->l('razem') . ': <strong>' . $total . '</strong>';
        $html .= '</div>';
        return $html;
    }

    private function renderEpSearchBar($q)
    {
        $token = Tools::getAdminTokenLite('AdminDivezoneChat');

        $html  = '<form method="get" class="dz-ep-search" action="">';
        $html .= '<input type="hidden" name="controller" value="AdminDivezoneChat">';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
        $html .= '<input type="hidden" name="tab" value="' . self::TAB_EDITORIAL . '">';
        $html .= '<input type="text" name="q" value="' . htmlspecialchars($q, ENT_QUOTES) . '" placeholder="' . $this->l('Szukaj produktu (nazwa / ID / referencja, min. 2 znaki)') . '" size="40">';
        $html .= '<button type="submit" class="btn btn-default">' . $this->l('Szukaj produkt') . '</button>';
        $html .= '</form>';
        return $html;
    }

    private function renderEpSearchResults($search, $q)
    {
        if (isset($search['error'])) {
            return '<div class="dz-ep-results dz-ep-results--empty">' . $this->l('Blad wyszukiwarki:') . ' ' . htmlspecialchars((string) $search['error'], ENT_QUOTES) . '</div>';
        }

        $products = isset($search['products']) && is_array($search['products']) ? $search['products'] : array();
        if (empty($products)) {
            $msg = isset($search['message']) && $search['message'] !== '' ? (string) $search['message'] : $this->l('Brak wynikow.');
            return '<div class="dz-ep-results dz-ep-results--empty">' . htmlspecialchars($msg, ENT_QUOTES) . '</div>';
        }

        $html  = '<div class="dz-ep-results"><strong>' . $this->l('Wyniki') . ':</strong>';
        $html .= '<ul>';
        foreach ($products as $p) {
            if (!is_array($p)) {
                continue;
            }
            $id      = isset($p['id']) ? (int) $p['id'] : 0;
            $name    = isset($p['name']) ? (string) $p['name'] : '';
            $price   = isset($p['price']) ? (float) $p['price'] : 0.0;
            $inStock = !empty($p['in_stock']);

            if ($id <= 0 || $name === '') {
                continue;
            }

            $url = $this->context->link->getAdminLink('AdminDivezoneChat')
                . '&tab=' . self::TAB_EDITORIAL
                . '&ep_product_id=' . $id
                . '&ep_product_name=' . rawurlencode($name);
            if ($q !== '') {
                $url .= '&q=' . rawurlencode($q);
            }

            $html .= '<li><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" class="dz-ep-result-item">';
            $html .= '<span class="name">' . htmlspecialchars($name, ENT_QUOTES) . '</span>';
            $html .= '<span class="id">#' . $id . '</span>';
            $html .= '<span class="price">' . number_format($price, 2, ',', ' ') . ' zl</span>';
            $html .= '<span class="stock ' . ($inStock ? 'in' : 'out') . '">' . ($inStock ? $this->l('w magazynie') : $this->l('brak')) . '</span>';
            $html .= '<span class="pick">' . $this->l('Wybierz') . ' &rarr;</span>';
            $html .= '</a></li>';
        }
        $html .= '</ul></div>';
        return $html;
    }

    private function renderEpAddForm($selectedProductId, $selectedProductName, $q)
    {
        $action = $this->context->link->getAdminLink('AdminDivezoneChat')
            . '&tab=' . self::TAB_EDITORIAL;
        if ($q !== '') {
            $action .= '&q=' . rawurlencode($q);
        }

        $html  = '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '" class="dz-ep-add-form">';

        if ($selectedProductId <= 0) {
            $html .= '<div class="dz-ep-add-empty">' . $this->l('Najpierw wyszukaj produkt powyzej i kliknij "Wybierz" przy odpowiedniej pozycji.') . '</div>';
            $html .= '</form>';
            return $html;
        }

        $html .= '<input type="hidden" name="ep_product_id" value="' . (int) $selectedProductId . '">';
        $html .= '<input type="hidden" name="ep_product_name" value="' . htmlspecialchars($selectedProductName, ENT_QUOTES) . '">';

        $html .= '<div class="dz-ep-selected"><strong>' . $this->l('Wybrany produkt') . ':</strong> ';
        $html .= htmlspecialchars($selectedProductName, ENT_QUOTES);
        $html .= ' <span style="color:#999">#' . (int) $selectedProductId . '</span></div>';

        $html .= '<div class="dz-ep-field"><label for="dz-ep-reason">' . $this->l('Powod (reason) — WYMAGANE') . '</label>';
        $html .= '<textarea id="dz-ep-reason" name="reason" rows="2" required></textarea></div>';

        $html .= '<div class="dz-ep-field"><label for="dz-ep-boost">' . $this->l('Boost factor (1.0 - 2.5)') . '</label>';
        $html .= '<input type="number" id="dz-ep-boost" name="boost_factor" value="1.5" min="1.0" max="2.5" step="0.1" required></div>';

        $html .= '<div class="dz-ep-field"><label for="dz-ep-cat">' . $this->l('Kategoria (opcjonalne)') . '</label>';
        $html .= '<input type="text" id="dz-ep-cat" name="category_hint" value="" placeholder="' . $this->l('np. regulator_recreational (puste = wszystkie)') . '"></div>';

        $html .= '<div class="dz-ep-field"><label for="dz-ep-ttl">' . $this->l('TTL dni (opcjonalne, puste = bezterminowo)') . '</label>';
        $html .= '<input type="number" id="dz-ep-ttl" name="ttl_days" value="" min="1" max="3650"></div>';

        $html .= '<div style="margin-top:14px;"><button type="submit" name="submitDivezoneChatEpAdd" class="btn btn-primary" style="padding:9px 22px;background:#1a5e5a;color:#fff;border:0;border-radius:4px;font-weight:600;cursor:pointer;font-size:13px;">' . $this->l('Dodaj pick') . '</button></div>';

        $html .= '</form>';
        return $html;
    }

    private function renderEpFilters($active, $orderBy)
    {
        $token = Tools::getAdminTokenLite('AdminDivezoneChat');

        $html  = '<form method="get" class="dz-ep-filters" action="">';
        $html .= '<input type="hidden" name="controller" value="AdminDivezoneChat">';
        $html .= '<input type="hidden" name="token" value="' . htmlspecialchars($token, ENT_QUOTES) . '">';
        $html .= '<input type="hidden" name="tab" value="' . self::TAB_EDITORIAL . '">';

        $html .= '<select name="active">';
        $html .= '<option value=""' . ($active === '' ? ' selected' : '') . '>' . $this->l('— wszystkie —') . '</option>';
        $html .= '<option value="1"' . ($active === '1' ? ' selected' : '') . '>' . $this->l('tylko aktywne') . '</option>';
        $html .= '<option value="0"' . ($active === '0' ? ' selected' : '') . '>' . $this->l('tylko nieaktywne') . '</option>';
        $html .= '</select>';

        $orderOpts = array(
            'added_at'       => $this->l('data dodania'),
            'expires_at'     => $this->l('data wygasniecia'),
            'boost_factor'   => $this->l('boost'),
            'product_name'   => $this->l('nazwa produktu'),
            'last_review_at' => $this->l('ost. przeglad'),
        );
        $html .= '<select name="order_by">';
        foreach ($orderOpts as $k => $lbl) {
            $sel = $k === $orderBy ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($k, ENT_QUOTES) . '"' . $sel . '>' . $this->l('sortuj:') . ' ' . htmlspecialchars($lbl, ENT_QUOTES) . '</option>';
        }
        $html .= '</select>';

        $html .= '<button type="submit" class="btn btn-default">' . $this->l('Filtruj') . '</button>';
        $html .= '</form>';
        return $html;
    }

    private function renderEpTable($list)
    {
        if (isset($list['error'])) {
            return '<div class="dz-analytics-empty">' . $this->l('Blad pobrania listy:') . ' ' . htmlspecialchars((string) $list['error'], ENT_QUOTES) . '</div>';
        }

        $picks = isset($list['picks']) && is_array($list['picks']) ? $list['picks'] : array();
        if (empty($picks)) {
            return '<div class="dz-analytics-empty">' . $this->l('Brak pickow w wybranych filtrach.') . '</div>';
        }

        $html  = '<table class="dz-analytics-table">';
        $html .= '<thead><tr>';
        $html .= '<th>' . $this->l('Produkt') . '</th>';
        $html .= '<th class="num">' . $this->l('Boost') . '</th>';
        $html .= '<th>' . $this->l('Powod') . '</th>';
        $html .= '<th>' . $this->l('Kategoria') . '</th>';
        $html .= '<th>' . $this->l('Dodane') . '</th>';
        $html .= '<th>' . $this->l('Wygasa') . '</th>';
        $html .= '<th>' . $this->l('Ost. przeglad') . '</th>';
        $html .= '<th>' . $this->l('Status') . '</th>';
        $html .= '<th>' . $this->l('Akcje') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($picks as $pick) {
            if (!is_array($pick)) {
                continue;
            }
            $html .= $this->renderEpRow($pick);
        }

        $html .= '</tbody></table>';
        return $html;
    }

    private function renderEpRow($pick)
    {
        $id           = isset($pick['id']) ? (int) $pick['id'] : 0;
        $productId    = isset($pick['product_id']) ? (int) $pick['product_id'] : 0;
        $productName  = isset($pick['product_name']) ? (string) $pick['product_name'] : '?';
        $categoryHint = (isset($pick['category_hint']) && $pick['category_hint'] !== null && $pick['category_hint'] !== '')
            ? (string) $pick['category_hint']
            : '';
        $boost      = isset($pick['boost_factor']) ? (float) $pick['boost_factor'] : 1.0;
        $reason     = isset($pick['reason']) ? (string) $pick['reason'] : '';
        $addedBy    = isset($pick['added_by']) ? (string) $pick['added_by'] : '';
        $addedAt    = isset($pick['added_at']) ? (string) $pick['added_at'] : '';
        $expiresAt  = (isset($pick['expires_at']) && $pick['expires_at'] !== null) ? (string) $pick['expires_at'] : '';
        $lastReview = (isset($pick['last_review_at']) && $pick['last_review_at'] !== null) ? (string) $pick['last_review_at'] : '';
        $active     = !empty($pick['active']);

        // Skrocony reason w komorce (pelny w title="").
        if (function_exists('mb_strlen') && mb_strlen($reason, 'UTF-8') > 80) {
            $reasonShort = mb_substr($reason, 0, 80, 'UTF-8') . '…';
        } elseif (strlen($reason) > 80) {
            $reasonShort = substr($reason, 0, 80) . '…';
        } else {
            $reasonShort = $reason;
        }

        $rowStyle = $active ? '' : ' style="background:#fafafa;color:#888;"';

        $html  = '<tr' . $rowStyle . '>';
        $html .= '<td><strong>' . htmlspecialchars($productName, ENT_QUOTES) . '</strong> <small style="color:#999">#' . $productId . '</small></td>';
        $html .= '<td class="num"><strong>' . number_format($boost, 2, '.', '') . '</strong></td>';
        $html .= '<td title="' . htmlspecialchars($reason, ENT_QUOTES) . '">' . htmlspecialchars($reasonShort, ENT_QUOTES) . '</td>';
        $html .= '<td>' . ($categoryHint !== '' ? '<code style="font-size:11px">' . htmlspecialchars($categoryHint, ENT_QUOTES) . '</code>' : '<em style="color:#999">' . $this->l('wszystkie') . '</em>') . '</td>';
        $html .= '<td>' . htmlspecialchars($this->formatConvDate($addedAt), ENT_QUOTES);
        if ($addedBy !== '') {
            $html .= '<br><small style="color:#999">' . htmlspecialchars($addedBy, ENT_QUOTES) . '</small>';
        }
        $html .= '</td>';
        $html .= '<td>' . ($expiresAt !== '' ? htmlspecialchars($this->formatConvDate($expiresAt), ENT_QUOTES) : '<em style="color:#999">' . $this->l('bezterminowo') . '</em>') . '</td>';
        $html .= '<td>' . ($lastReview !== '' ? htmlspecialchars($this->formatConvDate($lastReview), ENT_QUOTES) : '<span style="color:#999">—</span>') . '</td>';
        $html .= '<td>' . ($active ? '<span class="dz-ep-status dz-ep-status-active">' . $this->l('aktywny') . '</span>' : '<span class="dz-ep-status dz-ep-status-inactive">' . $this->l('nieaktywny') . '</span>') . '</td>';
        $html .= '<td class="dz-ep-row-actions">' . $this->renderEpRowActions($id, $boost, $reason, $active) . '</td>';
        $html .= '</tr>';
        return $html;
    }

    private function renderEpRowActions($id, $boost, $reason, $active)
    {
        $action = $this->context->link->getAdminLink('AdminDivezoneChat')
            . '&tab=' . self::TAB_EDITORIAL;

        // Edycja inline (rozwijana <details>).
        $html  = '<details class="dz-ep-edit-details"><summary>' . $this->l('Edytuj') . '</summary>';
        $html .= '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '" class="dz-ep-edit-form">';
        $html .= '<input type="hidden" name="ep_id" value="' . (int) $id . '">';

        $html .= '<label>' . $this->l('Boost') . '</label>';
        $html .= '<input type="number" name="boost_factor" value="' . number_format($boost, 2, '.', '') . '" min="1.0" max="2.5" step="0.1">';

        $html .= '<label>' . $this->l('Powod') . '</label>';
        $html .= '<input type="text" name="reason" value="' . htmlspecialchars($reason, ENT_QUOTES) . '">';

        $html .= '<label>' . $this->l('Aktywny') . '</label>';
        $html .= '<label style="font-weight:400;"><input type="checkbox" name="active" value="1"' . ($active ? ' checked' : '') . '> ' . $this->l('zaznacz aby aktywny') . '</label>';

        $html .= '<label>' . $this->l('Przedluz TTL') . '</label>';
        $html .= '<select name="ttl_extend_days"><option value="">—</option><option value="30">+30 ' . $this->l('dni') . '</option><option value="90">+90 ' . $this->l('dni') . '</option><option value="365">+365 ' . $this->l('dni') . '</option></select>';

        $html .= '<div class="submit-row"><button type="submit" name="submitDivezoneChatEpUpdate" class="btn btn-primary btn-xs">' . $this->l('Zapisz') . '</button></div>';
        $html .= '</form>';
        $html .= '</details>';

        // Quick action: "Oznacz przejrzane".
        $html .= '<div class="quick">';
        $html .= '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '" style="display:inline;">';
        $html .= '<input type="hidden" name="ep_id" value="' . (int) $id . '">';
        $html .= '<input type="hidden" name="ep_action" value="mark_reviewed">';
        $html .= '<button type="submit" name="submitDivezoneChatEpUpdate" class="btn btn-default btn-xs">' . $this->l('Oznacz przejrzane') . '</button>';
        $html .= '</form>';

        // Delete (inline confirm — jedyny mikro-JS w sekcji).
        $confirmMsg = $this->jsEscape($this->l('Na pewno usunac ten pick?'));
        $html .= '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES) . '" style="display:inline;" onsubmit="return confirm(\'' . $confirmMsg . '\');">';
        $html .= '<input type="hidden" name="ep_id" value="' . (int) $id . '">';
        $html .= '<button type="submit" name="submitDivezoneChatEpDelete" class="btn btn-danger btn-xs" style="background:#d9534f;color:#fff;border:0;padding:3px 8px;border-radius:3px;">' . $this->l('Usun') . '</button>';
        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    // ============================================================================
    // Editorial Picks — handlery POST (CHAT-T-055).
    // ============================================================================

    private function handleEpAdd($employeeId)
    {
        $productId   = (int) Tools::getValue('ep_product_id', 0);
        $productName = trim((string) Tools::getValue('ep_product_name', ''));
        $reason      = trim((string) Tools::getValue('reason', ''));
        $boost       = (float) Tools::getValue('boost_factor', 0);
        $categoryRaw = trim((string) Tools::getValue('category_hint', ''));
        $ttlRaw      = trim((string) Tools::getValue('ttl_days', ''));

        // Walidacja lokalna PRZED POST do backendu.
        if ($productId <= 0 || $productName === '') {
            $this->epFlash     = $this->l('Brak wybranego produktu. Najpierw wyszukaj i kliknij "Wybierz".');
            $this->epFlashType = 'error';
            return;
        }
        if ($reason === '') {
            $this->epFlash     = $this->l('Powod (reason) jest wymagany.');
            $this->epFlashType = 'error';
            return;
        }
        if ($boost < 1.0 || $boost > 2.5) {
            $this->epFlash     = $this->l('Boost musi byc w zakresie 1.0-2.5.');
            $this->epFlashType = 'error';
            return;
        }

        $payload = array(
            'product_id'   => $productId,
            'product_name' => $productName,
            'reason'       => $reason,
            'boost_factor' => $boost,
        );
        if ($categoryRaw !== '') {
            $payload['category_hint'] = $categoryRaw;
        }
        if ($ttlRaw !== '') {
            $payload['ttl_days'] = (int) $ttlRaw;
        }

        $body = json_encode($payload);
        $resp = $this->callBackend(self::ENDPOINT_EDITORIAL, $employeeId, 'POST', $body);

        $this->setEpFlashFromResponse($resp, $this->l('Pick dodany.'));
    }

    private function handleEpUpdate($employeeId)
    {
        $id = (int) Tools::getValue('ep_id', 0);
        if ($id <= 0) {
            $this->epFlash     = $this->l('Brak ep_id.');
            $this->epFlashType = 'error';
            return;
        }

        $epAction = (string) Tools::getValue('ep_action', '');
        $payload  = array();

        if ($epAction === 'mark_reviewed') {
            // Szybka akcja — tylko jeden klucz.
            $payload['mark_reviewed'] = true;
        } else {
            // Pelny edit: boost / reason / active / ttl_extend.
            $boostRaw = Tools::getValue('boost_factor', null);
            if ($boostRaw !== null && $boostRaw !== '') {
                $boost = (float) $boostRaw;
                if ($boost < 1.0 || $boost > 2.5) {
                    $this->epFlash     = $this->l('Boost musi byc w zakresie 1.0-2.5.');
                    $this->epFlashType = 'error';
                    return;
                }
                $payload['boost_factor'] = $boost;
            }

            $reasonRaw = Tools::getValue('reason', null);
            if ($reasonRaw !== null) {
                $reason = trim((string) $reasonRaw);
                if ($reason === '') {
                    $this->epFlash     = $this->l('Powod nie moze byc pusty.');
                    $this->epFlashType = 'error';
                    return;
                }
                $payload['reason'] = $reason;
            }

            // active checkbox: '1' jesli zaznaczone, brak w POST jesli odznaczone.
            $payload['active'] = ((string) Tools::getValue('active', '') === '1');

            $ttlExtend = (int) Tools::getValue('ttl_extend_days', 0);
            if ($ttlExtend > 0) {
                $payload['ttl_extend_days'] = $ttlExtend;
            }
        }

        if (empty($payload)) {
            $this->epFlash     = $this->l('Brak zmian do zapisania.');
            $this->epFlashType = 'error';
            return;
        }

        $body = json_encode($payload);
        $resp = $this->callBackend(self::ENDPOINT_EDITORIAL . '/' . $id, $employeeId, 'POST', $body);

        $successMsg = $epAction === 'mark_reviewed'
            ? $this->l('Oznaczono jako przejrzane.')
            : $this->l('Pick zaktualizowany.');
        $this->setEpFlashFromResponse($resp, $successMsg);
    }

    private function handleEpDelete($employeeId)
    {
        $id = (int) Tools::getValue('ep_id', 0);
        if ($id <= 0) {
            $this->epFlash     = $this->l('Brak ep_id.');
            $this->epFlashType = 'error';
            return;
        }

        $resp = $this->callBackend(self::ENDPOINT_EDITORIAL . '/' . $id . '/delete', $employeeId, 'POST', '{}');
        $this->setEpFlashFromResponse($resp, $this->l('Pick usuniety.'));
    }

    private function setEpFlashFromResponse($resp, $successMsg)
    {
        if (is_array($resp) && isset($resp['error'])) {
            $httpStatus = isset($resp['http_status']) ? (int) $resp['http_status'] : 0;
            if ($httpStatus === 401) {
                $this->epFlash = $this->l('Brak/nieprawidlowy token kanalu serwerowego. Sprawdz konfiguracje modulu (Sekret SERWEROWY).');
            } elseif ($httpStatus === 403) {
                $this->epFlash = $this->l('Brak roli (no_role): konto nie ma roli w divechat_admin_roles. Editorial wymaga operatora lub admina.');
            } elseif ($httpStatus === 400) {
                $this->epFlash = $this->l('Walidacja backendu:') . ' ' . (string) $resp['error'];
            } elseif ($httpStatus === 404) {
                $this->epFlash = $this->l('Pick nie znaleziony.');
            } else {
                $this->epFlash = $this->l('Blad:') . ' ' . (string) $resp['error'];
            }
            $this->epFlashType = 'error';
            return;
        }

        $this->epFlash     = $successMsg;
        $this->epFlashType = 'success';
    }

    // ============================================================================
    // HTTP call do backendu kanalem serwerowym. GET (default) lub POST z body JSON.
    // Podpis: hash_hmac sha256 z DIVEZONE_CHAT_SERVER_SECRET, payload employee_id:ts.
    // Headery X-DiveChat-Server-Token/-Employee/-Time.
    //
    // CHAT-T-045: dodano $method+$body do POST /api/settings. GET bez zmian (ten sam
    // payload HMAC dziala dla obu metod — backend ServerHmacVerifier nie patrzy na
    // metode HTTP).
    // ============================================================================
    private function callBackend($endpointPath, $employeeId, $method = 'GET', $body = null)
    {
        $backendUrl = trim((string) Configuration::get(Divezone_Chat::KEY_BACKEND_URL));
        $secret     = (string) Configuration::get(Divezone_Chat::KEY_SERVER_SECRET);

        if ($backendUrl === '' || $secret === '') {
            return array('error' => $this->l('Konfiguracja niekompletna — wypelnij Backend URL i sekret w konfiguracji modulu.'));
        }

        $timestamp = time();
        $token     = hash_hmac('sha256', $employeeId . ':' . $timestamp, $secret);
        $url       = rtrim($backendUrl, '/') . $endpointPath;
        $method    = strtoupper((string) $method);

        $headers = array(
            'X-DiveChat-Server-Token: ' . $token,
            'X-DiveChat-Server-Employee: ' . (int) $employeeId,
            'X-DiveChat-Server-Time: ' . $timestamp,
            'Accept: application/json',
        );

        $httpOpts = array(
            'method'        => $method,
            'timeout'       => self::HTTP_TIMEOUT_SEC,
            'ignore_errors' => true,
        );

        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
            $httpOpts['content'] = (string) $body;
        }

        $httpOpts['header'] = implode("\r\n", $headers);

        $context = stream_context_create(array('http' => $httpOpts));

        $body_resp = Tools::file_get_contents($url, false, $context, self::HTTP_TIMEOUT_SEC);

        // $http_response_header jest auto-set przez file_get_contents — wyciagamy
        // kod HTTP zeby rozroznic 401/403 od innych bledow w warstwie aplikacji.
        $httpStatus = 0;
        if (isset($http_response_header) && is_array($http_response_header) && !empty($http_response_header)) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', (string) $http_response_header[0], $m)) {
                $httpStatus = (int) $m[1];
            }
        }

        if ($body_resp === false || $body_resp === null) {
            return array(
                'error'       => $this->l('Brak odpowiedzi z backendu.'),
                'details'     => $url,
                'http_status' => $httpStatus,
            );
        }

        $data = json_decode($body_resp, true);
        if (!is_array($data)) {
            return array(
                'error'       => $this->l('Niepoprawna odpowiedz JSON.'),
                'details'     => Tools::substr((string) $body_resp, 0, 200),
                'http_status' => $httpStatus,
            );
        }

        // Jezeli backend zwrocil errror + my mamy http_status — przeniesc kod do payloadu
        // (zeby handleModelsSave mogl rozroznic 401/403).
        if (isset($data['error']) && !isset($data['http_status']) && $httpStatus > 0) {
            $data['http_status'] = $httpStatus;
        }

        return $data;
    }
}
