<?php

declare(strict_types=1);

namespace DiveChat\Tools;

use DiveChat\Database\PostgresConnection;

/**
 * Dane do przelewu (numery kont, SWIFT) oraz oficjalne linki sklepu
 * (kontakt, regulamin, polityka prywatności, blog, encyklopedia, zwroty,
 * formy płatności, serwis, o nas, B2B, filmy produktowe).
 * Dane z tabeli divechat_shop_config (edytowalne online, key/value).
 * ADR-095 (migracja 028).
 *
 * Czyta po whiteliście kluczy (prefiksy bank_ i link_). Klucze z wartością
 * 'TODO' (jeszcze niepotwierdzone URL-e) są pomijane — bot ich nie poda.
 */
final class GetShopLinks implements ToolInterface
{
    /** Mapowanie kluczy configu → grupa tematyczna (do filtra topic). */
    private const ACCOUNT_KEYS = ['bank_account_pln', 'bank_account_eur', 'bank_swift'];

    private const LINK_KEYS = [
        'link_kontakt',
        'link_regulamin',
        'link_polityka_prywatnosci',
        'link_blog',
        'link_encyklopedia',
        'link_zwroty',
        'link_platnosci',
        'link_serwis',
        'link_o_nas',
        'link_b2b',
        'link_filmy',
    ];

    public function __construct(
        private readonly PostgresConnection $db,
    ) {}

    public function getName(): string
    {
        return 'get_shop_links';
    }

    public function getDescription(): string
    {
        return 'Dane do przelewu (numery kont bankowych, SWIFT) oraz oficjalne linki sklepu divezone.pl '
             . '(kontakt, regulamin, polityka prywatności, blog, encyklopedia, zwroty, formy płatności, '
             . 'serwis sprzętu, o nas, współpraca B2B, filmy produktowe). '
             . 'Używaj gdy klient pyta o płatność/przelew/numer konta lub o którąś z tych stron. '
             . 'Parametr topic (opcjonalny) zawęża: payment (konta + formy płatności), legal (regulamin/polityka), '
             . 'content (blog/encyklopedia), contact (kontakt/zwroty), service (serwis), about (o nas/B2B/filmy). '
             . 'Domyślnie zwraca komplet.';
    }

    public function getParametersSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => [
                    'type' => 'string',
                    'enum' => ['payment', 'legal', 'content', 'contact', 'service', 'about'],
                    'description' => 'Zawężenie tematyczne: payment (numery kont + formy płatności), legal (regulamin/polityka), '
                        . 'content (blog/encyklopedia), contact (kontakt/zwroty), service (serwis sprzętu), '
                        . 'about (o nas/B2B/filmy). Pomiń, by dostać komplet.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $params): array
    {
        $topic = $params['topic'] ?? null;
        if (!in_array($topic, ['payment', 'legal', 'content', 'contact', 'service', 'about'], true)) {
            $topic = null;
        }

        return self::buildResult($this->loadConfig(), $topic);
    }

    /**
     * Czysta transformacja config-map → struktura wyniku (z filtrem topic).
     * Wydzielona by była deterministycznie testowalna bez PostgreSQL.
     *
     * @param array<string, string> $config klucze configu (już bez 'TODO'/pustych)
     */
    public static function buildResult(array $config, ?string $topic): array
    {
        $accounts = [
            'pln'   => $config['bank_account_pln'] ?? null,
            'eur'   => $config['bank_account_eur'] ?? null,
            'swift' => $config['bank_swift'] ?? null,
        ];

        $links = [
            'kontakt'              => $config['link_kontakt'] ?? null,
            'regulamin'            => $config['link_regulamin'] ?? null,
            'polityka_prywatnosci' => $config['link_polityka_prywatnosci'] ?? null,
            'blog'                 => $config['link_blog'] ?? null,
            'encyklopedia'         => $config['link_encyklopedia'] ?? null,
            'zwroty'               => $config['link_zwroty'] ?? null,
            'platnosci'            => $config['link_platnosci'] ?? null,
            'serwis'               => $config['link_serwis'] ?? null,
            'o_nas'                => $config['link_o_nas'] ?? null,
            'b2b'                  => $config['link_b2b'] ?? null,
            'filmy'                => $config['link_filmy'] ?? null,
        ];

        // Filtr topic — zawęża zwracaną strukturę.
        return match ($topic) {
            'payment' => [
                'accounts' => $accounts,
                'links'    => [
                    'kontakt'   => $links['kontakt'],
                    'platnosci' => $links['platnosci'],
                ],
                'note'     => 'Numer konta podaj wprost przy pytaniu o przelew. Zawsze dołącz link do strony kontaktu.',
            ],
            'legal' => [
                'links' => [
                    'regulamin'            => $links['regulamin'],
                    'polityka_prywatnosci' => $links['polityka_prywatnosci'],
                ],
            ],
            'content' => [
                'links' => [
                    'blog'         => $links['blog'],
                    'encyklopedia' => $links['encyklopedia'],
                ],
            ],
            'contact' => [
                'links' => [
                    'kontakt' => $links['kontakt'],
                    'zwroty'  => $links['zwroty'],
                ],
            ],
            'service' => [
                'links' => [
                    'serwis'  => $links['serwis'],
                    'kontakt' => $links['kontakt'],
                ],
            ],
            'about' => [
                'links' => [
                    'o_nas' => $links['o_nas'],
                    'b2b'   => $links['b2b'],
                    'filmy' => $links['filmy'],
                ],
            ],
            default => [
                'accounts' => $accounts,
                'links'    => $links,
                'note'     => 'Numer konta podaj wprost tylko przy pytaniu o płatność/przelew, zawsze z linkiem do kontaktu. '
                    . 'Przy pytaniach ogólnych — sam odpowiedni link.',
            ],
        };
    }

    /**
     * Wczytuje wartości configu po whiteliście kluczy z divechat_shop_config.
     *
     * @return array<string, string>
     */
    private function loadConfig(): array
    {
        $keys = array_merge(self::ACCOUNT_KEYS, self::LINK_KEYS);
        $placeholders = implode(',', array_fill(0, count($keys), '?'));

        $rows = $this->db->fetchAll(
            "SELECT key, value FROM divechat_shop_config WHERE key IN ({$placeholders})",
            $keys,
        );

        return self::filterRows($rows);
    }

    /**
     * Czysta filtracja wierszy configu → mapa key→value.
     * Pomija klucze z wartością pustą lub 'TODO' (niepotwierdzone URL-e) — graceful,
     * dzięki temu bot nie poda placeholdera dopóki Karol nie wstawi realnego URL-a.
     *
     * @param list<array{key: string, value: mixed}> $rows
     * @return array<string, string>
     */
    public static function filterRows(array $rows): array
    {
        $config = [];
        foreach ($rows as $row) {
            $value = trim((string) $row['value']);
            if ($value === '' || $value === 'TODO') {
                continue;
            }
            $config[$row['key']] = $value;
        }

        return $config;
    }
}
