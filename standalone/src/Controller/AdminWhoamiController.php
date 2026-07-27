<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\ServerHmacVerifier;
use DiveChat\Database\PostgresConnection;
use DiveChat\Http\Response;

/**
 * Echo endpoint kregoslupa panelu admin (ADR-068, T-032).
 *
 * GET /api/admin/whoami — weryfikuje podpis kanalu serwerowego (modul PS) i
 * zwraca {status, employee_id, role}. ZERO logiki rekomendacji — to fundament
 * walidacyjny dla auth/role/kanal. Kolejny task doklada wlasciwa CRUD na tym
 * sprawdzonym kregoslupie.
 *
 * Headery wejscia:
 *   X-DiveChat-Server-Token    HMAC sha256 hex (payload = employee_id:timestamp)
 *   X-DiveChat-Server-Employee int employee_id (pr_employee.id_employee)
 *   X-DiveChat-Server-Time     Unix timestamp (anti-replay ±300s)
 *
 * Kody odpowiedzi:
 *   200 {"status":"ok","employee_id":N,"role":"admin|operator"}
 *   401 {"error":"Unauthorized"}                            -- brak/zly podpis lub przeterminowany TS
 *   403 {"error":"Forbidden","reason":"no_role"}            -- podpis OK, employee bez wpisu w divechat_admin_roles
 */
final class AdminWhoamiController
{
    public function __construct(
        private readonly ServerHmacVerifier $verifier,
        private readonly PostgresConnection $db,
    ) {}

    public function handle(): void
    {
        $token = (string) ($_SERVER['HTTP_X_DIVECHAT_SERVER_TOKEN'] ?? '');
        $employeeRaw = $_SERVER['HTTP_X_DIVECHAT_SERVER_EMPLOYEE'] ?? '';
        $timeRaw = $_SERVER['HTTP_X_DIVECHAT_SERVER_TIME'] ?? '';

        if ($token === '' || $employeeRaw === '' || $timeRaw === '') {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        // Strict int parse — odrzucamy puste/floaty/nieliczby przez ctype_digit z trim leading '-'.
        $employeeId = filter_var($employeeRaw, FILTER_VALIDATE_INT);
        $timestamp = filter_var($timeRaw, FILTER_VALIDATE_INT);
        if ($employeeId === false || $timestamp === false || $employeeId <= 0) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        if (!$this->verifier->verify($token, $employeeId, $timestamp)) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $row = $this->db->fetchOne(
            'SELECT role FROM divechat_admin_roles WHERE employee_id = ?',
            [$employeeId],
        );

        if ($row === null) {
            Response::json(['error' => 'Forbidden', 'reason' => 'no_role'], 403);
        }

        Response::json([
            'status' => 'ok',
            'employee_id' => $employeeId,
            'role' => $row['role'],
        ]);
    }
}
