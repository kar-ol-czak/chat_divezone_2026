<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Database\PostgresConnection;
use GuzzleHttp\Client;

/**
 * Kurs USD→PLN z NBP.
 * Cache w `divechat_exchange_rates` per dzień. Refresh przez cron 1× dziennie 09:00 UTC.
 * Fallback przy 404 (weekend / święto): ostatni dostępny kurs.
 */
final class ExchangeRateService
{
    private const NBP_URL = 'https://api.nbp.pl/api/exchangerates/rates/A/USD/?format=json';
    private const FALLBACK_RATE = 4.00;

    private ?float $cachedRate = null;

    public function __construct(
        private readonly PostgresConnection $db,
        private readonly ?Client $http = null,
    ) {}

    public function getUsdToPln(): float
    {
        if ($this->cachedRate !== null) {
            return $this->cachedRate;
        }

        $row = $this->db->fetchOne(
            "SELECT rate_to_pln FROM divechat_exchange_rates
             WHERE currency = 'USD' AND rate_date = CURRENT_DATE
             LIMIT 1",
        );

        if ($row !== null) {
            return $this->cachedRate = (float) $row['rate_to_pln'];
        }

        // Brak kursu na dziś – ostatni znany.
        $row = $this->db->fetchOne(
            "SELECT rate_to_pln FROM divechat_exchange_rates
             WHERE currency = 'USD'
             ORDER BY rate_date DESC LIMIT 1",
        );

        if ($row !== null) {
            return $this->cachedRate = (float) $row['rate_to_pln'];
        }

        // Pierwsza inicjalizacja – brak żadnego kursu w bazie.
        return $this->cachedRate = self::FALLBACK_RATE;
    }

    public function refreshFromNBP(): array
    {
        $http = $this->http ?? new Client(['timeout' => 15]);

        try {
            $response = $http->request('GET', self::NBP_URL);
            $data = json_decode((string) $response->getBody(), true);

            $rate = (float) ($data['rates'][0]['mid'] ?? 0);
            $effectiveDate = (string) ($data['rates'][0]['effectiveDate'] ?? date('Y-m-d'));

            if ($rate <= 0) {
                throw new \RuntimeException('NBP zwrócił nieprawidłowy kurs');
            }

            $this->db->query(
                "INSERT INTO divechat_exchange_rates (rate_date, currency, rate_to_pln, source, fetched_at)
                 VALUES (?::date, 'USD', ?, 'NBP', NOW())
                 ON CONFLICT (rate_date, currency) DO UPDATE SET
                    rate_to_pln = EXCLUDED.rate_to_pln,
                    source = 'NBP',
                    fetched_at = NOW()",
                [$effectiveDate, $rate],
            );

            $this->cachedRate = null;

            return [
                'rate' => $rate,
                'date' => $effectiveDate,
                'status' => 'ok',
            ];
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // 404 NBP = weekend / święto. Bez błędu – kolejne pobranie spróbuje ponownie.
            if ($e->getResponse()->getStatusCode() === 404) {
                return [
                    'status' => 'no_rate_today',
                    'message' => 'NBP nie publikuje kursu (weekend/święto). Używam ostatniego znanego.',
                ];
            }
            throw $e;
        }
    }
}
