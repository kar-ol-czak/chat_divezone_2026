<?php

declare(strict_types=1);

namespace DiveChat\Database;

use PDO;
use PDOStatement;

/**
 * Połączenie z PostgreSQL (Aiven, pgvector).
 * Lazy init - połączenie tworzone przy pierwszym użyciu.
 */
final class PostgresConnection
{
    private static ?self $instance = null;
    private ?PDO $pdo = null;

    private function __construct(
        private readonly string $dsn,
    ) {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            $databaseUrl = $_ENV['DATABASE_URL']
                ?? throw new \RuntimeException('Brak DATABASE_URL w .env');

            self::$instance = new self($databaseUrl);
        }

        return self::$instance;
    }

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->buildDsn(), options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
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
     * Konwertuje postgres:// URL na PDO DSN.
     */
    private function buildDsn(): string
    {
        $parts = parse_url($this->dsn);

        $host = $parts['host'] ?? 'localhost';
        $port = $parts['port'] ?? 5432;
        $dbname = ltrim($parts['path'] ?? '/defaultdb', '/');
        $user = $parts['user'] ?? '';
        $pass = $parts['pass'] ?? '';

        // Parsuj query string (sslmode itp.)
        parse_str($parts['query'] ?? '', $queryParams);
        $sslmode = $queryParams['sslmode'] ?? 'require';

        // PDO pgsql DSN
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode}";

        // Ustaw user/pass bezpośrednio w PDO - wracamy do konstruktora
        // PDO pgsql akceptuje user/password w DSN
        $dsn .= ";user={$user};password={$pass}";

        return $dsn;
    }

    /**
     * Reset singletona (do testów).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
