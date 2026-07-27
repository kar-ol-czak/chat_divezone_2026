<?php

declare(strict_types=1);

/**
 * Daily cron: pobranie kursu USD→PLN z NBP do divechat_exchange_rates.
 *
 * Uruchamiać 1× dziennie 09:00 UTC (po publikacji NBP A-table).
 *
 * Cron entry (server, ea-php84):
 *   0 9 * * * /opt/cpanel/ea-php84/root/usr/bin/php \
 *     /home/divezone/public_html/chat.divezone.pl/scripts/refresh_exchange_rates.php \
 *     >> /home/divezone/logs/divechat_exchange_rates.log 2>&1
 */

use DiveChat\AI\ExchangeRateService;
use DiveChat\Database\PostgresConnection;

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

// Załaduj .env z root projektu (one level up od standalone/) lub lokalnie ze standalone/.
$dotenvPaths = [dirname($basePath), $basePath];
foreach ($dotenvPaths as $path) {
    if (is_file($path . '/.env')) {
        Dotenv\Dotenv::createImmutable($path)->safeLoad();
        break;
    }
}

$db = PostgresConnection::getInstance();
$service = new ExchangeRateService($db);

$timestamp = date('c');
echo "[{$timestamp}] Refreshing USD→PLN from NBP...\n";

try {
    $result = $service->refreshFromNBP();
    echo "[{$timestamp}] " . json_encode($result, JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "[{$timestamp}] ERROR: " . $e->getMessage() . "\n");
    exit(1);
}
