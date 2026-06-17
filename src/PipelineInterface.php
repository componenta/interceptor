<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

/**
 * Represents an immutable interceptor pipeline.
 *
 * The pipeline combines the capabilities of both a context handler
 * and an interceptor, allowing it to be used as a standalone executor
 * or as part of a larger interceptor chain.
 *
 * Implementations must be immutable - the {@see pipe()} method returns
 * a new pipeline instance with the added interceptor.
 */
interface PipelineInterface extends ContextHandlerInterface, InterceptorInterface
{
    /**
     * Creates a new pipeline with the given interceptor appended.
     *
     * @param InterceptorInterface ...$interceptor The interceptor to add.
     * @return PipelineInterface A new pipeline instance containing the added interceptor.
     */
    public function pipe(InterceptorInterface  ...$interceptor): PipelineInterface;
}