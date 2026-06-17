<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use WeakReference;

/**
 * Innermost link of a cached chain that holds the terminal handler weakly.
 *
 * Used as the deepest wrapper around a terminal {@see ContextHandlerInterface}
 * when the chain itself is stored in a long-lived cache (e.g., the
 * {@see AttributeInterceptor} per-terminal `WeakMap`).
 *
 * Without the weak link, the cached chain would strongly reference the
 * terminal, making the cache key (terminal) reachable through its own value
 * and defeating the {@see \WeakMap} auto-cleanup contract.
 *
 * Outer links of the chain remain regular {@see InterceptorHandler}
 * instances - they reference each other strongly, which is required for
 * the chain to survive across calls.
 *
 * The terminal is guaranteed to be alive whenever {@see handle()} runs,
 * because the cache owner ({@see AttributeInterceptor}) builds and invokes
 * the chain in a single call where the terminal is held as a stack-frame
 * parameter. If the WeakReference returns null, it indicates a contract
 * violation upstream - PHP will surface it as a TypeError on the
 * subsequent {@see InterceptorInterface::intercept()} call.
 *
 * @internal Used by chain-caching interceptor implementations.
 */
final readonly class WeakTerminalHandler implements ContextHandlerInterface
{
    private WeakReference $terminal;

    public function __construct(
        private InterceptorInterface $interceptor,
        ContextHandlerInterface $terminal,
    ) {
        $this->terminal = WeakReference::create($terminal);
    }

    public function handle(CallableContextInterface $context): mixed
    {
        return $this->interceptor->intercept($context, $this->terminal->get());
    }
}
