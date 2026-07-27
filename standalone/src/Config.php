<?php

declare(strict_types=1);

namespace DiveChat;

/**
 * Konfiguracja aplikacji z .env.
 */
final class Config
{
    private static bool $loaded = false;

    /**
     * Ładuje .env - szuka w standalone/.env lub ../../.env (root projektu).
     */
    public static function load(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $dotenv = \Dotenv\Dotenv::createImmutable(
            paths: self::resolveEnvPaths($basePath),
        );
        $dotenv->safeLoad();

        self::$loaded = true;
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    public static function getRequired(string $key): string
    {
        return self::get($key) ?? throw new \RuntimeException("Brak wymaganej zmiennej: {$key}");
    }

    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        return match ($value) {
            'true', '1', 'yes' => true,
            'false', '0', 'no' => false,
            null => $default,
            default => $default,
        };
    }

    public static function isDebug(): bool
    {
        return self::getBool('APP_DEBUG');
    }

    public static function isProduction(): bool
    {
        return self::get('APP_ENV', 'production') === 'production';
    }

    /**
     * Zwraca listę ścieżek do pliku .env.
     * Priorytet: standalone/.env > root projektu/.env
     */
    private static function resolveEnvPaths(string $basePath): array
    {
        $paths = [];

        // standalone/.env (lokalna kopia)
        if (file_exists($basePath . '/.env')) {
            $paths[] = $basePath;
        }

        // Root projektu (../ względem standalone/)
        $rootPath = dirname($basePath);
        if (file_exists($rootPath . '/.env')) {
            $paths[] = $rootPath;
        }

        if (empty($paths)) {
            throw new \RuntimeException('Brak pliku .env w standalone/ ani w root projektu');
        }

        return $paths;
    }
}
