<?php

declare(strict_types=1);

namespace DiveChat\AI;

use DiveChat\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

/**
 * Serwis generowania embeddingów (OpenAI text-embedding-3-large, 1536 dim).
 * Jeden reusowany klient HTTP.
 */
final class EmbeddingService
{
    private readonly Client $http;
    private readonly string $apiKey;

    public function __construct()
    {
        $this->apiKey = Config::getRequired('OPENAI_API_KEY');
        $this->http = new Client([
            'base_uri' => 'https://api.openai.com/',
            'timeout' => 15,
        ]);
    }

    /**
     * Generuje embedding dla tekstu.
     *
     * @return float[] Wektor 1536-wymiarowy
     */
    public function getEmbedding(string $text): array
    {
        $options = [
            'headers' => [
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => 'text-embedding-3-large',
                'input' => $text,
                'dimensions' => 1536,
            ],
        ];

        $response = $this->requestWithRetry('POST', 'v1/embeddings', $options);

        $data = json_decode($response->getBody()->getContents(), true);
        return $data['data'][0]['embedding'];
    }

    /**
     * Retry: 1 ponowienie po 2s przy HTTP 429 lub 5xx.
     */
    private function requestWithRetry(string $method, string $uri, array $options): \Psr\Http\Message\ResponseInterface
    {
        try {
            return $this->http->request($method, $uri, $options);
        } catch (ServerException $e) {
            sleep(2);
            return $this->http->request($method, $uri, $options);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 429) {
                sleep(2);
                return $this->http->request($method, $uri, $options);
            }
            throw $e;
        }
    }
}
