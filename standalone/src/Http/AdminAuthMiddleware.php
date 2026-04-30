<?php

declare(strict_types=1);

namespace DiveChat\Http;

/**
 * Defense in depth: weryfikacja basic auth dla endpointów /api/admin/*.
 *
 * Apache .htaccess pod `public/admin/` chroni statyczne pliki dashboardu.
 * Tu chronimy endpointy API – nawet gdyby ktoś bypassed Apache (np. PHP-FPM
 * direct na port), middleware sprawdza credentiale przeciwko `.htpasswd`.
 *
 * `.htpasswd` jest poza repo (gitignored) – generuje go skrypt deploy.
 */
final class AdminAuthMiddleware
{
    public function __construct(
        private readonly string $htpasswdPath,
    ) {}

    public function check(): void
    {
        $user = $_SERVER['PHP_AUTH_USER'] ?? null;
        $pass = $_SERVER['PHP_AUTH_PW'] ?? null;

        if ($user === null || $pass === null) {
            $this->fail401();
        }

        if (!$this->verifyAgainstHtpasswd($user, $pass)) {
            $this->fail401(); // świadomie 401 zamiast 403 – ujednolicamy semantykę
        }
    }

    private function fail401(): never
    {
        header('WWW-Authenticate: Basic realm="DiveChat Admin", charset="UTF-8"');
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Parsuje .htpasswd (linia per użytkownik: `user:hash`).
     * Obsługuje formaty: APR1 ($apr1$…), bcrypt ($2y$…), SHA1 ({SHA}…),
     * crypt() (DES, 13-znakowy hash) i plain-text fallback.
     */
    private function verifyAgainstHtpasswd(string $user, string $pass): bool
    {
        if (!is_file($this->htpasswdPath) || !is_readable($this->htpasswdPath)) {
            error_log("[DiveChat] .htpasswd missing or unreadable: {$this->htpasswdPath}");
            return false;
        }

        $lines = file($this->htpasswdPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, ':')) {
                continue;
            }
            [$lineUser, $lineHash] = explode(':', $line, 2);
            if (!hash_equals($lineUser, $user)) {
                continue;
            }
            return $this->verifyHash($pass, $lineHash);
        }

        return false;
    }

    private function verifyHash(string $pass, string $hash): bool
    {
        // bcrypt $2y$ / $2a$
        if (str_starts_with($hash, '$2y$') || str_starts_with($hash, '$2a$') || str_starts_with($hash, '$2b$')) {
            return password_verify($pass, $hash);
        }

        // APR1 (Apache MD5) – `htpasswd -m` default na większości systemów
        if (str_starts_with($hash, '$apr1$')) {
            return $this->verifyApr1($pass, $hash);
        }

        // SHA1 (`htpasswd -s`): `{SHA}base64sha1`
        if (str_starts_with($hash, '{SHA}')) {
            $expected = base64_encode(sha1($pass, true));
            return hash_equals(substr($hash, 5), $expected);
        }

        // crypt() (DES, 13 znaków) – stare htpasswd default na BSD
        if (strlen($hash) === 13) {
            return hash_equals($hash, crypt($pass, $hash));
        }

        // Plain-text (htpasswd -p, NIE używać produkcyjnie)
        return hash_equals($hash, $pass);
    }

    /**
     * APR1 – proprietary Apache MD5. PHP nie ma natywnej, używamy crypt() z saltem.
     */
    private function verifyApr1(string $pass, string $hash): bool
    {
        // Format: $apr1$salt$hash
        $parts = explode('$', $hash);
        if (count($parts) < 4) {
            return false;
        }
        $salt = $parts[2];

        return hash_equals($hash, crypt($pass, '$apr1$' . $salt));
    }
}
