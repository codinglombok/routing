<?php

declare(strict_types=1);

namespace LombokClarion\Routing;

use LombokClarion\Container\ContainerInterface;
use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\Response;
use RuntimeException;

/**
 * Request → Router (explicit route table) → Middleware (declared per-route)
 * → Container (resolves controller) → Controller.
 *
 * This class is deployment-target agnostic; RuntimeAdapters call
 * Kernel::handle() and are responsible only for translating their
 * environment's raw request/response into our Request/Response objects.
 */
final class Kernel
{
    /**
     * @param list<class-string> $globalMiddleware applied to every request,
     *        still fully visible here — not injected by convention
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Router $router,
        private readonly array $globalMiddleware = [],
    ) {
    }

    public function handle(Request $request): Response
    {
        // Global middleware wraps the routing decision itself — it must see
        // every request, including ones that end in 404/405 (e.g. security
        // headers on error responses, static-asset serving for paths that
        // have no route).
        $pipeline = $this->buildPipeline(
            $this->globalMiddleware,
            fn (Request $req): Response => $this->route($req)
        );

        return $pipeline($request);
    }

    private function route(Request $request): Response
    {
        $matched = $this->router->match($request->method, $request->path);

        if ($matched === null) {
            $status = $this->router->pathExists($request->path) ? 405 : 404;
            return Response::text($status === 404 ? 'Not Found' : 'Method Not Allowed', $status);
        }

        [$route, $params] = $matched;
        $request = $request->withAttributes($params);

        $pipeline = $this->buildPipeline(
            $route->middleware,
            fn (Request $req): Response => $this->dispatch($route, $req)
        );

        return $pipeline($request);
    }

    /**
     * @param list<class-string|Middleware> $middlewareClasses
     */
    private function buildPipeline(array $middlewareClasses, callable $core): callable
    {
        $pipeline = $core;

        foreach (array_reverse($middlewareClasses) as $middlewareEntry) {
            $next = $pipeline;
            $pipeline = function (Request $request) use ($middlewareEntry, $next): Response {
                $middleware = $middlewareEntry instanceof Middleware
                    ? $middlewareEntry
                    : $this->container->get($middlewareEntry);

                if (!$middleware instanceof Middleware) {
                    throw new RuntimeException("$middlewareEntry must implement " . Middleware::class);
                }
                return $middleware->handle($request, $next);
            };
        }

        return $pipeline;
    }

    private function dispatch(Route $route, Request $request): Response
    {
        [$controllerClass, $method] = $route->handler;
        $controller = $this->container->get($controllerClass);

        return $controller->{$method}($request);
    }
}
