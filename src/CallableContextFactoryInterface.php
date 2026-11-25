<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\Exception\ExceptionInterface;

/**
 * Factory for creating {@see CallableContextInterface} instances.
 *
 * Implementations may resolve callables from various representations
 * (strings, arrays, closures) before wrapping them in a context.
 */
interface CallableContextFactoryInterface
{
    /**
     * Creates a callable context from the given callable representation.
     * Top-level input slots are captured before callable resolution begins.
     *
     * @param mixed $callable The callable or its representation to resolve and wrap.
     * @param array<string|int, mixed> $params Parameters for the callable invocation.
     * @param array<string, mixed> $attributes Arbitrary attributes for the context.
     * @return CallableContextInterface The created context.
     * @throws ExceptionInterface If the callable cannot be resolved or captured.
     */
    public function createContext(mixed $callable, array $params = [], array $attributes = []): CallableContextInterface;
}