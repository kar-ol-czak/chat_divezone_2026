<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\MysqlConnection;

/**
 * Warianty (kombinacje kolor/rozmiar) produktu — READ-ONLY.
 * ATTR-T-052 / ADR-025. Kontrakt wyznaczony przez SystemPrompt.php:463-478
 * (pola `nieznany_kolor`, `domyslny_wariant`) — narzędzie go SPEŁNIA, nie zmienia.
 *
 * Źródło nazw kolorów: divezone_attr_color_lang.nazwa_wypowiadalna (ADR-019),
 * wypełnione potokiem T-047…051 (290 nazwanych, 9 nieznanych dla id_lang=1).
 * Wcześniej żadne narzędzie nie sięgało po te dane — bot zgadywał kolory z opisu.
 *
 * Bug do naprawienia (czat 606 / CHAT-T-129): Mares Avanti Superchannel (id_product=3322),
 * klient chciał żółte (RYL), w koszyku niebieskie (RBL, default_on=1). Narzędzie pokazuje
 * kolory po polsku + który wariant jest domyślny → bot wyjaśnia zamiast zmyślać.
 *
 * UWAGA implementacyjna (recon T-052, rozjazd R-3): kombinacja może mieć DOWOLNĄ liczbę
 * atrybutów z DOWOLNYCH grup (nie tylko 23 KOLOR + 27 ROZMIAR — sklep używa >23 grup:
 * SZKŁO LEWE/PRAWE 34/35, WYPORNOŚĆ 25, PŁYTA 26, ROZMIAR MĘSKI/DAMSKI 29/30 itd.).
 * Pola skalarne `kolor`/`kod_koloru`/`rozmiar` liczymy agregacją MAX(CASE WHEN
 * a.id_attribute_group = ...) — deterministyczną, gwarantującą, że atrybut spoza grupy 23
 * nie trafi do pola `kolor`. Wszystkie pozostałe cechy zwraca generyczne pole `atrybuty`
 * (tablica par grupa→wartość), zbierane przez GROUP_CONCAT.
 *
 * CHAT-T-168 / ADR-135: wcześniejszy INNER JOIN filtrował atrybuty do grup (23, 27) — przez to
 * produkty z wariantami wyłącznie w innych grupach (np. szkła korekcyjne 6573/6577/6993/6994
 * w grupach 34/35) wyglądały na bezwariantowe (liczba_wariantow=0), choć w bazie miały 8-23
 * kombinacje (czat 829). Filtr zdjęty; grupy 23/27 służą teraz WYŁĄCZNIE mapowaniu na pola
 * kontraktowe, nie do odrzucania kombinacji.
 *
 * Separatory GROUP_CONCAT to znaki sterujące 0x1f (para grupa|wartość) i 0x1e (między parami),
 * NIE przecinek — wartości atrybutów zawierają przecinki (moc szkła "+3,5"), więc domyślny
 * separator "," rozbiłby parsowanie.
 */
final class GetProductCombinations implements ToolInterface
{
    /** Grupa atrybutów PrestaShop: kolor. */
    private const GROUP_COLOR = 23;

    /** Grupa atrybutów PrestaShop: rozmiar. */
    private const GROUP_SIZE = 27;

    /** Bot mówi po polsku — id_lang=1 na sztywno (R2: JOIN po id_lang nie może zgubić PL). */
    private const ID_LANG = 1;

    public function __construct(
        private readonly MysqlConnection $db,
    ) {}

    public function getName(): string
    {
        return 'get_product_combinations';
    }

