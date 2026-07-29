<?php
/**
 * Divezone_ChatResendOrderInfoModuleFrontController — ponowna wysylka informacji
 * o zamowieniu na adres uzyty przy jego skladaniu (CHAT-T-180, karta Chat-68).
 *
 * Kontekst: klient pisze "nie dostalem maila z zamowieniem" -> bot (backend
 * standalone, narzedzie resend_order_info) wola TEN endpoint kanalem serwerowym,
 * a modul wysyla NOWY, prosty mail HTML z danymi zamowienia.
 *
 * BEZPIECZENSTWO — ochrona przed przejeciem:
 *  - mail idzie WYLACZNIE na adres email z zamowienia (email == parametr, ktory
 *    jednoczesnie WERYFIKUJE tozsamosc i jest CELEM wysylki — nie ma osobnego
 *    pola "wyslij na"). Nie da sie skierowac maila na obcy adres.
 *  - autoryzacja wywolania: HMAC sha256 sekretem SERWEROWYM (KEY_SERVER_SECRET,
 *    = DIVECHAT_SERVER_SECRET w .env backendu — TEN SAM sekret co kanal admin
 *    panelu, tylko nowy kierunek backend->modul). Payload:
 *    order_reference|email|timestamp. Anti-replay: ±300s (jak ServerHmacVerifier
 *    w backendzie).
 *  - odpowiedz nie zdradza czy bledny byl reference czy email — jeden komunikat
 *    "not_found" (wzorem OrderStatus.php w backendzie).
 *
 * Rate limit: max 1 wysylka na to samo zamowienie na 10 minut
 * (pr_divechat_resend_log).
 *
 * NOWY szablon maila (modules/divezone_chat/mails/pl|en/resend_order_info.html) —
 * pojedynczy placeholder {email_body}, HTML budowany w PHP. CELOWO NIE uzywamy
 * order_conf ani PaymentModule::validateOrder (kod platnosci — zero dotykania).
 *
 * PS 1.7.6 / PHP 7.2 (bez typed props, arrow fn, match, str_contains).
 *
 * UWAGA nazwa klasy: PS buduje ja jako module_name . controller_name .
 * 'ModuleFrontController' (Dispatcher l.399), BEZ camelizacji — podkreslenia
 * z 'resend_order_info' MUSZA zostac. Stad 'Resend_Order_Info' (nie
 * 'ResendOrderInfo'), inaczej Dispatcher nie znajdzie klasy (fatal 500).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Divezone_ChatResend_Order_InfoModuleFrontController extends ModuleFrontController
{
    // Endpoint serwerowy — brak sesji klienta PS, nie wymaga logowania.
    public $auth = false;
    public $ssl = true;

    const RESEND_LOG_TABLE = 'divechat_resend_log';
    const RATE_LIMIT_SECONDS = 600; // 10 min
    const HMAC_MAX_AGE = 300;       // ±5 min anti-replay
    const LOG_PREFIX = '[divezone_chat resend_order_info]';

    // Dane firmowe do stopki (potwierdzone przez Karola 2026-07-29, CHAT-T-180).
    const COMPANY_NAME  = 'DIVEZONE.PL Sp. z o.o.';
    const COMPANY_ADDR  = 'ul. Storczykowa 5, 87-100 Toruń';
    const COMPANY_NIP   = 'NIP: 9562346671';
    const COMPANY_PHONE = 'tel. 56 307 03 03 | dive@divezone.pl';

    public function initContent()
    {
        // Czysc bufory (BOM, fragmenty layoutu PS) zanim wyemitujemy JSON.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');

        try {
            $this->handle();
        } catch (Exception $e) {
            error_log(self::LOG_PREFIX . ' exception: ' . $e->getMessage());
            $this->respond(200, array('ok' => false, 'error' => 'error'));
        }
        // respond() konczy exit-em; ten punkt nieosiagalny, ale dla pewnosci:
        exit;
    }

    /**
     * Emituje JSON i konczy. Zawsze czytelny JSON — nigdy HTML/stack trace.
     */
    private function respond($httpCode, array $payload)
    {
        http_response_code((int) $httpCode);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function handle()
    {
        // 1) Sekret serwerowy — bez niego nie autoryzujemy.
        $secret = (string) Configuration::get(Divezone_Chat::KEY_SERVER_SECRET);
        if ($secret === '') {
            error_log(self::LOG_PREFIX . ' brak SERVER_SECRET w Configuration');
            $this->respond(500, array('ok' => false, 'error' => 'config'));
        }

        $reference = trim((string) Tools::getValue('order_reference'));
        $email     = trim((string) Tools::getValue('email'));

        // 2) Weryfikacja HMAC (backend->modul). Payload order_reference|email|timestamp.
        $token = isset($_SERVER['HTTP_X_DIVECHAT_RESEND_TOKEN'])
            ? (string) $_SERVER['HTTP_X_DIVECHAT_RESEND_TOKEN'] : '';
        $ts = isset($_SERVER['HTTP_X_DIVECHAT_RESEND_TIME'])
            ? (int) $_SERVER['HTTP_X_DIVECHAT_RESEND_TIME'] : 0;

        if (!$this->verifyHmac($token, $reference, $email, $ts, $secret)) {
            error_log(self::LOG_PREFIX . ' HMAC verify FAILED');
            $this->respond(401, array('ok' => false, 'error' => 'unauthorized'));
        }

        if ($reference === '' || $email === '') {
            // Podpis moglby byc poprawny dla pustych — traktujemy jak brak dopasowania.
            $this->respond(200, array('ok' => false, 'error' => 'not_found'));
        }

        // 3) Znajdz zamowienie: reference + email klienta (identyczny wzorzec
        //    weryfikacji tozsamosci co OrderStatus.php w backendzie).
        $idOrder = (int) Db::getInstance()->getValue(
            'SELECT o.id_order
             FROM ' . _DB_PREFIX_ . 'orders o
             JOIN ' . _DB_PREFIX_ . 'customer c ON o.id_customer = c.id_customer
             WHERE o.reference = \'' . pSQL($reference) . '\'
               AND c.email = \'' . pSQL($email) . '\'
             ORDER BY o.id_order DESC'
        );

        if ($idOrder <= 0) {
            // Nie zdradzaj czy bledny byl reference czy email — jeden komunikat.
            $this->respond(200, array('ok' => false, 'error' => 'not_found'));
        }

        // 4) Rate limit — max 1 wysylka / 10 min na to samo zamowienie.
        if ($this->isRateLimited($idOrder)) {
            $this->respond(200, array('ok' => false, 'error' => 'rate_limited'));
        }

        // 5) Zbuduj i wyslij maila.
        $sent = $this->buildAndSend($idOrder, $email);
        if (!$sent) {
            error_log(self::LOG_PREFIX . ' Mail::send zwrocil false (id_order=' . $idOrder . ')');
            $this->respond(200, array('ok' => false, 'error' => 'error'));
        }

        // 6) Zapisz wpis rate-limit (po sukcesie wysylki).
        $this->touchResendLog($idOrder);

        // 7) OK.
        $this->respond(200, array('ok' => true));
    }

    /**
     * HMAC sha256 hex, payload order_reference|email|timestamp, anti-replay ±300s.
     * Analogiczny do ServerHmacVerifier w backendzie (hash_equals + okno czasowe).
     */
    private function verifyHmac($token, $reference, $email, $timestamp, $secret)
    {
        if ($token === '' || $timestamp <= 0) {
            return false;
        }
        if (abs(time() - $timestamp) > self::HMAC_MAX_AGE) {
            return false;
        }
        $payload = $reference . '|' . $email . '|' . $timestamp;
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $token);
    }

    /**
     * True gdy dla tego zamowienia byla wysylka w ostatnich RATE_LIMIT_SECONDS.
     */
    private function isRateLimited($idOrder)
    {
        $lastAdd = Db::getInstance()->getValue(
            'SELECT date_add FROM ' . _DB_PREFIX_ . self::RESEND_LOG_TABLE
            . ' WHERE id_order = ' . (int) $idOrder
        );
        if (!$lastAdd) {
            return false;
        }
        $lastTs = strtotime($lastAdd);
        if ($lastTs === false) {
            return false;
        }
        return (time() - $lastTs) < self::RATE_LIMIT_SECONDS;
    }

    /**
     * Zapis/aktualizacja znacznika czasu wysylki (unique po id_order).
     */
    private function touchResendLog($idOrder)
    {
        $sql = 'INSERT INTO ' . _DB_PREFIX_ . self::RESEND_LOG_TABLE . ' (id_order, date_add) '
             . 'VALUES (' . (int) $idOrder . ', NOW()) '
             . 'ON DUPLICATE KEY UPDATE date_add = NOW()';
        Db::getInstance()->execute($sql);
    }

    /**
     * Pobiera dane zamowienia, buduje HTML+txt i wysyla przez Mail::send
     * z NOWYM szablonem modulu. Zwraca bool z Mail::send.
     */
    private function buildAndSend($idOrder, $email)
    {
        $order = new Order((int) $idOrder);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }

        $customer = new Customer((int) $order->id_customer);
        $currency = new Currency((int) $order->id_currency);
        $idLang   = (int) $order->id_lang;

        // Produkty zamowienia (zrodlo prawdy: pr_order_detail).
        $products = Db::getInstance()->executeS(
            'SELECT product_reference, product_name, product_quantity,
                    unit_price_tax_incl, total_price_tax_incl
             FROM ' . _DB_PREFIX_ . 'order_detail
             WHERE id_order = ' . (int) $idOrder
        );
        if (!is_array($products)) {
            $products = array();
        }

        // Adresy — format IDENTYCZNY z order_conf (_getFormatedAddress w PaymentModule):
        // AddressFormat::generateAddress(addr, avoid=[], '<br />', ' ', styl-pogrubienia).
        $style = array(
            'firstname' => '<span style="font-weight:bold;">%s</span>',
            'lastname'  => '<span style="font-weight:bold;">%s</span>',
        );
        $delivery = new Address((int) $order->id_address_delivery);
        $invoice  = new Address((int) $order->id_address_invoice);
        $deliveryHtml = AddressFormat::generateAddress($delivery, array('avoid' => array()), '<br />', ' ', $style);
        $invoiceHtml  = AddressFormat::generateAddress($invoice, array('avoid' => array()), '<br />', ' ', $style);

        // Przewoznik + numer sledzenia + link (logika linku wzorem OrderStatus.php
        // w backendzie — InPost/DPD/DHL fallback; PHP 7.2 => strpos zamiast str_contains).
        $tracking = $this->getTracking($idOrder);

        // Sumy — te same pola i wzory co order_conf (PaymentModule linie 619-626).
        $taxCalcExc = (Product::getTaxCalculationMethod() == PS_TAX_EXC);
        $totalProductsVal = $taxCalcExc ? $order->total_products : $order->total_products_wt;
        $totalTaxPaid = ($order->total_products_wt - $order->total_products)
                      + ($order->total_shipping_tax_incl - $order->total_shipping_tax_excl);

        $totals = array(
            'products'  => Tools::displayPrice($totalProductsVal, $currency, false),
            'discounts' => Tools::displayPrice($order->total_discounts, $currency, false),
            'wrapping'  => Tools::displayPrice($order->total_wrapping, $currency, false),
            'shipping'  => Tools::displayPrice($order->total_shipping, $currency, false),
            'tax'       => Tools::displayPrice($totalTaxPaid, $currency, false),
            'paid'      => Tools::displayPrice($order->total_paid, $currency, false),
        );

        $html = $this->renderHtml($order, $products, $totals, $tracking, $deliveryHtml, $invoiceHtml);
        $txt  = $this->renderTxt($order, $products, $totals, $tracking);

        $toName = trim($customer->firstname . ' ' . $customer->lastname);

        return Mail::send(
            $idLang,
            'resend_order_info',
            'Informacje o zamówieniu ' . $order->reference,
            array(
                '{email_body}'     => $html,
                '{email_body_txt}' => $txt,
            ),
            $email,          // CEL wysylki = zweryfikowany adres z zamowienia
            $toName,
            null,            // $from -> null = adres nadawcy z PS_SHOP_EMAIL
            'DIVEZONE.PL',   // $fromName — nazwa nadawcy (Karol 2026-07-29: NIE domyslne PS_SHOP_NAME="Divezone")
            null,
            null,
            _PS_MODULE_DIR_ . 'divezone_chat/mails/',
            false,
            (int) $order->id_shop
        );
    }

    /**
     * Przewoznik + tracking. Logika linku skopiowana z OrderStatus.php (backend)
     * — ta sama semantyka fallbacku URL, dostosowana do PHP 7.2.
     * Zwraca array|null: name, number, url(|null).
     */
    private function getTracking($idOrder)
    {
        $row = Db::getInstance()->getRow(
            'SELECT oc.tracking_number, c.name AS carrier_name, c.url AS tracking_url
             FROM ' . _DB_PREFIX_ . 'order_carrier oc
             JOIN ' . _DB_PREFIX_ . 'carrier c ON oc.id_carrier = c.id_carrier
             WHERE oc.id_order = ' . (int) $idOrder . '
             ORDER BY oc.date_add DESC'
        );
        if (!$row || empty($row['tracking_number'])) {
            return null;
        }
        $number = $row['tracking_number'];
        $trackingUrl = null;
        if (!empty($row['tracking_url'])) {
            $trackingUrl = str_replace('@', $number, $row['tracking_url']);
        }
        // Fallback: URL pusty lub bez znacznika '@' (nie podmienil sie).
        if (empty($trackingUrl) || $trackingUrl === $row['tracking_url']) {
            $carrierLower = Tools::strtolower(isset($row['carrier_name']) ? $row['carrier_name'] : '');
            if (strpos($carrierLower, 'inpost') !== false || strpos($carrierLower, 'paczkomat') !== false) {
                $trackingUrl = 'https://inpost.pl/sledzenie-przesylek?number=' . urlencode($number);
            } elseif (strpos($carrierLower, 'dpd') !== false) {
                $trackingUrl = 'https://tracktrace.dpd.com.pl/parcelDetails?typ=1&p1=' . urlencode($number);
            } elseif (strpos($carrierLower, 'dhl') !== false) {
                $trackingUrl = 'https://www.dhl.com/pl-pl/home/tracking.html?tracking-id=' . urlencode($number);
            } else {
                $trackingUrl = null;
            }
        }
        return array(
            'name'   => isset($row['carrier_name']) ? $row['carrier_name'] : '',
            'number' => $number,
            'url'    => $trackingUrl,
        );
    }

    /**
     * Buduje pelny HTML maila (prosty, inline style — klienci pocztowi).
     * Kolejnosc wg KROK A2 taska CHAT-T-180.
     */
    private function renderHtml($order, array $products, array $totals, $tracking, $deliveryHtml, $invoiceHtml)
    {
        $e = 'htmlspecialchars'; // skrot dla escapowania wartosci tekstowych
        $ref  = $e($order->reference, ENT_QUOTES, 'UTF-8');
        $date = $e(Tools::displayDate($order->date_add), ENT_QUOTES, 'UTF-8');
        $payment = $e((string) $order->payment, ENT_QUOTES, 'UTF-8');

        $font = 'font-family:Arial,Helvetica,sans-serif;font-size:16px;color:#222;line-height:1.5;';

        $h = '<div style="max-width:640px;margin:0 auto;padding:16px;' . $font . '">';

        // 1) Baner.
        $h .= '<div style="background:#eaf4fb;border:1px solid #b6dcf3;border-radius:6px;'
            . 'padding:14px 16px;margin-bottom:20px;font-size:15px;">'
            . 'Ten e-mail zawiera informacje dotyczące Twojego zamówienia i został wysłany na Twoje życzenie.'
            . '</div>';

        // 2) Naglowek.
        $h .= '<h2 style="margin:0 0 4px 0;font-size:20px;">Zamówienie ' . $ref . '</h2>';
        $h .= '<p style="margin:0 0 18px 0;color:#555;">Data złożenia: ' . $date . '</p>';

        // 3) Platnosc.
        $h .= '<p style="margin:0 0 18px 0;"><strong>Metoda płatności:</strong> ' . $payment . '</p>';

        // 4) Tabela produktow.
        $h .= '<table style="width:100%;border-collapse:collapse;margin-bottom:18px;font-size:15px;">';
        $h .= '<thead><tr style="background:#f2f2f2;">'
            . '<th style="text-align:left;padding:8px;border-bottom:2px solid #ddd;"><strong>Indeks</strong></th>'
            . '<th style="text-align:left;padding:8px;border-bottom:2px solid #ddd;"><strong>Produkt</strong></th>'
            . '<th style="text-align:right;padding:8px;border-bottom:2px solid #ddd;"><strong>Cena jedn.</strong></th>'
            . '<th style="text-align:center;padding:8px;border-bottom:2px solid #ddd;"><strong>Ilość</strong></th>'
            . '<th style="text-align:right;padding:8px;border-bottom:2px solid #ddd;"><strong>Cena końcowa</strong></th>'
            . '</tr></thead><tbody>';
        foreach ($products as $p) {
            $pref  = $e((string) $p['product_reference'], ENT_QUOTES, 'UTF-8');
            $pname = $e((string) $p['product_name'], ENT_QUOTES, 'UTF-8');
            $qty   = (int) $p['product_quantity'];
            $unit  = Tools::displayPrice($p['unit_price_tax_incl'], new Currency((int) $order->id_currency), false);
            $tot   = Tools::displayPrice($p['total_price_tax_incl'], new Currency((int) $order->id_currency), false);
            $h .= '<tr>'
                . '<td style="padding:8px;border-bottom:1px solid #eee;">' . $pref . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #eee;">' . $pname . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . $unit . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #eee;text-align:center;">' . $qty . '</td>'
                . '<td style="padding:8px;border-bottom:1px solid #eee;text-align:right;">' . $tot . '</td>'
                . '</tr>';
        }
        $h .= '</tbody></table>';

        // 5) Podsumowanie.
        $h .= '<table style="width:100%;max-width:340px;margin:0 0 18px auto;border-collapse:collapse;font-size:15px;">';
        $h .= $this->summaryRow('Produkty', $totals['products'], false);
        $h .= $this->summaryRow('Rabaty', $totals['discounts'], false);
        $h .= $this->summaryRow('Pakowanie prezentowe', $totals['wrapping'], false);
        $h .= $this->summaryRow('Wysyłka', $totals['shipping'], false);
        $h .= $this->summaryRow('Z podatkiem', $totals['tax'], false);
        $h .= $this->summaryRow('Zapłacono w sumie', $totals['paid'], true);
        $h .= '</table>';

        // 6) Przewoznik.
        if ($tracking !== null) {
            $cname = $e((string) $tracking['name'], ENT_QUOTES, 'UTF-8');
            $cnum  = $e((string) $tracking['number'], ENT_QUOTES, 'UTF-8');
            $h .= '<p style="margin:0 0 18px 0;"><strong>Przewoźnik:</strong> ' . $cname
                . '<br><strong>Numer śledzenia:</strong> ' . $cnum;
            if (!empty($tracking['url'])) {
                $url = $e((string) $tracking['url'], ENT_QUOTES, 'UTF-8');
                $h .= '<br><a href="' . $url . '" style="color:#1a73e8;">Śledź przesyłkę</a>';
            }
            $h .= '</p>';
        }

        // 7) Adresy.
        $h .= '<div style="display:block;margin-bottom:18px;">';
        $h .= '<div style="margin-bottom:12px;"><strong>Adres dostawy</strong><br>'
            . $deliveryHtml . '</div>';
        $h .= '<div><strong>Adres do faktury</strong><br>'
            . $invoiceHtml . '</div>';
        $h .= '</div>';

        // 8) Stopka firmowa.
        $h .= '<hr style="border:none;border-top:1px solid #ddd;margin:22px 0 14px 0;">';
        $h .= '<div style="font-size:13px;color:#666;line-height:1.6;">'
            . '<strong>' . self::COMPANY_NAME . '</strong><br>'
            . self::COMPANY_ADDR . '<br>'
            . self::COMPANY_NIP . '<br>'
            . self::COMPANY_PHONE
            . '</div>';

        $h .= '</div>';
        return $h;
    }

    private function summaryRow($label, $value, $bold)
    {
        $labelCell = $bold ? '<strong>' . $label . '</strong>' : $label;
        $valueCell = $bold ? '<strong>' . $value . '</strong>' : $value;
        $border = $bold ? 'border-top:2px solid #ccc;' : 'border-top:1px solid #eee;';
        return '<tr>'
            . '<td style="padding:6px 8px;' . $border . '">' . $labelCell . '</td>'
            . '<td style="padding:6px 8px;text-align:right;' . $border . '">' . $valueCell . '</td>'
            . '</tr>';
    }

    /**
     * Wersja tekstowa (PS_MAIL_TYPE=BOTH wymaga .txt). Prosta, bez HTML.
     */
    private function renderTxt($order, array $products, array $totals, $tracking)
    {
        $lines = array();
        $lines[] = 'Ten e-mail zawiera informacje dotyczące Twojego zamówienia i został wysłany na Twoje życzenie.';
        $lines[] = '';
        $lines[] = 'Zamówienie ' . $order->reference;
        $lines[] = 'Data złożenia: ' . Tools::displayDate($order->date_add);
        $lines[] = 'Metoda płatności: ' . $order->payment;
        $lines[] = '';
        $lines[] = 'Produkty:';
        $cur = new Currency((int) $order->id_currency);
        foreach ($products as $p) {
            $lines[] = '- [' . $p['product_reference'] . '] ' . $p['product_name']
                . ' x' . (int) $p['product_quantity']
                . ' = ' . Tools::displayPrice($p['total_price_tax_incl'], $cur, false);
        }
        $lines[] = '';
        $lines[] = 'Produkty: ' . strip_tags($totals['products']);
        $lines[] = 'Rabaty: ' . strip_tags($totals['discounts']);
        $lines[] = 'Pakowanie prezentowe: ' . strip_tags($totals['wrapping']);
        $lines[] = 'Wysyłka: ' . strip_tags($totals['shipping']);
        $lines[] = 'Z podatkiem: ' . strip_tags($totals['tax']);
        $lines[] = 'Zapłacono w sumie: ' . strip_tags($totals['paid']);
        if ($tracking !== null) {
            $lines[] = '';
            $lines[] = 'Przewoźnik: ' . $tracking['name'];
            $lines[] = 'Numer śledzenia: ' . $tracking['number'];
            if (!empty($tracking['url'])) {
                $lines[] = 'Śledzenie: ' . $tracking['url'];
            }
        }
        $lines[] = '';
        $lines[] = '---';
        $lines[] = self::COMPANY_NAME;
        $lines[] = self::COMPANY_ADDR;
        $lines[] = self::COMPANY_NIP;
        $lines[] = self::COMPANY_PHONE;
        return implode("\n", $lines);
    }
}
