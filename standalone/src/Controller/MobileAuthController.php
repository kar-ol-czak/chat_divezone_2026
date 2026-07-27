<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\MobileAuthenticator;
use DiveChat\Auth\MobileSessionStore;
use DiveChat\Http\Request;
use DiveChat\Http\Response;
use DiveChat\Usage\RateLimiter;

/**
 * Mobilny panel admina — login / logout / whoami (CHAT-T-071, ADR-086).
 *
 * Kanal cookie HttpOnly+Secure+SameSite=Lax pod /m/api/*. NIE myl z kanalem
 * serwerowym HMAC (panel PS): tamten ma X-DiveChat-Server-* naglowki, ten
 * ma cookie dz_madmin. Endpointy rozmow pod /m/api/conversations* — patrz
 * MobileConversationsController.
 *
 * Granice:
 *  - Komunikat bledu logowania zawsze identyczny ("Nieprawidlowy login lub haslo")
 *    — anti-enumeration (sukces/zly mail/zle haslo/brak roli/active=0).
 *  - Rate-limit login per IP (10 prob / 15 min). 429 po przekroczeniu.
 *  - Cookie path /m, by nie kolidowal z /api/*.
 */
final class MobileAuthController
{
    public const COOKIE_NAME = 'dz_madmin';
    public const COOKIE_PATH = '/m';
    private const LOGIN_RATE_LIMIT = 10;
    private const LOGIN_RATE_WINDOW = 900; // 15 min

    public function __construct(
        private readonly MobileAuthenticator $authenticator,
        private readonly MobileSessionStore $sessions,
        private readonly RateLimiter $rateLimiter,
    ) {}

    public function login(Request $request): void
    {
        // Rate-limit per IP (anti brute-force). Niespoofowalne REMOTE_ADDR
        // (CHAT-T-066, ADR-064/082): nie czytamy XFF/CF-* bez zaufanego proxy.
        $ip = $request->getClientIp();
        if ($ip !== null) {
            $allowed = $this->rateLimiter->check(
                'mlogin:ip:' . $ip,
                self::LOGIN_RATE_LIMIT,
                self::LOGIN_RATE_WINDOW,
            );
            if (!$allowed) {
                Response::json(
                    ['ok' => false, 'error' => 'Za duzo prob logowania. Sprobuj za 15 minut.'],
                    429,
                );
            }
        }

        $body = $request->getJsonBody();
        $email = is_string($body['email'] ?? null) ? (string) $body['email'] : '';
        $password = is_string($body['password'] ?? null) ? (string) $body['password'] : '';

        $employee = $this->authenticator->authenticate($email, $password);

        if ($employee === null) {
            Response::json(
                ['ok' => false, 'error' => 'Nieprawidlowy login lub haslo'],
                401,
            );
        }

        $token = $this->sessions->create($employee['employee_id'], $employee['role']);
        $this->setSessionCookie($token, $this->sessions->ttlSeconds());

        Response::json([
            'ok' => true,
            'role' => $employee['role'],
        ]);
    }

    public function logout(Request $request): void
    {
        $token = $this->readCookie();
        if ($token !== null) {
            $this->sessions->destroy($token);
        }
        $this->clearSessionCookie();

        Response::json(['ok' => true]);
    }

    public function whoami(Request $request): void
    {
        $session = $this->validateOrFail();
        Response::json([
            'employee_id' => $session['employee_id'],
            'role' => $session['role'],
        ]);
    }

    /**
     * Pomocnicze: zwraca dane sesji albo 401 (Response::json ma exit).
     *
     * @return array{employee_id: int, role: string}
     */
    public function validateOrFail(): array
    {
        $token = $this->readCookie();
        if ($token === null) {
            Response::json(['error' => 'Unauthorized'], 401);
        }

        $session = $this->sessions->validate($token);
        if ($session === null) {
            // Token wygasl / nieznany — sprzatamy cookie.
            $this->clearSessionCookie();
            Response::json(['error' => 'Unauthorized'], 401);
        }

        return $session;
    }

    private function readCookie(): ?string
    {
        $value = $_COOKIE[self::COOKIE_NAME] ?? null;
        if (!is_string($value) || $value === '') {
            return null;
        }
        // Whitelist: hex (64 znaki) — nasz format. Inne -> ignoruj.
        if (preg_match('/^[a-f0-9]{32,128}$/', $value) !== 1) {
            return null;
        }
        return $value;
    }

    private function setSessionCookie(string $token, int $ttlSeconds): void
    {
        setcookie(self::COOKIE_NAME, $token, [
            'expires' => time() + $ttlSeconds,
            'path' => self::COOKIE_PATH,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearSessionCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => self::COOKIE_PATH,
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
