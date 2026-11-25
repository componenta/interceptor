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
 * Implementations must preserve the callable and the top-level parameter and
 * attribute slots. All `with*` methods return new contexts. Objects and nested
 * reference-bearing values are shared as supplied; this is not a deep freeze.
 */
interface CallableContextInterface
{
    /** Optional scope marker. A non-null value must implement ScopeInterface. */
    public const string SCOPE_ATTRIBUTE = 'interceptor.scope';

    /**
     * Supplied argument slots, or final values when prepared. For a prepared
     * context, use invocationArguments() to obtain the native binding vector.
     *
     * @var array<string|int, mixed>
     */
    public array $parameters { get; }

    /**
     * Arbitrary attributes for interceptor communication.
     *
     * Attributes allow interceptors to pass data to each other
     * without modifying the callable or its parameters. PHP canonicalizes
     * integer-like string keys to integers; those names are preserved when
     * creating or copying a context.
     *
     * @var array<string|int, mixed>
     */
    public array $attributes { get; }

    /**
     * Reflection of the callable for introspection.
     *
     * Provides access to parameter types, attributes, return type, etc.
     * External changes to a returned reflector must not change the callable
     * metadata observed through the context. Null means native dispatch has no
     * concrete declaration (for example, a method handled by __call()).
     */
    public ?ReflectionFunctionAbstract $reflector { get; }

    /**
     * True when the parameters are final invocation values and must not be
     * passed through dependency resolution again.
     */
    public bool $prepared { get; }

    /**
     * Return the native callable, without any DI PreparedCallable adapters.
     */
    public function getCallable(): callable;

    /**
     * Replace the parameters with a complete native argument list, marking
     * them as prepared. Integer keys bind in insertion order.
     *
     * @param array<string|int, mixed> $parameters
     */
    public function withPreparedParameters(array $parameters): CallableContextInterface;

    /**
     * Return the parameters to pass to the configured invoker. For a prepared
     * context this is a native PHP argument vector, including any logical edits.
     * For an unprepared context these are the supplied DI arguments. Logical
     * edits of magic-call arguments preserve positional and named values but
     * cannot identify fixed parameters by declaration name.
     *
     * @return array<string|int, mixed>
     */
    public function invocationArguments(): array;

    /**
     * Creates a new context with the given attributes, replacing all existing ones.
     *
     * @param array<string|int, mixed> $attributes The new attributes.
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
     * @return CallableContextInterface A new context with the attribute set.
     */
    public function withAttribute(string $name, mixed $value): CallableContextInterface;

    /**
     * Creates a new context with the specified attribute removed.
     *
     * @param string $name The attribute name to remove.
     * @return CallableContextInterface A new context without the specified attribute.
     */
    public function withoutAttribute(string $name): CallableContextInterface;

    /**
     * Creates a new context with the given parameters, replacing all existing ones.
     * Prepared contexts retain preparation and interpret fixed argument keys as
     * declaration names or positions; a name takes precedence over a duplicate
     * positional alias, which is removed. Unprepared contexts retain DI input keys.
     *
     * @param array<string|int, mixed> $parameters The new parameters.
     * @return CallableContextInterface A new context with the specified parameters.
     */
    public function withParameters(array $parameters): CallableContextInterface;

    /**
     * Retrieves a parameter value by key. In a prepared context, a fixed
     * parameter name and its declaration position refer to the same value.
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
     * @return CallableContextInterface A new context with the parameter set.
     */
    public function withParameter(string|int $key, mixed $value): CallableContextInterface;

    /**
     * Creates a new context with the specified parameter removed.
     *
     * @param string|int $key The parameter key to remove.
     * @return CallableContextInterface A new context without the specified parameter.
     */
    public function withoutParameter(string|int $key): CallableContextInterface;

    /**
     * Creates a new context with a different callable.
     *
     * Replaces the target and resets argument preparation. A DI PreparedCallable
     * explicitly marks the replacement parameters as already prepared.
     * If parameters or attributes are null, they are inherited from the current context.
     * A prepared replacement preserves inherited logical argument slots; explicitly
     * supplied replacement arguments are treated as a native argument vector.
     *
     * @warning Inheriting parameters when the callable changes may pass arguments
     *          incompatible with the new callable's signature. Pass an explicit empty
     *          array to reset parameters when the signatures are not guaranteed to match.
     *
     * @param callable $callable The new callable.
     * @param array<string|int, mixed>|null $parameters New parameters, or null to keep current.
     * @param array<string|int, mixed>|null $attributes New attributes, or null to keep current.
     */
    public function withCallable(callable|string|array $callable, ?array $parameters = null, ?array $attributes = null): CallableContextInterface;
}