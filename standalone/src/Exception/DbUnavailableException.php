<?php

declare(strict_types=1);

namespace DiveChat\Exception;

/**
 * Rzucany przez PostgresConnection po wyczerpaniu prób (CHAT-T-107, P36c).
 *
 * Sygnalizuje warstwie wyzej: "baza (Railway) chwilowo niedostepna" — w
 * odroznieniu od golego PDOException (ktory moze tez znaczyc blad skladni/
 * constraint). Niesie FLAGE rodzaju degradacji, ktora zasila komunikat klienta
 * (P37c):
 *  - SOFT: polaczenie ZERWANE w trakcie (server closed / 57P01) — retry nie
 *    pomogl, ale to przejsciowe. Komunikat: "spróbuj ponownie za moment".
 *  - HARD: Railway NIEOSIAGALNE (could not connect / timeout / refused) — twarda
 *    awaria. Komunikat: "problemy techniczne" + kontakt do czlowieka.
 */
final class DbUnavailableException extends \RuntimeException
{
    public const SOFT = 'soft';
    public const HARD = 'hard';

    public function __construct(
        private readonly string $kind = self::HARD,
        string $message = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function isHard(): bool
    {
        return $this->kind === self::HARD;
    }

    public function isSoft(): bool
    {
        return $this->kind === self::SOFT;
    }
}
