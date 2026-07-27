<?php

declare(strict_types=1);

namespace DiveChat\Enum;

/**
 * Os jakosci czatu (CHAT-T-104, ADR-102 pkt 1). Nadawana przy domykaniu recenzji.
 * NULL dopoki recenzent nie domknie. Wartosci musza pasowac do CHECK constraint
 * w migracji 037 (divechat_conversation_review_verdict_chk).
 *
 * Przejscie na PROBLEM_ROZWIAZANY nadaje Karol po wdrozeniu fixu (ADR-102 pkt 1
 * — podzial rol, domkniecie petli poza narzedziem recenzenta).
 */
enum ReviewVerdict: string
{
    case OK = 'ok';
    case PROBLEM_DO_ROZWIAZANIA = 'problem_do_rozwiazania';
    case PROBLEM_ROZWIAZANY = 'problem_rozwiazany';

    /** Walidacja wartosci z payloadu — null gdy nieznana (caller zwraca 422). */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
