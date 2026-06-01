<?php
/**
 * AdminDivezoneChatController — natywny AdminController panelu obslugi.
 *
 * ADR-068 decyzja 175a: render natywny w PS (NIE iframe), dane ciagniete z
 * backendu kanalem serwerowym (HMAC + employee_id w ladunku, decyzja 174a).
 *
 * Sekcje (renderowane w tym samym widoku, jedna pod druga):
 *  1. Test echo /api/admin/whoami (T-032) — diagnostyka lancucha kregoslupa.
 *  2. Kuratorowane rekomendacje (T-035 CZESC B) — READ-ONLY widok wpisow z
 *     wskaznikiem popularnosci (bez surowych liczb sprzedazowych, decyzja 38b).
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
    const ENDPOINT_WHOAMI         = '/api/admin/whoami';
    const ENDPOINT_RECOMMENDATIONS = '/api/admin/recommendations';

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
        $whoami         = $this->callBackend(self::ENDPOINT_WHOAMI, $employeeId);
        $recommendations = $this->callBackend(self::ENDPOINT_RECOMMENDATIONS, $employeeId);

        $html  = $this->renderWhoamiSection($whoami, $employeeId);
        $html .= $this->renderRecommendationsSection($recommendations);

        $this->context->smarty->assign('content', $html);
    }

    // ============================================================================
    // SEKCJA 1: Echo whoami (T-032) — diagnostyka lancucha
    // ============================================================================
    private function renderWhoamiSection($result, $employeeId)
    {
        $html  = '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-comments"></i> ' . $this->l('DiveZone Chat — panel obslugi') . '</div>';
        $html .= '<div style="padding:18px;">';
        $html .= '<h3>' . $this->l('Test kanalu serwerowego (GET /api/admin/whoami)') . '</h3>';

        if (isset($result['error'])) {
            $html .= '<p style="color:#a94442;"><strong>' . $this->l('Blad:') . '</strong> ' . htmlspecialchars((string) $result['error'], ENT_QUOTES) . '</p>';
            if (isset($result['details'])) {
                $html .= '<pre style="background:#f5f5f5;padding:10px;border:1px solid #ddd;overflow:auto;">' . htmlspecialchars((string) $result['details'], ENT_QUOTES) . '</pre>';
            }
            $html .= '<p>' . $this->l('Diagnostyka:') . '</p><ul>';
            $html .= '<li>' . $this->l('Backend URL') . ': <code>' . htmlspecialchars((string) Configuration::get(Divezone_Chat::KEY_BACKEND_URL), ENT_QUOTES) . '</code></li>';
            $html .= '<li>' . $this->l('Sekret ustawiony') . ': ' . ((string) Configuration::get(Divezone_Chat::KEY_SERVER_SECRET) !== '' ? $this->l('TAK') : '<strong style="color:#a94442;">' . $this->l('NIE') . '</strong>') . '</li>';
            $html .= '<li>' . $this->l('Employee ID (kontekst)') . ': <code>' . (int) $employeeId . '</code></li>';
            $html .= '</ul>';
            $html .= '<p>' . $this->l('Jesli sekret nie jest ustawiony, otworz Moduly -> DiveZone Chat -> Konfiguruj.') . '</p>';
        } else {
            $html .= '<table class="table" style="max-width:540px;"><tbody>';
            $html .= '<tr><td>' . $this->l('Status') . '</td><td><strong>' . htmlspecialchars((string) (isset($result['status']) ? $result['status'] : '?'), ENT_QUOTES) . '</strong></td></tr>';
            $html .= '<tr><td>' . $this->l('Employee ID') . '</td><td><strong>' . (int) (isset($result['employee_id']) ? $result['employee_id'] : 0) . '</strong></td></tr>';
            $html .= '<tr><td>' . $this->l('Rola czatu') . '</td><td><strong>' . htmlspecialchars((string) (isset($result['role']) ? $result['role'] : '?'), ENT_QUOTES) . '</strong></td></tr>';
            $html .= '</tbody></table>';
            $html .= '<p style="margin-top:14px;color:#3c763d;">' . $this->l('Kanal serwerowy dziala. Kregoslup panelu potwierdzony.') . '</p>';
        }

        $html .= '</div></div>';
        return $html;
    }

    // ============================================================================
    // SEKCJA 2: Kuratorowane rekomendacje (T-035 CZESC B, read-only)
    // Dane z GET /api/admin/recommendations. Pokazuje WSZYSTKIE wpisy (active +
    // nieaktywne, decyzja 35a) — pracownik MUSI widziec martwe wpisy. Wskaznik
    // popularnosci = pasek + etykieta tekstowa, BEZ surowych liczb (decyzja 38b).
    // ============================================================================
    private function renderRecommendationsSection($result)
    {
        $html  = '<div class="panel">';
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

        // Priority
        $html .= '<td><strong>' . $priority . '</strong></td>';

        // Produkt: nazwa (link do podgladu produktu w PS) + product_id + rationale
        $html .= '<td>';
        $html .= '<a href="' . htmlspecialchars($this->context->link->getAdminLink('AdminProducts') . '&id_product=' . $productId . '&updateproduct', ENT_QUOTES) . '" target="_blank" style="font-weight:600;">';
        $html .= htmlspecialchars($productName, ENT_QUOTES);
        $html .= '</a>';
        $html .= ' <span style="color:#999;font-size:0.85em;">#' . $productId . '</span>';
        if ($rationale !== '') {
            $html .= '<div style="margin-top:4px;color:#666;font-size:0.9em;font-style:italic;">' . htmlspecialchars($rationale, ENT_QUOTES) . '</div>';
        }
        $html .= '</td>';

        // Cena
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

        // Dostepnosc (polskie etykiety)
        $html .= '<td>' . $this->formatAvailability($availability) . '</td>';

        // Popularnosc — pasek + etykieta tekstowa, BEZ liczb
        $html .= '<td>' . $this->renderPopularity($popBucket, $popPercent) . '</td>';

        // Status bota
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

        // Kolory + etykiety per bucket
        $colors = array(
            'low'  => '#d9534f',
            'mid'  => '#f0ad4e',
            'high' => '#5cb85c',
        );
        $labels = array(
            'low'  => $this->l('rzadko'),
            'mid'  => $this->l('srednio'),
            'high' => $this->l('czesto'),
        );
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
    // HTTP call do backendu kanalem serwerowym (wspolne dla whoami i recommendations).
    // Podpis: hash_hmac sha256 z DIVEZONE_CHAT_SERVER_SECRET, payload employee_id:ts.
    // Headery X-DiveChat-Server-Token/-Employee/-Time.
    // ============================================================================
    private function callBackend($endpointPath, $employeeId)
    {
        $backendUrl = trim((string) Configuration::get(Divezone_Chat::KEY_BACKEND_URL));
        $secret     = (string) Configuration::get(Divezone_Chat::KEY_SERVER_SECRET);

        if ($backendUrl === '' || $secret === '') {
            return array('error' => $this->l('Konfiguracja niekompletna — wypelnij Backend URL i sekret w konfiguracji modulu.'));
        }

        $timestamp = time();
        $token     = hash_hmac('sha256', $employeeId . ':' . $timestamp, $secret);
        $url       = rtrim($backendUrl, '/') . $endpointPath;

        $headers = array(
            'X-DiveChat-Server-Token: ' . $token,
            'X-DiveChat-Server-Employee: ' . (int) $employeeId,
            'X-DiveChat-Server-Time: ' . $timestamp,
            'Accept: application/json',
        );

        $context = stream_context_create(array(
            'http' => array(
                'method'        => 'GET',
                'header'        => implode("\r\n", $headers),
                'timeout'       => self::HTTP_TIMEOUT_SEC,
                'ignore_errors' => true,
            ),
        ));

        $body = Tools::file_get_contents($url, false, $context, self::HTTP_TIMEOUT_SEC);
        if ($body === false || $body === null) {
            return array(
                'error'   => $this->l('Brak odpowiedzi z backendu.'),
                'details' => $url,
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return array(
                'error'   => $this->l('Niepoprawna odpowiedz JSON.'),
                'details' => Tools::substr((string) $body, 0, 200),
            );
        }

        return $data;
    }
}
