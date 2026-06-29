<?php

declare(strict_types=1);

namespace DiveChat\Chip;

use DiveChat\Support\FileCache;
use DiveChat\Database\PostgresConnection;
use DiveChat\Exception\DbUnavailableException;

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
    private const TTL = 300;
    private const CACHE_KEY = 'chip_tree';

    private bool $degraded = false;

    public function __construct(
        private readonly PostgresConnection $db,
        private readonly ?FileCache $cache = null,
    ) {}

    /**
     * Całe aktywne drzewo od korzeni (parent_id IS NULL), zagnieżdżone.
     *
     * CHAT-T-107: drzewo to dane rzadko zmienne — po udanym zbudowaniu cache'ujemy
     * cały wynik. Przy niedostepnosci Railway (DbUnavailableException) degradujemy:
     * cache swiezy (TTL 300s) → last-known-good → puste drzewo (widget toleruje
     * puste — istniejacy kontrakt). NIGDY nie rzucamy do uzytkownika.
     *
     * @return list<array<string, mixed>>
     */
    public function getTree(): array
    {
        $this->degraded = false;
        $cache = $this->cache ?? new FileCache();
        try {
            $rows = $this->db->fetchAll(
                "SELECT id, node_key, parent_id, level, sort_order, label, bot_text, buttons, context_hint, model_level, ai_prompt
                 FROM divechat_chip_nodes
                 WHERE active = TRUE
                 ORDER BY parent_id NULLS FIRST, sort_order, id",
            );

            $tree = self::buildTree($rows, $this->loadLinkMap());
            $cache->set(self::CACHE_KEY, $tree);
            return $tree;
        } catch (DbUnavailableException $e) {
            $this->degraded = true;
            [$fresh, $val] = $cache->getFresh(self::CACHE_KEY, self::TTL);
            if ($fresh) {
                return $val;
            }
            [$any, $lkg] = $cache->getAny(self::CACHE_KEY);
            if ($any) {
                error_log('[ChipTreeService] Railway down — last-known-good drzewa chipow');
                return $lkg;
            }
            error_log('[ChipTreeService] Railway down + brak cache → puste drzewo (degradacja)');
            return [];
        }
    }

    /** Czy ostatni getTree() zwrocil dane z degradacji (Railway niedostepne). */
    public function wasDegraded(): bool
    {
        return $this->degraded;
    }

    /**
     * Mapa klucz→URL z divechat_shop_config dla przycisków link:<klucz> (decyzja 43a).
     * Jedno zapytanie (BEZ N+1). Pomija wartości puste/'TODO' (niepotwierdzone) —
     * lookup zwróci wtedy null (front dostanie url:null, jak dla brakującego klucza).
     *
     * @return array<string, string>
     */
    private function loadLinkMap(): array
    {
        $rows = $this->db->fetchAll(
            "SELECT key, value FROM divechat_shop_config WHERE key LIKE 'link\\_%'",
        );

        $map = [];
        foreach ($rows as $row) {
            $value = trim((string) $row['value']);
            if ($value === '' || $value === 'TODO') {
                continue;
            }
            $map[(string) $row['key']] = $value;
        }

        return $map;
    }

    /**
     * Czyste złożenie płaskich wierszy w zagnieżdżone drzewo (testowalne bez PG).
     *
     * Wejście: wiersze jak z divechat_chip_nodes (buttons jako JSON string z PG) +
     * opcjonalna mapa link klucz→URL (do rozwijania przycisków link:<klucz>).
     * Wyjście: korzenie (parent_id NULL) z rekurencyjnym children[]; każdy węzeł
     * zawiera node_key, label, bot_text, buttons[], children[], context_hint,
     * model_level, ai_prompt (kontrakt endpointu — pola wewnętrzne id/parent_id/level
     * NIE wychodzą).
     *
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $linkMap klucz configu → URL (puste = url:null)
     * @return list<array<string, mixed>>
     */
    public static function buildTree(array $rows, array $linkMap = []): array
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
                'label'        => isset($row['label']) && $row['label'] !== null ? (string) $row['label'] : null,
                'bot_text'     => $row['bot_text'] !== null ? (string) $row['bot_text'] : null,
                'buttons'      => self::decodeButtons($row['buttons'] ?? '[]', $linkMap),
                'children'     => [],
                'context_hint' => $row['context_hint'] !== null ? (string) $row['context_hint'] : null,
                'model_level'  => $row['model_level'] !== null ? (string) $row['model_level'] : null,
                // ai_prompt: opcjonalna instrukcja liścia dla AI (088e). isset chroni
                // stary SELECT/wiersze bez kolumny (jak label w 088b).
                'ai_prompt'    => isset($row['ai_prompt']) && $row['ai_prompt'] !== null ? (string) $row['ai_prompt'] : null,
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
     * Rozwija przyciski link:<klucz> (decyzja 43a): target zmienia się z
     * "link:link_serwis" na czysty "link", a url = URL z mapy configu (lub null gdy
     * klucz nieznany/pusty). Pozostałe targety (ai, modal:, curated:) → target jak
     * jest, url:null. Front nie zna configu — dostaje gotowy URL.
     *
     * @param array<string, string> $linkMap klucz configu → URL
     * @return list<array{label: string, target: string, url: string|null}>
     */
    private static function decodeButtons(mixed $raw, array $linkMap = []): array
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
            $target = (string) $btn['target'];

            if (str_starts_with($target, 'link:')) {
                $key = substr($target, 5);
                $buttons[] = [
                    'label'  => (string) $btn['label'],
                    'target' => 'link',
                    'url'    => $linkMap[$key] ?? null,
                ];
            } else {
                $buttons[] = [
                    'label'  => (string) $btn['label'],
                    'target' => $target,
                    'url'    => null,
                ];
            }
        }

        return $buttons;
    }
}
