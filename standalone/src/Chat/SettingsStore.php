<?php

declare(strict_types=1);

namespace DiveChat\Chat;

use DiveChat\Database\PostgresConnection;

/**
 * CRUD dla divechat_settings (PostgreSQL).
 * Wartości przechowywane jako JSONB — obsługuje skalary i obiekty.
 */
final class SettingsStore
{
    private readonly PostgresConnection $db;

    public function __construct()
    {
        $this->db = PostgresConnection::getInstance();
    }

    /**
     * Pobiera wszystkie ustawienia jako key => value (zdekodowane z JSON).
     */
    public function getAll(): array
    {
        $rows = $this->db->fetchAll('SELECT key, value FROM divechat_settings ORDER BY key');

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['key']] = json_decode($row['value'], true);
        }

        return $settings;
    }

    /**
     * Pobiera jedno ustawienie (zdekodowane z JSON).
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $row = $this->db->fetchOne(
            'SELECT value FROM divechat_settings WHERE key = ?',
            [$key],
        );

        return $row ? json_decode($row['value'], true) : $default;
    }

    /**
     * Ustawia wartość (kodowana jako JSON).
     */
    public function set(string $key, mixed $value): void
    {
        $jsonValue = json_encode($value, JSON_UNESCAPED_UNICODE);

        $this->db->query(
            'INSERT INTO divechat_settings (key, value, updated_at) VALUES (?, ?::jsonb, NOW())
             ON CONFLICT (key) DO UPDATE SET value = ?::jsonb, updated_at = NOW()',
            [$key, $jsonValue, $jsonValue],
        );
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
    }
}
