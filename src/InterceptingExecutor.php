<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\Interceptor\Internal\ArraySlots;
use Componenta\Interceptor\Internal\InterceptorHandler;
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
final class InterceptingExecutor implements PipelineInterface
{
    /** @var list<InterceptorInterface> */
    private array $interceptors;

    private readonly ContextHandlerInterface $contextHandler;
    private readonly CallableContextFactoryInterface $contextFactory;

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
        $this->contextFactory = new CallableContextFactory($executor);
    }

    /**
     * @throws Throwable
     */
    public function call(mixed $callable, array $params = []): mixed
    {
        return $this->handle($this->contextFactory->createContext($callable, $params));
    }

    /**
     * @throws ExceptionInterface
     */
    public function resolve(mixed $callable): callable
    {
        if (is_array($callable)) {
            $callable = ArraySlots::snapshot($callable);
        }
        try {
            return $this->executor->resolve($callable);
        } catch (ExceptionInterface $error) {
            throw $error;
        } catch (Throwable $error) {
            throw InvalidCallableException::forValue($callable, $error);
        }
    }

    /**
     * @throws Throwable
     */
    public function handle(CallableContextInterface $context): mixed
    {
        return ($this->composed ??= $this->compose($this->contextHandler))
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
        return $this->compose($handler)
            ->handle($context);
    }

    private function compose(ContextHandlerInterface $terminal): ContextHandlerInterface
    {
        $handler = $terminal;
        for ($i = count($this->interceptors) - 1; $i >= 0; --$i) {
            $handler = new InterceptorHandler($this->interceptors[$i], $handler);
        }

        return $handler;
    }

    public function pipe(InterceptorInterface ...$interceptor): self
    {
        $copy = clone $this;
        $copy->interceptors = [...$this->interceptors, ...array_values($interceptor)];
        $copy->composed = null;

        return $copy;
    }
}
