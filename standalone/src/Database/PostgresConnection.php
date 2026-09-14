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
 * CHAT-T-107 (P36c) + CHAT-T-191. query() klasyfikuje bledy polaczenia na TRZY
 * kategorie WEWNETRZNE, bo maja rozny optymalny czas reakcji dla klienta:
 *  - SOFT: polaczenie zylo, padlo mid-query (server closed / 57P01).
 *    → 3 proby retry+reconnect, backoff 100/300 ms — reconnect realnie ratuje.
 *  - HARD_RETRY: trasa do hosta chwilowo nie przepuszcza pakietow (could not
 *    connect / connection timed out / timeout expired / no route to host /
 *    network is unreachable). → 3 proby, backoff 1000/3000 ms.
 *    Podstawa (CHAT-T-191 §1): monitor trasy serwer→Railway 2026-08-15..09-14,
 *    465 841 probek co 5 s, 149 serii niedostepnosci, mediana serii 19 s,
 *    p75 46 s. Host ZYJE, gina pakiety — budzet 10 s ratuje ~20 % zapytan
 *    trafiajacych w awarie (liczone wagami ekspozycji). Poprzednia wersja
 *    zakladala "host lezy, retry nie pomoze" i przerywala po 1. probie.
 *  - HARD_FINAL: stan trwaly, ponawianie to marnowanie sekund klienta
 *    (connection refused = host zyje, port zamkniety; DNS nie rozwiazuje).
 *    → 1 proba, od razu breaker.
 *
 * Budzet najgorszego przypadku HARD_RETRY, przy limicie connectu 2 s
 * (PDO::ATTR_TIMEOUT w getPdo() — patrz tam, DSN sam nie wystarcza):
 *   2 s (proba 1) + 1 s (backoff) + 2 s (proba 2) + 3 s (backoff) + 2 s (proba 3)
 *   = 10 s. SOFT bez zmian: 0,1 + 0,3 = 0,4 s narzutu backoffu.
 *
 * Na zewnatrz kategorie mapuja sie na dotychczasowa flage DbUnavailableException
 * (toPublicKind): SOFT→SOFT, HARD_RETRY i HARD_FINAL→HARD. Komunikaty klienta
 * (DB_SOFT_MESSAGE / DB_HARD_MESSAGE w ChatController) bez zmian.
 * Po wyczerpaniu prob rzuca DbUnavailableException, NIE goly PDOException.
 * Circuit-breaker per-request: operacja, ktora WYCZERPIE proby, zatrzaskuje
 * $this->unavailable i kolejne w TYM zadaniu robia fail-fast (z zapamietana
 * flaga) — jedno zadanie czatu robi kilkanascie zapytan do PG, wiec bez breakera
 * budzet mnozylby sie przez ich liczbe. Zmierzone: 10 operacji przy niedostepnej
 * bazie = 10 006 ms, nie 100 s (produkcja, 2026-09-14).
 *
 * OGRANICZENIE, zmierzone i NIEROZSTRZYGNIETE (CHAT-T-191, recenzja ustalenie 1):
 * operacja, ktora UDA SIE po ponowieniu, breakera NIE zatrzaskuje — i slusznie,
 * baza przeciez odpowiedziala. Ale w zadaniu, w ktorym kilka operacji odzyskuje
 * sie dopiero za drugim razem, koszt ponowien sumuje sie liniowo (pomiar
 * syntetyczny: 5 operacji = 8,0 s). Gwarancja "budzet raz na zadanie" obowiazuje
 * wiec dla operacji PRZEGRANYCH, nie dla calego zadania. Domkniecie wymaga
 * skumulowanego budzetu czasu na zadanie — decyzja architekta, nie wprowadzona.
 *
 * Bledy nie-polaczeniowe (skladnia/constraint) → rzucane od razu.
 */
final class PostgresConnection
{
    private const KIND_SOFT = DbUnavailableException::SOFT;
    private const KIND_HARD = DbUnavailableException::HARD;

    /**
     * Kategorie WEWNETRZNE (CHAT-T-191). Nie wychodza poza te klase — na zewnatrz
     * mapuje je toPublicKind() na KIND_SOFT / KIND_HARD.
     */
    private const CAT_SOFT = 'soft';
    private const CAT_HARD_RETRY = 'hard_retry';
    private const CAT_HARD_FINAL = 'hard_final';

    /** Max prob dla SOFT i HARD_RETRY. HARD_FINAL przerywa po 1. probie. */
    private const MAX_ATTEMPTS = 3;

    /** Backoff przed kolejna proba [ms] — SOFT (reconnect jest tani). */
    private const RETRY_BACKOFF_MS = [100, 300];

    /** Backoff przed kolejna proba [ms] — HARD_RETRY (czekamy az trasa wroci). */
    private const HARD_RETRY_BACKOFF_MS = [1000, 3000];

