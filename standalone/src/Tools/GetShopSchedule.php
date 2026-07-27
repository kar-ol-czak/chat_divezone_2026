<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DateTimeImmutable;
use DateTimeZone;
use DiveChat\Shop\ShopCalendar;

/**
 * Sprawdza godziny pracy sklepu dla podanej daty (dziś / relative / bezwzględna).
 *
 * CHAT-T-070 (ADR-085, HOTFIX): model NIE liczy dat względnych ("jutro", "w piątek")
 * — defaultuje do swojego knowledge cutoffu (~styczeń 2025), wysyła np. date=2025-01-25.
 * Zamiast tego model wskazuje INTENCJĘ przez parametr `relative` (enum), backend
 * deterministycznie liczy ISO date z prawdziwego today (Europe/Warsaw).
 * Pole `server_today` w KAŻDEJ odpowiedzi jest dodatkową kotwicą.
 */
final class GetShopSchedule implements ToolInterface
{
    /** Mapowanie nazwy dnia tygodnia na ISO numer (pon=1 … niedz=7). NIE locale. */
    private const DAY_OF_WEEK_ISO = [
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
    ];

    private const RELATIVE_VALUES = [
        'today', 'tomorrow', 'day_after_tomorrow',
        'this_monday', 'this_tuesday', 'this_wednesday', 'this_thursday',
        'this_friday', 'this_saturday', 'this_sunday',
        'next_monday', 'next_tuesday', 'next_wednesday', 'next_thursday',
        'next_friday', 'next_saturday', 'next_sunday',
    ];

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
             . 'godziny pracy oraz server_today (dzisiejsza data Europe/Warsaw — kotwica). '
             . 'Używaj gdy klient pyta o "dziś/jutro/pojutrze/w piątek/za tydzień" (parametr relative) '
             . 'albo o konkretną datę kalendarzową (parametr date).';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'relative' => [
                    'type' => 'string',
                    'enum' => self::RELATIVE_VALUES,
                    'description' => 'Dla dat WZGLĘDNYCH użyj TEGO parametru, NIE licz daty samodzielnie. '
                        . 'today=dziś, tomorrow=jutro, day_after_tomorrow=pojutrze. '
                        . 'this_<dzień>=najbliższe wystąpienie tego dnia (dziś włącznie). '
                        . 'next_<dzień>=ten dzień w PRZYSZŁYM tygodniu. '
                        . 'Jeśli klient użył samej nazwy dnia (bez "następny/przyszły") a dziś JEST tym dniem '
                        . '— NIE zgaduj, dopytaj klienta dziś czy za tydzień, dopiero potem wołaj.',
                ],
                'date' => [
                    'type' => 'string',
                    'description' => 'Konkretna data kalendarzowa YYYY-MM-DD (np. "15 lipca" → 2026-07-15). '
                        . 'UŻYWAJ TYLKO dla dat bezwzględnych. Dla "jutro/dziś/w piątek" użyj relative. '
                        . 'NIE licz daty względnej sam — defaultujesz do złego roku.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): array
    {
        $tz = new DateTimeZone(ShopCalendar::TIMEZONE);
        $today = new DateTimeImmutable('today', $tz);
        $serverToday = $today->format('Y-m-d');

        $relativeInput = trim((string) ($params['relative'] ?? ''));
        $dateInput = trim((string) ($params['date'] ?? ''));

        if ($relativeInput !== '') {
            $date = $this->resolveRelative($relativeInput, $today);
            if ($date === null) {
                return [
                    'error' => "Nieznana wartość relative: {$relativeInput}. "
                        . 'Dozwolone: ' . implode(', ', self::RELATIVE_VALUES) . '.',
                    'server_today' => $serverToday,
                ];
            }
        } elseif ($dateInput !== '') {
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $dateInput, $tz);
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                return [
                    'error' => "Nieprawidłowy format daty: {$dateInput}. Wymagany YYYY-MM-DD.",
                    'server_today' => $serverToday,
                ];
            }
            $parsedYear = (int) $parsed->format('Y');
            $currentYear = (int) $today->format('Y');
            if ($parsedYear < $currentYear) {
                return [
                    'error' => "Podana data jest w przeszłym roku ({$parsedYear}). "
                        . 'Dla dat względnych użyj parametru relative; dla konkretnej daty podaj rok bieżący lub przyszły.',
                    'server_today' => $serverToday,
                ];
            }
            $date = $parsed;
        } else {
            $date = $today;
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
            'server_today' => $serverToday,
        ];
    }

    /**
     * Translacja enum relative → konkretny DateTimeImmutable (Europe/Warsaw).
     * Zwraca null gdy wartość spoza enuma.
     */
    private function resolveRelative(string $relative, DateTimeImmutable $today): ?DateTimeImmutable
    {
        if ($relative === 'today') {
            return $today;
        }
        if ($relative === 'tomorrow') {
            return $today->modify('+1 day');
        }
        if ($relative === 'day_after_tomorrow') {
            return $today->modify('+2 days');
        }

        if (str_starts_with($relative, 'this_') || str_starts_with($relative, 'next_')) {
            [$prefix, $dayName] = explode('_', $relative, 2);
            $targetDow = self::DAY_OF_WEEK_ISO[$dayName] ?? null;
            if ($targetDow === null) {
                return null;
            }
            $todayDow = (int) $today->format('N');
            $deltaThis = ($targetDow - $todayDow + 7) % 7;

            if ($prefix === 'this') {
                // Dziś włącznie: gdy dziś == target → delta=0.
                return $today->modify('+' . $deltaThis . ' days');
            }
            // next_<day>: zawsze PRZYSZŁY tydzień (gdy dziś=target → +7; inaczej delta_this+7).
            $deltaNext = $deltaThis === 0 ? 7 : $deltaThis + 7;
            return $today->modify('+' . $deltaNext . ' days');
        }

        return null;
    }
}
