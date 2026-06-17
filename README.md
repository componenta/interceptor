# Componenta Interceptor

![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-blue)
![License MIT](https://img.shields.io/badge/License-MIT-green)
![Tests](https://img.shields.io/badge/tests-53%20passed-brightgreen)
![MSI](https://img.shields.io/badge/MSI-100%25-brightgreen)

Middleware-style interceptor pipeline for PHP callables. Wrap any function, method or closure with cross-cutting logic (logging, caching, transactions, authorization, serialization) declared either via `pipe()` or method-level attributes.

**[Русская документация](README.ru.md)**

## Installation

```bash
composer require componenta/interceptor
```

## Requirements

- PHP 8.4+
- `psr/container`
- `componenta/di` (`CallableExecutorInterface`, `FactoryInterface`, `ParametersResolver`)
- `componenta/reflection` (lazy reflector resolution)
- `componenta/config` (optional `ConfigProvider` integration)

## Related Packages

| Package | Why it matters here |
|---|---|
| `componenta/di` | Invokes callables and resolves missing parameters before interceptors run. |
| `componenta/reflection` | Reads callable reflection and method attributes lazily. |
| `componenta/config` | Registers context factories and attribute interceptors. |
| `componenta/interceptor-app` | Compiles interceptor attributes into application cache. |
| `componenta/serialize-interceptor` | Ready-made result serialization interceptor backed by Symfony Serializer. |
| `componenta/http-respond-interceptor` | Ready-made HTTP interceptor that wraps results into PSR-7 responses. |
| `componenta/http-paginate-interceptor` | Ready-made HTTP interceptor for `PaginatorInterface` -> `ResourcePaginator`. |
| `componenta/pipeline` | Similar chain idea for PSR-15 HTTP middleware; this package wraps arbitrary PHP callables. |

## Quick Start

```php
use Componenta\DI\CallableExecutorInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\InterceptingExecutor;

$executor = new InterceptingExecutor(
    $container->get(CallableExecutorInterface::class),
    CallbackInterceptorFactory::around(
        before: fn ($ctx) => $ctx->withAttribute('started', microtime(true)),
        after:  fn ($result, $ctx) => ['result' => $result, 'ms' => (microtime(true) - $ctx->getAttribute('started')) * 1000],
    ),
);

$result = $executor->call([$controller, 'handle'], ['id' => 42]);
```

## Core Concepts

### Interceptor

A class implementing `InterceptorInterface`. Receives the execution context and a continuation handler; may act before/after, short-circuit, or transform the result.

```php
interface InterceptorInterface
{
    public function intercept(
        CallableContextInterface $context,
        ContextHandlerInterface $handler,
    ): mixed;
}
```

### Context

Immutable object carrying the callable, its parameters, arbitrary attributes, and a lazily-resolved reflector. Mutators return new instances:

```php
$context = $context
    ->withParameter('userId', 42)
    ->withAttribute('trace.id', $traceId);
```

### Pipeline

`InterceptingExecutor` composes interceptors into a pre-built chain on first use. Execution order is **FIFO** — the first registered interceptor is outermost (runs first in the call direction, last on unwind):

```php
$executor = new InterceptingExecutor($base, $auth, $logger, $cache);

// Single invocation, one pass through the chain:
//
//   auth.before → logger.before → cache.before → callable
//                                              → cache.after → logger.after → auth.after
//
// Each interceptor's intercept() is called exactly once. Work placed before
// $handler->handle() runs on the way in; work after it runs on unwind.
```

`pipe()` returns a new immutable pipeline:

```php
$withTx = $executor->pipe($transactionInterceptor);
```

### Short-circuit

An interceptor may return without invoking the handler (auth rejections, cache hits, maintenance screens). The pipeline stops, and the value bubbles back through outer interceptors.

## Attributes

Declare interceptors on methods via `#[Intercept]`:

```php
use Componenta\Interceptor\Attribute\Intercept;

final class UserController
{
    #[Intercept(CacheInterceptor::class, ['ttl' => 300])]
    #[Intercept(AuthInterceptor::class)]
    public function show(int $id): User { /* ... */ }
}
```

Resolution is driven by `AttributeInterceptor` (register it once in your pipeline). The interceptor instance is built via `FactoryInterface` with the declared `params`. Attributes are read as layers wrapped around the method, **from outside in** — the topmost attribute is the outermost layer (enters first, returns last), the bottommost attribute is closest to the method body.

Put entry-side interceptors (authorization, rate limits, caching gates) above result-side ones (response formatting, serialization, pagination). The method's return value flows outward through the inner layers first, so a serializer placed below a response wrapper gets the raw value and passes the serialized string up to the wrapper:

```php
#[Respond(200, 'application/json')]      // outermost — wraps the final string in a PSR-7 response
#[Serialize(context: [...])]             // innermost — receives the raw return value first
public function show(int $id): User { /* ... */ }
```

Attribute classes can also implement `InterceptorInterface` directly — they are instantiated through native PHP attribute construction:

```php
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class WrapJson implements InterceptorInterface
{
    public function intercept($ctx, $handler): mixed
    {
        return json_encode($handler->handle($ctx));
    }
}
```

### Scopes

Restrict where an interceptor runs by implementing `Componenta\Scope\ScopedInterface` on the attribute or on the interceptor instance:

```php
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\Scope;
use Componenta\Scope\ScopedInterface;
use Componenta\Scope\Scopes;

final class RespondInterceptor implements InterceptorInterface, ScopedInterface
{
    public Scopes $scopes {
        get => Scopes::of(Scope::HTTP);
    }

    // ...
}
```

The integrator signals the current scope by setting a context attribute before the pipeline runs:

```php
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\Scope;

$context = $context->withAttribute(CallableContext::SCOPE_ATTRIBUTE, Scope::HTTP);
```

Attribute-level scope takes priority over instance-level scope. Interceptors without either `ScopedInterface` always match.

Built-in scopes: `HTTP`, `CONSOLE`, `GRPC`, `QUEUE`, `WEBSOCKET`. Custom scopes can be represented by `Componenta\Scope\ScopeName` or by a package-specific enum implementing `Componenta\Scope\ScopeInterface`.

## Callback Interceptors

Build interceptors from closures without dedicated classes:

```php
use Componenta\Interceptor\CallbackInterceptorFactory as F;

$logger   = F::before(fn ($ctx) => $log->info('calling ' . $ctx->reflector->name));
$envelope = F::after(fn ($result) => ['data' => $result]);
$recover  = F::catch(fn (\Throwable $e) => ['error' => $e->getMessage()]);
$cleanup  = F::finally(fn () => $this->releaseLock());

$tracer = F::around(
    before: fn ($ctx) => $ctx->withAttribute('t0', microtime(true)),
    after:  function ($result, $ctx) use ($log) {
        $log->info(sprintf('%.2fms', (microtime(true) - $ctx->getAttribute('t0')) * 1000));
        return $result;
    },
);
```

## Parameter Resolution

Register `ParameterResolvingInterceptor` to enrich the callable's parameters through DI before downstream interceptors see them:

```php
new InterceptingExecutor(
    $container->get(CallableExecutorInterface::class),
    new ParameterResolvingInterceptor($parametersResolver), // outermost — runs first
    $container->get(AttributeInterceptor::class),
);
```

This lets attribute interceptors read resolved arguments (e.g., `$ctx->parameters` for cache keys).

## Caching

`AttributeInterceptor` caches attribute resolution on two levels:

1. **Candidates per signature** — `#[Intercept]` instances are created once per method and reused.
2. **Composed chains per terminal** — stored in a `WeakMap` keyed by the terminal handler; innermost link holds the terminal weakly, so GC reclaims entries when the terminal goes out of scope (e.g., when `pipe()` discards an old pipeline).

No configuration required — caching is always on. Closures bypass the cache (no stable signature).

## Container Wiring

Register the module's `ConfigProvider` in your application:

```php
new \Componenta\Interceptor\ConfigProvider();
```

It binds `CallableContextFactory`, `AttributeInterceptor`, and `PipelineInterface`. The `PipelineInterface` service is intended for HTTP route handler execution and is built by `HttpInterceptorPipelineFactory`:

1. `ParameterResolvingInterceptor` is always registered first, so callable parameters are resolved through DI before application interceptors run.
2. Additional interceptors are read from `ConfigKey::HTTP_INTERCEPTORS`.
3. Each configured item may be a container service id or an `InterceptorInterface` instance.

Typical HTTP configuration:

```php
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\ConfigKey;

return [
    ConfigKey::HTTP_INTERCEPTORS => [
        AttributeInterceptor::class,
    ],
];
```

`componenta/router-app` consumes `PipelineInterface` when it executes route handlers. Applications usually configure the interceptor list in their `src/ConfigProvider.php`.

## License

MIT
