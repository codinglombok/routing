<?php

declare(strict_types=1);

namespace LombokClarion\Routing;

/**
 * The entire route table lives here, built explicitly in
 * bootstrap/routes.php. No attribute scanning, no directory-convention
 * discovery of controllers — every route is a line you can read.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var list<class-string> */
    private array $groupMiddleware = [];

    private string $groupPrefix = '';

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string> $middleware
     */
    public function get(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('GET', $path, $handler, $middleware, $name);
    }

    public function post(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('POST', $path, $handler, $middleware, $name);
    }

    public function put(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('PUT', $path, $handler, $middleware, $name);
    }

    public function patch(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('PATCH', $path, $handler, $middleware, $name);
    }

    public function delete(string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->addRoute('DELETE', $path, $handler, $middleware, $name);
    }

    public function addRoute(string $method, string $path, array $handler, array $middleware = [], ?string $name = null): void
    {
        $this->routes[] = new Route(
            strtoupper($method),
            $this->groupPrefix . $path,
            $handler,
            [...$this->groupMiddleware, ...$middleware],
            $name
        );
    }

    /**
     * @param list<class-string> $middleware
     */
    public function group(string $prefix, array $middleware, callable $callback): void
    {
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = $previousPrefix . $prefix;
        $this->groupMiddleware = [...$previousMiddleware, ...$middleware];

        $callback($this);

        $this->groupPrefix = $previousPrefix;
        $this->groupMiddleware = $previousMiddleware;
    }

    /**
     * @return array{0: Route, 1: array<string, string>}|null
     */
    public function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route->method !== strtoupper($method)) {
                continue;
            }
            $params = $route->match($path);
            if ($params !== null) {
                return [$route, $params];
            }
        }

        return null;
    }

    /**
     * True if the path matches some route, regardless of method (used to
     * distinguish 404 from 405).
     */
    public function pathExists(string $path): bool
    {
        foreach ($this->routes as $route) {
            if ($route->match($path) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @return list<Route> */
    public function routes(): array
    {
        return $this->routes;
    }
}
