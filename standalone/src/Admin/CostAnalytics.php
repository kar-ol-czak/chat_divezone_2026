<?php

declare(strict_types=1);

namespace DiveChat\Admin;

use DiveChat\AI\ExchangeRateService;
use DiveChat\AI\PricingService;
use DiveChat\Database\PostgresConnection;

/**
 * Agregaty kosztów do admin dashboardu (TASK-055 sekcja A).
 *
 * Wszystkie liczone on-the-fly z `divechat_message_usage` + `divechat_conversations`.
 * Indeksy z migracji 008 (idx_usage_created_model, idx_conversations_cost) zapewniają
 * sub-secondową odpowiedź przy <1M wpisów.
 */
final class CostAnalytics
{
    public function __construct(
        private readonly PostgresConnection $db,
        private readonly PricingService $pricing,
        private readonly ExchangeRateService $exchangeRates,
    ) {}

    /**
     * KPI w nagłówku: dziś / tydzień / miesiąc + cost per resolution.
     *
     * @return array<string, mixed>
     */
    public function kpi(): array
    {
        $rate = $this->exchangeRates->getUsdToPln();

        $today = $this->aggregateRange('CURRENT_DATE', 'CURRENT_DATE + INTERVAL \'1 day\'');
        $thisWeek = $this->aggregateRange("DATE_TRUNC('week', NOW())", 'NOW()');
        $thisMonth = $this->aggregateRange("DATE_TRUNC('month', NOW())", 'NOW()');

        $cprUsd = $thisMonth['conversations'] > 0
            ? round($thisMonth['cost_usd'] / $thisMonth['conversations'], 6)
            : 0.0;

        return [
            'today' => $this->withPln($today, $rate),
            'this_week' => $this->withPln($thisWeek, $rate),
            'this_month' => $this->withPln($thisMonth, $rate),
            'cost_per_resolution' => [
                'this_month_usd' => $cprUsd,
                'this_month_pln' => round($cprUsd * $rate, 4),
                'industry_benchmark_usd' => '0.30 - 1.50',
                'vs_human_agent_usd' => '5.00 - 15.00',
            ],
            'currency_pln_rate' => round($rate, 4),
        ];
    }

    /**
     * Trend wydatków w okresie. period: daily|weekly|monthly.
     *
     * @return array<string, mixed>
     */
    public function trend(string $period, int $days): array
    {
        $bucket = match ($period) {
            'weekly' => "DATE_TRUNC('week', mu.created_at)::date",
            'monthly' => "DATE_TRUNC('month', mu.created_at)::date",
            default => 'DATE(mu.created_at)',
        };

        $rate = $this->exchangeRates->getUsdToPln();

        $rows = $this->db->fetchAll(
            "SELECT
                {$bucket} AS date,
                SUM(mu.cost_total_usd) AS cost_usd,
                COUNT(*) AS messages,
                COUNT(DISTINCT mu.conversation_id) AS conversations
             FROM divechat_message_usage mu
             WHERE mu.created_at >= NOW() - (? || ' days')::interval
             GROUP BY {$bucket}
             ORDER BY date ASC",
            [(string) $days],
        );

        $data = [];
        $totalUsd = 0.0;
        $totalMessages = 0;
        $totalConversations = 0;

        foreach ($rows as $r) {
            $usd = (float) ($r['cost_usd'] ?? 0);
            $msgs = (int) ($r['messages'] ?? 0);
            $convs = (int) ($r['conversations'] ?? 0);
            $data[] = [
                'date' => (string) $r['date'],
                'cost_usd' => round($usd, 6),
                'cost_pln' => round($usd * $rate, 4),
                'messages' => $msgs,
                'conversations' => $convs,
            ];
            $totalUsd += $usd;
            $totalMessages += $msgs;
            $totalConversations += $convs;
        }

        return [
            'period' => $period,
            'days' => $days,
            'currency_pln_rate' => round($rate, 4),
            'data' => $data,
            'totals' => [
                'cost_usd' => round($totalUsd, 6),
                'cost_pln' => round($totalUsd * $rate, 4),
                'messages' => $totalMessages,
                'conversations' => $totalConversations,
            ],
        ];
    }

