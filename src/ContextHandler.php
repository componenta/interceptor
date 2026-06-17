<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Exception\CallableExceptionInterface;

/**
 * Terminal handler that invokes the callable from context.
 *
 * This handler serves as the end of the interceptor chain,
 * delegating actual invocation to a {@see CallableExecutorInterface}.
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
     * Invokes the callable from the context using the executor.
     *
     * @param CallableContextInterface $context The context containing the callable.
     * @return mixed The result of the callable invocation.
     *
     * @throws CallableExceptionInterface If invocation fails.
     */
    public function handle(CallableContextInterface $context): mixed
    {
        return $this->executor->call($context->getCallable(), $context->parameters);
    }
}
