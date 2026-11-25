<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\Reflection\Reflection;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\ScopedInterface;
use Closure;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use TypeError;

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
        get => $this->resolveReflector();
    }

    /**
     * The encapsulated callable.
     *
     * @var callable
     */
    private readonly mixed $callable;

    /** @var array<string|int, mixed> */
    public readonly array $parameters;

    /** @var array<string, mixed> */
    public readonly array $attributes;

    /**
     * Creates a new callable context.
     *
     * @param callable $callable The callable to encapsulate.
     * @param array<string|int, mixed> $parameters Parameters for invocation.
     * @param array<string, mixed> $attributes Arbitrary attributes.
     */
    public function __construct(
        callable|array $callable,
        array $parameters = [],
        array $attributes = [],
    ) {
        // Snapshot array callables before native validation can invoke autoloaders.
        if (is_array($callable)) {
            $callable = self::snapshot($callable);
            if (!is_callable($callable)) {
                throw new TypeError('Expected a valid PHP callable.');
            }
        }

        $this->callable = $callable;
        $this->parameters = self::snapshot($parameters);
        $this->attributes = self::snapshot($attributes);
    }

    /**
     * Detach top-level reference slots without changing keys or object identities.
     *
     * @template TKey of array-key
     * @param array<TKey, mixed> $values
     * @return array<TKey, mixed>
     */
    private static function snapshot(array $values): array
    {
        $snapshot = [];
        foreach ($values as $key => $value) {
            $snapshot[$key] = $value;
        }
        return $snapshot;
    }

    private function resolveReflector(): ReflectionFunctionAbstract
    {
        try {
            // Reflection 3 provides guarded cached handles.
            return $this->reflectorCache ??= Reflection::callable($this->callable);
        } catch (InvalidArgumentException) {
            // Magic dispatch has no concrete declaration. Never cache or share
            // this mutable native fallback with callers or context copies.
            return new ReflectionFunction(Closure::fromCallable($this->callable));
        }
    }

    /** @param array<string|int, mixed> $parameters
     *  @param array<string, mixed> $attributes
     */
    private function copy(array $parameters, array $attributes): self
    {
        $copy = new self($this->callable, $parameters, $attributes);
        $copy->reflectorCache = $this->reflectorCache;
        return $copy;
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
        return array_key_exists($name, $this->attributes) ? $this->attributes[$name] : $default;
    }

    /**
     * @inheritDoc
     */
    public function withAttribute(string $name, mixed $value): CallableContextInterface
    {
        $attributes = array_replace($this->attributes, [$name => $value]);

        $copy = $this->copy($this->parameters, $attributes);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withoutAttribute(string $name): CallableContextInterface
    {
        $attributes = $this->attributes;
        unset($attributes[$name]);

        $copy = $this->copy($this->parameters, $attributes);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withAttributes(array $attributes): CallableContextInterface
    {
        $copy = $this->copy($this->parameters, $attributes);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withParameters(array $parameters): CallableContextInterface
    {
        $copy = $this->copy($parameters, $this->attributes);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function getParameter(string|int $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $this->parameters) ? $this->parameters[$key] : $default;
    }

    /**
     * @inheritDoc
     */
    public function withParameter(string|int $key, mixed $value): CallableContextInterface
    {
        $parameters = array_replace($this->parameters, [$key => $value]);

        $copy = $this->copy($parameters, $this->attributes);

        return $copy;
    }

    /**
     * @inheritDoc
     */
    public function withoutParameter(string|int $key): CallableContextInterface
    {
        $parameters = $this->parameters;
        unset($parameters[$key]);

        $copy = $this->copy($parameters, $this->attributes);

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
