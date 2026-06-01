<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Database\MysqlConnection;
use DiveChat\Database\PostgresConnection;
use DiveChat\Http\Response;
use DiveChat\Shop\MysqlProductEnrichmentService;

/**
 * Sekcja read-only kuratorowanych rekomendacji dla panelu obslugi czatu
 * (T-035 CZESC B). Druga walidacja kregoslupa panelu — tym razem na REALNYM
 * odczycie danych przez kanal serwerowy (nie echo whoami).
 *
 * GET /api/admin/recommendations
 *
 * Auth: ServerHmacVerifier (kanal serwerowy modul PS -> backend, ADR-068 174a)
 *       + lookup roli w divechat_admin_roles. DOWOLNA rola (operator/admin)
 *       -> 200; brak roli -> 403; zly podpis -> 401.
 *
 * UWAGA (decyzja 35a): NIE reuzywamy logiki filtrujacej z CuratedRecommendations
 * (tool bota pomija niedostepne wpisy — active=false/visibility=none/availability=
 * unavailable). Panel pokazuje WSZYSTKIE wpisy z flaga hidden_from_bot dla tych
 * ktore bot ukrywa — pracownik MUSI widziec martwe wpisy jako sygnal do
 * maczowania.
 *
 * Decyzje:
 *  - 36a: dowolna rola dostepu (operator + admin).
 *  - 37a: grupowanie po category_key, w kazdej wpisy wg priority ASC.
 *  - 38b/41a: backend liczy wskaznik popularnosci (bucket low/mid/high lub
 *    percent wzgledem max W OBREBIE KATEGORII) z qty_12m. NIGDY surowych
 *    liczb sprzedazowych (qty_12m, net_value_12m) w odpowiedzi JSON.
 *  - Zrodlo nazwy produktu: MySQL pr_product_lang (id_lang=1, PL) — authoritative
 *    w PrestaShop. NIE pgvector divechat_product_embeddings (to cache,
 *    name moze byc nieaktualne po zmianie nazwy produktu w PS).
 *
 * Kontrakt JSON:
 *   200: {
 *     "status": "ok", "employee_id": N, "role": "admin|operator",
 *     "categories": [
 *       {
 *         "category_key": "regulator_recreational",
 *         "category_label_pl": "...",
 *         "items": [
 *           {
 *             "product_id": 2368, "priority": 1, "active": true,
 *             "rationale_pl": "...", "verified_at": "2026-...", "recheck_interval_days": 180,
 *             "product_name": "...", "price": 2103.02, "price_before_discount": 2389.80,
 *             "availability": "in_stock", "product_active": true, "product_visible": true,
 *             "hidden_from_bot": false,
 *             "popularity_bucket": "high",     // low|mid|high|no_data
 *             "popularity_percent": 92         // 0-100 wzgledem max w kategorii, null gdy no_data
 *           }
 *         ]
 *       }
 *     ]
 *   }
 *   401: {"error":"Unauthorized"}                       — brak/zly podpis/przeterminowany TS
 *   403: {"error":"Forbidden","reason":"no_role"}       — podpis OK ale employee bez roli
 */
final class AdminRecommendationsController
{
    private const LANG_PL = 1;

    public function __construct(
        private readonly ServerHmacVerifier $verifier,
        private readonly PostgresConnection $pg,
        private readonly MysqlProductEnrichmentService $enricher,
    ) {}

