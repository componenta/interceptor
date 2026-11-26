# Native callback declarations for Componenta Interceptor

**Readiness:** blocked by reconciliation with the user-requested conversation and the DI 6 / Config dependency graph.

This is a design draft. The recommendations below are explicit proposed decisions, not decisions attributed to an unavailable discussion. They require reconciliation with the requested design context before production slices become executable.

## Objective

Allow applications to declare a small interceptor directly in a PHP attribute, while retaining the existing interception contract: an interceptor receives a callable context and a continuation, controls continuation invocation, and returns the observable result. Attribute metadata caching must not change invocation order, callable scope, mutable state, or lifetime.

## Evidence and target baseline

- Repository: `componenta/interceptor`.
- Current `main`: `21123acfd5b37e5f1a6dec705701dae6da341c34`.
- Proposed implementation base: `refactor/di6-interceptor` at `cb68b34fd47b9c4d430c237a9ef25756e54aa055`. It is 45 commits ahead of the inspected main and contains the current DI 6 contract integration.
- No `AGENTS.md` or existing specification directory was present in either inspected repository tree.
- `src/Attribute/Intercept.php` accepts a non-empty factory entry ID and constructor parameters.
- `src/AttributeInterceptor.php` and `src/Internal/AttributeHandler.php` discover native declarations, materialize only reached layers, select scopes, and resolve factory-backed interceptors through `Componenta\DI\FactoryInterface`.
- `src/CallbackInterceptorFactory.php` already exposes `create`, `before`, `after`, `catch`, `finally`, and `around`. These runtime callbacks currently use native invocation; they do not promise DI suffix resolution.
- `src/Internal/CallbackInterceptor.php` is internal. It must not become a cross-package dependency merely to avoid a small adapter.
- `src/CallableContextInterface.php` is the public context boundary, including prepared parameters, parameter lookup, immutable updates, and optional reflection.
- `composer.json` in the proposed base requires PHP `^8.5.13`, Config `^4.0`, DI `^6.0`, Reflection `^3.0`, and Scope `^1.0`.
- DI main `ce77080b79a0e10a335bf1b9953b9299ed091df1` and DI branch `refactor/di6-parameter-resolution-contract` at `2f8edc2ba93217854359d2a27f546e7da3524d03` both declare Config `^3.0.0`. Their inspected Composer constraints do not currently form an installable graph with this interceptor baseline.

Source permalinks:

