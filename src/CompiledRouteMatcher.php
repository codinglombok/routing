<?php

declare(strict_types=1);

namespace LombokClarion\Routing;

use RuntimeException;

/**
 * The request-time half of route compilation: loads the match index
 * RouteCompiler produced and answers match()/pathExists() without ever
 * building a regex. The Kernel uses it when present; the live Router
 * remains the source of handlers and middleware (see RouteCompiler for
 * why only matching is compiled).
 *
 * Construction verifies the fingerprint against the live router and
 * throws on mismatch. Deliberately not a silent fallback to live
 * matching: a deploy that shipped new routes without re-running
 * `optimize` should fail at boot with the fix in the message, not run
 * with whichever half of the change happens to agree.
 */
final class CompiledRouteMatcher
{
    /** @var array<string, array<string, int>> method => path => route index */
    private readonly array $static;

    /** @var array<string, list<array{regex: string, names: list<string>, types: array<string, string>, index: int}>> */
    private readonly array $dynamic;

    /**
     * @param array{fingerprint: string, static: array<string, array<string, int>>, dynamic: array<string, list<array{regex: string, names: list<string>, types: array<string, string>, index: int}>>} $table
     */
    public function __construct(array $table, Router $router)
    {
        $expected = RouteCompiler::fingerprint($router);

        if (($table['fingerprint'] ?? null) !== $expected) {
            throw new RuntimeException(
                'routes.compiled.php is stale: it was built from a different route table than ' .
                'bootstrap/routes.php now defines (routes were added, removed, or reordered). ' .
                'Re-run `lombokclarion optimize` — a stale match index dispatching to the wrong ' .
                'route is not something to fall back through silently.'
            );
        }

        $this->static = $table['static'];
        $this->dynamic = $table['dynamic'];
    }

    public static function fromFile(string $compiledFilePath, Router $router): self
    {
        /** @var array{fingerprint: string, static: array<string, array<string, int>>, dynamic: array<string, list<array{regex: string, names: list<string>, types: array<string, string>, index: int}>>} $table */
        $table = require $compiledFilePath;

        return new self($table, $router);
    }

    /**
     * @return array{0: int, 1: array<string, string|int>}|null [route index, cast params], or null
     */
    public function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);

        // Exact paths first: one hash lookup, no regex at all.
        $index = $this->static[$method][$path] ?? null;
        if ($index !== null) {
            return [$index, []];
        }

        foreach ($this->dynamic[$method] ?? [] as $entry) {
            if (preg_match($entry['regex'], $path, $matches)) {
                return [$entry['index'], Route::castParams($matches, $entry['names'], $entry['types'])];
            }
        }

        return null;
    }

    /**
     * True if the path matches some route regardless of method — the
     * 404-vs-405 distinction, same contract as Router::pathExists().
     */
    public function pathExists(string $path): bool
    {
        foreach ($this->static as $paths) {
            if (isset($paths[$path])) {
                return true;
            }
        }

        foreach ($this->dynamic as $entries) {
            foreach ($entries as $entry) {
                if (preg_match($entry['regex'], $path)) {
                    return true;
                }
            }
        }

        return false;
    }
}
