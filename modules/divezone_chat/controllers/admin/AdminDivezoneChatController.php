<?php
/**
 * AdminDivezoneChatController — natywny AdminController panelu obslugi (T-032).
 *
 * ADR-068 decyzja 175a: render natywny w PS (NIE iframe), dane ciagniete z
 * backendu kanalem serwerowym (HMAC + employee_id w ladunku, decyzja 174a).
 *
 * Faza 1 (T-032): strona prezentuje wynik echo /api/admin/whoami — walidacja
 * lancucha auth/role/kanal/render. Pelny CRUD rekomendacji + koszty + ustawienia
 * dorzucamy w kolejnym tasku.
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
    const ENDPOINT_PATH    = '/api/admin/whoami';

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

        $employeeId = (int)$this->context->employee->id;
        $result     = $this->callWhoami($employeeId);

        $html = '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-comments"></i> ' . $this->l('DiveZone Chat — panel obslugi (faza 1: echo kregoslupa)') . '</div>';
        $html .= '<div style="padding:18px;">';
        $html .= '<h3>' . $this->l('Test kanalu serwerowego (GET /api/admin/whoami)') . '</h3>';

        if (isset($result['error'])) {
            $html .= '<p style="color:#a94442;"><strong>' . $this->l('Blad:') . '</strong> ' . htmlspecialchars((string)$result['error'], ENT_QUOTES) . '</p>';
            if (isset($result['details'])) {
                $html .= '<pre style="background:#f5f5f5;padding:10px;border:1px solid #ddd;overflow:auto;">' . htmlspecialchars((string)$result['details'], ENT_QUOTES) . '</pre>';
            }
            $html .= '<p>' . $this->l('Diagnostyka:') . '</p><ul>';
            $html .= '<li>' . $this->l('Backend URL') . ': <code>' . htmlspecialchars((string)Configuration::get(DivezoneChat::KEY_BACKEND_URL), ENT_QUOTES) . '</code></li>';
            $html .= '<li>' . $this->l('Sekret ustawiony') . ': ' . ((string)Configuration::get(DivezoneChat::KEY_SERVER_SECRET) !== '' ? $this->l('TAK') : '<strong style="color:#a94442;">' . $this->l('NIE') . '</strong>') . '</li>';
            $html .= '<li>' . $this->l('Employee ID (kontekst)') . ': <code>' . (int)$employeeId . '</code></li>';
            $html .= '</ul>';
            $html .= '<p>' . $this->l('Jesli sekret nie jest ustawiony, otworz Moduly -> DiveZone Chat -> Konfiguruj.') . '</p>';
        } else {
            $html .= '<table class="table" style="max-width:540px;"><tbody>';
            $html .= '<tr><td>' . $this->l('Status') . '</td><td><strong>' . htmlspecialchars((string)(isset($result['status']) ? $result['status'] : '?'), ENT_QUOTES) . '</strong></td></tr>';
            $html .= '<tr><td>' . $this->l('Employee ID') . '</td><td><strong>' . (int)(isset($result['employee_id']) ? $result['employee_id'] : 0) . '</strong></td></tr>';
            $html .= '<tr><td>' . $this->l('Rola czatu') . '</td><td><strong>' . htmlspecialchars((string)(isset($result['role']) ? $result['role'] : '?'), ENT_QUOTES) . '</strong></td></tr>';
            $html .= '</tbody></table>';
            $html .= '<p style="margin-top:14px;color:#3c763d;">' . $this->l('Kanal serwerowy dziala. Kregoslup panelu potwierdzony.') . '</p>';
        }

        $html .= '<hr>';
        $html .= '<p><em>' . $this->l('To jest echo endpoint fazy 1 (T-032). Pelny panel CRUD rekomendacji + koszty + ustawienia: kolejny task na sprawdzonym fundamencie.') . '</em></p>';
        $html .= '</div></div>';

        $this->context->smarty->assign('content', $html);
    }

    /**
     * Wola backend GET /api/admin/whoami kanalem serwerowym.
     * Podpis: hash_hmac sha256 z DIVEZONE_CHAT_SERVER_SECRET, payload
     * employee_id:timestamp. Headery X-DiveChat-Server-Token/-Employee/-Time.
     *
     * @param int $employeeId
     * @return array assoc — odpowiedz JSON z backendu lub blok ['error'=>..., 'details'=>...]
     */
    private function callWhoami($employeeId)
    {
        $backendUrl = trim((string)Configuration::get(DivezoneChat::KEY_BACKEND_URL));
        $secret     = (string)Configuration::get(DivezoneChat::KEY_SERVER_SECRET);

        if ($backendUrl === '' || $secret === '') {
            return array('error' => $this->l('Konfiguracja niekompletna — wypelnij Backend URL i sekret w konfiguracji modulu.'));
        }

        $timestamp = time();
        $token     = hash_hmac('sha256', $employeeId . ':' . $timestamp, $secret);
        $url       = rtrim($backendUrl, '/') . self::ENDPOINT_PATH;

        $headers = array(
            'X-DiveChat-Server-Token: ' . $token,
            'X-DiveChat-Server-Employee: ' . (int)$employeeId,
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

        // Tools::file_get_contents ma curl-fallback dla srodowisk z allow_url_fopen=0;
        // ostatni argument = curl timeout (sec).
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
                'details' => Tools::substr((string)$body, 0, 200),
            );
        }

        return $data;
    }
}
