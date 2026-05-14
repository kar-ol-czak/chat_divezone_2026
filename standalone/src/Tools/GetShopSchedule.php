<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DateTimeImmutable;
use DateTimeZone;
use DiveChat\Shop\ShopCalendar;

/**
 * Sprawdza godziny pracy sklepu dla podanej daty (lub dziś).
 * Czyta ShopCalendar (święta + override'y z divechat_shop_calendar_overrides).
 */
final class GetShopSchedule implements ToolInterface
{
    public function __construct(
        private readonly ShopCalendar $calendar,
    ) {}

    public function getName(): string
    {
        return 'get_shop_schedule';
    }

    public function getDescription(): string
    {
        return 'Sprawdza godziny pracy sklepu dla podanej daty. '
             . 'Zwraca czy sklep będzie otwarty/zamknięty, nazwę święta jeśli dotyczy, '
             . 'i godziny pracy. Używaj gdy klient pyta o konkretną datę lub o "czy będzie otwarte" '
             . 'w przyszłości.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'date' => [
                    'type' => 'string',
                    'description' => 'Data w formacie YYYY-MM-DD. Jeśli pominięte, używa dzisiejszej daty (Europe/Warsaw).',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): array
    {
        $tz = new DateTimeZone(ShopCalendar::TIMEZONE);
        $dateInput = trim((string) ($params['date'] ?? ''));

        if ($dateInput === '') {
            $date = new DateTimeImmutable('today', $tz);
        } else {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput, $tz);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                return ['error' => "Nieprawidłowy format daty: {$dateInput}. Wymagany YYYY-MM-DD."];
            }
            $date = $parsed;
        }

        $schedule = $this->calendar->scheduleForDate($date);
        $nextWorkingDay = $this->calendar->nextWorkingDay($date);

        return [
            'date' => $date->format('Y-m-d'),
            'working_day' => $schedule->workingDay,
            'is_currently_open' => $schedule->isOpen,
            'holiday_name' => $schedule->holidayName,
            'closed_reason' => $schedule->closedReason,
            'opens_at' => $schedule->workingDay ? $schedule->opensAt : null,
            'closes_at' => $schedule->workingDay ? $schedule->closesAt : null,
            'next_working_day' => $nextWorkingDay->format('Y-m-d'),
        ];
    }
}
