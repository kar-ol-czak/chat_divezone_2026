<?php

declare(strict_types=1);

namespace DiveChat\Shop;

/**
 * Wynik ShopCalendar::scheduleForDate().
 */
final readonly class ScheduleResult
{
    public function __construct(
        public bool $isOpen,
        public bool $workingDay,
        public ?string $holidayName,
        public string $opensAt,
        public string $closesAt,
        public ?string $closedReason,
    ) {}
}
