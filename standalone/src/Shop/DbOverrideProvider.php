<?php

declare(strict_types=1);

namespace DiveChat\Shop;

use DateTimeImmutable;
use DiveChat\Database\PostgresConnection;

/**
 * Produkcyjny OverrideProvider — czyta z tabeli divechat_shop_calendar_overrides.
 */
final class DbOverrideProvider implements OverrideProvider
{
    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    public function findByDate(DateTimeImmutable $date): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT is_working_day, reason, opens_at::text AS opens_at, closes_at::text AS closes_at
             FROM divechat_shop_calendar_overrides
             WHERE date = ?',
            [$date->format('Y-m-d')],
        );

        if ($row === null) {
            return null;
        }

        return [
            'is_working_day' => (bool) $row['is_working_day'],
            'reason' => (string) $row['reason'],
            'opens_at' => $row['opens_at'] !== null ? substr((string) $row['opens_at'], 0, 5) : null,
            'closes_at' => $row['closes_at'] !== null ? substr((string) $row['closes_at'], 0, 5) : null,
        ];
    }
}
