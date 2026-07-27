<?php

declare(strict_types=1);

namespace DiveChat\Usage;

use DiveChat\Chat\SettingsStore;
use DiveChat\Config;
use DiveChat\Database\PostgresConnection;

/**
 * Alert na awarie polaczenia z baza danych (CHAT-T-079, ADR-088).
 *
 * Wzorzec 1:1 z CostGuard::maybeSendAlert (decyzja 247a — OSOBNA klasa,
 * OSOBNA tabela, NIE rozszerzamy CostGuard). Dedup race-safe:
 * INSERT ... ON CONFLICT (alert_window, db_target) DO NOTHING + rowCount()
 * rozstrzyga, ktory worker wysyla mail. Okno 30 min (decyzja 245b —
 * krotsze niz cost_alerts dobowe; awaria infra wymaga szybkiego
 * ponowienia po nawrocie).
 *
 * Wyzwala TYLKO bledy polaczenia/dostepu (decyzja 246a):
 *  - MySQL po driver code w errorInfo[1]: 1045/2002/2003/2006/2013
 *  - PG po SQLSTATE: klasa 08 (connection_exception) + 57P01 admin_shutdown
 *    + 57P03 cannot_connect_now
 * Bledy logiczne (42703 zla kolumna, 42P01 zla tabela, 42601 syntax) NIE
 * alertuja — to bug kodu/migracji, nie awaria infra. Puste wyniki tym
 * bardziej (nie sa PDOException).
 *
 * mail() fail NIE blokuje czatu (error_log + mail_ok=FALSE). Alert to
 * dodatek, nie bramka. Cala scieszka opakowana defensywnie — `maybeAlert`
 * NIGDY nie rzuca w gore, zeby nie zmienic zachowania czatu (Karol
 * dalej zwraca ['error' => ...] do toola).
 *
 * Tabela divechat_db_alerts zyje w PG (Railway), NIE w MySQL PS — gdy
 * padnie MySQL, alert musi dzialac. Gdy padnie PG samo, dedup INSERT
 * sie wywroci -> NIE wysylamy maila (zeby nie zarzucic skrzynki przy
 * dluzszej awarii PG). [DB-DOWN] w error_log poszedl PRZED probie
 * INSERT -> sygnal jest, mail tylko gdy dedup zadzialal.
 */
final class DbHealthAlert
{
    /** MySQL driver-specific codes (errorInfo[1]) interpretowane jako awaria polaczenia. */
    private const MYSQL_CONNECTION_CODES = [
        1045, // Access denied (incydent ADR-088)
        2002, // Can't connect (socket/host down)
        2003, // Can't connect to MySQL server (TCP)
        2006, // MySQL server has gone away
        2013, // Lost connection during query
    ];

    /** PG SQLSTATE poza klasa 08 (admin_shutdown / cannot_connect_now). */
    private const PG_CONNECTION_SQLSTATES = ['57P01', '57P03'];

    /** Okno dedup w sekundach (decyzja 245b — 30 min). */
    private const WINDOW_SECONDS = 1800;

    public function __construct(
        private readonly PostgresConnection $db,
        private readonly SettingsStore $settingsStore,
    ) {}

