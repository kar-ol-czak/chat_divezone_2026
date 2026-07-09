<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;

/**
 * Warianty produktu (kolor × rozmiar) z MySQL PrestaShop (read-only).
 * CHAT-T-129 / ADR-112. Powstało po fabrykacji bota o "automatycznej zmianie wariantu"
 * (czat 606: klient chciał żółte płetwy Mares Avanti Superchannel, w koszyku dostał
 * niebieskie = domyślny wariant default_on). Dane były w bazie — narzędzie ich nie czytało.
 *
 * Osobne narzędzie, NIE rozszerzenie get_product_details (rozdzielenie odpowiedzialności;
 * get_product_details obiecywał "warianty", których nie dostarczał — współprzyczyna buga).
 *
 * Reguły (decyzje Karola, ADR-112):
 *  - filtr 51b: wariant zwracany gdy quantity > 0 LUB reference niepusta
 *    (pusta reference = wariant niehandlowy → odfiltruj; quantity bywa ujemne, nigdy != 0);
 *  - nazwa koloru: nazwa_czytelna != kod_ps → nazwa; równa/NULL → nieznany_kolor=true
 *    (83% kolorów ma nazwa_czytelna = kod PS — bot NIE zgaduje);
 *  - BEZ hex_1 (zweryfikowany błąd danych: RYL = #D32F2F = czerwony, faktycznie żółty);
 *  - miniatura tylko z id_image wariantu; brak → image_url=null, ŻADNEGO fallbacku na
 *    zdjęcie główne (pokazałoby domyślny kolor = ten sam bug).
 */
final class ProductCombinations implements ToolInterface
{
    private const LANG_ID = 1; // polski

    public function __construct(
        private readonly MysqlConnection $db,
    ) {}

    public function getName(): string
    {
        return 'get_product_combinations';
    }

