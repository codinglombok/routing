<?php

declare(strict_types=1);

namespace LombokClarion\Routing;

/**
 * A single registered route. Handlers are always [ControllerClass::class,
 * 'method'] array callables resolved from the container — never a raw
 * Closure defined inline in routes.php, which keeps the route table
 * `grep`-able and controllers unit-testable in isolation.
 *
 * TYPED PARAMS (Stage 11): a placeholder may carry a type, `{id:int}`.
 * The type does two jobs at once and they are inseparable on purpose:
 * it narrows the MATCH (so `GET /widgets/abc` against `/widgets/{id:int}`
 * is a 404, not a controller receiving "abc") and it CASTS the captured
 * value (so the controller reads an int attribute, not a numeric string).
 * Validation-by-matching means there is no "route matched but the param
 * is invalid" state for the controller to handle.
 *
 * Known types: `int` (digits only, cast to PHP int) and `string` (one
 * segment, the default — `{id}` and `{id:string}` are identical). The
 * vocabulary is deliberately tiny; anything richer (uuid, slug, dates)
 * is input VALIDATION and belongs in a FormRequest, not the route table.
 *
 * LAZY COMPILE (Stage 11): the regex is built on first match(), not in
 * the constructor. When a compiled route table is in use the Kernel never
 * calls match() on these objects at all — so building routes.php on every
 * request costs object construction only, and the per-request
 * preg_replace_callback work the compiled table exists to remove is
 * actually removed rather than merely moved.
 */
final class Route
{
    private ?string $regex = null;

    /** @var list<string> */
    private array $paramNames = [];

    /** @var array<string, 'int'|'string'> param name => declared type */
    private array $paramTypes = [];

    private const TYPE_PATTERNS = [
        'int' => '\d+',
        'string' => '[^/]+',
    ];

    /**
     * @param array{0: class-string, 1: string} $handler
     * @param list<class-string|\LombokClarion\Http\Middleware> $middleware
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $handler,
        public readonly array $middleware = [],
        public readonly ?string $name = null,
    ) {
    }

    /**
     * The compiled pattern pieces for this route — used lazily by match()
     * and eagerly by RouteCompiler at `optimize` time. Public because the
     * compiler is a separate class and the pieces ARE the artifact; not
     * cached state leaking, but the route's one canonical compilation.
     *
     * @return array{0: string, 1: list<string>, 2: array<string, 'int'|'string'>}
     */
    public function compiled(): array
    {
        if ($this->regex === null) {
            [$this->regex, $this->paramNames, $this->paramTypes] = self::compile($this->path);
        }

        return [$this->regex, $this->paramNames, $this->paramTypes];
    }

    /**
     * @return array{0: string, 1: list<string>, 2: array<string, 'int'|'string'>}
     */
    private static function compile(string $path): array
    {
        $paramNames = [];
        $paramTypes = [];

        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([a-zA-Z]+))?\}#',
            function (array $m) use (&$paramNames, &$paramTypes, $path): string {
                $name = $m[1];
                $type = $m[2] ?? 'string';

                if (!isset(self::TYPE_PATTERNS[$type])) {
                    // An unknown type is a typo in trusted route code — fail loudly
                    // at compile, never silently match everything.
                    throw new \InvalidArgumentException(sprintf(
                        'Unknown route param type "{%s:%s}" in path "%s". Known types: %s.',
                        $name,
                        $type,
                        $path,
                        implode(', ', array_keys(self::TYPE_PATTERNS))
                    ));
                }

                $paramNames[] = $name;
                $paramTypes[$name] = $type;

                return '(?P<' . $name . '>' . self::TYPE_PATTERNS[$type] . ')';
            },
            $path
        );

        return ['#^' . $pattern . '$#', $paramNames, $paramTypes];
    }

    /**
     * @return array<string, string|int>|null null if the path does not match
     */
    public function match(string $path): ?array
    {
        [$regex, $paramNames, $paramTypes] = $this->compiled();

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        return self::castParams($matches, $paramNames, $paramTypes);
    }

    /**
     * Shared with CompiledRouteMatcher so the cast semantics cannot drift
     * between the live and compiled paths.
     *
     * @param array<int|string, string> $matches
     * @param list<string> $paramNames
     * @param array<string, 'int'|'string'> $paramTypes
     * @return array<string, string|int>
     */
    public static function castParams(array $matches, array $paramNames, array $paramTypes): array
    {
        $params = [];
        foreach ($paramNames as $name) {
            $value = $matches[$name];
            $params[$name] = ($paramTypes[$name] ?? 'string') === 'int' ? (int) $value : $value;
        }

        return $params;
    }
}
