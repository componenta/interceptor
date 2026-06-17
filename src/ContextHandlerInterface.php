<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

/**
 * Handles a callable context and returns the execution result.
 *
 * This interface represents the "next" step in the interceptor chain.
 * Implementations may either invoke the callable directly or delegate
 * to further interceptors in the pipeline.
 */
interface ContextHandlerInterface
{
    /**
     * Handles the given callable context.
     *
     * @param CallableContextInterface $context The context containing the callable and its metadata.
     * @return mixed The result of handling the context.
     */
    public function handle(CallableContextInterface $context): mixed;
}