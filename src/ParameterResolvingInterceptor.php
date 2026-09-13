<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Interceptor\Internal\ResolvedCallableContext;

/**
 * Resolves callable parameters through DI before delegating downstream.
 *
 * Register this interceptor before any interceptor that reads
 * {@see CallableContextInterface::$parameters}. Under the FIFO
 * execution order of {@see InterceptingExecutor}, that typically means
 * registering it first (so it becomes the outermost wrapper).
 *
 * Prepared contexts retain their arguments through downstream interceptors.
 * The terminal invokes them without repeating DI attribute processing.
 */
final readonly class ParameterResolvingInterceptor implements InterceptorInterface
{
    public function __construct(
        private ParametersResolver $parametersResolver,
    ) {
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        if ($context instanceof ResolvedCallableContext) {
            return $handler->handle($context);
        }

        $parameters = $context->reflector->getParameters();

        if ($parameters !== []) {
            $resolved = $this->parametersResolver->resolve($parameters, $context->parameters);
            $context = new ResolvedCallableContext($context->withParameters($resolved));
        }

        return $handler->handle($context);
    }
}
