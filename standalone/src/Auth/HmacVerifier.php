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
        private int $maxAgeSec = 900, // 15 min (CHAT-T-076; po wdrozeniu odswiezania tokenu T-069 dlugie okno zbedne; domyka okno replay ADR-079; spojne z expires_in:900 endpointu)
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
