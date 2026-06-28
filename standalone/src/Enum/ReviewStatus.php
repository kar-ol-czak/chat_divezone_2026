<?php

declare(strict_types=1);

namespace DiveChat\Enum;

/**
 * Os pracy recenzenta rozmowy (CHAT-T-104, ADR-102 pkt 1).
 * Wartosci musza pasowac do CHECK constraint w migracji 037
 * (divechat_conversation_review_status_chk).
 */
enum ReviewStatus: string
{
    case NOWY = 'nowy';
    case DO_WERYFIKACJI = 'do_weryfikacji';
    case W_TRAKCIE = 'w_trakcie';
    case ZAMKNIETY = 'zamkniety';

    /** Status nadawany przy tworzeniu wiersza przez flagowanie (ADR-102 pkt 2). */
    public const DEFAULT = self::DO_WERYFIKACJI;

    /** Walidacja wartosci z payloadu — null gdy nieznana (caller zwraca 422). */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}