    /**
     * Top N najdroższych rozmów w okresie.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topConversations(int $limit, int $days): array
    {
        $rate = $this->exchangeRates->getUsdToPln();

        $rows = $this->db->fetchAll(
            "SELECT
                c.id,
                c.session_id,
                c.started_at,
                c.updated_at,
                c.estimated_cost AS cost_usd,
                COALESCE(c.model_used, '') AS model_used,
                jsonb_array_length(COALESCE(c.messages, '[]'::jsonb)) AS messages_count,
                (SELECT m.content FROM divechat_messages m
                 WHERE m.conversation_id = c.id AND m.role = 'user'
                 ORDER BY m.created_at, m.id LIMIT 1) AS first_user_message
             FROM divechat_conversations c
             WHERE c.started_at >= NOW() - (? || ' days')::interval
               AND c.estimated_cost > 0
             ORDER BY c.estimated_cost DESC
             LIMIT ?",
            [(string) $days, $limit],
        );

        return array_map(function (array $r) use ($rate): array {
            $usd = (float) $r['cost_usd'];
            return [
                'id' => (int) $r['id'],
                'session_id' => $r['session_id'],
                'started_at' => $r['started_at'],
                'updated_at' => $r['updated_at'],
                'model_used' => $r['model_used'] ?: null,
                'messages_count' => (int) $r['messages_count'],
                'cost_usd' => round($usd, 6),
                'cost_pln' => round($usd * $rate, 4),
                'first_user_message' => $r['first_user_message'] ?: null,
            ];
        }, $rows);
    }

    /**
     * Breakdown kosztów per model.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byModel(int $days): array
    {
        $rate = $this->exchangeRates->getUsdToPln();

        $rows = $this->db->fetchAll(
            "SELECT
                mu.model_id,
                COUNT(*) AS uses,
                SUM(mu.input_tokens) AS input_tokens,
                SUM(mu.output_tokens) AS output_tokens,
                SUM(mu.cache_read_tokens) AS cache_read_tokens,
                SUM(mu.cost_total_usd) AS cost_usd,
                AVG(mu.latency_ms) FILTER (WHERE mu.latency_ms IS NOT NULL) AS avg_latency_ms
             FROM divechat_message_usage mu
             WHERE mu.created_at >= NOW() - (? || ' days')::interval
             GROUP BY mu.model_id
             ORDER BY cost_usd DESC",
            [(string) $days],
        );

        return array_map(function (array $r) use ($rate): array {
            $usd = (float) $r['cost_usd'];
            $uses = (int) $r['uses'];
            $price = $this->pricing->getPrice((string) $r['model_id']);
            return [
                'model_id' => (string) $r['model_id'],
                'label' => $price?->label ?? (string) $r['model_id'],
                'provider' => $price?->provider ?? 'unknown',
                'uses' => $uses,
                'input_tokens' => (int) $r['input_tokens'],
                'output_tokens' => (int) $r['output_tokens'],
                'cache_read_tokens' => (int) $r['cache_read_tokens'],
                'cost_usd' => round($usd, 6),
                'cost_pln' => round($usd * $rate, 4),
                'avg_cost_per_use_usd' => $uses > 0 ? round($usd / $uses, 6) : 0.0,
                'avg_latency_ms' => $r['avg_latency_ms'] !== null ? (int) round((float) $r['avg_latency_ms']) : null,
            ];
        }, $rows);
    }

    /**
     * Pomocnicza: agregaty (cost_usd, conversations) dla zakresu dat (raw SQL fragments).
     */
    private function aggregateRange(string $fromExpr, string $toExpr): array
    {
        $row = $this->db->fetchOne(
            "SELECT
                COALESCE(SUM(cost_total_usd), 0) AS cost_usd,
                COUNT(DISTINCT conversation_id) AS conversations,
                COUNT(*) AS messages
             FROM divechat_message_usage
             WHERE created_at >= {$fromExpr} AND created_at < {$toExpr}",
        );
        return [
            'cost_usd' => round((float) ($row['cost_usd'] ?? 0), 6),
            'conversations' => (int) ($row['conversations'] ?? 0),
            'messages' => (int) ($row['messages'] ?? 0),
        ];
    }

    private function withPln(array $bucket, float $rate): array
    {
        $bucket['cost_pln'] = round($bucket['cost_usd'] * $rate, 4);
        return $bucket;
    }
}
