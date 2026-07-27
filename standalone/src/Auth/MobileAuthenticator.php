<?php

declare(strict_types=1);

namespace DiveChat\Auth;

use DiveChat\Database\MysqlConnection;
use DiveChat\Database\PostgresConnection;

/**
 * Weryfikacja poswiadczen pracownika dla mobilnego panelu admina
 * (CHAT-T-071, ADR-086).
 *
 * Logika:
 *  1. SELECT pr_employee po emailu (active=1).
 *  2. Hasla:
 *     - PRIMARY: bcrypt -> password_verify (dzisiaj WSZYSCY pracownicy).
 *     - FALLBACK: legacy md5(_COOKIE_KEY_ . password) gdy hash dlugosc=32 hex
 *       (konta sprzed migracji 1.6->1.7; obecnie ZERO w sklepie).
 *  3. SELECT divechat_admin_roles po employee_id — brak roli = brak dostepu.
 *
 * Timing-safe:
 *  - Gdy email nieznany / active=0 — i tak wywolujemy password_verify na
 *    dummy bcrypt hashu, zeby czas odpowiedzi nie zdradzal istnienia konta.
 *  - Komunikat bledu zawsze identyczny po stronie controllera; tutaj zwracamy
 *    po prostu null, controller mapuje na "Nieprawidlowy login lub haslo".
 *
 * Granice:
 *  - ZERO zapisu do MySQL PS (MysqlConnection wymusza SELECT-only).
 *  - cookie_key NIGDY nie loguje sie / wraca w API.
 */
final class MobileAuthenticator
{
    /**
     * Stały dummy bcrypt hash do timing-safe verify gdy email nieznany.
     * Wartosc nieistotna — chodzi o to, by password_verify zawsze
     * konsumowal czas porownywalny z realnym hashem (cost=10).
     * Wygenerowane: password_hash('dummy-no-account', PASSWORD_BCRYPT).
     */
    private const DUMMY_BCRYPT = '$2y$10$0zx/jL8pKbDmKEF63.ZOlOZjBGrjiSZP4G6xb6/XMLC2db8s3ugFy';

    public function __construct(
        private readonly MysqlConnection $mysql,
        private readonly PostgresConnection $pg,
        private readonly PsCookieKeyReader $cookieKeyReader,
    ) {}

    /**
     * @return array{employee_id: int, role: string, email: string}|null
     */
    public function authenticate(string $email, string $password): ?array
    {
        $email = trim($email);
        if ($email === '' || $password === '') {
            // I tak wywolaj dummy verify, by nie ujawnic 'pusty input' krotszym czasem.
            $this->dummyVerify($password);
            return null;
        }

        $row = $this->mysql->fetchOne(
            'SELECT id_employee, email, passwd, active FROM ' . $this->employeesTable()
            . ' WHERE email = ? LIMIT 1',
            [$email],
        );

        $employeeId = null;
        $passwdHash = null;
        $active = false;

        if ($row !== null) {
            $employeeId = (int) ($row['id_employee'] ?? 0);
            $passwdHash = isset($row['passwd']) ? (string) $row['passwd'] : null;
            $active = (int) ($row['active'] ?? 0) === 1;
        }

        $verified = $this->verifyPassword($password, $passwdHash);

        if (!$verified || !$active || $employeeId === null || $employeeId <= 0) {
            return null;
        }

        $roleRow = $this->pg->fetchOne(
            'SELECT role FROM divechat_admin_roles WHERE employee_id = ?',
            [$employeeId],
        );

        if ($roleRow === null) {
            return null;
        }

        return [
            'employee_id' => $employeeId,
            'role' => (string) $roleRow['role'],
            'email' => (string) ($row['email'] ?? $email),
        ];
    }

    /**
     * Wybor algorytmu po hashu z bazy:
     *  - bcrypt (prefix $2): password_verify.
     *  - legacy md5 (dlugosc 32, ctype_xdigit): hash_equals(md5(cookie_key.pass)).
     *  - inne / null: dummy verify (timing-safe), zwrot false.
     */
    private function verifyPassword(string $password, ?string $hash): bool
    {
        if (!is_string($hash) || $hash === '') {
            $this->dummyVerify($password);
            return false;
        }

        if (str_starts_with($hash, '$2')) {
            return password_verify($password, $hash);
        }

        if (strlen($hash) === 32 && ctype_xdigit($hash)) {
            $cookieKey = $this->cookieKeyReader->getCookieKey();
            if ($cookieKey === null) {
                // Legacy hash, ale brak cookie_key -> fallback nieaktywny.
                // Dummy verify do utrzymania czasu odpowiedzi.
                $this->dummyVerify($password);
                return false;
            }
            return hash_equals($hash, md5($cookieKey . $password));
        }

        // Nieznany format (np. argon2i wprowadzony pozniej w PS) — odrzucamy
        // bezpiecznie, dummy verify dla czasu.
        $this->dummyVerify($password);
        return false;
    }

    private function dummyVerify(string $password): void
    {
        // password_verify($password, DUMMY_BCRYPT) zwroci false; chodzi
        // wylacznie o zuzycie czasu CPU porownywalnego z realnym hashem.
        password_verify($password, self::DUMMY_BCRYPT);
    }

    /**
     * Nazwa tabeli z prefiksem PS (domyslnie pr_). Konfigurowalna z .env
     * dla srodowisk testowych — w realnym deploy MySQL ma prefix pr_.
     */
    private function employeesTable(): string
    {
        $prefix = $_ENV['PS_DB_PREFIX'] ?? 'pr_';
        // Whitelist: prefix moze byc tylko [a-z0-9_], inaczej fallback pr_.
        if (!preg_match('/^[a-z0-9_]+$/i', (string) $prefix)) {
            $prefix = 'pr_';
        }
        return $prefix . 'employee';
    }
}
