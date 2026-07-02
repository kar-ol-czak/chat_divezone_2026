<?php

declare(strict_types=1);

namespace DiveChat\Chip;

/**
 * Dekoder kolumny jsonb `divechat_conversations.chip_path` (CHAT-T-122/123/125,
 * ADR-110). Wspolny helper dla panelu recenzji: standalone /admin
 * (ConversationViewer, endpoint /api/admin/conversations/:id — CHAT-T-123) ORAZ
 * panel PS (ConversationsController::detail, /api/conversations/{sid} — CHAT-T-125).
 *
 * Wydzielony zeby NIE duplikowac identycznej logiki dekodowania w dwoch klasach
 * (ADR-110 realizacja). jsonb z PG (PDO) wraca jako string -> json_decode.
 */
final class ChipPathCodec
{
    /**
     * Dekoduj chip_path do listy wezlow `{node_key, label, level}`.
     * Defensywnie: null/pusty/niepoprawny JSON/nie-tablica/pusta tablica -> null
     * (panel nie renderuje breadcrumbu dla rozmow z wolnego pisania).
     *
     * @return list<array<string, mixed>>|null
     */
    public static function decode(mixed $raw): ?array
    {
        // Zwykle string z PG jsonb; tolerujemy tez juz-zdekodowana tablice.
        $decoded = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? json_decode($raw, true) : null);
        if (!is_array($decoded) || $decoded === []) {
            return null;
        }

        return array_values($decoded);
    }
}