- [Attribute declaration](https://github.com/componenta/interceptor/blob/cb68b34fd47b9c4d430c237a9ef25756e54aa055/src/Attribute/Intercept.php)
- [Reached-layer execution](https://github.com/componenta/interceptor/blob/cb68b34fd47b9c4d430c237a9ef25756e54aa055/src/Internal/AttributeHandler.php)
- [Callback factory](https://github.com/componenta/interceptor/blob/cb68b34fd47b9c4d430c237a9ef25756e54aa055/src/CallbackInterceptorFactory.php)
- [Public context contract](https://github.com/componenta/interceptor/blob/cb68b34fd47b9c4d430c237a9ef25756e54aa055/src/CallableContextInterface.php)
- [DI parameter resolution contract](https://github.com/componenta/di/blob/2f8edc2ba93217854359d2a27f546e7da3524d03/src/Resolver/Parameter/ParametersResolverInterface.php)
- [DI invocation contract](https://github.com/componenta/di/blob/2f8edc2ba93217854359d2a27f546e7da3524d03/src/CallableInvokerInterface.php)
- [DI prepared invocation adapter](https://github.com/componenta/di/blob/2f8edc2ba93217854359d2a27f546e7da3524d03/src/PreparedCallable.php)
- [PHP closures in constant expressions](https://wiki.php.net/rfc/closures_in_const_expr) and [first-class callable expressions](https://wiki.php.net/rfc/fcc_in_const_expr)
- [PHP 8.5.13 fixes](https://www.php.net/ChangeLog-8.php#8.5.13)

## Scope

The affected capability is attribute-declared callback interception and its composition with factory-backed and native interceptor attributes. The change does not require another callable pipeline, global callback registry, source-code exporter, or new application build artifact.

The current runtime `CallbackInterceptorFactory` remains independently useful for callbacks assembled in application code. The recommended target adds narrow native-attribute adapters to its existing contracts. DI suffix injection is not planned in this proposal; adding it would change the callback contract and requires a separate explicit decision during reconciliation.

## Settled architectural constraints

1. `componenta/interceptor` owns interception semantics and native attribute execution. DI owns service resolution and callable invocation.
2. Existing pipeline integration continues to depend on the public `FactoryInterface`, `CallableInvokerInterface`, and `Componenta\DI\Resolver\Parameter\ParametersResolverInterface` contracts where already appropriate. The proposed adapters add no dependency-resolution layer. Production code must not require `Container`, a concrete parameter resolver, or anything in `Componenta\DI\Internal`.
3. `CallableContextInterface` and `ContextHandlerInterface` remain the semantic inputs. A callback must not receive internal handler-chain or argument-binding objects.
4. Source declarations remain authoritative. Any cache is derived process-local metadata and cannot become a second behavioral path.
5. A callback is application code with the same authority as a class-based interceptor. No expression evaluation, callback compilation from user input, or global service locator is introduced.
6. PHP 8.5 attribute expressions permit capture-free `static function` closures and first-class references to declared functions/static methods. Arrow functions, object-method references, captured variables, and dynamic callable names are not valid in this PHP 8.5 syntax. Ordinary runtime callbacks retain their ordinary PHP capabilities.
7. PHP `^8.5.13` is the minimum for the selected DI 6 integration because earlier 8.5 releases have incorrect scope/visibility behavior for first-class static method references in trait constant expressions.

## Recommended public API proposal

Add two method/function attributes, each repeatable, that implement the existing public `InterceptorInterface`:

| Proposed attribute | Constructor | Existing semantic contract adapted |
|---|---|---|
| `Componenta\Interceptor\Attribute\After` | `__construct(Closure $callback)` | `CallbackInterceptorFactory::after`: invoke continuation once, then invoke callback with `(mixed $result, CallableContextInterface $context)` and return its result. |
| `Componenta\Interceptor\Attribute\InterceptCallback` | `__construct(Closure $callback)` | `CallbackInterceptorFactory::create`: invoke callback with `(CallableContextInterface $context, ContextHandlerInterface $next)` and return its result. |

`After` covers the common result-transform use case without exposing a continuation. `InterceptCallback` gives the same complete interception capability as a class-based interceptor. Both use native attribute construction and the existing attribute discovery/execution path; they do not require widening `Intercept::$interceptor` or overloading factory constructor parameters as callback arguments.

The name `InterceptCallback` is preferred to `Around`: the existing `CallbackInterceptorFactory::around` accepts separate before and after callbacks, whereas the proposed attribute accepts one callback with continuation control. Using the same name for those different public shapes would add avoidable ambiguity.

```php
#[After(static function (mixed $result): array {
    return ['data' => $result];
})]
```

```php
#[InterceptCallback(static function (
    CallableContextInterface $context,
    ContextHandlerInterface $next,
): mixed {
    return ['data' => $next->handle($context)];
})]
```

These are proposed APIs, not existing supported syntax. The attributes need not expose a public callback property or a new interface beyond `InterceptorInterface`.

### Proposed invocation contract

- Native invocation passes exactly the semantic argument vector described in the table. PHP determines whether a specific declared Closure accepts that vector; no arity-guessing, DI suffix injection, or callback parameter-attribute processing is added.
- A user-defined callback may omit unused trailing arguments according to native PHP calling semantics. Missing required extra arguments and incompatible types produce native invocation errors. Adding a third required service argument does not request DI.
- `After` runs only after successful continuation completion. Its callback may return any value, including null. If the continuation throws, the callback does not run.
- `InterceptCallback` may call the continuation zero, one, or multiple times, exactly as the existing class-based/public callback interceptor contract permits. The library does not add retries or exactly-once enforcement. The callback owns any repeated effects.
- Callback-body Throwables, continuation Throwables, and native invocation errors propagate unchanged unless an enclosing interceptor handles them.
- Callback parameters are ordinary native arguments; their annotations do not trigger DI transformations. No new by-reference mutation guarantee is added beyond the existing runtime callback contract.
- Applications needing injected dependencies continue to use a class-based interceptor resolved through `FactoryInterface`, or explicitly obtain already prepared invocation data through the public context. A future DI-aware callback contract must be specified separately using public DI contracts, never DI internals.

This recommendation deliberately retains `Intercept(string $interceptor, array $params = [])` as a factory declaration and the existing runtime callback factory API without semantic changes. Additional phase-specific attributes are outside this proposed slice unless the missing design context requires them.

## Lifecycle, order, and scope constraints

- Attribute order remains outermost-first: the first declared interceptor enters first and unwinds last.
- An outer short circuit prevents inner attribute construction and callback execution.
- The proposed callback attributes are unscoped, like an interceptor that does not implement `ScopedInterface`; they run in every selected execution scope. Existing scoped class-based/native attributes retain their filtering rules. No new scope constructor option is introduced by this proposal.
- Every reached native attribute gets fresh native argument evaluation and attribute construction; metadata reuse must not retain an invocation's Closure, context, result, service, or temporary argument object.
- Preserve PHP's native lexical scope, including private static references and trait-using-class resolution. Do not reconstruct a Closure as a string callable.
- A callback declaring local static state must exhibit the same behavior on cold and warm metadata paths. Named function/static method state retains its PHP-defined lifetime; the library does not reset application state.
- Existing factory-created interceptor lifetime is owned by `FactoryInterface`, not by the metadata cache.
- The selected attribute chain and scope remain fixed at entry into `AttributeInterceptor`; context replacement within that chain does not rediscover attributes.
- No library retry or rollback is introduced. Effects already produced by a callback remain application-owned when a later operation fails.

## Contract-driven verification design

Tests must exercise public invocation results, continuation effects, observed ordering, errors, and object lifetimes. They must not assert private cache layout or a specific internal class design.

| Contract property | Acceptance evidence |
|---|---|
| Native callback declaration | Invoke an attributed named function and method through the public pipeline; observe returned transformation. |
| Phase semantics | `After` observes one successful continuation result and does not run after failure; `InterceptCallback` supports explicit continuation control and short circuit. |
| Mixed declaration order | Combine callback, factory-backed, and direct native interceptor attributes in both orders. |
| Scope | Unscoped callback attributes run under existing scopes; surrounding scoped declarations retain their filtering and short-circuit behavior. |
| Semantic argument identity | Callback observes the exact supplied public context/continuation; a custom context implementation works. |
| Native invocation without DI suffix | A required third callback parameter fails natively; no service resolver is consulted. Runtime factory and attribute adapters produce equivalent results/errors for the same semantic argument vector. |
| Failure ownership | Callback-body and continuation Throwable identity propagates unchanged unless an outer interceptor handles it; `After` never runs after a continuation failure. |
| Freshness and cache parity | Repeat the same declaration cold and warm; compare results, error boundaries, construction count, and local static behavior. |
| Lifetime | Weak references show that per-invocation context, callback-owned temporary objects, and unshared dependencies are released after invocation. |
| Native scope | Function references, private static methods, trait declarations used by two classes, and inherited attributes preserve PHP scope. |
| Preserved factory path | Non-empty opaque factory IDs, parameter slot capture, custom factory implementations, native target/repeatability errors, and late-loaded attributes retain their established contracts. |
| No dependency on DI internals | Static source/import inspection and public-interface test doubles demonstrate the dependency direction. |

During implementation, each accepted behavior starts with a failing contract test, followed by the minimum implementation and targeted verification. Existing `composer test`, `composer analyse`, and `composer bench:smoke` commands are available in the inspected baseline. Use the PHPBench suite only to assess the changed hot path; no unsupported numeric performance target is introduced.

## Grounded change surface

- Existing module `src/Attribute/`: add the proposed `After` and `InterceptCallback` declarations. These are planned new symbols, not existing files.
- `src/Attribute/Intercept.php`: retain the factory-ID contract and parameter meaning.
- `src/AttributeInterceptor.php` and `src/Internal/AttributeHandler.php`: consume the new `InterceptorInterface` attributes through the existing reached-layer path; production changes here are not a goal unless acceptance exposes a real integration gap.
- `src/Factory/AttributeInterceptorFactory.php`: retain existing public DI wiring; the proposed native adapters require no additional invoker or resolver dependency.
- `src/CallbackInterceptorFactory.php`: reuse existing semantic behavior through its public API; do not change runtime callback semantics incidentally.
- `src/CallableContextInterface.php`: consume the existing contract; no additional exposure is currently justified.
- `tests/NativeAttributeTest.php`, `tests/InterceptDeclarationTest.php`, `tests/CallbackInterceptorFactoryTest.php`, and `tests/AttributeInterceptorFactoryTest.php`: existing behavior anchors.
- `README.md` and `README.ru.md`: distinguish native attribute callbacks from runtime callback factories, document invocation phases and PHP syntax.
- `composer.json`: reconcile the installable DI/Config graph before claiming integration acceptance.

## Bounded evidence-acquisition plan

### E1 — Reconcile the requested conversation

Obtain the relevant user-supplied design decisions from the unavailable prior discussion. Compare them with the recommended `After`/`InterceptCallback` native adapters, explicit absence of DI suffix injection, continuation semantics, and compatibility treatment. Revise this draft into a single normative design. Stop when each material decision has an authoritative requirement or the proposal has been explicitly accepted for this task.

### E2 — Reconcile the DI 6 / Config integration base

Identify the intended compatible DI 6 and Config 4 revisions, including any existing unpublished work. Verify the public contract namespaces and Composer constraints. The present evidence is sufficient to prove the version-range conflict, but not to decide which repository owns the version migration. Do not modify production manifests as part of specification-only work.

## Production execution outline — not executable while blocked

After E1 and E2, derive vertical slices for: (1) one native callback layer and public invocation contract; (2) mixed ordering, scopes, and short-circuit composition; (3) native lifetime/scope/cache parity and real DI integration; (4) documentation and dependency convergence. Each slice must reference the finalized contracts and include failing proof, minimum implementation, cleanup of superseded paths if any, and an observable done condition. This outline does not authorize implementation.

## Final convergence requirement

Before calling a future implementation complete, compare the observable callback API, ownership, metadata lifetime, dependency direction, and failure behavior with the finalized normative specification. Resolve missing, partial, contradictory, or unrequested behavior; passing tests alone does not settle architectural drift.
