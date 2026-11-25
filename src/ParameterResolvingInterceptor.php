<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\DI\Resolver\Parameter\ParametersResolverInterface;
use Throwable;

/**
 * Prepares callable arguments before downstream interceptors run.
 *
 * The resolver receives the reflected parameter declarations and supplied
 * arguments, and returns a complete native invocation vector. It may omit
 * optional arguments, but must expand positional and named variadic values.
 * Prepared contexts and native magic calls do not run the resolver.
 */
final readonly class ParameterResolvingInterceptor implements InterceptorInterface
{
    public function __construct(
        private ParametersResolverInterface $parametersResolver,
    ) {}

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        if ($context->prepared) {
            return $handler->handle($context);
        }

        try {
            $reflector = $context->reflector;
            $parameters = $reflector === null
                ? $context->parameters
                : $this->parametersResolver->resolve($reflector->getParameters(), $context->parameters);

            $prepared = $context->withPreparedParameters($parameters);
        } catch (ExceptionInterface $error) {
            throw $error;
        } catch (Throwable $error) {
            throw InvalidCallableException::forValue($context->getCallable(), $error);
        }

        // The continuation owns its own errors; application failures are not
        // parameter-resolution failures.
        return $handler->handle($prepared);
    }
}
