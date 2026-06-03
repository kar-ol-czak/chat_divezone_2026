<?php

declare(strict_types=1);

namespace DiveChat\Auth;

/**
 * Weryfikacja tokenów HMAC z modułu PrestaShop.
 */
final readonly class HmacVerifier
{
    public function __construct(
        private string $secret,
        private int $maxAgeSec = 3600, // 1 h (CHAT-T-057; bylo 5 min — za krotkie dla realnych sesji; odswiezanie tokenu w ADR-064)
    ) {}

    /**
     * Sprawdza poprawność tokenu HMAC.
     */
    public function verify(string $token, int $customerId, int $timestamp): bool
    {
        // Sprawdź wiek timestampa
        if (abs(time() - $timestamp) > $this->maxAgeSec) {
            return false;
        }

        $expected = hash_hmac('sha256', $customerId . ':' . $timestamp, $this->secret);
        return hash_equals($expected, $token);
    }
}
