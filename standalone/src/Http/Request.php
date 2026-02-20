<?php

declare(strict_types=1);

namespace DiveChat\Http;

/**
 * Wrapper na dane HTTP requestu.
 */
final readonly class Request
{
    public string $method;
    public string $path;
    private array $headers;
    private ?array $jsonBody;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->path = $this->parsePath();
        $this->headers = $this->parseHeaders();
        $this->jsonBody = $this->parseJsonBody();
    }

    public function getHeader(string $name): ?string
    {
        // Normalizuj nazwę headera do lowercase
        $normalized = strtolower($name);
        return $this->headers[$normalized] ?? null;
    }

    public function getJsonBody(): array
    {
        return $this->jsonBody ?? [];
    }

    private function parsePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Usuń trailing slash (ale zostaw /)
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function parseHeaders(): array
    {
        $headers = [];

        // getallheaders() lub fallback na $_SERVER
        if (function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                $headers[strtolower($name)] = $value;
            }
        } else {
            foreach ($_SERVER as $key => $value) {
                if (str_starts_with($key, 'HTTP_')) {
                    $name = strtolower(str_replace('_', '-', substr($key, 5)));
                    $headers[$name] = $value;
                }
            }
        }

        return $headers;
    }

    private function parseJsonBody(): ?array
    {
        if (!in_array($this->method, ['POST', 'PUT', 'PATCH'])) {
            return null;
        }

        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return null;
        }

        $data = json_decode($raw, true);
        return is_array($data) ? $data : null;
    }
}
