<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use ReflectionFunctionAbstract;

/**
 * Encapsulates a callable with its execution context.
 *
 * The context provides access to:
 * - The callable itself
 * - Parameters to be passed during invocation
 * - Arbitrary attributes for interceptor communication
 * - Reflection information for introspection
 *
 * Implementations must be immutable - all `with*` methods return
 * new instances with the modified state.
 */
interface CallableContextInterface
{
    /**
     * Parameters to be passed to the callable during invocation.
     *
     * @var array<string|int, mixed>
     */
    public array $parameters { get; }

    /**
     * Arbitrary attributes for interceptor communication.
     *
     * Attributes allow interceptors to pass data to each other
     * without modifying the callable or its parameters.
     *
     * @var array<string, mixed>
     */
    public array $attributes { get; }

    /**
     * Reflection of the callable for introspection.
     *
     * Provides access to parameter types, attributes, return type, etc.
     */
    public ReflectionFunctionAbstract $reflector { get; }

    /**
     * Returns the encapsulated callable.
     */
    public function getCallable(): callable;

    /**
     * Creates a new context with the given attributes, replacing all existing ones.
     *
     * @param array<string, mixed> $attributes The new attributes.
     * @return CallableContextInterface A new context with the specified attributes.
     */
    public function withAttributes(array $attributes): CallableContextInterface;

    /**
     * Retrieves an attribute value by name.
     *
     * @param string $name The attribute name.
     * @param mixed $default Value to return if the attribute does not exist.
     * @return mixed The attribute value or the default.
     */
    public function getAttribute(string $name, mixed $default = null): mixed;

    /**
     * Creates a new context with the specified attribute added or updated.
     *
     * @param string $name The attribute name.
     * @param mixed $value The attribute value.
     * @return static A new context with the attribute set.
     */
    public function withAttribute(string $name, mixed $value): CallableContextInterface ;

    /**
     * Creates a new context with the specified attribute removed.
     *
     * @param string $name The attribute name to remove.
     * @return static A new context without the specified attribute.
     */
    public function withoutAttribute(string $name): CallableContextInterface;

    /**
     * Creates a new context with the given parameters, replacing all existing ones.
     *
     * @param array<string|int, mixed> $parameters The new parameters.
     * @return static A new context with the specified parameters.
     */
    public function withParameters(array $parameters): CallableContextInterface ;

    /**
     * Retrieves a parameter value by key.
     *
     * @param string|int $key The parameter key (name or position).
     * @param mixed $default Value to return if the parameter does not exist.
     * @return mixed The parameter value or the default.
     */
    public function getParameter(string|int $key, mixed $default = null): mixed;

    /**
     * Creates a new context with the specified parameter added or updated.
     *
     * @param string|int $key The parameter key (name or position).
     * @param mixed $value The parameter value.
     * @return static A new context with the parameter set.
     */
    public function withParameter(string|int $key, mixed $value): CallableContextInterface ;

    /**
     * Creates a new context with the specified parameter removed.
     *
     * @param string|int $key The parameter key to remove.
     * @return static A new context without the specified parameter.
     */
    public function withoutParameter(string|int $key): CallableContextInterface ;

    /**
     * Creates a new context with a different callable.
     *
     * Replaces the callable and resets the cached reflector.
     * If parameters or attributes are null, they are inherited from the current context.
     *
     * @warning Inheriting parameters when the callable changes may pass arguments
     *          incompatible with the new callable's signature. Pass an explicit empty
     *          array to reset parameters when the signatures are not guaranteed to match.
     *
     * @param callable $callable The new callable.
     * @param array<string|int, mixed>|null $parameters New parameters, or null to keep current.
     * @param array<string, mixed>|null $attributes New attributes, or null to keep current.
     */
    public function withCallable(callable $callable, ?array $parameters = null, ?array $attributes = null): CallableContextInterface;
}