<?php

declare(strict_types=1);

namespace DiveChat\Admin;

/**
 * Rzucany gdy payload recenzji ma nieznana wartosc status/verdict (CHAT-T-104).
 * Kontroler lapie -> HTTP 422 (ADR-102, kontrakt API pkt 3).
 */
final class InvalidReviewValueException extends \InvalidArgumentException
{
}
