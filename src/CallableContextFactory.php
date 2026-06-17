<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\CallableResolverInterface;
use Componenta\DI\Exception\CallableExceptionInterface;

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
     * @return CallableContext The created context.
     *
     * @throws CallableExceptionInterface If the callable cannot be resolved.
     */
    public function createContext(mixed $callable, array $params = [], array $attributes = []): CallableContext
    {
        return new CallableContext(
            $this->resolver->resolve($callable),
            $params,
            $attributes,
        );
    }
}