<?php

declare(strict_types=1);

/**
 * Cron godzinowy: dezaktywuje wygasłe Editorial Picks (ADR-054, T-008).
 *
 * Crontab linijka (do dodania ręcznie na serwerze):
 *   0 * * * * /opt/cpanel/ea-php84/root/usr/bin/php /home/divezone/public_html/chat.divezone.pl/scripts/cron_editorial_picks_expire.php >> /home/divezone/logs/divechat_editorial_picks_expire.log 2>&1
 *
 * Idempotentny: UPDATE z WHERE active=TRUE AND expires_at <= NOW(). Bezpieczny do wielokrotnego uruchamiania.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

use DiveChat\Config;
use DiveChat\Database\PostgresConnection;
use DiveChat\Editorial\EditorialPicksService;

Config::load(dirname(__DIR__));

$startedAt = (new DateTimeImmutable('now'))->format('Y-m-d H:i:sP');

try {
    $service = new EditorialPicksService(PostgresConnection::getInstance());
    $expired = $service->expireDue();
    fwrite(STDOUT, "[{$startedAt}] cron_editorial_picks_expire: expired {$expired} pick(s)\n");
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[{$startedAt}] cron_editorial_picks_expire ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
