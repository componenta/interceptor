<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Exception\CallableExceptionInterface;

/**
 * Executes callables through an interceptor pipeline.
 *
 * Interceptors are executed in registration order:
 * the first registered interceptor is the outermost one (runs first
 * in the call direction, last in the unwind direction).
 *
 * Parameter resolution is not built-in - register a
 * {@see ParameterResolvingInterceptor} before any interceptor that
 * reads {@see CallableContextInterface::$parameters}.
 *
 * The pipeline is pre-composed lazily on first use into a chain of
 * {@see InterceptorHandler} instances, so per-call traversal performs
 * one indirect call per interceptor with no per-call allocation.
 *
 * The executor is immutable - {@see pipe()} returns a new instance
 * with a fresh (uncomposed) pipeline.
 */
final class InterceptingExecutor implements CallableExecutorInterface, PipelineInterface
{
    /** @var InterceptorInterface[] */
    private array $interceptors;

    private readonly ContextHandler $contextHandler;

    /**
     * Lazily composed terminal-rooted handler chain.
     */
    private ?ContextHandlerInterface $composed = null;

    /**
     * @param CallableExecutorInterface $executor Base executor for callable resolution and invocation.
     * @param InterceptorInterface ...$interceptors Initial interceptors (empty by default).
     */
    public function __construct(
        private readonly CallableExecutorInterface $executor,
        InterceptorInterface ...$interceptors,
    ) {
        $this->interceptors = $interceptors;
        $this->contextHandler = new ContextHandler($executor);
    }

    /**
     * @throws CallableExceptionInterface
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->handle(new CallableContext(
            $this->executor->resolve($callable),
            $params,
        ));
    }

    /**
     * @throws CallableExceptionInterface
     */
    public function resolve(mixed $callable): callable
    {
        return $this->executor->resolve($callable);
    }

    /**
     * @throws CallableExceptionInterface
     */
    public function handle(CallableContextInterface $context): mixed
    {
        return ($this->composed ??= ChainComposer::compose($this->interceptors, $this->contextHandler))
            ->handle($context);
    }

    /**
     * Runs this pipeline as a nested interceptor on top of the provided handler.
     *
     * The outer handler becomes the terminal of this pipeline's chain.
     * Composition is performed per call because the terminal differs from
     * the executor's own one and is not memoizable across distinct callers.
     */
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return ChainComposer::compose($this->interceptors, $handler)
            ->handle($context);
    }

    public function pipe(InterceptorInterface ...$interceptor): self
    {
        $copy = clone $this;
        $copy->interceptors = [...$this->interceptors, ...$interceptor];
        $copy->composed = null;

        return $copy;
    }
}