    /**
     * Gdy $e to awaria polaczenia DB — zaloguj [DB-DOWN], zapisz dedup,
     * wyslij mail (1 mail / 30 min / baza). NIGDY nie rzuca.
     *
     * @param \Throwable $e Wyjatek z ChatService::executeTool() catch.
     * @param string $toolName Nazwa narzedzia, ktore wywolalo blad — do log/mail.
     */
    public function maybeAlert(\Throwable $e, string $toolName): void
    {
        $classified = self::classifyConnectionFailure($e);
        if ($classified === null) {
            return; // blad logiczny / nie-PDO — nie alertujemy (decyzja 246a).
        }

        // [DB-DOWN] log ZAWSZE, niezaleznie od dedup maila (decyzja 248a — tani
        // sygnal do gridowania error_log i potwierdzenia czasu trwania awarii).
        error_log(sprintf(
            '[DB-DOWN] target=%s sqlstate=%s code=%s tool=%s',
            $classified['target'],
            $classified['sqlstate'] ?? '-',
            $classified['driver_code'] ?? '-',
            $toolName,
        ));

        $excerpt = self::sanitizeMessage($e->getMessage());

        $inserted = false;
        try {
            $stmt = $this->db->query(
                "INSERT INTO divechat_db_alerts
                    (alert_window, db_target, sqlstate, driver_code, error_excerpt, mail_ok)
                 VALUES (
                    to_timestamp(floor(EXTRACT(EPOCH FROM NOW()) / " . self::WINDOW_SECONDS . ") * " . self::WINDOW_SECONDS . "),
                    ?, ?, ?, ?, TRUE
                 )
                 ON CONFLICT (alert_window, db_target) DO NOTHING",
                [
                    $classified['target'],
                    $classified['sqlstate'],
                    $classified['driver_code'],
                    $excerpt,
                ],
            );
            $inserted = $stmt->rowCount() > 0;
        } catch (\Throwable $insertEx) {
            // PG padl lub tabela jeszcze nie zmigrowana. NIE wysylamy maila bez
            // dedup (ryzyko zarzucenia skrzynki przy dluzszej awarii PG).
            // [DB-DOWN] juz polecial -> sygnal jest.
            error_log('[DbHealthAlert] dedup insert failed: ' . $insertEx->getMessage());
            return;
        }

        if (!$inserted) {
            return; // inny worker juz wstawil w tym oknie -> jego mail.
        }

        $alertEmail = $this->resolveAlertEmail();
        $this->sendMail($classified, $excerpt, $toolName, $alertEmail);
    }

    /**
     * Czy wyjatek to awaria polaczenia/dostepu do DB? Convenience wrapper
     * nad classifyConnectionFailure() dla czytelnosci.
     */
    public static function isConnectionFailure(\Throwable $e): bool
    {
        return self::classifyConnectionFailure($e) !== null;
    }

    /**
     * Klasyfikuje wyjatek. Zwraca:
     *   ['target' => 'mysql'|'pgsql', 'sqlstate' => ?string, 'driver_code' => ?int]
     * lub null gdy nie jest to awaria polaczenia.
     *
     * Public static — pure function, ulatwia testowanie bez DI (PostgresConnection
     * ma private constructor; pozwala odpytywac klasyfikator w izolacji).
     */
    public static function classifyConnectionFailure(\Throwable $e): ?array
    {
        if (!$e instanceof \PDOException) {
            return null;
        }

        // PDOException::$code dziedziczone z Exception jest deklarowane jako int,
        // ale PDO ustawia tam SQLSTATE jako string (np. 'HY000', '08006'). Castujemy
        // na string defensywnie.
        $sqlstate = (string) $e->getCode();
        $errorInfo = $e->errorInfo ?? [];
        $driverCode = isset($errorInfo[1]) && is_numeric($errorInfo[1]) ? (int) $errorInfo[1] : null;

        // MySQL: rozpoznajemy po driver-specific code w errorInfo[1].
        // Dla 1045 SQLSTATE = '28000', dla 2002/2003/2006/2013 najczesciej 'HY000'.
        if ($driverCode !== null && in_array($driverCode, self::MYSQL_CONNECTION_CODES, true)) {
            return [
                'target' => 'mysql',
                'sqlstate' => $sqlstate !== '' ? $sqlstate : null,
                'driver_code' => $driverCode,
            ];
        }

        // PG: SQLSTATE klasa 08 (connection_exception/...) + admin_shutdown /
        // cannot_connect_now. Pierwsze znaki SQLSTATE rozstrzygaja klase.
        if (preg_match('/^08[0-9A-Z]{3}$/', $sqlstate) === 1
            || in_array($sqlstate, self::PG_CONNECTION_SQLSTATES, true)
        ) {
            return [
                'target' => 'pgsql',
                'sqlstate' => $sqlstate,
                'driver_code' => $driverCode,
            ];
        }

        return null;
    }

