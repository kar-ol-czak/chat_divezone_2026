<?php

declare(strict_types=1);

namespace DiveChat\Database;

use DiveChat\Exception\DbUnavailableException;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Połączenie z PostgreSQL (Railway, pgvector).
 * Lazy init - połączenie tworzone przy pierwszym użyciu.
 *
 * CHAT-T-107 (P36c — odpornosc na zrywanie Railway). query() rozroznia DWA
 * rodzaje bledow polaczenia, bo maja rozny optymalny czas reakcji dla klienta:
 *  - ZERWANIE (SOFT): polaczenie zylo, padlo mid-query (server closed / 57P01).
 *    → 3 proby retry+reconnect (backoff 100/300 ms) — reconnect realnie ratuje.
 *  - NIEOSIAGALNE (HARD): host lezy (could not connect / timeout / refused).
 *    → 1 proba (connect_timeout=2s), od razu breaker — retry i tak nie pomoze,
 *    nie marnujemy 15s klienta.
 * Po wyczerpaniu rzuca DbUnavailableException z flaga SOFT/HARD (zasila komunikat
 * klienta, P37c), NIE goly PDOException. Circuit-breaker per-request: pierwsze
 * zapytanie placi pelny koszt, kolejne w TYM zadaniu fail-fast (z zapamietana
 * flaga). Bledy nie-polaczeniowe (skladnia/constraint) → rzucane od razu.
 */
final class PostgresConnection
{
    private const KIND_SOFT = DbUnavailableException::SOFT;
    private const KIND_HARD = DbUnavailableException::HARD;

    /** Max prob dla ZERWANIA (SOFT). HARD przerywa po 1. probie. */
    private const MAX_ATTEMPTS = 3;

    /** Backoff przed kolejna proba [ms] (tylko SOFT). */
    private const RETRY_BACKOFF_MS = [100, 300];

    private static ?self $instance = null;
    private ?PDO $pdo = null;

    /** Circuit-breaker per-request + zapamietana flaga rodzaju. */
    private bool $unavailable = false;
    private string $breakerKind = self::KIND_HARD;

    /** true gdy ktoras operacja w tym zadaniu udala sie DOPIERO po retry (lag). */
    private bool $delayed = false;

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
        return $this->executeWithRetry(function () use ($sql, $params): PDOStatement {
            $stmt = $this->getPdo()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        });
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

    /** Czy ktorakolwiek operacja w tym zadaniu udala sie dopiero po retry (P37c). */
    public function wasDelayed(): bool
    {
        return $this->delayed;
    }

    /**
     * Wykonuje operacje DB z retry+reconnect (CHAT-T-107, P36c).
     *
     * @template T
     * @param callable(): T $op
     * @return T
     */
    private function executeWithRetry(callable $op)
    {
        if ($this->unavailable) {
            // Juz w tym zadaniu uznano baze za niedostepna — fail-fast z zapamietana flaga.
            throw $this->makeUnavailable($this->breakerKind, null);
        }

        $lastError = null;
        $kind = self::KIND_HARD;
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            try {
                $result = $op();
                if ($attempt > 0) {
                    $this->delayed = true; // udalo sie po reconnect — sygnal "lag" (P37c)
                }
                return $result;
            } catch (PDOException $e) {
                $kind = self::classifyError($e);
                if ($kind === null) {
                    throw $e; // blad logiczny (skladnia/constraint) — nie ponawiamy
                }
                $lastError = $e;
                $this->pdo = null; // reconnect przy nastepnej probie

                // HARD = host nieosiagalny → retry nie pomoze, przerwij po 1. probie.
                if ($kind === self::KIND_HARD) {
                    break;
                }
                // SOFT = zerwanie → backoff i ponow (o ile zostaly proby).
                if ($attempt < self::MAX_ATTEMPTS - 1) {
                    usleep(self::RETRY_BACKOFF_MS[$attempt] * 1000);
                }
            }
        }

        $this->unavailable = true;
        $this->breakerKind = $kind;
        error_log('[PostgresConnection] Railway niedostepne (' . $kind . '): '
            . ($lastError?->getMessage() ?? '?'));
        throw $this->makeUnavailable($kind, $lastError);
    }

    private function makeUnavailable(string $kind, ?\Throwable $prev): DbUnavailableException
    {
        return new DbUnavailableException(
            $kind,
            'Railway PostgreSQL niedostepne (' . $kind . ')',
            $prev,
        );
    }

    /**
     * Klasyfikuje wyjatek: 'hard' (nieosiagalne) / 'soft' (zerwane) / null (nie-polaczeniowy).
     * Komunikat decyduje PRZED SQLSTATE (08006 wystepuje i przy refused, i przy dropie).
     */
    private static function classifyError(PDOException $e): ?string
    {
        $msg = strtolower($e->getMessage());

        // HARD — host nieosiagalny (retry nie pomoze).
        foreach ([
            'could not connect',
            'connection timed out',
            'connection refused',
            'could not translate host',
            'timeout expired',
            'no route to host',
            'network is unreachable',
            'name or service not known',
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::KIND_HARD;
            }
        }

        // SOFT — polaczenie zylo, padlo w trakcie (reconnect ma sens).
        foreach ([
            'server closed the connection',
            'no connection to the server',
            'terminating connection',
            'connection reset',
            'server closed',
            'eof detected',
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::KIND_SOFT;
            }
        }

        // Dopelnienie po SQLSTATE.
        $code = (string) $e->getCode();
        if ($code === '57P01') {
            return self::KIND_SOFT; // admin_shutdown — restart serwera, reconnect pomoze
        }
        if (in_array($code, ['08001', '08004'], true)) {
            return self::KIND_HARD; // sqlclient_unable_to_establish / rejected
        }
        if (in_array($code, ['08006', '08000', '08003', '7'], true)) {
            return self::KIND_SOFT; // connection_exception (domyslnie traktuj jak zerwanie)
        }

        return null; // nie blad polaczenia
    }

    /**
     * Szybki test połączenia (bez retry/breaker — niezalezna sonda np. dla /health).
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
     * Konwertuje postgres:// URL na PDO DSN. connect_timeout=2 (CHAT-T-107 P36c) —
     * przy awarii (glowny scenariusz) degradacja w ~2s, nie 30s (domyslny libpq).
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

        // PDO pgsql DSN + connect_timeout (sekundy) — szybka degradacja przy awarii.
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode={$sslmode};connect_timeout=2";

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
