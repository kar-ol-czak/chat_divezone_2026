<?php

declare(strict_types=1);

namespace DiveChat\Auth;

/**
 * Weryfikacja tokenow HMAC kanalu SERWEROWEGO (modul PrestaShop -> backend).
 *
 * ADR-068 decyzja 174a: osobny sekret serwerowy (DIVECHAT_SERVER_SECRET) z
 * ladunkiem employee_id+timestamp. Rozdzielenie zaufania od klienckiego
 * HmacVerifier (kanal front PS -> backend dla klienta czatu, podpis
 * customer_id+timestamp).
 *
 * Konsekwencja: employee_id obecny w podpisie OD POCZATKU = gotowy fundament
 * pod live chat fazy 3 (kto obsluguje czat).
 *
 * Analogia algorytmu do HmacVerifier (sha256 hex + hash_equals + anti-replay
 * ±300s), ale OSOBNA klasa zeby nie miec ryzyka regresji w istniejacym kanale
 * /api/chat oraz zeby semantyka payloadu byla jednoznaczna w typach.
 */
final readonly class ServerHmacVerifier
{
    public function __construct(
        private string $secret,
        private int $maxAgeSec = 300, // 5 min anti-replay
    ) {}

    /**
     * Sprawdza poprawnosc tokenu HMAC dla podpisu serwerowego.
     *
     * @param string $token      HMAC sha256 hex (X-DiveChat-Server-Token)
     * @param int    $employeeId id pracownika PS (X-DiveChat-Server-Employee)
     * @param int    $timestamp  Unix ts modulu (X-DiveChat-Server-Time)
     */
    public function verify(string $token, int $employeeId, int $timestamp): bool
    {
        if (abs(time() - $timestamp) > $this->maxAgeSec) {
            return false;
        }

        $expected = hash_hmac('sha256', $employeeId . ':' . $timestamp, $this->secret);
        return hash_equals($expected, $token);
    }
}
