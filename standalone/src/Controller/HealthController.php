<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Database\PostgresConnection;
use DiveChat\Database\MysqlConnection;
use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Health check endpoint.
 * GET /api/health
 */
final class HealthController
{
    public static function handle(Request $request): void
    {
        $pgOk = false;
        $mysqlOk = false;

        try {
            $pgOk = PostgresConnection::getInstance()->isConnected();
        } catch (\Throwable) {
            // Połączenie niedostępne
        }

        try {
            $mysqlOk = MysqlConnection::getInstance()->isConnected();
        } catch (\Throwable) {
            // Połączenie niedostępne
        }

        Response::json([
            'status' => ($pgOk && $mysqlOk) ? 'ok' : 'degraded',
            'php' => PHP_VERSION,
            'postgres' => $pgOk,
            'mysql' => $mysqlOk,
            'timestamp' => date('c'),
        ]);
    }
}
