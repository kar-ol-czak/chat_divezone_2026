<?php

declare(strict_types=1);

namespace DiveChat\Http;

/**
 * Helper do odpowiedzi JSON.
 */
final class Response
{
    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        self::setCorsHeaders();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function error(string $message, int $status = 400): never
    {
        self::json(['error' => $message], $status);
    }

    private static function setCorsHeaders(): void
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $allowed = ['https://divezone.pl', 'https://www.divezone.pl', 'https://dev.divezone.pl', 'https://chat.divezone.pl', 'http://localhost:3000'];

        if (in_array($origin, $allowed, true)) {
            header("Access-Control-Allow-Origin: {$origin}");
        }

        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-DiveChat-Token, X-DiveChat-Customer, X-DiveChat-Time');
        header('Access-Control-Max-Age: 86400');
    }

    /**
     * Obsługa preflight OPTIONS.
     */
    public static function handlePreflight(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            self::setCorsHeaders();
            exit;
        }
    }
}
