<?php

declare(strict_types=1);

namespace DiveChat\Usage;

use DiveChat\Database\PostgresConnection;

/**
 * Rate-limiter sliding window licznikowy w PostgreSQL (CHAT-T-066, ADR-064/082).
 *
 * Warstwa 3 ochrony publicznego endpointu LLM: licznik per zrodlo
 * (sessionId i IP). Atomowy UPSERT — INSERT ON CONFLICT DO UPDATE z
 * warunkiem reset/inkrement w jednym SQL — gwarantuje liczenie bez
 * gubienia inkrementu nawet przy rownoleglych requestach (lock na
 * wierszu PK podczas UPDATE).
 *
 * Algorytm:
 *  - Pierwszy request danego klucza: INSERT (count=1, window_start=NOW).
 *  - Kolejne: jesli window_start starszy niz windowSec -> RESET (count=1,
 *    window_start=NOW), inaczej INCREMENT (count+=1).
 *  - check() zwraca true gdy count <= limit (dozwolone). Wartosc count
 *    PO UPDATE jest porownywana z limitem.
 *
 * NIE blokujemy requestu cleanupem starych wpisow — tabela jest mala
 * (1 wiersz / aktywny visitor), DELETE moze chodzic z crona/recznie.
 */
final class RateLimiter
{
    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    /**
     * Inkrementuje licznik dla danego klucza w sliding window i zwraca
     * czy request jest dozwolony (count <= limit po inkrementacji).
     *
     * @param string $key       np. 'sess:abc123' lub 'ip:188.47.75.145'
     * @param int    $limit     maksymalna liczba requestow w oknie (>=1)
     * @param int    $windowSec dlugosc okna w sekundach (>0)
     * @return bool true = dozwolony, false = przekroczono limit
     */
    public function check(string $key, int $limit, int $windowSec): bool
    {
        if ($limit < 1 || $windowSec < 1 || $key === '') {
            // Defensywa: bledny config -> fail-open (lepiej puscic ruch niz wszystko odciac).
            return true;
        }

        $sql = <<<SQL
            INSERT INTO divechat_rate_limit (key, window_start, count)
            VALUES (?, NOW(), 1)
            ON CONFLICT (key) DO UPDATE
            SET
                window_start = CASE
                    WHEN divechat_rate_limit.window_start < NOW() - (? || ' seconds')::interval
                        THEN NOW()
                    ELSE divechat_rate_limit.window_start
                END,
                count = CASE
                    WHEN divechat_rate_limit.window_start < NOW() - (? || ' seconds')::interval
                        THEN 1
                    ELSE divechat_rate_limit.count + 1
                END
            RETURNING count
        SQL;

        try {
            $row = $this->db->fetchOne($sql, [$key, (string) $windowSec, (string) $windowSec]);
        } catch (\Throwable $e) {
            // PG chwilowo niedostepne -> fail-open. Limit to ochrona,
            // nie zaleznosc krytyczna — czat nie pada gdy licznik padnie.
            error_log('[RateLimiter] upsert failed (fail-open): ' . $e->getMessage());
            return true;
        }

        $count = (int) ($row['count'] ?? 0);
        return $count <= $limit;
    }
}
