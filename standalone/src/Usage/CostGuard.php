<?php

declare(strict_types=1);

namespace DiveChat\Usage;

use DiveChat\Database\PostgresConnection;

/**
 * Lekka straz kosztow API LLM (CHAT-T-064, ADR-064).
 *
 * Wzorzec liczenia: identyczny jak CostAnalytics::aggregateRange — JEDNO
 * zrodlo prawdy o kosztach (tabela divechat_message_usage, kolumna
 * cost_total_usd, indeks idx_usage_created_model pokrywa filtr po dacie).
 *
 * Cap (hard 10 USD/dobe, decyzja 161) i alert (5 USD/dobe, decyzja 161/166)
 * sprawdzane PRZED kazdym wywolaniem LLM w ChatController. Koszt biezacej
 * rozmowy ksiegowany dopiero PO handle() w ChatService->UsageLogger, wiec
 * cap moze byc lekko przekroczony (1 rozmowa) — akceptowalne na poziomie 10$.
 *
 * Idempotencja alertu: tabela divechat_cost_alerts (PK=date). INSERT
 * ON CONFLICT DO NOTHING + rowCount() rozstrzyga race przy rownoleglych
 * requestach: tylko jeden worker "wygrywa" wpis i wysyla mail().
 *
 * mail() fail NIE blokuje czatu: error_log + flaga mail_ok=false, koniec.
 * Alert to dodatek, nie bramka.
 */
final class CostGuard
{
    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    /**
     * Sumaryczny koszt dnia (USD) z divechat_message_usage od CURRENT_DATE.
     * Jedno zapytanie, indeks pokrywa. Zwraca 0.0 przy braku wpisow.
     */
    public function dailyCostUsd(): float
    {
        $row = $this->db->fetchOne(
            "SELECT COALESCE(SUM(cost_total_usd), 0) AS cost_usd
             FROM divechat_message_usage
             WHERE created_at >= CURRENT_DATE",
        );
        return (float) ($row['cost_usd'] ?? 0);
    }

    /**
     * Wysyla mail alertu jesli dzisiejszy wpis w divechat_cost_alerts JESZCZE
     * nie istnieje (race-safe przez INSERT ON CONFLICT DO NOTHING + rowCount).
     *
     * Zwraca true jesli ten request wykonal wlasciwa akcje (insert+mail) —
     * do logowania/diagnostyki. False jesli alert juz wyslany dzis (lub blad
     * DB; cap dziala niezaleznie).
     *
     * mail() false -> error_log + mail_ok=false w wierszu, NIE rzucamy.
     */
    public function maybeSendAlert(float $spent, float $hardCap, string $alertEmail): bool
    {
        try {
            $stmt = $this->db->query(
                "INSERT INTO divechat_cost_alerts (alert_date, cost_usd, mail_ok)
                 VALUES (CURRENT_DATE, ?, TRUE)
                 ON CONFLICT (alert_date) DO NOTHING",
                [$spent],
            );
            $inserted = $stmt->rowCount() > 0;
        } catch (\Throwable $e) {
            error_log('[CostGuard] alert insert failed: ' . $e->getMessage());
            return false;
        }

        if (!$inserted) {
            // Inny worker juz wstawil wiersz dzisiaj -> nie wysylamy drugiego maila.
            return false;
        }

        $subject = '[DiveChat] Dzienny koszt przekroczyl ' . number_format($spent, 2) . ' USD';
        $today = date('Y-m-d');
        $body = "Dzienny koszt API czatu przekroczyl prog alertu.\n\n"
            . "Data:        {$today}\n"
            . 'Koszt dnia:  ' . number_format($spent, 4) . " USD\n"
            . 'Prog twardy: ' . number_format($hardCap, 2) . " USD (po nim czat zwraca komunikat zamiast wolac LLM)\n\n"
            . "Panel analityki: https://chat.divezone.pl/admin/\n"
            . "Zrodlo: divechat_message_usage (CURRENT_DATE).\n";

        $headers = "From: noreply@divezone.pl\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        $ok = @mail($alertEmail, $subject, $body, $headers);

        if (!$ok) {
            error_log('[CostGuard] mail() returned false for alert to ' . $alertEmail . ' (cost=' . $spent . ')');
            try {
                $this->db->query(
                    "UPDATE divechat_cost_alerts SET mail_ok = FALSE WHERE alert_date = CURRENT_DATE",
                );
            } catch (\Throwable $e) {
                error_log('[CostGuard] mail_ok update failed: ' . $e->getMessage());
            }
        }

        return true;
    }
}
