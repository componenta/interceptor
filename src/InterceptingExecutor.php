<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\PreparedCallable;
use Componenta\Interceptor\Internal\ResolvedCallableContext;
use Throwable;

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
    /** @var list<InterceptorInterface> */
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
        $this->interceptors = array_values($interceptors);
        $this->contextHandler = new ContextHandler($executor);
    }

    /**
     * @throws Throwable
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->handle(new CallableContext(
            $this->executor->resolve($callable),
            $params,
        ));
    }

    /**
     * @throws Throwable
     */
    public function resolve(mixed $callable): callable
    {
        return $this->executor->resolve($callable);
    }

    /**
     * @throws Throwable
     */
    public function handle(CallableContextInterface $context): mixed
    {
        return ($this->composed ??= ChainComposer::compose($this->interceptors, $this->contextHandler))
            ->handle($this->normalizeContext($context));
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
            ->handle($this->normalizeContext($context));
    }

    private function normalizeContext(CallableContextInterface $context): CallableContextInterface
    {
        $callable = $context->getCallable();
        if (!$callable instanceof PreparedCallable) {
            return $context;
        }

        do {
            $callable = $callable->callable;
        } while ($callable instanceof PreparedCallable);

        return new ResolvedCallableContext(
            $context->withCallable($callable, [...$context->parameters]),
            nativeArguments: true,
        );
    }

    public function pipe(InterceptorInterface ...$interceptor): self
    {
        $copy = clone $this;
        $copy->interceptors = [...$this->interceptors, ...array_values($interceptor)];
        $copy->composed = null;

        return $copy;
    }
}
