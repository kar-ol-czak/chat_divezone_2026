<?php
// CHAT-T-127 purge_litespeed.php (ADR-111)
//
// Selektywny purge LSCache po tagach — zastępuje pełny flush (flush_all_litespeed.php ZOSTAJE jako fallback "spal wszystko").
// Plik leży w root sklepu (newtmp2), więc realpath(__DIR__) == _PS_ROOT_DIR_ liczone przez plugin LiteSpeed.
// Bez bootstrapu config.inc.php — lekki skrypt.
//
// Źródło prawdy: kod pluginu modules/litespeedcache/classes/ (Cache.php, Config.php, Helper.php).
//   - nagłówek purge:  X-Litespeed-Purge2  (Cache.php::LSHEADER_PURGE)
//   - składnia:        tag=<PREFIX>_<TAG>,tag=<PREFIX>_<TAG>   (getPurgeHeader())
//   - prefiks instalacji: 'PS'.substr(md5(_PS_ROOT_DIR_),0,5)  (Helper.php::initInternals())
//   - prefiksy typów (Config.php): produkt P, kategoria C, marka M, dostawca L, CMS G, sklep S; home H, search SR
//
// Wejście (GET), typy łączone w jednym wywołaniu:
//   ?product=ID[,ID...]      → tag P<ID>
//   ?category=ID[,ID...]     → tag C<ID>
//   ?manufacturer=ID[,ID...] → tag M<ID>
//   ?supplier=ID[,ID...]     → tag L<ID>
//   ?cms=ID[,ID...]          → tag G<ID>
//   ?home=1                  → tag H
//   ?search=1                → tag SR
//   ?tag=<goły>[,<goły>...]  → dowolny surowy tag (whitelist ^[A-Za-z0-9]{1,20}$)
//   ?all=1                   → awaryjny pełny flush "*" (NIE domyślny)
//   ?prefix=PSxxxxx          → override prefiksu (walidacja ^PS[0-9a-f]{5}$), domyślnie liczony runtime
// Zabezpieczenie: ?k=<KLUCZ> + hash_equals. NOWY klucz — NIE kopiować starego z flush_all.

// ── KLUCZ DOSTĘPU (NOWY, nie z flush_all_litespeed.php) ─────────────────────────
// TODO(Karol): ustaw własny sekret przed deployem do root newtmp2.
const PURGE_KEY = 'ZMIEN_MNIE_CHAT_T_127';

header('Content-Type: text/plain; charset=utf-8');

// ── Autoryzacja ────────────────────────────────────────────────────────────────
$providedKey = isset($_GET['k']) && is_string($_GET['k']) ? $_GET['k'] : '';
if (!hash_equals(PURGE_KEY, $providedKey)) {
    http_response_code(403);
    echo "403 Forbidden: zły lub brak klucza (?k=).\n";
    exit;
}

// ── Prefiks instalacji ───────────────────────────────────────────────────────────
// Domyślnie liczony runtime z fizycznej lokalizacji pliku (root sklepu == _PS_ROOT_DIR_).
$prefix = 'PS' . substr(md5(realpath(__DIR__)), 0, 5);
if (isset($_GET['prefix']) && is_string($_GET['prefix']) && $_GET['prefix'] !== '') {
    $override = $_GET['prefix'];
    if (!preg_match('/^PS[0-9a-f]{5}$/', $override)) {
        http_response_code(400);
        echo "400 Bad Request: zły format ?prefix= (oczekiwano ^PS[0-9a-f]{5}$).\n";
        exit;
    }
    $prefix = $override;
}

/**
 * Zwraca listę dodatnich int-ów z wartości GET typu "ID" lub "ID,ID,...".
 * Odrzuca puste, nie-numeryczne i <=0. Duplikaty usuwane.
 *
 * @return int[]
 */
function parseIdList($raw): array
{
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $out = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '' || !ctype_digit($part)) {
            continue; // twarda walidacja: tylko czyste cyfry
        }
        $id = (int) $part;
        if ($id > 0) {
            $out[$id] = true;
        }
    }
    return array_keys($out);
}

// ── Awaryjny pełny flush ─────────────────────────────────────────────────────────
if (isset($_GET['all']) && $_GET['all'] === '1') {
    $header = 'X-Litespeed-Purge2: *';
    header($header);
    echo "OK: pełny flush (awaryjny).\n";
    echo "Nagłówek: {$header}\n";
    exit;
}

// ── Budowanie tagów ──────────────────────────────────────────────────────────────
$tags = [];

// Typy z listą ID: klucz GET → prefiks typu wg Config.php
$idTypes = [
    'product'      => 'P',
    'category'     => 'C',
    'manufacturer' => 'M',
    'supplier'     => 'L',
    'cms'          => 'G',
];
foreach ($idTypes as $param => $typePrefix) {
    if (!isset($_GET[$param])) {
        continue;
    }
    foreach (parseIdList($_GET[$param]) as $id) {
        $tags[] = $typePrefix . $id;
    }
}

// Tagi specjalne bez ID
if (isset($_GET['home']) && $_GET['home'] === '1') {
    $tags[] = 'H';
}
if (isset($_GET['search']) && $_GET['search'] === '1') {
    $tags[] = 'SR';
}

// Surowe tagi ?tag= — whitelist ^[A-Za-z0-9]{1,20}$
if (isset($_GET['tag']) && is_string($_GET['tag']) && $_GET['tag'] !== '') {
    foreach (explode(',', $_GET['tag']) as $rawTag) {
        $rawTag = trim($rawTag);
        if ($rawTag !== '' && preg_match('/^[A-Za-z0-9]{1,20}$/', $rawTag)) {
            $tags[] = $rawTag;
        }
        // złe znaki (np. "zły@znak") → cicho pomijane, nie trafiają do nagłówka
    }
}

// Deduplikacja przy zachowaniu kolejności
$tags = array_values(array_unique($tags));

// ── Zero poprawnych tagów i brak ?all=1 → 400, NIC nie czyścimy ──────────────────
if (empty($tags)) {
    http_response_code(400);
    echo "400 Bad Request: brak poprawnych tagów do purge — nic nie wysłano.\n";
    echo "Użycie: ?k=KLUCZ&product=123,124&manufacturer=7 | &home=1 | &search=1 | &tag=SR | &all=1\n";
    exit;
}

// ── Składanie nagłówka: tag=<PREFIX>_<TAG>,tag=<PREFIX>_<TAG> ──────────────────────
$parts = [];
foreach ($tags as $tag) {
    $parts[] = 'tag=' . $prefix . '_' . $tag;
}
$value = implode(',', $parts);

// Ostatnia zapora: finalna wartość tylko ze znaków [A-Za-z0-9_,=]
if (!preg_match('/^[A-Za-z0-9_,=]+$/', $value)) {
    http_response_code(400);
    echo "400 Bad Request: nagłówek zawiera niedozwolone znaki — przerwano.\n";
    exit;
}

$header = 'X-Litespeed-Purge2: ' . $value;
header($header);

echo "OK: purge " . count($tags) . " tag(ów).\n";
echo "Prefiks: {$prefix}\n";
echo "Nagłówek: {$header}\n";
