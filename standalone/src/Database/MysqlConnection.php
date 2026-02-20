<?php

declare(strict_types=1);

namespace DiveChat\Database;

use PDO;
use PDOStatement;

/**
 * Połączenie z MySQL (PrestaShop, read-only).
 * Lazy init - połączenie tworzone przy pierwszym użyciu.
 *
 * UWAGA: Tylko SELECT! Nigdy nie modyfikuj danych PrestaShop.
 */
final class MysqlConnection
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;

    private function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $dbname,
        private readonly string $user,
        private readonly string $password,
    ) {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self(
                host: $_ENV['DB_HOST'] ?? '127.0.0.1',
                port: (int) ($_ENV['DB_PORT'] ?? 3306),
                dbname: $_ENV['DB_NAME_PROD']
                    ?? throw new \RuntimeException('Brak DB_NAME_PROD w .env'),
                user: $_ENV['DB_USER']
                    ?? throw new \RuntimeException('Brak DB_USER w .env'),
                password: $_ENV['DB_PASSWORD']
                    ?? throw new \RuntimeException('Brak DB_PASSWORD w .env'),
            );
        }

        return self::$instance;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $dsn = "mysql:host={$this->host};port={$this->port}"
                 . ";dbname={$this->dbname};charset=utf8mb4";

            $this->pdo = new PDO($dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        // Bezpiecznik: blokuj operacje zapisu
        $normalized = strtoupper(trim($sql));
        if (!str_starts_with($normalized, 'SELECT') && !str_starts_with($normalized, 'SHOW') && !str_starts_with($normalized, 'DESCRIBE')) {
            throw new \RuntimeException('MysqlConnection: dozwolone tylko zapytania SELECT (read-only)');
        }

        $stmt = $this->getPdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    /**
     * Szybki test połączenia.
     */
    public function isConnected(): bool
    {
        try {
            $this->getPdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reset singletona (do testów).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
