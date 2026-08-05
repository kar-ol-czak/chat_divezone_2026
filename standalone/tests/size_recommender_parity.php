<?php

declare(strict_types=1);

/**
 * CHAT-T-100 / CHAT-T-103 — parytet SizeRecommender (PHP) z size_matcher.py.
 * CHAT-T-103: źródło danych = MySQL PrestaShop (divezone_attr_*), nie Railway/PG.
 * Wartości oczekiwane są identyczne jak przy PG (parytet danych 100%, ATTR-T-001) —
 * ten sam zestaw asercji co dowód PG = dowód parytetu MySQL vs PG.
 *
 * Uruchom NA SERWERZE (MySQL = localhost): php tests/size_recommender_parity.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DiveChat\Config;
use DiveChat\Database\MysqlConnection;
use DiveChat\Tools\SizeRecommender;

// Na serwerze Config::load czyta .env (DB_HOST/PORT/NAME_PROD/USER/PASSWORD = reader).
Config::load(dirname(__DIR__));

$ok = true;
$db = MysqlConnection::getInstance();
$tool = new SizeRecommender($db);

$check = function (string $desc, array $params, string $expDecision, ?array $expContains, ?bool $expGran = null) use ($tool, &$ok): void {
    $res = $tool->execute($params);
    $dec = $res['decision'] ?? ('ERROR:' . ($res['error'] ?? '?'));
    $sizes = $res['sizes'] ?? [];
    $good = ($dec === $expDecision);
    foreach ($expContains ?? [] as $want) {
        if (!in_array($want, $sizes, true)) {
            $good = false;
        }
    }
    if ($expGran !== null) {
        $good = $good && (($res['graniczny'] ?? null) === $expGran);
    }
    $ok = $ok && $good;
    $extra = isset($res['graniczny']) ? ' graniczny=' . var_export($res['graniczny'], true) : '';
    $full = isset($res['size_full']) ? ' full=' . json_encode($res['size_full'], JSON_UNESCAPED_UNICODE) : '';
    echo '  [' . ($good ? 'OK ' : 'FAIL') . "] {$desc}: -> {$dec} "
        . json_encode($sizes, JSON_UNESCAPED_UNICODE) . $extra . $full . "\n";
};

echo "PARYTET SizeRecommender (PHP) vs size_matcher.py — żywe dane MySQL (divezone_attr_*):\n";

// Dorośli (przedziałowy). Parytet z self-test PY + acceptance z handoffu.
// ATTR-T-080 G1 (CHAT-T-165 §3.3): fallback po marce wymaga OBOWIĄZKOWO `category`, inaczej
// resolveChart() zwraca error (marka ma tabele w wielu kategoriach). Dodano category='skafander';
// oczekiwane wyniki BEZ zmian — to te same charty (Scubapro/Bare skafander), które test celował.
$check('Scubapro M chest 104 h182 w88 -> L',
    ['brand' => 'Scubapro', 'gender' => 'M', 'category' => 'skafander', 'chest' => 104, 'height' => 182, 'weight' => 88], 'match', ['L']);
$check('Scubapro M chest 200 -> out_of_scale',
    ['brand' => 'Scubapro', 'gender' => 'M', 'category' => 'skafander', 'chest' => 200], 'out_of_scale', null);
$check('Scubapro M chest 88 -> out_of_scale (poniżej skali)',
    ['brand' => 'Scubapro', 'gender' => 'M', 'category' => 'skafander', 'chest' => 88], 'out_of_scale', null);
$check('Bare K chest 88 h165 -> 6',
    ['brand' => 'Bare', 'gender' => 'K', 'category' => 'skafander', 'chest' => 88, 'height' => 165], 'match', ['6']);

// Dziecięcy (punktowy, height wiodący). category='skafander' jw. (CHAT-T-165 §3.3), wyniki bez zmian.
$check('DZIECI height 134 -> [S,M] graniczny',
    ['brand' => 'Scubapro', 'gender' => 'DZIECI', 'category' => 'skafander', 'height' => 134], 'boundary', ['S', 'M'], true);
$check('DZIECI height 140 -> M',
    ['brand' => 'Scubapro', 'gender' => 'DZIECI', 'category' => 'skafander', 'height' => 140], 'match', ['M'], false);
$check('DZIECI height 170 -> out_of_scale (>XL)',
    ['brand' => 'Scubapro', 'gender' => 'DZIECI', 'category' => 'skafander', 'height' => 170], 'out_of_scale', null, false);
$check('DZIECI height 100 -> out_of_scale (<XXS)',
    ['brand' => 'Scubapro', 'gender' => 'DZIECI', 'category' => 'skafander', 'height' => 100], 'out_of_scale', null, false);

// CHAT-T-103: wybór charta przez product_id (mapowanie divezone_attr_product_chart).
// Produkty 4243/4244/6681 = bi-gender (mapowane do chartu Scubapro M + K) — płeć klienta wybiera.
// Wartości oczekiwane = baseline PG (parytet danych 100%).
$check('product 4243 + M chest 104 h182 w88 -> L (chart Scubapro M)',
    ['product_id' => 4243, 'gender' => 'M', 'chest' => 104, 'height' => 182, 'weight' => 88], 'match', ['L']);
// ATTR-T-080 G3 (ADR-034 aneks 1, półotwartość [min,max)): 4243/K chart 2, chest=88 -> {S,ST}
// (S[86,91], ST[86,91]), ale height=165 -> {ST}: S kończy [160,165) i 165 należy do ST[165,170)
// (165 jest początkiem ST/M/LS, więc górna granica S wyłączająca). Przecięcie = {ST}, JEDEN
// rozmiar => decision 'match' (nie 'multiple'). Stare [S,ST]/'ambiguous' sprzed wdrożenia półotwartości.
$check('product 4243 + K chest 88 h165 -> match [ST] (chart Scubapro K, ADR-034)',
    ['product_id' => 4243, 'gender' => 'K', 'chest' => 88, 'height' => 165], 'match', ['ST']);
// ATTR-T-080 G2: kod zwraca 'multiple' (grep -c ambiguous = 0, SizeRecommender.php). 6681/M chart 1,
// chest=104 mieści się w LS/L/LT [101,107] (zmierzone na PROD), trzy trafienia => 'multiple' [LS,L,LT].
// Zestaw rozmiarów BEZ zmian wobec starej asercji; zmieniona wyłącznie nazwa decyzji (ambiguous->multiple).
$check('product 6681 + M chest 104 (bez weryfikatorów) -> multiple [LS,L,LT]',
    ['product_id' => 6681, 'gender' => 'M', 'chest' => 104], 'multiple', ['LS', 'L', 'LT']);

// CHAT-T-103: warstwa aliasów etykiet (divezone_attr_size_label_alias).
$aliasRes = $tool->execute(['product_id' => 4243, 'gender' => 'K', 'chest' => 88, 'height' => 165]);
$aOk = ($aliasRes['aliases']['M tall'] ?? null) === 'MT';
$ok = $ok && $aOk;
echo '  [' . ($aOk ? 'OK ' : 'FAIL') . "] alias 'M tall'->'MT' obecny w wyniku (chart Scubapro K): "
    . json_encode($aliasRes['aliases'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

// Reguły walidacji (bot ma dopytać — narzędzie zwraca error, nie zgaduje).
$noGender = $tool->execute(['brand' => 'Scubapro', 'chest' => 104]);
$g1 = isset($noGender['error']);
$ok = $ok && $g1;
echo '  [' . ($g1 ? 'OK ' : 'FAIL') . '] brak płci -> error/dopytanie: ' . ($noGender['error'] ?? $noGender['decision'] ?? '?') . "\n";

$noChest = $tool->execute(['brand' => 'Scubapro', 'gender' => 'M']);
$g2 = isset($noChest['error']);
$ok = $ok && $g2;
echo '  [' . ($g2 ? 'OK ' : 'FAIL') . '] brak klatki -> error/dopytanie: ' . ($noChest['error'] ?? $noChest['decision'] ?? '?') . "\n";

// ATTR-T-076 (ADR-039): chart progowy BEZ osi doboru -> size_list_only.
// Wszystkie wymiary charta to wymiary produktu (g_*) spoza schematu; narzędzie zwraca listę
// rozmiarów z size_full zamiast prosić o wymiary, których nie przyjmuje. Dowód wdrożenia ADR-039.
$check('T-076 chart 55 Santi koszulki (product 7006, M) -> size_list_only [S,M,L,XL,XXL]',
    ['product_id' => 7006, 'gender' => 'M'], 'size_list_only', ['S', 'M', 'L', 'XL', 'XXL']);

// Gałąź size_list_only NIE jest sytuacją błędną — brak klucza error (kontrakt §3 specu).
$ch55 = $tool->execute(['product_id' => 7006, 'gender' => 'M']);
$e55 = !array_key_exists('error', $ch55);
$ok = $ok && $e55;
echo '  [' . ($e55 ? 'OK ' : 'FAIL') . "] T-076 chart 55: brak klucza 'error' w payload size_list_only\n";

// Chart 72 (Mola Mola spodnie) -> size_list_only oraz obecny klucz aliases (jeden wpis, zmierzone).
$check('T-076 chart 72 Mola Mola spodnie (product 6967, M) -> size_list_only',
    ['product_id' => 6967, 'gender' => 'M'], 'size_list_only', null);
$ch72 = $tool->execute(['product_id' => 6967, 'gender' => 'M']);
$a72 = isset($ch72['aliases']) && count($ch72['aliases']) === 1;
$ok = $ok && $a72;
echo '  [' . ($a72 ? 'OK ' : 'FAIL') . "] T-076 chart 72: klucz 'aliases' obecny (1 wpis, jak w gałęzi głównej): "
    . json_encode($ch72['aliases'] ?? null, JSON_UNESCAPED_UNICODE) . "\n";

// Kontrola PRZECIWNA: chart 6 Mares ma oś doboru (shoe_eu w schemacie), strażnik NIE może się
// na niego rozlać. shoe_eu=42 dalej daje normalny wynik doboru, nie size_list_only (D2, K3).
$ch6 = $tool->execute(['product_id' => 5368, 'gender' => 'M', 'shoe_eu' => 42]);
$c6dec = $ch6['decision'] ?? null;
$c6 = ($c6dec !== null) && ($c6dec !== 'size_list_only');
$ok = $ok && $c6;
echo '  [' . ($c6 ? 'OK ' : 'FAIL') . "] T-076 kontrola przeciwna: chart 6 Mares shoe_eu=42 -> "
    . ($c6dec ?? ('ERROR:' . ($ch6['error'] ?? '?'))) . ' '
    . json_encode($ch6['sizes'] ?? [], JSON_UNESCAPED_UNICODE) . " (NIE size_list_only)\n";

// Normalizacja etykiet (KROK 4).
$n1 = SizeRecommender::normalizeLabel('6 Plus') === '6+';
$n2 = SizeRecommender::normalizeLabel('10 Plus') === '10+';
$n3 = SizeRecommender::normalizeLabel('L') === 'L';
$nOk = $n1 && $n2 && $n3;
$ok = $ok && $nOk;
echo '  [' . ($nOk ? 'OK ' : 'FAIL') . "] normalizeLabel: '6 Plus'->'6+', '10 Plus'->'10+', 'L'->'L'\n";

// ATTR-T-079B (ADR-042, ANEKS 1 Q148b) — walidacje kolizji aliasów wymiarów, live-DB.
// Dług A1.2 z etapu A: przeniesione z jednorazowych zapytań architekta do testu chodzącego
// przy KAŻDYM uruchomieniu. K6: obie asercje mają zaświecić na czerwono na sztucznie zepsutych
// danych DEV (V1: wiersz `chest` dołożony do Kwark/K; V2: alias równy nazwie kanonicznej),
// a na prawdziwych danych na zielono.

// V2 — żaden alias_dimension w bazie nie jest jedną z 11 nazw kanonicznych (SCHEMA_DIMS).
// Lista kanoniczna czytana Z KODU (reflection), nie skopiowana do testu — rozjazd bazy z kodem
// wychodzi tutaj, nie u klienta (wzorzec K7 z ATTR-T-076 / schema_dims.php).
$schemaDims = (new ReflectionClass(SizeRecommender::class))->getConstant('SCHEMA_DIMS');
$aliasRows = $db->fetchAll('SELECT alias_dimension FROM divezone_attr_dimension_alias');
$aliasDims = array_map(static fn(array $r): string => (string) $r['alias_dimension'], $aliasRows);
$v2Collisions = array_values(array_intersect($aliasDims, $schemaDims));
$v2 = ($v2Collisions === []);
$ok = $ok && $v2;
echo '  [' . ($v2 ? 'OK ' : 'FAIL') . '] V2: żaden alias_dimension nie jest nazwą kanoniczną (SCHEMA_DIMS z kodu): kolizje='
    . json_encode($v2Collisions, JSON_UNESCAPED_UNICODE) . "\n";

// V1 — żaden chart nie ma jednocześnie wymiaru będącego aliasem i jego celu kanonicznego.
// JOIN po tabeli aliasów (NIE literały hips/bust): chart z wierszem o dimension=alias ORAZ
// wierszem o dimension=canonical. Taka kolizja złamałaby K2 przez ciche nadpisanie klucza
// w buildSizes():434 (jeden filtr znika z przecięcia → wynik szerszy).
$v1Rows = $db->fetchAll(
    'SELECT DISTINCT r.id_chart, a.alias_dimension, a.canonical_dimension
     FROM divezone_attr_size_chart_rows r
     JOIN divezone_attr_dimension_alias a ON a.alias_dimension = r.dimension
     JOIN divezone_attr_size_chart_rows r2
          ON r2.id_chart = r.id_chart AND r2.dimension = a.canonical_dimension'
);
$v1 = ($v1Rows === []);
$ok = $ok && $v1;
echo '  [' . ($v1 ? 'OK ' : 'FAIL') . '] V1: żaden chart nie ma jednocześnie aliasu i jego celu: kolizje='
    . json_encode($v1Rows, JSON_UNESCAPED_UNICODE) . "\n";

echo 'WYNIK: ' . ($ok ? 'wszystkie OK' : 'SĄ BŁĘDY') . "\n";
exit($ok ? 0 : 1);
