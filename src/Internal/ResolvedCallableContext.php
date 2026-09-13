<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

use Componenta\Interceptor\CallableContextInterface;
use ReflectionFunctionAbstract;

/** @internal Keeps argument preparation separate from application context attributes. */
final class ResolvedCallableContext implements CallableContextInterface
{
    public function __construct(
        private readonly CallableContextInterface $context,
        private readonly bool $nativeArguments = false,
    ) {
    }

    public array $parameters {
        get => $this->context->parameters;
    }

    public array $attributes {
        get => $this->context->attributes;
    }

    public ReflectionFunctionAbstract $reflector {
        get => $this->context->reflector;
    }

    public function getCallable(): callable
    {
        return $this->context->getCallable();
    }

    public function getAttribute(string $name, mixed $default = null): mixed
    {
        return $this->context->getAttribute($name, $default);
    }

    public function withAttributes(array $attributes): CallableContextInterface
    {
        return new self($this->context->withAttributes($attributes), $this->nativeArguments);
    }

    public function withAttribute(string $name, mixed $value): CallableContextInterface
    {
        return new self($this->context->withAttribute($name, $value), $this->nativeArguments);
    }

    public function withoutAttribute(string $name): CallableContextInterface
    {
        return new self($this->context->withoutAttribute($name), $this->nativeArguments);
    }

    public function getParameter(string|int $key, mixed $default = null): mixed
    {
        return $this->context->getParameter($this->parameterKey($key), $default);
    }

    public function withParameters(array $parameters): CallableContextInterface
    {
        return new self($this->context->withParameters($parameters));
    }

    public function withParameter(string|int $key, mixed $value): CallableContextInterface
    {
        return new self($this->context->withParameter($this->parameterKey($key), $value));
    }

    private function parameterKey(string|int $key): string|int
    {
        $key = array_key_first([$key => null]);
        foreach ($this->reflector->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            $name = $parameter->getName();
            $position = $parameter->getPosition();
            if ($key !== $name && $key !== $position) {
                continue;
            }

            // Match invocation binding: a named fixed argument takes precedence.
            if (array_key_exists($name, $this->parameters)) {
                return $name;
            }
            if (array_key_exists($position, $this->parameters)) {
                return $position;
            }
            break;
        }

        return $key;
    }

    public function withoutParameter(string|int $key): CallableContextInterface
    {
        $key = $this->parameterKey($key);
        $context = $this->context->withoutParameter($key);
        foreach ($this->reflector->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            if ($key === $parameter->getName() || $key === $parameter->getPosition()) {
                $context = $context->withoutParameter($parameter->getName())
                    ->withoutParameter($parameter->getPosition());
                break;
            }
        }

        return new self($context);
    }

    /** @return array<string|int, mixed> */
    public function invocationArguments(): array
    {
        // Untouched native arguments keep PHP's binding order and binding errors.
        // Parameter edits switch to logical binding so removing a fixed argument
        // does not shift its neighbours.
        if ($this->nativeArguments) {
            return $this->parameters;
        }

        $remaining = $this->parameters;
        $fixed = [];
        $declarations = [];
        foreach ($this->reflector->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            $declarations[] = $parameter;
            $name = $parameter->getName();
            $position = $parameter->getPosition();
            if (array_key_exists($name, $remaining)) {
                $fixed[$name] = $remaining[$name];
            } elseif (array_key_exists($position, $remaining)) {
                $fixed[$name] = $remaining[$position];
            }
            unset($remaining[$name], $remaining[$position]);
        }

        // Names preserve omitted fixed arguments instead of shifting their neighbours.
        if (!array_any(array_keys($remaining), static fn (string|int $key): bool => is_int($key))) {
            return [...$fixed, ...$remaining];
        }

        // PHP requires positional variadic arguments to precede named arguments.
        // Materialize only PHP defaults needed to reach that positional tail.
        $arguments = [];
        foreach ($declarations as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $fixed)) {
                $arguments[] = $fixed[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new \ArgumentCountError(sprintf(
                    'Missing required argument $%s for %s.',
                    $name,
                    $this->reflector->getName(),
                ));
            }
        }

        return [...$arguments, ...$remaining];
    }

    public function withCallable(callable $callable, ?array $parameters = null, ?array $attributes = null): CallableContextInterface
    {
        // A replacement callable has its own parameter and attribute contract.
        return $this->context->withCallable($callable, $parameters, $attributes);
    }
}
