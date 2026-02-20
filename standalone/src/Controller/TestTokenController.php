<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Config;
use DiveChat\Http\Response;

/**
 * Generuje token testowy HMAC (tylko APP_ENV=dev).
 * GET /api/test-token
 */
final class TestTokenController
{
    public static function handle(): void
    {
        if (Config::get('APP_ENV', 'production') !== 'dev') {
            Response::error('Niedostępne w produkcji', 403);
        }

        $secret = Config::get('DIVECHAT_SECRET', '');
        if ($secret === '') {
            Response::error('Brak DIVECHAT_SECRET', 500);
        }

        $customerId = 0;
        $timestamp = time();
        $token = hash_hmac('sha256', "{$customerId}:{$timestamp}", $secret);

        Response::json([
            'token' => $token,
            'customer_id' => $customerId,
            'timestamp' => $timestamp,
            'expires_in' => 300,
        ]);
    }
}
