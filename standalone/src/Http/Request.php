<?php

declare(strict_types=1);

namespace DiveChat\Http;

/**
 * Wrapper na dane HTTP requestu.
 */
final class Request
{
    public readonly string $method;
    public readonly string $path;
    private readonly array $headers;
    private readonly ?array $jsonBody;
    private readonly array $queryParams;

    /** @var array<string, string> Parametry z path (np. {session_id}) */
    public array $params = [];

    public function __construct()
    {
        $this->method = $this->resolveMethod();
        $this->path = $this->parsePath();
        $this->headers = $this->parseHeaders();
        $this->jsonBody = $this->parseJsonBody();
        $this->queryParams = $_GET;
    }

    /**
     * Mapuje POST + X-HTTP-Method-Override: PUT|DELETE|PATCH na faktyczny method.
     * Workaround dla shared hosting / Apache ModSecurity blokujących PUT i DELETE
     * (smoke T-012 15.05 wykrył HTTP 403 dla PUT na chat.divezone.pl).
     */
    private function resolveMethod(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if ($method !== 'POST') {
            return $method;
        }

        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? null;
        if ($override === null) {
            return $method;
        }

        $override = strtoupper(trim($override));
        if (in_array($override, ['PUT', 'DELETE', 'PATCH'], true)) {
            return $override;
        }

        return $method;
    }

    public function getHeader(string $name): ?string
    {
        $normalized = strtolower($name);
        return $this->headers[$normalized] ?? null;
    }

    public function getJsonBody(): array
    {
        return $this->jsonBody ?? [];
    }

    public function getQueryParam(string $name, string $default = ''): string
    {
        return $this->queryParams[$name] ?? $default;
    }

    public function getQueryInt(string $name, int $default = 0): int
    {
        return isset($this->queryParams[$name]) ? (int) $this->queryParams[$name] : $default;
    }

    public function getQueryBool(string $name): ?bool
    {
        if (!isset($this->queryParams[$name])) {
            return null;
        }
        return filter_var($this->queryParams[$name], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    /**
     * Ustawia path params (wywoływane przez Router).
     */
    public function withParams(array $params): self
    {
        $this->params = $params;
        return $this;
    }

    private function parsePath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    private function parseHeaders(): array
    {
        $headers = [];

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
