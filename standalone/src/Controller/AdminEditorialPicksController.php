<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Database\MysqlConnection;
use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Admin API Editorial Picks (ADR-054, T-008).
 *
 * GET    /api/admin/editorial-picks?active=1|0|all&order_by=added_at|expires_at|boost_factor
 * POST   /api/admin/editorial-picks            — body: {product_id, product_name, category_hint?, boost_factor, reason, ttl_days?}
 * PUT    /api/admin/editorial-picks/{id}       — body: subset {boost_factor, reason, expires_at, active, ttl_extend_days, mark_reviewed}
 * DELETE /api/admin/editorial-picks/{id}       — twarde DELETE (audit trail przez deactivate w PUT)
 *
 * Aliasy POST (CHAT-T-054 decyzja 128a — PS callBackend wysyla body tylko dla POST):
 * POST   /api/admin/editorial-picks/{id}         — alias PUT update
 * POST   /api/admin/editorial-picks/{id}/delete  — alias DELETE
 *
 * Auth (CHAT-T-054, ADR-068/070, decyzja 127b): kanal serwerowy panelu PS —
 * ServerHmacVerifier (X-DiveChat-Server-*, sekret DIVECHAT_SERVER_SECRET) +
 * lookup roli w divechat_admin_roles. ANY-ROLE: operator + admin (NIE
 * admin-only, w przeciwienstwie do Settings/Pricing/Analytics). 401 brak/zly
 * podpis, 403 no_role.
 */
final class AdminEditorialPicksController
{
    public function __construct(
        private readonly EditorialPicksService $service,
        private readonly ServerHmacVerifier $serverVerifier,
        private readonly PostgresConnection $pg,
    ) {}

    public function list(Request $request): void
    {
        $this->requireAnyRole();

        $activeParam = strtolower((string) ($_GET['active'] ?? 'all'));
        $active = match ($activeParam) {
            '1', 'true', 'yes' => true,
            '0', 'false', 'no' => false,
            default => null,
        };

        $orderBy = (string) ($_GET['order_by'] ?? 'added_at');

        $items = $this->service->list($active, $orderBy);

        Response::json(['picks' => $items, 'count' => count($items)]);
    }

    public function add(Request $request): void
    {
        $employeeId = $this->requireAnyRole();

        $body = $request->getJsonBody() ?? [];
        $productId = (int) ($body['product_id'] ?? 0);
        $productName = trim((string) ($body['product_name'] ?? ''));
        $reason = trim((string) ($body['reason'] ?? ''));
        $boostFactor = (float) ($body['boost_factor'] ?? 1.5);

        if ($productId <= 0) {
            Response::error('product_id wymagane (int > 0)', 400);
        }
        if ($productName === '') {
            Response::error('product_name wymagane', 400);
        }
        if ($reason === '') {
            Response::error('reason wymagane', 400);
        }
        if ($boostFactor < 1.0 || $boostFactor > 2.5) {
            Response::error('boost_factor musi być w zakresie 1.0-2.5', 400);
        }

        $categoryHint = isset($body['category_hint']) && $body['category_hint'] !== ''
            ? (string) $body['category_hint']
            : null;
        $ttlDays = isset($body['ttl_days']) && $body['ttl_days'] !== null
            ? (int) $body['ttl_days']
            : null;
        // CHAT-T-054: po przelaczeniu na kanal serwerowy nie ma PHP_AUTH_USER.
        // Audit trail uzywa employee_id z weryfikowanego podpisu (jednoznaczne).
        $addedBy = 'employee:' . $employeeId;

        try {
            $pick = $this->service->add(
                $productId,
                $productName,
                $categoryHint,
                $boostFactor,
                $reason,
                $addedBy,
                $ttlDays,
            );
        } catch (\InvalidArgumentException $e) {
            Response::error($e->getMessage(), 400);
        }

        Response::json(['pick' => $pick], 201);
    }

