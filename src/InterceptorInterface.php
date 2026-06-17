<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

/**
 * Intercepts callable execution within a pipeline.
 *
 * An interceptor receives the current execution context and
 * a handler representing the remainder of the pipeline.
 *
 * Implementations may:
 * - perform logic before delegating to the handler
 * - perform logic after the handler returns
 * - short-circuit execution by not delegating further
 *
 * Implementations SHOULD:
 * - invoke {@see ContextHandlerInterface::handle()} at most once
 *   unless intentionally terminating the pipeline
 * - treat the handler as an opaque continuation
 *
 * Implementations MUST NOT:
 * - invoke the handler more than once
 * - retain references to the handler or context beyond execution
 */
interface InterceptorInterface
{
    /**
     * Intercept callable execution.
     *
     * @param CallableContextInterface $context Callable execution context.
     * @param ContextHandlerInterface $handler Continuation of execution.
     *
     * @return mixed Execution result.
     */
    public function intercept(
        CallableContextInterface $context,
        ContextHandlerInterface $handler
    ): mixed;
}
