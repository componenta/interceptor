<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\Interceptor\CallableContextInterface;
use ReflectionFunctionAbstract;

/** An alternative context implementation preserving the public context contract. */
class DelegatingContext implements CallableContextInterface
{
    public function __construct(private readonly CallableContextInterface $context) {}

    public array $parameters {
        get => $this->context->parameters;
    }

    public array $attributes {
        get => $this->context->attributes;
    }

    public bool $prepared {
        get => $this->context->prepared;
    }

    public ?ReflectionFunctionAbstract $reflector {
        get => $this->context->reflector;
    }

    public function getCallable(): callable
    {
        return $this->context->getCallable();
    }

    public function withPreparedParameters(array $parameters): self
    {
        return new self($this->context->withPreparedParameters($parameters));
    }

    public function invocationArguments(): array
    {
        return $this->context->invocationArguments();
    }

    public function withParameters(array $parameters): self
    {
        return new self($this->context->withParameters($parameters));
    }

    public function withParameter(string|int $key, mixed $value): self
    {
        return new self($this->context->withParameter($key, $value));
    }

    public function withoutParameter(string|int $key): self
    {
        return new self($this->context->withoutParameter($key));
    }

    public function getParameter(string|int $key, mixed $default = null): mixed
    {
        return $this->context->getParameter($key, $default);
    }

    public function withAttributes(array $attributes): self
    {
        return new self($this->context->withAttributes($attributes));
    }

    public function withAttribute(string $name, mixed $value): self
    {
        return new self($this->context->withAttribute($name, $value));
    }

    public function withoutAttribute(string $name): self
    {
        return new self($this->context->withoutAttribute($name));
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->context->getAttribute($name, $default);
    }

    public function withCallable(callable|string|array $callable, ?array $parameters = null, ?array $attributes = null): self
    {
        return new self($this->context->withCallable($callable, $parameters, $attributes));
    }
}
