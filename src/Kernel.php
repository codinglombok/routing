<?php

declare(strict_types=1);

namespace LombokClarion\Routing;

use LombokClarion\Container\ContainerInterface;
use LombokClarion\Http\Errors\ErrorHandler;
use LombokClarion\Http\Middleware;
use LombokClarion\Http\Request;
use LombokClarion\Http\RequestContext;
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
     * @param list<class-string|Middleware> $globalMiddleware applied to every
     *        request, still fully visible here — not injected by convention.
     *        Class-strings are resolved from the container per request;
     *        instances are used as-is (buildPipeline's actual contract).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly Router $router,
        private readonly array $globalMiddleware = [],
        // Stage 11: when a compiled match index exists (`optimize` emitted
        // routes.compiled.php and the front controller loaded it), matching
        // goes through it and no regex is ever built at request time. Null —
        // the default, and what every test constructing a bare Kernel gets —
        // means live matching, byte-for-byte the pre-Stage-11 behaviour.
        private readonly ?CompiledRouteMatcher $compiledMatcher = null,
        // Stage 17: when present, uncaught exceptions become a rendered 500
        // instead of bubbling to the SAPI, and 404/405 get a rendered body.
        // Null — the default — is the pre-Stage-17 behaviour exactly: plain
        // text for 404/405, crashes propagate. Tests that construct a bare
        // Kernel therefore see no change, and an app opts in by passing one.
        private readonly ?ErrorHandler $errorHandler = null,
    ) {
    }

    public function handle(Request $request): Response
    {
        // One RequestContext per request, bound before any middleware runs, so
        // that everything downstream — Authenticate binding the user, the tenant
        // resolver, the i18n locale — reads and writes the SAME object. Without
        // this the context is resolved fresh on every get() and each writer talks
        // to a different instance (AUDIT-TRAIL #35). Under a warm worker (Swoole)
        // this fresh binding per request is also what keeps one request's user
        // from leaking into the next.
        //
        // Reuse a context the caller has already bound as an instance; otherwise
        // bind a fresh one. `has()` is the wrong test here — it returns true for
        // any autowirable class, so it would report "already bound" for a
        // RequestContext nobody has actually put in the container yet. What we
        // mean is specifically "is there an instance bound", which is exactly
        // what get() returns without constructing anything new only when an
        // instance exists. We ask the container directly.
        if (!$this->container->hasInstance(RequestContext::class)) {
            $this->container->instance(RequestContext::class, new RequestContext());
        }

        // Global middleware wraps the routing decision itself — it must see
        // every request, including ones that end in 404/405 (e.g. security
        // headers on error responses, static-asset serving for paths that
        // have no route).
        $pipeline = $this->buildPipeline(
            $this->globalMiddleware,
            fn (Request $req): Response => $this->route($req)
        );

        try {
            return $pipeline($request);
        } catch (\LombokClarion\Http\RendersResponse $e) {
            // Only exceptions that ARE an HTTP outcome (ValidationException
            // -> 422). Anything else is a bug and keeps bubbling as a 500 —
            // catching Throwable here would turn crashes into quiet JSON.
            return $e->toResponse();
        } catch (\Throwable $e) {
            // Stage 17. Note what did NOT change: this catch does not map the
            // exception's CLASS to a status. Everything landing here is a 500,
            // exactly as before — the only difference is that instead of
            // reaching the SAPI as a raw fatal, it gets logged, reported, and
            // rendered. There is still no exception-class-to-status registry
            // anywhere in the framework, which is the property RendersResponse
            // exists to protect.
            //
            // Without a handler configured, the exception continues to bubble
            // untouched, so nothing about the pre-Stage-17 contract moved.
            if ($this->errorHandler === null) {
                throw $e;
            }

            return $this->errorHandler->handle($e, $request);
        }
    }

    private function route(Request $request): Response
    {
        if ($this->compiledMatcher !== null) {
            $matched = $this->compiledMatcher->match($request->method, $request->path);

            if ($matched === null) {
                $status = $this->compiledMatcher->pathExists($request->path) ? 405 : 404;
                return $this->renderStatus($status, $request);
            }

            // The compiled index answers WHICH route and WHAT params; the live
            // table still owns handler and middleware. Fingerprint verification
            // at construction is what makes this index trustworthy.
            [$index, $params] = $matched;
            $route = $this->router->routes()[$index];
        } else {
            $matched = $this->router->match($request->method, $request->path);

            if ($matched === null) {
                $status = $this->router->pathExists($request->path) ? 405 : 404;
                return $this->renderStatus($status, $request);
            }

            [$route, $params] = $matched;
        }

        $request = $request->withAttributes($params);

        $pipeline = $this->buildPipeline(
            $route->middleware,
            fn (Request $req): Response => $this->dispatch($route, $req)
        );

        return $pipeline($request);
    }

    /**
     * A routing outcome that is not a crash: 404 and 405. Rendered through the
     * error handler when one is configured, so these get the same authored
     * pages as a 500 does — and fall back to the exact plain-text bodies used
     * before Stage 17 when one is not.
     */
    private function renderStatus(int $status, Request $request): Response
    {
        if ($this->errorHandler !== null) {
            return $this->errorHandler->renderStatus($status, $request);
        }

        return Response::text($status === 404 ? 'Not Found' : 'Method Not Allowed', $status);
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
