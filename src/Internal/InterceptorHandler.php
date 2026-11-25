<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;

/**
 * Adapter that binds an interceptor to its continuation.
 *
 * Enables runtime pre-composition of a pipeline into a chain of
 * {@see ContextHandlerInterface} instances, so that per-call traversal
 * does not allocate a new handler per step.
 *
 * @internal Immutable node of a composed pipeline.
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