    public function handle(): void
    {
        // === Auth (analogicznie do AdminWhoamiController) ===
        $token = (string) ($_SERVER['HTTP_X_DIVECHAT_SERVER_TOKEN'] ?? '');
        $employeeRaw = $_SERVER['HTTP_X_DIVECHAT_SERVER_EMPLOYEE'] ?? '';
        $timeRaw = $_SERVER['HTTP_X_DIVECHAT_SERVER_TIME'] ?? '';

        if ($token === '' || $employeeRaw === '' || $timeRaw === '') {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $employeeId = filter_var($employeeRaw, FILTER_VALIDATE_INT);
        $timestamp = filter_var($timeRaw, FILTER_VALIDATE_INT);
        if ($employeeId === false || $timestamp === false || $employeeId <= 0) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        if (!$this->verifier->verify($token, $employeeId, $timestamp)) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->pg->fetchOne(
            'SELECT role FROM divechat_admin_roles WHERE employee_id = ?',
            [$employeeId],
        );

        if ($row === null) {
            Response::json(['error' => 'Forbidden', 'reason' => 'no_role'], 403);
        }

        $role = (string) $row['role'];

        // === Fetch wszystkich wpisow (active + nieaktywne, decyzja 35a) ===
        $allItems = $this->pg->fetchAll(
            'SELECT id, category_key, category_label_pl, product_id, priority,
                    rationale_pl, verified_at, recheck_interval_days, active
             FROM divechat_curated_recommendations
             ORDER BY category_key ASC, priority ASC, id ASC'
        );

        if (empty($allItems)) {
            Response::json([
                'status' => 'ok',
                'employee_id' => $employeeId,
                'role' => $role,
                'categories' => [],
            ]);
        }

        // === Batch loaders dla wszystkich product_id ===
        $productIds = [];
        foreach ($allItems as $it) {
            $productIds[] = (int) $it['product_id'];
        }
        $productIds = array_values(array_unique($productIds));

        $enriched = $this->enricher->enrich($productIds);
        $names = $this->fetchProductNames($productIds);
        $popularity = $this->fetchPopularity($productIds);

        // === Grupowanie po category_key + liczenie max qty per kategoria
        //     (do percent wskaznika 41a) ===
        $byCategory = [];
        foreach ($allItems as $item) {
            $catKey = (string) $item['category_key'];
            if (!isset($byCategory[$catKey])) {
                $byCategory[$catKey] = [
                    'category_key' => $catKey,
                    'category_label_pl' => (string) $item['category_label_pl'],
                    '_items' => [],
                    '_max_qty' => 0,
                ];
            }
            $pid = (int) $item['product_id'];
            $byCategory[$catKey]['_items'][] = $item;

            if (isset($popularity[$pid]) && $popularity[$pid] > $byCategory[$catKey]['_max_qty']) {
                $byCategory[$catKey]['_max_qty'] = $popularity[$pid];
            }
        }

        // === Build response ===
        $categories = [];
        foreach ($byCategory as $cat) {
            $maxQty = $cat['_max_qty'];
            $items = [];
            foreach ($cat['_items'] as $item) {
                $pid = (int) $item['product_id'];
                $e = $enriched[$pid] ?? null;
                $qty = $popularity[$pid] ?? null;

                // Popularity bucket + percent (41a) — TYLKO te dwa pola
                // w odpowiedzi, NIGDY surowe qty_12m / net_value_12m (38b).
                if ($qty === null) {
                    $bucket = 'no_data';
                    $percent = null;
                } else {
                    $percent = $maxQty > 0 ? (int) round(100 * $qty / $maxQty) : 0;
                    if ($percent <= 33) {
                        $bucket = 'low';
                    } elseif ($percent <= 66) {
                        $bucket = 'mid';
                    } else {
                        $bucket = 'high';
                    }
                }

                // Flaga hidden_from_bot: tool CuratedRecommendations pomija
                // wpisy gdzie brak enrich / active=false / visible=false /
                // availability=unavailable. Pracownik MUSI to widziec.
                $hiddenFromBot = false;
                if ($e === null) {
                    $hiddenFromBot = true;
                } elseif (!$e['active'] || !$e['visible'] || $e['availability'] === 'unavailable') {
                    $hiddenFromBot = true;
                }

                $items[] = [
                    'product_id' => $pid,
                    'priority' => (int) $item['priority'],
                    'rationale_pl' => (string) $item['rationale_pl'],
                    'verified_at' => $item['verified_at'],
                    'recheck_interval_days' => (int) $item['recheck_interval_days'],
                    'active' => (bool) $item['active'],
                    'product_name' => $names[$pid] ?? null,
                    'price' => $e !== null ? (float) $e['price'] : null,
                    'price_before_discount' => $e['price_before_discount'] ?? null,
                    'availability' => $e['availability'] ?? null,
                    'product_active' => $e !== null ? (bool) $e['active'] : null,
                    'product_visible' => $e !== null ? (bool) $e['visible'] : null,
                    'hidden_from_bot' => $hiddenFromBot,
                    'popularity_bucket' => $bucket,
                    'popularity_percent' => $percent,
                ];
            }

            $categories[] = [
                'category_key' => $cat['category_key'],
                'category_label_pl' => $cat['category_label_pl'],
                'items' => $items,
            ];
        }

        Response::json([
            'status' => 'ok',
            'employee_id' => $employeeId,
            'role' => $role,
            'categories' => $categories,
        ]);
    }

    /**
     * Batch fetch nazw produktow z MySQL pr_product_lang (PL).
     * Zrodlo authoritative w PrestaShop — uzywamy zamiast cache pgvector.
     *
     * @param list<int> $productIds
     * @return array<int, string>
     */
    private function fetchProductNames(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $mysql = MysqlConnection::getInstance();
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $mysql->fetchAll(
            "SELECT id_product, name FROM pr_product_lang
             WHERE id_lang = ? AND id_product IN ({$placeholders})",
            array_merge([self::LANG_PL], $productIds),
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['id_product']] = (string) $r['name'];
        }
        return $result;
    }

    /**
     * Batch fetch qty_12m z divechat_product_sales_popularity (PG).
     * UWAGA: te liczby (qty + net_value) sa WRAZLIWE (38b) — wracaja TYLKO do
     * uzytku wewnetrznego do liczenia bucketow. NIE eksponowac w JSON odpowiedzi.
     *
     * @param list<int> $productIds
     * @return array<int, int>  product_id => qty_12m
     */
    private function fetchPopularity(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $rows = $this->pg->fetchAll(
            "SELECT product_id, qty_12m FROM divechat_product_sales_popularity
             WHERE product_id IN ({$placeholders})",
            $productIds,
        );
        $result = [];
        foreach ($rows as $r) {
            $result[(int) $r['product_id']] = (int) $r['qty_12m'];
        }
        return $result;
    }
}
