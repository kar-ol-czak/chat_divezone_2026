<?php

declare(strict_types=1);

namespace DiveChat\Support;

/**
 * Lekki cache plikowy (CHAT-T-107) — fallback dla odczytow krytycznych gdy
 * Railway niedostepne. APCu BRAK na ea-php84 (zweryfikowane), wiec pliki.
 *
 * (Namespace Support, NIE Cache — katalog `cache/` jest w .gitignore, kolidowal.)
 *
 * Katalog `var/cache/` jest SIBLINGIEM `public/` (poza docrootem → niedostepny
 * z web); dodatkowo przy pierwszym zapisie kladziemy `.htaccess` deny (obrona
 * w glab). Format wpisu: JSON {ts, data}. Rozroznienie "swiezy" (TTL) od
 * "last known good" (dowolny wiek) dla strategii degradacji.
 *
 * Zwroty jako [bool $found, mixed $value] — by odroznic zapisany null/false od
 * braku wpisu (wartosci settings moga byc legalnie null/false).
 */
final class FileCache
{
    private readonly string $dir;

    public function __construct(?string $dir = null)
    {
        // src/Support/FileCache.php → standalone/ → var/cache
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/var/cache';
    }

    /** @param mixed $value */
    public function set(string $key, $value): void
    {
        $this->ensureDir();
        $payload = json_encode(['ts' => time(), 'data' => $value], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return; // wartosc nieserializowalna — cicho rezygnujemy z cache (nie wybuchamy)
        }
        @file_put_contents($this->path($key), $payload, LOCK_EX);
    }

    /**
     * Wpis swiezszy niz $ttl sekund.
     * @return array{0: bool, 1: mixed} [found, value]
     */
    public function getFresh(string $key, int $ttl): array
    {
        $r = $this->read($key);
        if ($r === null) {
            return [false, null];
        }
        if (time() - (int) $r['ts'] > $ttl) {
            return [false, null];
        }
        return [true, $r['data']];
    }

    /**
     * Ostatnia znana wartosc, niezaleznie od wieku (ostatnia deska ratunku).
     * @return array{0: bool, 1: mixed} [found, value]
     */
    public function getAny(string $key): array
    {
        $r = $this->read($key);
        return $r === null ? [false, null] : [true, $r['data']];
    }

    public function forget(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->dir . '/' . sha1($key) . '.json';
    }

    /** @return array{ts: int|string, data: mixed}|null */
    private function read(string $key): ?array
    {
        $p = $this->path($key);
        if (!is_file($p)) {
            return null;
        }
        $raw = @file_get_contents($p);
        if ($raw === false) {
            return null;
        }
        $d = json_decode($raw, true);
        if (!is_array($d) || !isset($d['ts']) || !array_key_exists('data', $d)) {
            return null;
        }
        return $d;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0o775, true);
        }
        $ht = $this->dir . '/.htaccess';
        if (!file_exists($ht)) {
            // Apache 2.2 (Deny) + 2.4 (Require) — obrona w glab (katalog i tak poza docrootem).
            @file_put_contents($ht, "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }
    }
}
