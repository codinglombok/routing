# lombokclarion/routing

**Explicit route table, compiled matching, three runtime adapters.**

> **[READ-ONLY]** This is a subtree split of the [LombokClarion](https://github.com/codinglombok/LombokClarion) monorepo.  
> Do not send pull requests here — contribute to the [main repository](https://github.com/codinglombok/LombokClarion) instead.

## Install

```bash
composer require lombokclarion/routing
```

## Namespace

```php
LombokClarion\Routing
```

## What's Inside

| Class | Role |
|-------|------|
| `Router` | Route registration: `get()`, `post()`, `put()`, `delete()`, `group()` |
| `Route` | Route value object (method, path, handler, middleware) |
| `Kernel` | Request lifecycle: match → middleware pipeline → controller → response |
| `RouteCompiler` | Compiles route table → static PHP match index |
| `CompiledRouteMatcher` | Loads compiled index, O(1) matching |
| `FpmAdapter` | PHP-FPM runtime (Apache/Nginx) |
| `FunctionAdapter` | Serverless/edge runtime (Lambda, Workers) |
| `SwooleAdapter` | Swoole/OpenSwoole persistent-worker runtime |

## Usage

```php
use LombokClarion\Routing\Router;
use LombokClarion\Routing\Kernel;

$router = new Router();
$router->get('/', [HomeController::class, 'index']);
$router->post('/widgets', [WidgetController::class, 'store'], [
    ValidateCsrf::class,
    RateLimit::perMinute(30, $store),
]);

$router->group('/api', [Authenticate::class], function (Router $r) {
    $r->get('/users', [UserController::class, 'list']);
    $r->get('/users/{id}', [UserController::class, 'show']);
});

// Boot
$kernel = new Kernel($container, $router, globalMiddleware: [SecurityHeaders::class]);
$response = $kernel->handle($request);

// Production: use compiled routes
$matcher = CompiledRouteMatcher::fromFile('storage/routes.compiled.php');
$kernel = new Kernel($container, $router, compiledMatcher: $matcher);
```

### Runtime Adapters

```php
// PHP-FPM (index.php)
(new FpmAdapter())->run($kernel);

// Swoole
(new SwooleAdapter('0.0.0.0', 8080))->run($kernel);

// Serverless
(new FunctionAdapter($event))->run($kernel);
```

## License

Apache-2.0 — see [LICENSE](https://github.com/codinglombok/LombokClarion/blob/main/LICENSE) in the main repository.
