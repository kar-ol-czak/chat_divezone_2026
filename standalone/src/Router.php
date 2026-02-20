<?php

declare(strict_types=1);

namespace DiveChat;

use DiveChat\Http\Request;
use DiveChat\Http\Response;

/**
 * Prosty router bez frameworka.
 * Mapuje METHOD + path na callable handler.
 */
final class Router
{
    /** @var array<string, callable> */
    private array $routes = [];

    /**
     * Rejestruje route: $method + $path -> $handler.
     */
    public function add(string $method, string $path, callable $handler): self
    {
        $key = strtoupper($method) . ':' . rtrim($path, '/');
        $this->routes[$key] = $handler;
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
        $key = $request->method . ':' . $request->path;

        $handler = $this->routes[$key] ?? null;

        if ($handler === null) {
            Response::error('Not found', 404);
        }

        $handler($request);
    }
}