    public function getDescription(): string
    {
        return 'Zwraca dostępne warianty produktu (kolory, rozmiary) z dostępnością i informacją, '
             . 'który wariant jest domyślny. Używaj ZAWSZE gdy klient pyta o kolor, rozmiar, '
             . 'dostępność konkretnego wariantu, albo gdy zgłasza że w koszyku ma inny wariant '
             . 'niż zamierzał. NIE zgaduj kolorów ani rozmiarów bez wywołania tego narzędzia.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'ID produktu z PrestaShop (ps_product_id z wyników search_products)',
                ],
            ],
            'required' => ['product_id'],
        ];
    }

    public function execute(array $params): array
    {
        $productId = (int) ($params['product_id'] ?? 0);
        if ($productId <= 0) {
            return ['error' => 'Nieprawidłowe product_id'];
        }

        // Jeden wiersz na (id_pa × atrybut) — kombinacja ma zwykle KOLOR + ROZMIAR,
        // więc na id_pa przypada kilka wierszy (grupowane w PHP).
        $rows = $this->db->fetchAll(
            'SELECT
                pa.id_product_attribute        AS id_pa,
                pa.reference,
                pa.default_on,
                COALESCE(sa.quantity, 0)       AS quantity,
                agl.name                       AS grupa,
                al.name                        AS kod_ps,
                c.nazwa_czytelna,
                c.id_attribute                 AS color_match,
                pai.id_image
             FROM pr_product_attribute pa
             JOIN pr_product_attribute_combination pac ON pac.id_product_attribute = pa.id_product_attribute
             JOIN pr_attribute a          ON a.id_attribute = pac.id_attribute
             JOIN pr_attribute_lang al    ON al.id_attribute = a.id_attribute AND al.id_lang = ?
             JOIN pr_attribute_group_lang agl ON agl.id_attribute_group = a.id_attribute_group AND agl.id_lang = ?
             LEFT JOIN pr_stock_available sa ON sa.id_product_attribute = pa.id_product_attribute
                                            AND sa.id_product = pa.id_product
             LEFT JOIN divezone_attr_color c ON c.id_attribute = a.id_attribute
             LEFT JOIN pr_product_attribute_image pai ON pai.id_product_attribute = pa.id_product_attribute
             WHERE pa.id_product = ?
             ORDER BY pa.id_product_attribute',
            [self::LANG_ID, self::LANG_ID, $productId],
        );

        if (!$rows) {
            return [
                'product_id' => $productId,
                'warianty' => [],
                'komunikat' => 'Produkt nie ma wariantów.',
            ];
        }

        // Grupowanie po id_pa: sklejamy atrybuty wariantu, wykrywamy kolor.
        $byPa = [];
        $grupy = []; // nazwy grup atrybutów obecnych w produkcie (kolejność wystąpienia)
        foreach ($rows as $r) {
            $pa = (int) $r['id_pa'];
            if (!isset($byPa[$pa])) {
                $byPa[$pa] = [
                    'id_pa' => $pa,
                    'reference' => (string) ($r['reference'] ?? ''),
                    'default_on' => (int) ($r['default_on'] ?? 0) === 1,
                    'quantity' => (int) ($r['quantity'] ?? 0),
                    'id_image' => $r['id_image'] !== null ? (int) $r['id_image'] : null,
                    'atrybuty' => [],
                    'kolor' => null,
                    'kolor_grupa' => null,
                ];
            }

            $grupa = (string) ($r['grupa'] ?? '');
            $kod = (string) ($r['kod_ps'] ?? '');
            if ($grupa !== '') {
                $byPa[$pa]['atrybuty'][$grupa] = $kod;
                if (!in_array($grupa, $grupy, true)) {
                    $grupy[] = $grupa;
                }
            }

            // Atrybut jest kolorem, gdy ma dopasowanie w divezone_attr_color.
            if ($r['color_match'] !== null) {
                $czytelna = $r['nazwa_czytelna'];
                $nazwa = ($czytelna !== null && $czytelna !== '' && $czytelna !== $kod)
                    ? (string) $czytelna
                    : null;
                $byPa[$pa]['kolor'] = [
                    'kod' => $kod,
                    'nazwa' => $nazwa,
                    'nieznany_kolor' => $nazwa === null,
                ];
                $byPa[$pa]['kolor_grupa'] = $grupa;
            }
        }

        // Filtr 51b + budowa kontraktu.
        $warianty = [];
        $domyslny = null;
        $koloryDostepne = [];
        foreach ($byPa as $v) {
            $qty = $v['quantity'];
            $ref = $v['reference'];
            if (!($qty > 0 || $ref !== '')) {
                continue; // wariant niehandlowy — pusta reference i brak stanu
            }

            $dostepny = $qty > 0;
            $imageUrl = $this->buildImageUrl($v['id_image']);

            // rozmiar = wartość pierwszej grupy innej niż kolor (może być null).
            $rozmiar = null;
            foreach ($v['atrybuty'] as $g => $val) {
                if ($g !== $v['kolor_grupa']) {
                    $rozmiar = $val;
                    break;
                }
            }

            $warianty[] = [
                'id_pa' => $v['id_pa'],
                'reference' => $ref,
                'atrybuty' => $v['atrybuty'],
                'kolor' => $v['kolor'],
                'dostepny' => $dostepny,
                'quantity' => $qty,
                'na_zamowienie' => $qty <= 0 && $ref !== '',
                'image_url' => $imageUrl,
            ];

            if ($v['default_on']) {
                $domyslny = [
                    'id_pa' => $v['id_pa'],
                    'kolor' => $v['kolor'],
                    'rozmiar' => $rozmiar,
                ];
            }

            if ($dostepny && $v['kolor'] !== null) {
                $koloryDostepne[$v['kolor']['kod']] = true;
            }
        }

        return [
            'product_id' => $productId,
            'grupy' => $grupy,
            'domyslny_wariant' => $domyslny,
            'warianty' => $warianty,
            'podsumowanie' => [
                'kolorow_dostepnych' => count($koloryDostepne),
                'wariantow_ogolem' => count($warianty),
            ],
        ];
    }

    /**
     * Deterministyczny URL miniatury wariantu (cart_default 125×125).
     * id_image=9192 → https://divezone.pl/img/p/9/1/9/2/9192-cart_default.jpg
     * (każda cyfra id_image jako osobny katalog). Null → null (bez fallbacku).
     */
    private function buildImageUrl(?int $idImage): ?string
    {
        if ($idImage === null || $idImage <= 0) {
            return null;
        }

        $digits = implode('/', str_split((string) $idImage));

        return "https://divezone.pl/img/p/{$digits}/{$idImage}-cart_default.jpg";
    }
}