    private static ?self $instance = null;
    private ?PDO $pdo = null;

    /** Circuit-breaker per-request + zapamietana flaga PUBLICZNA (soft/hard). */
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
            // CHAT-T-134 (ADR-117): ATTR_PERSISTENT — połączenie do Railway żyje
            // między requestami w procesie FPM (zmierzone: świeży connect TCP+TLS
            // = ~161 ms na KAŻDE żądanie; RTT ~115 ms). pdo_pgsql przy pobraniu
            // z puli robi check_liveness (PQstatus + PQreset), a martwy handle
            // odrzuca — retry/reconnect z CHAT-T-107 ($this->pdo = null) nadal
            // działa. Kod nie używa transakcji (zweryfikowane), więc brak ryzyka
            // wycieku otwartego TX między requestami.
            $this->pdo = new PDO($this->buildDsn(), options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => true,
                // CHAT-T-191: TO JEST jedyny dzialajacy limit czasu connectu.
                // pdo_pgsql dokleja wlasne "connect_timeout=<ATTR_TIMEOUT, domyslnie 30>"
                // na KONCU conninfo, a w libpq wygrywa OSTATNIE wystapienie keywordu —
                // czyli connect_timeout=2 z buildDsn() bylo martwe od CHAT-T-107.
                // Pomiar na produkcji (2026-09-14, PHP 8.4.24, libpq 13.23, cel bez trasy):
                // sam DSN 7108 ms, ATTR_TIMEOUT=2 → 2002 ms, kontrola pg_connect 2002 ms.
                // Bez tej linii budzet retry HARD_RETRY wynosilby 30+1+30+3+30 = 94 s.
                // Dotyczy WYLACZNIE nawiazania polaczenia, nie czasu wykonania zapytania.
                PDO::ATTR_TIMEOUT => 2,
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
     * Wykonuje operacje DB z retry+reconnect (CHAT-T-107, P36c; CHAT-T-191).
     *
     * Budzet czasu przy niedostepnej bazie: SOFT 0,4 s backoffu, HARD_RETRY do
     * 10 s (2+1+2+3+2, patrz docblock klasy), HARD_FINAL jedna proba. Operacja
     * przegrana wydaje go RAZ na zadanie ($this->unavailable). Operacje odzyskane
     * po ponowieniu sumuja sie — patrz OGRANICZENIE w docblocku klasy.
     *
     * @template T
     * @param callable(): T $op
     * @return T
     */
    private function executeWithRetry(callable $op)
    {
        if ($this->unavailable) {
            // Juz w tym zadaniu uznano baze za niedostepna — fail-fast z zapamietana
            // flaga. To ten bezpiecznik trzyma budzet na poziomie zadania, nie zapytania.
            throw $this->makeUnavailable($this->breakerKind, null);
        }

        $lastError = null;
        $category = self::CAT_HARD_FINAL;
        $startedAt = hrtime(true);
        $attempts = 0;

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $attempts++;
            try {
                $result = $op();
                if ($attempt > 0) {
                    // Udalo sie dopiero po reconnect — sygnal "lag" (P37c) oraz slad
                    // do zmierzenia po miesiacu, czy prognoza 20 % sie potwierdzila
                    // (CHAT-T-191 §4.5).
                    $this->delayed = true;
                    error_log(sprintf(
                        '[PostgresConnection] OK po retry (%s): proby=%d, czas=%d ms',
                        $category,
                        $attempts,
                        self::elapsedMs($startedAt),
                    ));
                }
                return $result;
            } catch (PDOException $e) {
                $category = self::classifyError($e);
                if ($category === null) {
                    throw $e; // blad logiczny (skladnia/constraint) — nie ponawiamy
                }
                $lastError = $e;
                $this->pdo = null; // reconnect przy nastepnej probie

                // HARD_FINAL = stan trwaly (port zamkniety / DNS) → przerwij po 1. probie.
                if ($category === self::CAT_HARD_FINAL) {
                    break;
                }
                // SOFT i HARD_RETRY → backoff i ponow (o ile zostaly proby).
                if ($attempt < self::MAX_ATTEMPTS - 1) {
                    usleep(self::backoffMs($category, $attempt) * 1000);
                }
            }
        }

        $kind = self::toPublicKind($category);
        $this->unavailable = true;
        $this->breakerKind = $kind;
        error_log(sprintf(
            '[PostgresConnection] Railway niedostepne (%s → %s): proby=%d, czas=%d ms: %s',
            $category,
            $kind,
            $attempts,
            self::elapsedMs($startedAt),
            $lastError?->getMessage() ?? '?',
        ));
        throw $this->makeUnavailable($kind, $lastError);
    }

    /** Backoff [ms] przed kolejna proba, wg kategorii wewnetrznej (CHAT-T-191). */
    private static function backoffMs(string $category, int $attempt): int
    {
        $table = $category === self::CAT_SOFT
            ? self::RETRY_BACKOFF_MS
            : self::HARD_RETRY_BACKOFF_MS;

        return $table[$attempt] ?? 0;
    }

