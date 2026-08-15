<?php

declare(strict_types=1);

namespace App\Http;

use Closure;

final class Router
{
    /**
     * @var array<string, array<string, Closure(Request): Response>>
     */
    private array $routes = [];

    /**
     * @param Closure(Request): Response $handler
     */
    public function get(string $path, Closure $handler): void
    {
        $this->routes['GET'][$path] = $handler;
    }

    /**
     * @param Closure(Request): Response $handler
     */
    public function post(string $path, Closure $handler): void
    {
        $this->routes['POST'][$path] = $handler;
    }

    public function dispatch(Request $request): Response
    {
        $handler = $this->routes[$request->method()][$request->path()] ?? null;
        if ($handler === null) {
            return Response::html('<h1>404</h1><p>Route not found.</p>', 404);
        }

        return $handler($request);
    }
}
