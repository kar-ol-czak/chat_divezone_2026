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

    // Aktywna zakladka — CHAT-T-048 (106a): DEFAULT = Rozmowy.
    const TAB_CONVERSATIONS   = 'conversations';
    const TAB_RECOMMENDATIONS = 'recommendations';
    const TAB_MODELS          = 'models';
    // CHAT-T-047 (decyzja 108a): konfiguracja jako zakladka obok Moduly->Konfiguruj.
    // renderConfigSection() wola publiczne metody Divezone_Chat (decyzja 114a OPCJA B):
    // renderConfigForm() + handleConfigSubmit() — zero duplikacji formularza.
    const TAB_CONFIG          = 'config';

    /** @var string komunikat flashowy do wyswietlenia na gorze ekranu Modele */
    private $modelsFlash = '';
    /** @var string typ komunikatu: success | error */
    private $modelsFlashType = 'success';
    /** @var string komunikat flashowy do wyswietlenia na gorze widoku Rozmow (CHAT-T-048) */
    private $convFlash = '';
    /** @var string typ komunikatu: success | error */
    private $convFlashType = 'success';

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
        if (!in_array($activeTab, array(self::TAB_CONVERSATIONS, self::TAB_RECOMMENDATIONS, self::TAB_MODELS, self::TAB_CONFIG), true)) {
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

        // 3. Whoami zawsze (maly pasek u gory).
        $whoami = $this->callBackend(self::ENDPOINT_WHOAMI, $employeeId);

        // 4. Tresc aktywnej zakladki — pobieramy TYLKO te dane, ktorych potrzebujemy.
        $tabContent = '';
        if ($activeTab === self::TAB_CONVERSATIONS) {
            $tabContent = $this->renderConversationsSection($employeeId);
        } elseif ($activeTab === self::TAB_MODELS) {
            $settings = $this->callBackend(self::ENDPOINT_SETTINGS, $employeeId);
            $tabContent = $this->renderModelsSection($settings);
        } elseif ($activeTab === self::TAB_CONFIG) {
            $tabContent = $this->renderConfigSection();
        } else {
            $recommendations = $this->callBackend(self::ENDPOINT_RECOMMENDATIONS, $employeeId);
            $tabContent = $this->renderRecommendationsSection($recommendations);
        }

        $html  = $this->renderTabsStyles();
        $html .= $this->renderWhoamiBar($whoami, $employeeId);
        $html .= $this->renderTabsNav($activeTab);
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

    private function renderTabsNav($activeTab)
    {
        // CHAT-T-048 (118a): kolejnosc wg czestosci uzycia — Rozmowy pierwsze.
        $baseUrl = $this->context->link->getAdminLink('AdminDivezoneChat');
        $conv = $baseUrl . '&tab=' . self::TAB_CONVERSATIONS;
        $rec  = $baseUrl . '&tab=' . self::TAB_RECOMMENDATIONS;
        $mod  = $baseUrl . '&tab=' . self::TAB_MODELS;
        $cfg  = $baseUrl . '&tab=' . self::TAB_CONFIG;

        $clsConv = $activeTab === self::TAB_CONVERSATIONS   ? ' is-active' : '';
        $clsRec  = $activeTab === self::TAB_RECOMMENDATIONS ? ' is-active' : '';
        $clsMod  = $activeTab === self::TAB_MODELS          ? ' is-active' : '';
        $clsCfg  = $activeTab === self::TAB_CONFIG          ? ' is-active' : '';

        $html  = '<nav class="dz-tabs-nav" role="tablist">';
        $html .= '<a href="' . htmlspecialchars($conv, ENT_QUOTES) . '" class="dz-tab-link' . $clsConv . '" role="tab">' . $this->l('Rozmowy') . '</a>';
        $html .= '<a href="' . htmlspecialchars($rec, ENT_QUOTES) . '" class="dz-tab-link' . $clsRec . '" role="tab">' . $this->l('Rekomendacje') . '</a>';
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
