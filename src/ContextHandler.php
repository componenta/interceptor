<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Closure;
use Componenta\DI\CallableInvokerInterface;
use Componenta\DI\PreparedCallable;
use Componenta\Interceptor\Internal\ResolvedCallableContext;
use Throwable;

/**
 * Terminal handler that invokes the callable from context.
 *
 * This handler serves as the end of the interceptor chain,
 * using {@see CallableInvokerInterface} for every invocation, including
 * calls whose arguments have already been prepared.
 *
 * @example
 * ```php
 * $handler = new ContextHandler($executor);
 * $result = $handler->handle($context);
 * ```
 */
final readonly class ContextHandler implements ContextHandlerInterface
{
    /**
     * Creates a new context handler.
     *
     * @param CallableInvokerInterface $executor Executor for callable invocation.
     */
    public function __construct(
        private CallableInvokerInterface $executor,
    ) {
    }

    /**
     * Delegates invocation while preserving whether arguments need DI resolution.
     *
     * @param CallableContextInterface $context The context containing the callable.
     * @return mixed The result of the callable invocation.
     *
     * @throws Throwable If invocation fails.
     */
    public function handle(CallableContextInterface $context): mixed
    {
        if ($context instanceof ResolvedCallableContext) {
            return $this->executor->call(new PreparedCallable($context->getCallable()), $context->invocationArguments());
        }

        $callable = $context->getCallable();
        if (is_string($callable)) {
            // DI resolves strings as service IDs before native callable names.
            // Preserve the native target selected by the resolver/context.
            // A method pair retains native magic dispatch and called-class semantics.
            $callable = str_contains($callable, '::')
                ? explode('::', $callable, 2)
                : Closure::fromCallable($callable);
        }

        return $this->executor->call($callable, $context->parameters);
    }
}
