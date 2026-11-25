<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\DI\PreparedCallable;
use Componenta\Interceptor\Internal\ArgumentBinding;
use Componenta\Interceptor\Internal\ArgumentMode;
use Componenta\Interceptor\Internal\ArraySlots;
use Componenta\Interceptor\Internal\NativeCallable;
use Componenta\Reflection\Reflection;
use Componenta\Scope\ScopeInterface;
use InvalidArgumentException;
use ReflectionFunctionAbstract;

/** Immutable invocation context with value-owned top-level argument and attribute slots. */
final class CallableContext implements CallableContextInterface
{
    /** @var callable */
    private readonly mixed $callable;
    /** @var array<string|int, mixed> */
    private array $argumentSlots;
    /** @var array<string, mixed> */
    private array $attributeSlots;
    private ArgumentMode $mode;
    private bool $reflectionResolved = false;
    private ?ReflectionFunctionAbstract $reflection = null;

    public array $parameters {
        get => $this->argumentSlots;
    }

    public array $attributes {
        get => $this->attributeSlots;
    }

    public bool $prepared {
        get => $this->mode !== ArgumentMode::Supplied;
    }

    public ?ReflectionFunctionAbstract $reflector {
        get => $this->resolveReflection();
    }

    /**
     * @param callable $callable
     * @param array<string|int, mixed> $parameters
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        callable|string|array $callable,
        array $parameters = [],
        array $attributes = [],
    ) {
        $parameters = ArraySlots::snapshot($parameters);
        $attributes = ArraySlots::snapshot($attributes);
        $mode = ArgumentMode::Supplied;

        while ($callable instanceof PreparedCallable) {
            $mode = ArgumentMode::Native;
            $callable = $callable->callable;
        }

        // Initialize the readonly target before publishing any new mutable fields.
        $this->callable = NativeCallable::capture($callable);
        $this->mode = $mode;
        $this->argumentSlots = $mode === ArgumentMode::Native ? [...$parameters] : $parameters;
        $this->attributeSlots = $attributes;
    }

    private function resolveReflection(): ?ReflectionFunctionAbstract
    {
        if (!$this->reflectionResolved) {
            try {
                $this->reflection = Reflection::callable($this->callable);
            } catch (InvalidArgumentException) {
                // Reflection's public contract excludes native magic dispatch.
                $this->reflection = null;
            }
            $this->reflectionResolved = true;
        }

        return $this->reflection;
    }

    /**
     * @param callable $callable
     * @param array<string|int, mixed> $parameters
     * @param array<string, mixed> $attributes
     */
    public static function scoped(
        ScopeInterface $scope,
        callable|string|array $callable,
        array $parameters = [],
        array $attributes = [],
    ): self {
        return new self($callable, $parameters, [...$attributes, self::SCOPE_ATTRIBUTE => $scope]);
    }

    public function getCallable(): callable
    {
        return $this->callable;
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return array_key_exists($name, $this->attributeSlots) ? $this->attributeSlots[$name] : $default;
    }

    public function withAttributes(array $attributes): self
    {
        $copy = clone $this;
        $copy->attributeSlots = ArraySlots::snapshot($attributes);
        return $copy;
    }

    public function withAttribute(string $name, mixed $value): self
    {
        $copy = clone $this;
        $copy->attributeSlots[$name] = $value;
        return $copy;
    }

    public function withoutAttribute(string $name): self
    {
        $copy = clone $this;
        unset($copy->attributeSlots[$name]);
        return $copy;
    }

    public function withPreparedParameters(array $parameters): self
    {
        $copy = clone $this;
        $copy->argumentSlots = [...ArraySlots::snapshot($parameters)];
        $copy->mode = ArgumentMode::Native;
        return $copy;
    }

    public function invocationArguments(): array
    {
        return $this->mode === ArgumentMode::Logical
            ? ArgumentBinding::native($this->reflector, $this->argumentSlots)
            : $this->argumentSlots;
    }

    public function withParameters(array $parameters): self
    {
        $copy = clone $this;
        $copy->argumentSlots = ArraySlots::snapshot($parameters);
        if ($copy->prepared) {
            $copy->argumentSlots = ArgumentBinding::logical($this->reflector, $copy->argumentSlots);
            $copy->mode = ArgumentMode::Logical;
        }
        return $copy;
    }

    public function getParameter(string|int $key, mixed $default = null): mixed
    {
        $key = $this->parameterKey($key);
        return array_key_exists($key, $this->argumentSlots) ? $this->argumentSlots[$key] : $default;
    }

    public function withParameter(string|int $key, mixed $value): self
    {
        $key = $this->parameterKey($key);
        $copy = clone $this;
        $copy->argumentSlots[$key] = $value;
        if ($copy->prepared) {
            $copy->argumentSlots = ArgumentBinding::logical($this->reflector, $copy->argumentSlots);
            $copy->mode = ArgumentMode::Logical;
        }
        return $copy;
    }

    public function withoutParameter(string|int $key): self
    {
        $copy = clone $this;
        if ($copy->prepared) {
            $reflector = $this->reflector;
            $copy->argumentSlots = ArgumentBinding::logical(
                $reflector,
                ArgumentBinding::remove($reflector, $this->argumentSlots, $key),
            );
            $copy->mode = ArgumentMode::Logical;
        } else {
            unset($copy->argumentSlots[$key]);
        }
        return $copy;
    }

    private function parameterKey(string|int $key): string|int
    {
        return $this->prepared ? ArgumentBinding::key($this->reflector, $this->argumentSlots, $key) : $key;
    }

    public function withCallable(callable|string|array $callable, ?array $parameters = null, ?array $attributes = null): self
    {
        $copy = new self(
            $callable,
            $parameters ?? $this->argumentSlots,
            $attributes ?? $this->attributeSlots,
        );

        // Inherited logical slots describe declaration positions, not a
        // native vector. Reindexing them would move arguments into omitted
        // fixed parameters of the replacement callable.
        if ($parameters === null && $this->mode === ArgumentMode::Logical && $copy->prepared) {
            $copy->argumentSlots = ArgumentBinding::logical($copy->reflector, $this->argumentSlots);
            $copy->mode = ArgumentMode::Logical;
        }

        return $copy;
    }
}
