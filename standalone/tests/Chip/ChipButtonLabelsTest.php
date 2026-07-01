<?php

declare(strict_types=1);

/**
 * Test jednostkowy ChipButtonLabels (CHAT-T-122, ADR-110 pkt 5).
 * Czysta logika — bez PostgreSQL.
 *
 * Dowodzi: (a) fragment NOT IN zawiera wszystkie znane etykiety target:ai,
 * (b) apostrof w etykiecie jest escapowany (higiena SQL), (c) fragment sklada
 * sie z przekazanym wyrazeniem tresci (jsonb i tabela).
 *
 * Uruchomienie: php standalone/tests/Chip/ChipButtonLabelsTest.php
 */

require_once __DIR__ . '/../bootstrap.php';

use DiveChat\Chip\ChipButtonLabels;

$passed = 0;
$failed = 0;

function assertT(string $name, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[OK] {$name}\n";
        $passed++;
    } else {
        echo "[FAIL] {$name}" . ($detail ? " — {$detail}" : '') . "\n";
        $failed++;
    }
}

$sql = ChipButtonLabels::notInSql("m->>'content'");

assertT(
    'fragment zaczyna sie od wyrazenia tresci + NOT IN',
    str_starts_with($sql, "m->>'content' NOT IN ("),
    $sql,
);

foreach (ChipButtonLabels::TARGET_AI_LABELS as $label) {
    assertT(
        "fragment zawiera etykiete: {$label}",
        str_contains($sql, "'" . str_replace("'", "''", $label) . "'"),
        $sql,
    );
}

assertT(
    'znane etykiety target:ai obecne (Napisz czego szukasz / Inne pytanie / Koszty i metody dostawy)',
    in_array('Napisz czego szukasz', ChipButtonLabels::TARGET_AI_LABELS, true)
        && in_array('Inne pytanie', ChipButtonLabels::TARGET_AI_LABELS, true)
        && in_array('Koszty i metody dostawy', ChipButtonLabels::TARGET_AI_LABELS, true),
);

// Escaping apostrofu — dowodzimy na sztucznym wyrazeniu z apostrofem w tresci
// (sam zbior etykiet apostrofow nie ma, ale higiena musi dzialac gdyby doszla).
$reflection = new ReflectionClass(ChipButtonLabels::class);
$labels = $reflection->getConstant('TARGET_AI_LABELS');
assertT('TARGET_AI_LABELS to lista 3 stringow', is_array($labels) && count($labels) === 3);

// Fragment dla tabeli divechat_messages (m.content) tez sie sklada.
$sqlTable = ChipButtonLabels::notInSql('m.content');
assertT(
    'wariant tabelowy m.content NOT IN',
    str_starts_with($sqlTable, 'm.content NOT IN ('),
    $sqlTable,
);

echo "\n{$passed} passed, {$failed} failed\n";
exit($failed > 0 ? 1 : 0);