    /**
     * Kategoria WEWNETRZNA → flaga PUBLICZNA DbUnavailableException.
     * Obie kategorie HARD_* mapuja sie na dotychczasowy KIND_HARD — kontrakt
     * z ChatController (DB_SOFT_MESSAGE / DB_HARD_MESSAGE) bez zmian.
     */
    private static function toPublicKind(string $category): string
    {
        return $category === self::CAT_SOFT ? self::KIND_SOFT : self::KIND_HARD;
    }

    /** Czas od znacznika hrtime(true) w milisekundach. */
    private static function elapsedMs(int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
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
     * Klasyfikuje wyjatek na kategorie WEWNETRZNA (CHAT-T-191):
     * CAT_HARD_FINAL / CAT_HARD_RETRY / CAT_SOFT / null (blad nie-polaczeniowy).
     *
     * Komunikat decyduje PRZED SQLSTATE (08006 wystepuje i przy refused, i przy dropie).
     * HARD_FINAL sprawdzany PRZED HARD_RETRY, bo libpq zagniezdza przyczyne w komunikacie
     * ogolnym ("could not connect to server: Connection refused") — przy odwrotnej
     * kolejnosci prefiks "could not connect" przejalby tez przypadki trwale i kazdy
     * zamkniety port kosztowalby klienta pelne 10 s.
     */
    private static function classifyError(PDOException $e): ?string
    {
        $msg = strtolower($e->getMessage());

        // HARD_FINAL — stan trwaly: host odpowiada RST (port zamkniety) albo DNS
        // nie rozwiazuje nazwy. Ponawianie to czyste marnowanie sekund klienta.
        foreach ([
            'connection refused',
            'could not translate host',
            'name or service not known',
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::CAT_HARD_FINAL;
            }
        }

        // HARD_RETRY — trasa chwilowo nie przepuszcza pakietow, host zyje
        // (CHAT-T-191 §1: mediana serii niedostepnosci 19 s).
        foreach ([
            'could not connect',
            'connection timed out',
            'timeout expired',
            'no route to host',
            'network is unreachable',
        ] as $needle) {
            if (str_contains($msg, $needle)) {
                return self::CAT_HARD_RETRY;
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
                return self::CAT_SOFT;
            }
        }

        // Dopelnienie po SQLSTATE — UWAGA na zasieg (zmierzone na produkcji 2026-09-14):
        // przy bledzie w KONSTRUKTORZE PDO getCode() zwraca natywny kod pgsql (int 7),
        // a SQLSTATE siedzi w errorInfo[0]. Dowod: zle haslo → getCode()=7,
        // errorInfo=["08006",7,"FATAL: password authentication failed..."].
        // Galezie 08001/08004 lapia wiec praktycznie tylko bledy fazy ZAPYTANIA,
        // gdzie PDO wstawia SQLSTATE do kodu. Nierozpoznany blad connectu wpada
        // do '7' → SOFT (3 proby, 100/300 ms) — tak samo jak przed CHAT-T-191.
        $code = (string) $e->getCode();
        if ($code === '57P01') {
            return self::CAT_SOFT; // admin_shutdown — restart serwera, reconnect pomoze
        }
        if ($code === '08004') {
            return self::CAT_HARD_FINAL; // server_rejected — pg_hba, retry nie pomoze
        }
        if ($code === '08001') {
            // sqlclient_unable_to_establish z komunikatem, ktorego nie rozpoznalismy.
            // Domyslnie przejsciowe: przypadki trwale (refused, DNS) maja wlasne needle.
            return self::CAT_HARD_RETRY;
        }
        if (in_array($code, ['08006', '08000', '08003', '7'], true)) {
            return self::CAT_SOFT; // connection_exception (domyslnie traktuj jak zerwanie)
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
     * Konwertuje postgres:// URL na PDO DSN.
     *
     * connect_timeout=2 (CHAT-T-107 P36c) zostaje w DSN, ale UWAGA: sam z siebie
     * nic nie robi — pdo_pgsql dokleja wlasny connect_timeout na koncu conninfo
     * i to on wygrywa. Realnie limit ustawia PDO::ATTR_TIMEOUT w getPdo()
     * (CHAT-T-191, tam pomiar). Trzymamy obie wartosci rowne 2, zeby nie
     * rozjechaly sie przy nastepnej zmianie.
     *
     * Ta dwojka jest skladnikiem budzetu HARD_RETRY: trzy proby po 2 s plus
     * backoff 1 s i 3 s daje 2+1+2+3+2 = 10 s na zadanie. Podniesienie limitu
     * podnosi budzet o 3× roznice (kazda z trzech prob), wiec nie zmieniaj go
     * bez przeliczenia sufitu czekania klienta.
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
