<?php

declare(strict_types=1);

namespace DiveChat;

use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Prosty router bez frameworka.
 * Mapuje METHOD + path na callable handler.
 * Obsługuje parametry w ścieżce: /api/conversations/{session_id}
 */
final class Router
{
    /** @var array{method: string, path: string, handler: callable}[] */
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): self
    {
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => rtrim($path, '/'),
            'handler' => $handler,
        ];
        return $this;
    }

    public function get(string $path, callable $handler): self
    {
        return $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): self
    {
        return $this->add('POST', $path, $handler);
    }

    /**
     * Obsługuje request - znajduje handler i wywołuje go.
     */
    public function dispatch(Request $request): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method) {
                continue;
            }

            $params = $this->matchPath($route['path'], $request->path);
            if ($params !== null) {
                $req = !empty($params) ? $request->withParams($params) : $request;
                ($route['handler'])($req);
                return;
            }
        }

        Response::error('Not found', 404);
    }

    /**
     * Dopasowuje path z parametrami {name}. Zwraca null jeśli brak dopasowania.
     *
     * @return array<string, string>|null
     */
    private function matchPath(string $routePath, string $requestPath): ?array
    {
        // Szybka ścieżka: brak parametrów
        if (!str_contains($routePath, '{')) {
            return $routePath === $requestPath ? [] : null;
        }

        // Zamień {param} na regex named groups
        $pattern = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $routePath);
        $pattern = '#^' . $pattern . '$#';

        if (preg_match($pattern, $requestPath, $matches)) {
            return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
        }

        return null;
    }
}
