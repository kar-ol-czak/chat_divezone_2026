<?php

declare(strict_types=1);

namespace DiveChat\Auth;

use DiveChat\Database\PostgresConnection;

/**
 * Store sesji mobilnego panelu admina (CHAT-T-071, ADR-086).
 *
 * Sesje server-side w PostgreSQL (divechat_mobile_sessions). Cookie HttpOnly
 * + Secure + SameSite=Lax po stronie controllera; tutaj tylko CRUD na tokenie.
 *
 * Sliding TTL 12h: kazdy validate() przesuwa expires_at i last_seen_at
 * o 12h od NOW(). Token generujemy bin2hex(random_bytes(32)) — 64 znaki hex.
 */
final class MobileSessionStore
{
    private const TTL_SECONDS = 12 * 3600;

    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    /**
     * Tworzy nowa sesje. Zwraca token (cookie value).
     */
    public function create(int $employeeId, string $role): string
    {
        $token = bin2hex(random_bytes(32));

        $this->db->query(
            'INSERT INTO divechat_mobile_sessions
                 (session_token, employee_id, role, created_at, expires_at, last_seen_at)
             VALUES (?, ?, ?, NOW(), NOW() + (? || \' seconds\')::interval, NOW())',
            [$token, $employeeId, $role, (string) self::TTL_SECONDS],
        );

        return $token;
    }

    /**
     * Sprawdza token, przedluza sesje (sliding) i zwraca pyloadem albo null.
     *
     * @return array{employee_id: int, role: string}|null
     */
    public function validate(string $token): ?array
    {
        if ($token === '' || strlen($token) > 128) {
            return null;
        }

        // Atomowy UPDATE WHERE expires_at > NOW() — gwarantuje brak race
        // miedzy odczytem ważności a przedluzeniem. RETURNING zwraca dane
        // pracownika tylko jesli wpis byl jeszcze wazny.
        $row = $this->db->fetchOne(
            'UPDATE divechat_mobile_sessions
                SET last_seen_at = NOW(),
                    expires_at   = NOW() + (? || \' seconds\')::interval
              WHERE session_token = ?
                AND expires_at > NOW()
              RETURNING employee_id, role',
            [(string) self::TTL_SECONDS, $token],
        );

        if ($row === null) {
            return null;
        }

        return [
            'employee_id' => (int) $row['employee_id'],
            'role' => (string) $row['role'],
        ];
    }

    /**
     * Usuwa sesje (logout). Brak rzucenia gdy token nieznany — idempotentne.
     */
    public function destroy(string $token): void
    {
        if ($token === '' || strlen($token) > 128) {
            return;
        }
        $this->db->query(
            'DELETE FROM divechat_mobile_sessions WHERE session_token = ?',
            [$token],
        );
    }

    public function ttlSeconds(): int
    {
        return self::TTL_SECONDS;
    }
}
