<?php

declare(strict_types=1);

namespace DiveChat\Chat;

use DiveChat\Support\FileCache;
use DiveChat\Database\PostgresConnection;
use DiveChat\Exception\DbUnavailableException;

/**
 * CRUD dla divechat_settings (PostgreSQL).
 * Wartości przechowywane jako JSONB — obsługuje skalary i obiekty.
 *
 * CHAT-T-107: odczyty (get/getAll) NIGDY nie rzucaja do uzytkownika. Przy
 * niedostepnosci Railway (DbUnavailableException) degraduja przez cache plikowy:
 * swiezy (TTL 300s) → last-known-good (dowolny wiek) → default/[]. Zapisy
 * (set/delete) sa write-through (odswiezaja cache po sukcesie); gdy baza pada,
 * zapis legalnie rzuca (admin widzi blad — nie udajemy udanego zapisu).
 */
final class SettingsStore
{
    private const TTL = 300;

    private readonly PostgresConnection $db;
    private readonly FileCache $cache;

    public function __construct(?FileCache $cache = null)
    {
        $this->db = PostgresConnection::getInstance();
        $this->cache = $cache ?? new FileCache();
    }

    /**
     * Pobiera wszystkie ustawienia jako key => value (zdekodowane z JSON).
     * Degradacja: cache (swiezy → last-known-good) → [].
     */
    public function getAll(): array
    {
        $cacheKey = 'settings.all';
        try {
            $rows = $this->db->fetchAll('SELECT key, value FROM divechat_settings ORDER BY key');
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['key']] = json_decode($row['value'], true);
            }
            $this->cache->set($cacheKey, $settings);
            return $settings;
        } catch (DbUnavailableException $e) {
            return $this->degrade($cacheKey, []);
        }
    }

    /**
     * Pobiera jedno ustawienie (zdekodowane z JSON).
     * Degradacja: cache (swiezy → last-known-good) → $default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $cacheKey = 'settings.get.' . $key;
        try {
            $row = $this->db->fetchOne(
                'SELECT value FROM divechat_settings WHERE key = ?',
                [$key],
            );
            if ($row) {
                $val = json_decode($row['value'], true);
                $this->cache->set($cacheKey, $val); // cache'ujemy TYLKO realna wartosc z DB
                return $val;
            }
            return $default; // klucz nieobecny — NIE cache'ujemy defaultu callera
        } catch (DbUnavailableException $e) {
            return $this->degrade($cacheKey, $default);
        }
    }

    /**
     * Ustawia wartość (kodowana jako JSON). Write-through: po sukcesie odswiez
     * cache klucza i uniewaznij agregat.
     */
    public function set(string $key, mixed $value): void
    {
        $jsonValue = json_encode($value, JSON_UNESCAPED_UNICODE);

        $this->db->query(
            'INSERT INTO divechat_settings (key, value, updated_at) VALUES (?, ?::jsonb, NOW())
             ON CONFLICT (key) DO UPDATE SET value = ?::jsonb, updated_at = NOW()',
            [$key, $jsonValue, $jsonValue],
        );

        $this->cache->set('settings.get.' . $key, $value);
        $this->cache->forget('settings.all');
    }

    /**
     * Aktualizuje wiele ustawień naraz.
     */
    public function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            $this->set($key, $value);
        }
    }

    /**
     * Usuwa wpis settings (no-op gdy nie istnieje).
     */
    public function delete(string $key): void
    {
        $this->db->query('DELETE FROM divechat_settings WHERE key = ?', [$key]);

        $this->cache->forget('settings.get.' . $key);
        $this->cache->forget('settings.all');
    }

    /**
     * Strategia degradacji odczytu: swiezy cache (TTL) → last-known-good → fallback.
     */
    private function degrade(string $cacheKey, mixed $fallback): mixed
    {
        [$fresh, $val] = $this->cache->getFresh($cacheKey, self::TTL);
        if ($fresh) {
            return $val;
        }
        [$any, $lkg] = $this->cache->getAny($cacheKey);
        if ($any) {
            error_log('[SettingsStore] Railway down — last-known-good dla ' . $cacheKey);
            return $lkg;
        }
        error_log('[SettingsStore] Railway down + brak cache dla ' . $cacheKey . ' → fallback');
        return $fallback;
    }
}
