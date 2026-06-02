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

    // Aktywna zakladka — fallback do 'recommendations' jak brak ?tab.
    const TAB_RECOMMENDATIONS = 'recommendations';
    const TAB_MODELS          = 'models';

    /** @var string komunikat flashowy do wyswietlenia na gorze ekranu Modele */
    private $modelsFlash = '';
    /** @var string typ komunikatu: success | error */
    private $modelsFlashType = 'success';

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

        // 1. Aktywna zakladka — z querystring lub form submit, default rekomendacje.
        $activeTab = (string) Tools::getValue('tab', self::TAB_RECOMMENDATIONS);
        if (!in_array($activeTab, array(self::TAB_RECOMMENDATIONS, self::TAB_MODELS), true)) {
            $activeTab = self::TAB_RECOMMENDATIONS;
        }

        // 2. Submit ekranu Modele -> POST /api/settings przed odczytem.
        if (Tools::isSubmit('submitDivezoneChatModels')) {
            $this->handleModelsSave($employeeId);
            $activeTab = self::TAB_MODELS; // zostan na zakladce po zapisie
        }

        // 3. Whoami zawsze (maly pasek u gory).
        $whoami = $this->callBackend(self::ENDPOINT_WHOAMI, $employeeId);

        // 4. Tresc aktywnej zakladki — pobieramy TYLKO te dane, ktorych potrzebujemy.
        $tabContent = '';
        if ($activeTab === self::TAB_MODELS) {
            $settings = $this->callBackend(self::ENDPOINT_SETTINGS, $employeeId);
            $tabContent = $this->renderModelsSection($settings);
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
        $baseUrl = $this->context->link->getAdminLink('AdminDivezoneChat');
        $rec = $baseUrl . '&tab=' . self::TAB_RECOMMENDATIONS;
        $mod = $baseUrl . '&tab=' . self::TAB_MODELS;

        $clsRec = $activeTab === self::TAB_RECOMMENDATIONS ? ' is-active' : '';
        $clsMod = $activeTab === self::TAB_MODELS          ? ' is-active' : '';

        $html  = '<nav class="dz-tabs-nav" role="tablist">';
        $html .= '<a href="' . htmlspecialchars($rec, ENT_QUOTES) . '" class="dz-tab-link' . $clsRec . '" role="tab">' . $this->l('Rekomendacje') . '</a>';
        $html .= '<a href="' . htmlspecialchars($mod, ENT_QUOTES) . '" class="dz-tab-link' . $clsMod . '" role="tab">' . $this->l('Modele') . '</a>';
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
