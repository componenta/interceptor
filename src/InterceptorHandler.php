<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

/**
 * Adapter that binds an interceptor to its continuation.
 *
 * Enables compile-time composition of a pipeline into a chain of
 * {@see ContextHandlerInterface} instances, so that per-call traversal
 * does not allocate a new handler per step.
 *
 * @internal Used by {@see InterceptingExecutor} for pipeline pre-composition.
 */
final readonly class InterceptorHandler implements ContextHandlerInterface
{
    public function __construct(
        private InterceptorInterface $interceptor,
        private ContextHandlerInterface $next,
    ) {}

    public function handle(CallableContextInterface $context): mixed
    {
        return $this->interceptor->intercept($context, $this->next);
    }
}
