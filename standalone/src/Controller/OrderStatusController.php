<?php

declare(strict_types=1);

namespace DiveChat\Controller;

use DiveChat\Auth\HmacVerifier;
use DiveChat\Config;
use DiveChat\Http\Request;
use DiveChat\Http\Response;
use DiveChat\Tools\OrderStatus;

/**
 * Endpoint statusu zamówienia (modal "Status zamówienia", CHAT-T-042).
 * POST /api/order/status
 *
 * ADR-063 (privacy-by-design): numer zamówienia + email walidowane PHP,
 * NIE przez LLM. Tożsamość wymusza zgodność OBU pól z jednym zamówieniem
 * (delegowane do OrderStatus::execute, który zwraca jednolity komunikat
 * "nie znaleziono" niezależnie czy nie ma numeru, czy email nie pasuje —
 * brak enumeracji cudzych zamówień).
 *
 * Auth: kliencki HMAC jak /api/chat/stream (X-DiveChat-Token/-Customer/-Time).
 * customerId=0 dozwolony (anonimowy klient sprawdza zamówienie bez logowania —
 * email + numer jest autorytatywną weryfikacją tożsamości).
 *
 * Kody:
 *  200 — sukces (payload z OrderStatus::execute)
 *  400 — brak/puste pole order_reference lub email
 *  401 — brak/zły HMAC
 *  404 — nie znaleziono (jednolite — nie wyciekamy czy nie ma numeru czy zły email)
 *  500 — wyjątek serwera
 */
final class OrderStatusController
{
    public function __construct(
        private readonly OrderStatus $orderStatus,
    ) {}

    public function handle(Request $request): void
    {
        // 1. Weryfikacja HMAC (identyczny wzorzec co ChatController).
        $token = $request->getHeader('x-divechat-token');
        $customerId = $request->getHeader('x-divechat-customer');
        $timestamp = $request->getHeader('x-divechat-time');

        if ($token === null || $customerId === null || $timestamp === null) {
            Response::error('Brak wymaganych headerów autoryzacji', 401);
        }

        $secret = Config::get('DIVECHAT_SECRET', '');
        if ($secret === '') {
            Response::error('Brak konfiguracji DIVECHAT_SECRET', 500);
        }

        $verifier = new HmacVerifier($secret);
        if (!$verifier->verify($token, (int) $customerId, (int) $timestamp)) {
            Response::error('Nieprawidłowy token', 401);
        }

        // 2. Walidacja body.
        $body = $request->getJsonBody();
        $reference = trim((string) ($body['order_reference'] ?? ''));
        $email = trim((string) ($body['email'] ?? ''));

        if ($reference === '' || $email === '') {
            Response::error('Pola "order_reference" i "email" są wymagane', 400);
        }

        // 3. Lookup — ZERO LLM, czysty wywołanie istniejącego narzędzia.
        try {
            $result = $this->orderStatus->execute([
                'order_reference' => $reference,
                'customer_email' => $email,
            ]);

            // Tool zwraca ['error' => ...] dla "nie znaleziono / brak zgodności
            // numer+email" — jednolite, brak enumeracji. Mapujemy na 404.
            if (isset($result['error'])) {
                Response::error($result['error'], 404);
            }

            Response::json([
                'success' => true,
                'order' => $result,
            ]);
        } catch (\Throwable $e) {
            // NIE pokazujemy szczegółów wyjątku w produkcji — mogą zawierać
            // fragmenty zapytań SQL z danymi klienta.
            $errorMessage = Config::isDebug()
                ? $e->getMessage()
                : 'Wystąpił błąd. Spróbuj ponownie.';

            Response::error($errorMessage, 500);
        }
    }
}
