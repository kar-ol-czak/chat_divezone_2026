<?php

declare(strict_types=1);

namespace DiveChat\Chip;

use DiveChat\Database\PostgresConnection;

/**
 * Odczyt drzewa chipów z PG i złożenie zagnieżdżenia po parent_id (ADR-096).
 *
 * Drzewo żyje w `divechat_chip_nodes` (jawna hierarchia: parent_id, level,
 * sort_order; węzeł hybrydowy: bot_text + buttons + dzieci). Widget pobiera całe
 * aktywne drzewo raz na starcie (endpoint GET /api/chip-tree) i renderuje lokalnie.
 *
 * Dzieci-podchipy wynikają z parent_id (NIE z buttons). buttons = tylko akcje
 * końcowe (link:/curated:/modal:/ai). Endpoint składa: dla każdego węzła
 * children = węzły o parent_id == id, posortowane po sort_order.
 */
final class ChipTreeService
{
    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    /**
     * Całe aktywne drzewo od korzeni (parent_id IS NULL), zagnieżdżone.
     *
     * @return list<array<string, mixed>>
     */
    public function getTree(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT id, node_key, parent_id, level, sort_order, bot_text, buttons, context_hint, model_level
             FROM divechat_chip_nodes
             WHERE active = TRUE
             ORDER BY parent_id NULLS FIRST, sort_order, id",
        );

        return self::buildTree($rows);
    }

    /**
     * Czyste złożenie płaskich wierszy w zagnieżdżone drzewo (testowalne bez PG).
     *
     * Wejście: wiersze jak z divechat_chip_nodes (buttons jako JSON string z PG).
     * Wyjście: korzenie (parent_id NULL) z rekurencyjnym children[]; każdy węzeł
     * zawiera node_key, bot_text, buttons[], children[], context_hint, model_level
     * (kontrakt endpointu — pola wewnętrzne id/parent_id/level NIE wychodzą).
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function buildTree(array $rows): array
    {
        // Indeks węzłów po id + grupowanie id-dzieci per rodzic (klucz '' = korzenie).
        $childrenByParent = [];
        $nodeById = [];
        $sortById = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $parentKey = $row['parent_id'] === null ? '' : (string) (int) $row['parent_id'];

            $nodeById[$id] = [
                'node_key'     => (string) $row['node_key'],
                'bot_text'     => $row['bot_text'] !== null ? (string) $row['bot_text'] : null,
                'buttons'      => self::decodeButtons($row['buttons'] ?? '[]'),
                'children'     => [],
                'context_hint' => $row['context_hint'] !== null ? (string) $row['context_hint'] : null,
                'model_level'  => $row['model_level'] !== null ? (string) $row['model_level'] : null,
            ];
            $sortById[$id] = (int) $row['sort_order'];
            $childrenByParent[$parentKey][] = $id;
        }

        // Stabilna kolejność po sort_order na każdym poziomie (test nie polega na ORDER BY).
        foreach ($childrenByParent as $parentKey => $ids) {
            usort($ids, static fn(int $a, int $b): int =>
                ($sortById[$a] <=> $sortById[$b]) ?: ($a <=> $b));
            $childrenByParent[$parentKey] = $ids;
        }

        // Sortowanie zrobione PRZED utworzeniem domknięcia (capture-by-value).
        $build = static function (string $parentKey) use (&$build, $childrenByParent, $nodeById): array {
            $out = [];
            foreach ($childrenByParent[$parentKey] ?? [] as $id) {
                $node = $nodeById[$id];
                $node['children'] = $build((string) $id);
                $out[] = $node;
            }
            return $out;
        };

        return $build('');
    }

    /**
     * Dekoduje buttons (JSONB z PG przychodzi jako string; może być już array).
     *
     * @return list<array{label: string, target: string}>
     */
    private static function decodeButtons(mixed $raw): array
    {
        $decoded = is_array($raw) ? $raw : json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $buttons = [];
        foreach ($decoded as $btn) {
            if (!is_array($btn) || !isset($btn['label'], $btn['target'])) {
                continue;
            }
            $buttons[] = [
                'label'  => (string) $btn['label'],
                'target' => (string) $btn['target'],
            ];
        }

        return $buttons;
    }
}
