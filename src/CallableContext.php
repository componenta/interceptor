<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\Reflection\Reflection;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\ScopedInterface;
use ReflectionFunctionAbstract;

/**
 * Immutable implementation of {@see CallableContextInterface}.
 *
 * Encapsulates a callable along with its parameters and attributes,
 * providing lazy reflection resolution for performance.
 *
 */
final class CallableContext implements CallableContextInterface
{
    public const string SCOPE_ATTRIBUTE = 'interceptor.scope';

    /**
     * Cached reflection instance, lazily initialized.
     */
    private ?ReflectionFunctionAbstract $reflectorCache = null;

    /**
     * Reflection of the callable.
     *
     * Lazily resolved on first access using {@see Reflection::callable()}.
     */
    public ReflectionFunctionAbstract $reflector {
        get => $this->reflectorCache ??= Reflection::callable($this->callable);
    }

    /**
     * The encapsulated callable.
     *
     * @var callable
     */
    private $callable;

    /**
     * Creates a new callable context.
     *
     * @param callable $callable The callable to encapsulate.
     * @param array<string|int, mixed> $parameters Parameters for invocation.
     * @param array<string, mixed> $attributes Arbitrary attributes.
     * @param ReflectionFunctionAbstract|null $reflector Pre-resolved callable reflector.
     */
    public function __construct(
        callable $callable,
        private(set) readonly array $parameters = [],
        private(set) readonly array $attributes = [],
        ?ReflectionFunctionAbstract $reflector = null,
    ) {
        $this->callable = $callable;
        $this->reflectorCache = $reflector;
    }

    /**
     * Creates a context tagged with an execution scope.
     *
     * Convenience wrapper for entry-points (HTTP middleware, console command dispatchers,
     * queue message handlers) that build a context and must signal their scope for
     * {@see ScopedInterface}-aware interceptors. Any {@see self::SCOPE_ATTRIBUTE}
     * entry already present in $attributes is overwritten by $scope.
     *
     * @param ScopeInterface $scope Execution scope marker.
     * @param callable $callable The callable to encapsulate.
     * @param array<string|int, mixed> $parameters Parameters for invocation.
     * @param array<string, mixed> $attributes Arbitrary attributes.
     */
    public static function scoped(
        ScopeInterface $scope,
        callable $callable,
        array $parameters = [],
        array $attributes = [],
    ): self {
        return new self(
            $callable,
            $parameters,
            [...$attributes, self::SCOPE_ATTRIBUTE => $scope],
        );
    }

    /**
     * @inheritDoc
     */
    public function getCallable(): callable
    {
        return $this->callable;
    }

    /**
     * @inheritDoc
     */
    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->attributes[$name] ?? $default;
    }

    /**
     * @inheritDoc
     */
    public function withAttribute(string $name, mixed $value): CallableContextInterface
    {
        $attributes = $this->attributes;
        $attributes[$name] = $value;

        $copy = new self($this->callable, $this->parameters, $attributes, $this->reflectorCache);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withoutAttribute(string $name): CallableContextInterface
    {
        $attributes = $this->attributes;
        unset($attributes[$name]);

        $copy = new self($this->callable, $this->parameters, $attributes, $this->reflectorCache);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withAttributes(array $attributes): CallableContextInterface
    {
        $copy = new self($this->callable, $this->parameters, $attributes, $this->reflectorCache);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withParameters(array $parameters): CallableContextInterface
    {
        $copy = new self($this->callable, $parameters, $this->attributes, $this->reflectorCache);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function getParameter(string|int $key, mixed $default = null): mixed
    {
        return $this->parameters[$key] ?? $default;
    }

    /**
     * @inheritDoc
     */
    public function withParameter(string|int $key, mixed $value): CallableContextInterface
    {
        $parameters = $this->parameters;
        $parameters[$key] = $value;

        $copy = new self($this->callable, $parameters, $this->attributes, $this->reflectorCache);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withoutParameter(string|int $key): CallableContextInterface
    {
        $parameters = $this->parameters;
        unset($parameters[$key]);

        $copy = new self($this->callable, $parameters, $this->attributes, $this->reflectorCache);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withCallable(callable $callable, ?array $parameters = null, ?array $attributes = null): CallableContextInterface
    {
        return new self(
            $callable,
            $parameters ?? $this->parameters,
            $attributes ?? $this->attributes,
        );
    }
}
