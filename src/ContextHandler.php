<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\PreparedCallable;
use Componenta\Interceptor\Internal\ResolvedCallableContext;
use Throwable;

/**
 * Terminal handler that invokes the callable from context.
 *
 * This handler serves as the end of the interceptor chain,
 * using {@see CallableExecutorInterface} for every invocation, including
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
     * @param CallableExecutorInterface $executor Executor for callable invocation.
     */
    public function __construct(
        private CallableExecutorInterface $executor,
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

        return $this->executor->call($context->getCallable(), $context->parameters);
    }
}