    public function update(Request $request): void
    {
        $this->requireAnyRole();

        $id = (int) ($request->params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('id wymagane w ścieżce', 400);
        }

        $body = $request->getJsonBody() ?? [];

        // Specjalne akcje: mark_reviewed, ttl_extend_days.
        if (!empty($body['mark_reviewed'])) {
            $ok = $this->service->markReviewed($id);
            if (!$ok) {
                Response::error('Pick nie znaleziony', 404);
            }
        }

        if (isset($body['ttl_extend_days']) && (int) $body['ttl_extend_days'] > 0) {
            $extendDays = (int) $body['ttl_extend_days'];
            $newExpires = (new \DateTimeImmutable('now'))
                ->modify("+{$extendDays} days")
                ->format('Y-m-d H:i:sP');
            $body['expires_at'] = $newExpires;
        }

        $changes = [];
        foreach (['boost_factor', 'reason', 'expires_at', 'active'] as $key) {
            if (array_key_exists($key, $body)) {
                $changes[$key] = $body[$key];
            }
        }

        if (!empty($changes)) {
            try {
                $ok = $this->service->update($id, $changes);
            } catch (\InvalidArgumentException $e) {
                Response::error($e->getMessage(), 400);
            }
            if (!$ok && empty($body['mark_reviewed'])) {
                Response::error('Pick nie znaleziony lub bez zmian', 404);
            }
        }

        Response::json(['success' => true, 'id' => $id]);
    }

    public function delete(Request $request): void
    {
        $this->requireAnyRole();

        $id = (int) ($request->params['id'] ?? 0);
        if ($id <= 0) {
            Response::error('id wymagane w ścieżce', 400);
        }

        $ok = $this->service->delete($id);
        if (!$ok) {
            Response::error('Pick nie znaleziony', 404);
        }

        Response::json(['success' => true, 'id' => $id]);
    }

    public function pendingReviews(Request $request): void
    {
        $this->requireAnyRole();
        Response::json($this->service->pendingReviews());
    }

    /**
     * Autocomplete dla form Add picka — wyszukuje produkty w PrestaShop MySQL.
     * GET /api/admin/products/search?q={min 2 znaki}
     */
    public function productsSearch(Request $request): void
    {
        $this->requireAnyRole();

        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2) {
            Response::json(['products' => [], 'message' => 'Min. 2 znaki']);
        }

        try {
            $mysql = MysqlConnection::getInstance();
        } catch (\Throwable $e) {
            Response::error('Database unavailable', 503);
        }

        $exactId = ctype_digit($q) ? (int) $q : 0;
        $like = '%' . $q . '%';

        $rows = $mysql->fetchAll(
            "SELECT
                p.id_product,
                pl.name AS product_name,
                ps.price,
                COALESCE(sa.quantity, 0) AS quantity
            FROM pr_product p
            JOIN pr_product_lang pl ON p.id_product = pl.id_product AND pl.id_lang = 1
            JOIN pr_product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = 1 AND ps.active = 1
            LEFT JOIN pr_stock_available sa ON p.id_product = sa.id_product AND sa.id_product_attribute = 0
            WHERE pl.name LIKE ? OR p.id_product = ? OR p.reference LIKE ?
            ORDER BY (CASE WHEN p.id_product = ? THEN 0 ELSE 1 END), pl.name
            LIMIT 20",
            [$like, $exactId, $like, $exactId],
        );

        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'id' => (int) $row['id_product'],
                'name' => (string) $row['product_name'],
                'price' => (float) $row['price'],
                'in_stock' => ((int) $row['quantity']) > 0,
            ];
        }

        Response::json(['products' => $products, 'count' => count($products)]);
    }

    /**
     * Weryfikacja kanalu serwerowego + lookup roli (any-role: operator+admin).
     * Wzorzec 1:1 z AdminRecommendationsController::handle() — koszty/settings
     * sa admin-only (SettingsController::requireAdmin), editorial picks dostepne
     * dla operatora i admina (decyzja 127b — operator zarzadza wpisami w
     * standardowym workflow).
     *
     * Response::json konczy `exit`, wiec po nieudanej walidacji metoda nie wraca.
     * Zwraca employee_id z podpisu — uzywany dla audit trail w add() (zamiast
     * PHP_AUTH_USER z bylego Basic Auth).
     */
    private function requireAnyRole(): int
    {
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

        if (!$this->serverVerifier->verify($token, $employeeId, $timestamp)) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->pg->fetchOne(
            'SELECT role FROM divechat_admin_roles WHERE employee_id = ?',
            [$employeeId],
        );

        if ($row === null) {
            Response::json(['error' => 'Forbidden', 'reason' => 'no_role'], 403);
        }

        return $employeeId;
    }
}
