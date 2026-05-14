<?php

declare(strict_types=1);

namespace DiveChat\Shop;

use DateTimeImmutable;

/**
 * Dostawca override'ów kalendarza (urlopy, inwentaryzacje).
 * Produkcyjna implementacja czyta z PG; testowa może wstrzyknąć dowolne dane.
 */
interface OverrideProvider
{
    /**
     * Zwraca override dla danej daty lub null jeśli nie istnieje.
     *
     * @return array{is_working_day: bool, reason: string, opens_at: ?string, closes_at: ?string}|null
     */
    public function findByDate(DateTimeImmutable $date): ?array;
}
