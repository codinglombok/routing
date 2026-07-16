<?php

declare(strict_types=1);

namespace LombokClarion\Routing;

/**
 * A single registered route. Handlers are always [ControllerClass::class,
 * 'method'] array callables resolved from the container — never a raw
 * Closure defined inline in routes.php, which keeps the route table
 * `grep`-able and controllers unit-testable in isolation.
 */
final class Route
{
    private readonly string $regex;

    /** @var list<string> */
    private readonly array $paramNames;

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
        [$this->regex, $this->paramNames] = self::compile($path);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private static function compile(string $path): array
    {
        $paramNames = [];
        $pattern = preg_replace_callback(
            '#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#',
            function (array $m) use (&$paramNames): string {
                $paramNames[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $path
        );

        return ['#^' . $pattern . '$#', $paramNames];
    }

    /**
     * @return array<string, string>|null null if the path does not match
     */
    public function match(string $path): ?array
    {
        if (!preg_match($this->regex, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            $params[$name] = $matches[$name];
        }

        return $params;
    }
}
