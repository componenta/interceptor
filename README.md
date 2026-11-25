# Componenta Interceptor

![PHP 8.5.13+](https://img.shields.io/badge/PHP-8.5.13%2B-blue)
![License MIT](https://img.shields.io/badge/License-MIT-green)

Middleware-style interceptor pipeline for PHP callables. Wrap any function, method or closure with cross-cutting logic (logging, caching, transactions, authorization, serialization) declared either via `pipe()` or method-level attributes.

**[Русская документация](README.ru.md)**

## Installation

```bash
composer require componenta/interceptor
```

## Requirements

- PHP 8.5.13+
- `psr/container`
- `componenta/di` 6 (`CallableExecutorInterface`, `CallableInvokerInterface`, `FactoryInterface`, `ParametersResolver`)
- `componenta/reflection` 3 (lazy reflector resolution)
- `componenta/config` (optional `ConfigProvider` integration)

## Related Packages

| Package | Why it matters here |
|---|---|
| `componenta/di` | Invokes callables and resolves missing parameters before interceptors run. |
| `componenta/reflection` | Reads callable reflection and method attributes lazily. |
| `componenta/config` | Registers context factories and attribute interceptors. |
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

Immutable object carrying the callable, its parameters, arbitrary attributes, and a lazily-resolved reflector. Callable array slots and top-level argument/attribute slots are captured by value; objects and nested reference-bearing values are not deep-cloned. Mutators return new instances:

```php
$context = $context
    ->withParameter('userId', 42)
    ->withAttribute('trace.id', $traceId);
```

Reflection is derived from the native callable. The `reflector` property is `null` for native magic dispatch without a concrete declaration.

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
    #[Intercept(AuthInterceptor::class)]
    #[Intercept(CacheInterceptor::class, ['ttl' => 300])]
    public function show(int $id): User { /* ... */ }
}
```

Resolution is driven by `AttributeInterceptor` (register it once in your pipeline). The interceptor instance is built via `FactoryInterface` with the declared `params` when its layer is reached. Short-circuiting skips the construction and execution of inner layers. Attributes are read as layers wrapped around the method, **from outside in** — the topmost attribute is the outermost layer (enters first, returns last), the bottommost attribute is closest to the method body.

Put authorization before any cache that can short-circuit it. Put entry-side interceptors (authorization, rate limits, caching gates) above result-side ones (response formatting, serialization, pagination). The method's return value flows outward through the inner layers first, so a serializer placed below a response wrapper gets the raw value and passes the serialized string up to the wrapper:

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

Attribute-level scope takes priority over instance-level scope and is checked before resolving the interceptor's dependencies. Interceptors without either `ScopedInterface` always match. An absent or null scope disables scope filtering; another value that does not implement `ScopeInterface` is rejected.

Built-in scopes: `HTTP`, `CONSOLE`, `GRPC`, `QUEUE`, `WEBSOCKET`. Custom scopes can be represented by `Componenta\Scope\ScopeName` or by a package-specific enum implementing `Componenta\Scope\ScopeInterface`.

## Callback Interceptors

Build interceptors from closures without dedicated classes:

```php
use Componenta\Interceptor\CallbackInterceptorFactory as F;

$logger   = F::before(fn ($ctx) => $log->info('calling ' . ($ctx->reflector?->name ?? 'dynamic callable')));
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

Parameter attributes run once during this preparation. The terminal passes prepared arguments through the configured executor using DI's PreparedCallable adapter, including changes made by downstream interceptors. Executor decoration still runs; nested InterceptingExecutor instances retain the original callable and its method attributes. Changing context attributes does not repeat preparation. Nested parameter-resolving interceptors reuse the prepared arguments.

Reading, updating and removing a prepared fixed parameter by name or position address the same argument. Explicit null parameters and context attributes remain null even when a fallback is supplied; the fallback applies only to absent keys.

`CallableContext` recognizes `PreparedCallable` during construction and `withCallable()`, unwraps nested adapters, and exposes the native target through `getCallable()`. The public `prepared` property indicates that parameters are final. `withPreparedParameters()` creates a context with a complete native argument list; `invocationArguments()` provides the argument vector for the invoker. Parameter preparation and terminal invocation depend on the context contract rather than its implementation.

PreparedCallable arguments follow native PHP binding: integer keys bind in insertion order, while string keys bind by name. Untouched arguments retain native binding errors. Editing parameters through the context switches to logical name/position binding, so removing a fixed argument preserves the positions of its neighbours.

Prepared parameters are final invocation values: updates by name or position replace the corresponding argument, and omitted optional arguments use PHP defaults. DI does not refill arguments removed after preparation. Replacing the callable with `withCallable()` starts ordinary DI resolution for the new target unless a `PreparedCallable` is supplied. A pipeline without ParameterResolvingInterceptor continues to invoke its target through the configured DI executor. A string that has already been selected as a native callable is invoked as a native function or method rather than being looked up as another service ID.

Prepared arguments and top-level context slots are passed by value; by-reference parameter mutation is not supported by this pipeline. Use DI's native `CallableInvoker` for explicit reference-bearing arguments. To share context attributes across nested pipelines, compose them with `pipe()`; the DI executor's `call()` boundary carries only the callable and arguments.

## Caching

`AttributeInterceptor` caches native `ReflectionAttribute` metadata for stable method and function signatures. Every reached layer constructs fresh attribute arguments and attributes via `ReflectionAttribute::newInstance()`, then calls `FactoryInterface::make()` for `Intercept` declarations. Scope selection uses the attribute and the instance returned by the factory. The factory contract may reuse instances.

Declarations whose attribute classes are not yet available are rechecked on later invocations; incomplete classification is not retained in the metadata cache.

Explicit interceptor instances passed to `InterceptingExecutor` or `pipe()` retain their normal application-defined lifetime. Native attribute instances and their constructed arguments are fresh. Factory-created interceptor lifetime is determined by the factory, not by the metadata cache. Closures are not retained in the metadata cache.

Native source declarations are authoritative. Interception does not require a generated attribute map or an application build. Stable declaration metadata is classified once per signature; unavailable attribute classes are rechecked. PHP remains responsible for argument construction, targets and repeatability when the declaration is reached. Replace a target before `AttributeInterceptor` if the new target's attribute chain should be selected.


## Container Wiring

Register the module's `ConfigProvider` in your application:

```php
new \Componenta\Interceptor\ConfigProvider();
```

It binds `CallableContextFactoryInterface`, `AttributeInterceptor`, and `PipelineInterface`. `PipelineInterface` extends DI's `CallableExecutorInterface` and the interception and context-handler contracts. The `PipelineInterface` service is intended for HTTP route handler execution and is built by `HttpInterceptorPipelineFactory`:

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

## Development

```bash
composer install
composer test
composer bench:smoke
composer bench
```

The [PHPBench suite](benchmarks/README.md) compares native, DI and interceptor execution on equivalent fixtures.

## License

MIT
