<?php

declare(strict_types=1);

namespace LombokClarion\Routing;

/**
 * Emits `routes.compiled.php`: a pre-built MATCH INDEX for the route
 * table, produced at `optimize` time so no request ever pays for
 * compiling 500 placeholder patterns into regexes.
 *
 * What is compiled — and, more importantly, what is not:
 *
 *   COMPILED: per-route regexes, param names/types, and an exact-path
 *   hash map for routes with no placeholders at all (the common case,
 *   and an O(1) lookup instead of any regex).
 *
 *   NOT COMPILED: handlers and middleware. Routes carry instance
 *   middleware (`RateLimit::perMinute(5)`, `Authorize::role('admin')`)
 *   which cannot honestly be serialized into a PHP file — so the
 *   compiled artifact stores route INDEXES into the live table that
 *   bootstrap/routes.php still builds. Loading routes.php stays (it is
 *   cheap object construction now that Route compiles its regex
 *   lazily); what disappears is every preg_replace_callback and every
 *   linear regex scan on the hot path.
 *
 * The file carries a fingerprint of the table it was built from
 * (method+path list, in order). CompiledRouteMatcher refuses a table
 * whose fingerprint no longer matches the live router: a stale compiled
 * index dispatching request N to route M is the worst kind of silent
 * wrong, so staleness is a loud boot-time error naming the fix
 * (re-run `optimize`), never a fallback.
 */
final class RouteCompiler
{
    /**
     * @return string PHP source for routes.compiled.php
     */
    public function compile(Router $router): string
    {
        $static = [];
        $dynamic = [];

        foreach ($router->routes() as $index => $route) {
            [$regex, $paramNames, $paramTypes] = $route->compiled();

            if ($paramNames === []) {
                // No placeholders: the path IS the key. preg metacharacters in a
                // literal path are possible in principle, but the regex was built
                // from the same string, so exact-string equality is the same test
                // preg would perform — minus preg.
                $static[$route->method][$route->path] = $index;
                continue;
            }

            $dynamic[$route->method][] = [
                'regex' => $regex,
                'names' => $paramNames,
                'types' => $paramTypes,
                'index' => $index,
            ];
        }

        $table = [
            'fingerprint' => self::fingerprint($router),
            'static' => $static,
            'dynamic' => $dynamic,
        ];

        return "<?php\n\n"
            . "declare(strict_types=1);\n\n"
            . "// GENERATED FILE — do not edit by hand.\n"
            . "// Produced by LombokClarion\\Routing\\RouteCompiler at `lombokclarion optimize` time\n"
            . "// from bootstrap/routes.php. A match index only: handlers and middleware stay in\n"
            . "// the live route table; entries here point at it by index.\n\n"
            . 'return ' . var_export($table, true) . ";\n";
    }

    public function compileToFile(Router $router, string $outputPath): void
    {
        $source = $this->compile($router);
        $tmp = $outputPath . '.tmp';
        file_put_contents($tmp, $source);
        rename($tmp, $outputPath);
    }

    /**
     * Order-sensitive on purpose: route precedence is declaration order,
     * so reordering routes.php changes behaviour and must invalidate the
     * compiled index even though the set of routes is unchanged.
     */
    public static function fingerprint(Router $router): string
    {
        $lines = [];
        foreach ($router->routes() as $route) {
            $lines[] = $route->method . ' ' . $route->path;
        }

        return hash('sha256', implode("\n", $lines));
    }
}