    /**
     * Krotki, bezpieczny komunikat do error_excerpt. PDOException messages
     * historycznie nie nosza realnego hasla, ale defensywnie filtrujemy
     * wzorce password=/pwd= i ucinamy do 240 znakow. Bez DSN.
     */
    public static function sanitizeMessage(string $raw): string
    {
        $cleaned = preg_replace(
            '/(password|pwd)\s*[=:]\s*["\']?[^\s"\',;)]+/i',
            '$1=[REDACTED]',
            $raw,
        ) ?? $raw;

        return mb_substr($cleaned, 0, 240);
    }

    /**
     * Odczyt alert e-mail wg wzorca z ChatController::readEmail (CHAT-T-067).
     *
     * Reuse klucza CostGuard `protect_cost_alert_email` + .env
     * `DIVECHAT_COST_ALERT_EMAIL` (decyzja 248a "jak reszta" — jeden adres
     * dla wszystkich alertow ochronnych, zero nowych kluczy konfiguracyjnych).
     * Jesli Karol chce osobnej skrzynki dla alertow DB — wystarczy zmiana
     * tu na nowy klucz, bez touchowania CostGuard.
     *
     * Gdy SettingsStore::get rzuci (np. PG padl wlasnie teraz) — fallback .env
     * tym samym filtrem sanity. Defensywa: alert e-mail ma sie wyslac.
     */
    private function resolveAlertEmail(): string
    {
        try {
            $raw = $this->settingsStore->get('protect_cost_alert_email', null);
            if (is_string($raw) && filter_var($raw, FILTER_VALIDATE_EMAIL) !== false) {
                return $raw;
            }
        } catch (\Throwable $e) {
            error_log('[DbHealthAlert] settings read failed (fallback .env): ' . $e->getMessage());
        }

        $envVal = Config::get('DIVECHAT_COST_ALERT_EMAIL');
        if (is_string($envVal) && filter_var($envVal, FILTER_VALIDATE_EMAIL) !== false) {
            return $envVal;
        }

        return 'k.susicki@divezone.pl';
    }

    private function sendMail(array $classified, string $excerpt, string $toolName, string $alertEmail): void
    {
        $target = $classified['target'];
        $code = (string) ($classified['driver_code'] ?? ($classified['sqlstate'] ?? '?'));
        $subject = "[DiveChat][DB-DOWN] Baza {$target} niedostepna ({$code})";
        $now = date('Y-m-d H:i:s');

        $body = "Czat wykryl awarie polaczenia z baza '{$target}'.\n\n"
            . "Czas:        {$now}\n"
            . "Baza:        {$target}\n"
            . "SQLSTATE:    " . ($classified['sqlstate'] ?? '-') . "\n"
            . "Driver code: " . ($classified['driver_code'] ?? '-') . "\n"
            . "Narzedzie:   {$toolName}\n"
            . "Excerpt:     {$excerpt}\n\n"
            . "Skutek dla uzytkownika: czat zwraca komunikat zastepczy zamiast wynikow narzedzia.\n"
            . "Dedup: ten mail nie powtorzy sie przez 30 min dla tej bazy (decyzja 245b).\n"
            . "Po nawrocie awarii kolejny mail pojdzie po kolejnym oknie 30-min.\n\n"
            . "Diagnoza: error_log na chat.divezone.pl (prefix [DB-DOWN]).\n"
            . "Kontekst: ADR-088 (incydent 1045 z 2026-06-06).\n";

        $headers = "From: noreply@divezone.pl\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n";

        $ok = @mail($alertEmail, $subject, $body, $headers);

        if (!$ok) {
            error_log('[DbHealthAlert] mail() returned false for alert to ' . $alertEmail . ' (target=' . $target . ')');
            try {
                $this->db->query(
                    "UPDATE divechat_db_alerts SET mail_ok = FALSE
                     WHERE alert_window = to_timestamp(floor(EXTRACT(EPOCH FROM NOW()) / " . self::WINDOW_SECONDS . ") * " . self::WINDOW_SECONDS . ")
                       AND db_target = ?",
                    [$target],
                );
            } catch (\Throwable $e) {
                error_log('[DbHealthAlert] mail_ok update failed: ' . $e->getMessage());
            }
        }
    }
}