    public function getDescription(): string
    {
        return 'Zwraca warianty (kombinacje) konkretnego produktu: kolor po polsku, kod koloru, '
             . 'rozmiar, dostępność (stan magazynowy) i który wariant jest domyślny. '
             . 'Pole atrybuty zawiera WSZYSTKIE cechy wariantu, także spoza koloru i rozmiaru '
             . '(moc szkła korekcyjnego, wyporność, płyta, rozmiar męski/damski, kieszenie balastowe) — '
             . 'tablica par {grupa, wartosc}. '
             . 'Wołaj gdy klient pyta o kolor, rozmiar, moc, dostępność lub jakikolwiek inny wariant, '
             . 'albo gdy zgłasza, że w koszyku ma inny wariant niż chciał. NIGDY nie mów "w opisie nie ma '
             . 'informacji o kolorach/wariantach" bez wywołania tego narzędzia. '
             . 'Gdy kolor i rozmiar są puste, a atrybuty nie — opisz wariant przez atrybuty, NIE mów '
             . '"produkt nie ma wariantów". '
             . 'Gdy wariant ma nieznany_kolor=true — NIE nazywaj koloru; opisz go kodem (kod_koloru) '
             . 'i rozmiarem. Pole domyslny_wariant=true wskazuje wariant, który trafia do koszyka, '
             . 'gdy klient nie wybierze wariantu przy dodawaniu.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'id_product PrestaShop — produkt, którego warianty chcesz sprawdzić.',
                ],
            ],
            'required' => ['product_id'],
        ];
    }

    public function execute(array $params): array
    {
        $productId = isset($params['product_id']) ? (int) $params['product_id'] : 0;
        if ($productId <= 0) {
            return ['error' => 'Brak poprawnego product_id (dodatnia liczba całkowita).'];
        }

        // Stałe (grupy 23/27, id_lang=1) wstrzykiwane jako literały %d — NIE dane użytkownika.
        // Jedyny parametr użytkownika (product_id) idzie przez pozycyjny ? (bind), jak w SizeRecommender.
        // Powód pozycyjnego ?: MysqlConnection ma PDO::ATTR_EMULATE_PREPARES=false — natywne
        // prepared statements nie pozwalają powtórzyć nazwanego placeholdera.
        $sql = sprintf(
            <<<'SQL'
            SELECT
                pa.id_product_attribute AS id_product_attribute,
                pa.reference            AS reference,
                pa.default_on           AS default_on,
                MAX(CASE WHEN a.id_attribute_group = %1$d AND cl.pewnosc <> 'nieznany'
                         THEN cl.nazwa_wypowiadalna END) AS kolor,
                MAX(CASE WHEN a.id_attribute_group = %1$d THEN al.name END) AS kod_koloru,
                MAX(CASE WHEN a.id_attribute_group = %2$d THEN al.name END) AS rozmiar,
                sa.quantity             AS dostepnosc,
                GROUP_CONCAT(
                    CONCAT(COALESCE(agl.public_name, ''), 0x1f, COALESCE(al.name, ''))
                    ORDER BY a.id_attribute_group, a.id_attribute
                    SEPARATOR 0x1e
                )                       AS atrybuty_raw
            FROM pr_product_attribute pa
            JOIN pr_product_attribute_combination pac
                ON pac.id_product_attribute = pa.id_product_attribute
            JOIN pr_attribute a
                ON a.id_attribute = pac.id_attribute
            LEFT JOIN pr_attribute_group_lang agl
                ON agl.id_attribute_group = a.id_attribute_group
               AND agl.id_lang = %3$d
            LEFT JOIN pr_attribute_lang al
                ON al.id_attribute = a.id_attribute
               AND al.id_lang = %3$d
            LEFT JOIN divezone_attr_color_lang cl
                ON cl.id_attribute = a.id_attribute
               AND cl.id_lang = %3$d
            LEFT JOIN pr_stock_available sa
                ON sa.id_product_attribute = pa.id_product_attribute
               AND sa.id_product = pa.id_product
            WHERE pa.id_product = ?
            GROUP BY pa.id_product_attribute, pa.reference, pa.default_on, sa.quantity
            ORDER BY pa.id_product_attribute
            SQL,
            self::GROUP_COLOR,
            self::GROUP_SIZE,
            self::ID_LANG,
        );

        $rows = $this->db->fetchAll($sql, [$productId]);

        $warianty = [];
        foreach ($rows as $row) {
            // R1: `kolor` MUSI być null (nie ""), gdy nazwa nieznana — prompt rozróżnia te przypadki.
            // SQL zwraca NULL wprost (guard pewnosc<>'nieznany'), więc nie rzutujemy na string.
            $kolor = $row['kolor'] !== null ? (string) $row['kolor'] : null;

            $warianty[] = [
                'id_product_attribute' => (int) $row['id_product_attribute'],
                'kolor'                => $kolor,
                // nieznany_kolor = brak nazwy wypowiadalnej (⟺ pewnosc='nieznany', zweryfikowane 9/9).
                'nieznany_kolor'       => $kolor === null,
                // Fallback dla nieznanego koloru: kod producencki z pr_attribute_lang (np. "RYL").
                'kod_koloru'           => $row['kod_koloru'] !== null ? (string) $row['kod_koloru'] : null,
                'rozmiar'              => $row['rozmiar'] !== null ? (string) $row['rozmiar'] : null,
                'dostepnosc'           => (int) ($row['dostepnosc'] ?? 0),
                'domyslny_wariant'     => !empty($row['default_on']),
                // Klucz Subiekt/SLINK — TYLKO odczyt (HARD STOP nr 1).
                'reference'            => $row['reference'] !== null ? (string) $row['reference'] : null,
                // Wszystkie cechy wariantu (ADR-135), także spoza kolor/rozmiar — para grupa→wartość.
                // Kolejność deterministyczna: wg id_attribute_group rosnąco (ORDER BY w GROUP_CONCAT).
                'atrybuty'             => $this->parseAtrybuty($row['atrybuty_raw'] ?? null),
            ];
        }

        // Produkt bez wariantów → pusta lista, nie błąd (kryt. 11).
        return [
            'product_id'       => $productId,
            'liczba_wariantow' => count($warianty),
            'warianty'         => $warianty,
        ];
    }

    /**
     * Rozbija GROUP_CONCAT z execute() na tablicę par grupa→wartość.
     * Format: pary rozdzielone 0x1e, wewnątrz para "grupa" 0x1f "wartosc".
     * Puste wartości (brak nazwy grupy lub wartości) pomijamy — atrybut bez treści
     * jest bezużyteczny dla bota. Kolejność zachowana z SQL (id_attribute_group rosnąco).
     */
    private function parseAtrybuty(?string $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        $atrybuty = [];
        foreach (explode("\x1e", $raw) as $para) {
            $czesci = explode("\x1f", $para, 2);
            $grupa  = trim($czesci[0] ?? '');
            $wartosc = trim($czesci[1] ?? '');
            if ($grupa === '' || $wartosc === '') {
                continue;
            }
            $atrybuty[] = ['grupa' => $grupa, 'wartosc' => $wartosc];
        }

        return $atrybuty;
    }
}
