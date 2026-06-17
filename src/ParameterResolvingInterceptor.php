<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\Resolver\Parameter\ParametersResolver;

/**
 * Resolves callable parameters through DI before delegating downstream.
 *
 * Register this interceptor before any interceptor that reads
 * {@see CallableContextInterface::$parameters}. Under the FIFO
 * execution order of {@see InterceptingExecutor}, that typically means
 * registering it first (so it becomes the outermost wrapper).
 *
 * The interceptor is idempotent relative to already-filled parameters -
 * {@see ParametersResolver} skips keys that are already present.
 */
final readonly class ParameterResolvingInterceptor implements InterceptorInterface
{
    public function __construct(
        private ParametersResolver $parametersResolver,
    ) {}

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        $parameters = $context->reflector->getParameters();

        if ($parameters !== []) {
            $resolved = $this->parametersResolver->resolve($parameters, $context->parameters);
            $context = $context->withParameters($resolved);
        }

        return $handler->handle($context);
    }
}
