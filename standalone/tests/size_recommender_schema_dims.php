<?php

declare(strict_types=1);

/**
 * ATTR-T-076 (ADR-039, ANEKS 1 A1.2 / K7) — trwały strażnik zgodności SCHEMA_DIMS ze schematem.
 * SCHEMA_DIMS jest ręcznym duplikatem listy wymiarów przyjmowanych przez getParametersSchema();
 * ten test wykrywa ich rozjazd LOKALNIE, zanim trafi na serwer (recenzja Codeksa, Finding 5).
 *
 * BEZ połączenia z bazą: refleksja na klasie + instancja bez konstruktora (getParametersSchema()
 * zwraca literał, nie dotyka MySQL), więc rozjazd stałej ze schematem jest wykrywalny lokalnie.
 * Uruchom: php tests/size_recommender_schema_dims.php
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DiveChat\Tools\SizeRecommender;

$ref = new ReflectionClass(SizeRecommender::class);
$inst = $ref->newInstanceWithoutConstructor(); // bez MysqlConnection — schemat jest literałem
$schema = $inst->getParametersSchema();

$props = array_keys($schema['properties']);
$control = ['product_id', 'brand', 'category', 'gender'];
$schemaDims = array_values(array_diff($props, $control));

$constDims = $ref->getConstant('SCHEMA_DIMS');
if ($constDims === false) {
    fwrite(STDERR, "[FAIL] brak stałej SCHEMA_DIMS w SizeRecommender (ADR-039).\n");
    exit(1);
}

$a = $schemaDims; sort($a);
$b = $constDims;  sort($b);
$equalSet = ($a === $b);
$countOk = (count($schemaDims) === count($constDims)) && (count($constDims) === 11);

echo "ATTR-T-076 — zgodność SCHEMA_DIMS ze schematem narzędzia (K7):\n";
echo '  schemat (properties - sterujące): ' . json_encode($schemaDims, JSON_UNESCAPED_SLASHES) . ' (' . count($schemaDims) . ")\n";
echo '  stała   SCHEMA_DIMS            : ' . json_encode($constDims, JSON_UNESCAPED_SLASHES) . ' (' . count($constDims) . ")\n";

if ($equalSet && $countOk) {
    echo "  [OK ] SCHEMA_DIMS == właściwości schematu minus klucze sterujące; zbiór i liczność (11).\n";
    exit(0);
}

$missingInConst = array_values(array_diff($schemaDims, $constDims));
$extraInConst   = array_values(array_diff($constDims, $schemaDims));
echo "  [FAIL] rozjazd stałej ze schematem:\n";
if ($missingInConst !== []) {
    echo '    w schemacie, brak w SCHEMA_DIMS (dodano wymiar do schematu, zapomniano o stałej): '
        . json_encode($missingInConst, JSON_UNESCAPED_SLASHES) . "\n";
}
if ($extraInConst !== []) {
    echo '    w SCHEMA_DIMS, brak w schemacie (usunięto wymiar ze schematu, zapomniano o stałej): '
        . json_encode($extraInConst, JSON_UNESCAPED_SLASHES) . "\n";
}
if (count($constDims) !== 11) {
    echo '    liczność SCHEMA_DIMS = ' . count($constDims) . ", oczekiwano 11.\n";
}
exit(1);
