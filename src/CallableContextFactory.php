<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Exception\ExceptionInterface;
use Componenta\DI\Exception\InvalidCallableException;
use Componenta\Interceptor\Internal\ArraySlots;
use Throwable;

/**
 * Factory that resolves callables before creating contexts.
 *
 * Uses a {@see CallableResolverInterface} to resolve callable representations
 * (class names, service references, etc.) into actual callables before
 * wrapping them in a {@see CallableContext}.
 */
final readonly class CallableContextFactory implements CallableContextFactoryInterface
{
    /**
     * Creates a new factory instance.
     *
     * @param CallableResolverInterface $resolver Resolver for callable representations.
     */
    public function __construct(
        private CallableResolverInterface $resolver,
    ) {
    }

    /**
     * Creates a context by resolving the callable first.
     *
     * @param mixed $callable The callable or its representation.
     * @param array<string|int, mixed> $params Parameters for invocation.
     * @param array<string, mixed> $attributes Arbitrary attributes.
     * @return CallableContextInterface The created context.
     *
     * @throws ExceptionInterface If the callable cannot be resolved or captured.
     */
    public function createContext(mixed $callable, array $params = [], array $attributes = []): CallableContextInterface
    {
        if (is_array($callable)) {
            $callable = ArraySlots::snapshot($callable);
        }
        $params = ArraySlots::snapshot($params);
        $attributes = ArraySlots::snapshot($attributes);
        try {
            return new CallableContext(
                $this->resolver->resolve($callable),
                $params,
                $attributes,
            );
        } catch (ExceptionInterface $error) {
            throw $error;
        } catch (Throwable $error) {
            throw InvalidCallableException::forValue($callable, $error);
        }
    }
}
