<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

use ArgumentCountError;
use ReflectionFunctionAbstract;

/** @internal Logical edits of an already prepared native argument list. */
final class ArgumentBinding
{
    private function __construct() {}

    /**
     * Keep a single logical slot for each fixed argument. As in DI input
     * binding, an explicit name takes precedence over its positional alias.
     * Native vectors are not normalized until a logical edit is requested.
     *
     * @param array<string|int, mixed> $arguments
     * @return array<string|int, mixed>
     */
    public static function logical(?ReflectionFunctionAbstract $reflector, array $arguments): array
    {
        foreach ($reflector?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            if (array_key_exists($parameter->getName(), $arguments)) {
                unset($arguments[$parameter->getPosition()]);
            }
        }
        return $arguments;
    }

    /** @param array<string|int, mixed> $arguments */
    public static function key(?ReflectionFunctionAbstract $reflector, array $arguments, string|int $key): string|int
    {
        $key = array_key_first([$key => null]);
        foreach ($reflector?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            $name = $parameter->getName();
            $position = $parameter->getPosition();
            if ($key !== $name && $key !== $position) {
                continue;
            }
            if (array_key_exists($name, $arguments)) {
                return $name;
            }
            if (array_key_exists($position, $arguments)) {
                return $position;
            }
            break;
        }
        return $key;
    }

    /** @param array<string|int, mixed> $arguments
     *  @return array<string|int, mixed>
     */
    public static function remove(?ReflectionFunctionAbstract $reflector, array $arguments, string|int $key): array
    {
        $key = array_key_first([$key => null]);
        unset($arguments[$key]);

        foreach ($reflector?->getParameters() ?? [] as $parameter) {
            if ($parameter->isVariadic()) {
                break;
            }
            if ($key === $parameter->getName() || $key === $parameter->getPosition()) {
                unset($arguments[$parameter->getName()], $arguments[$parameter->getPosition()]);
                break;
            }
        }

        return $arguments;
    }

    /**
     * Adapt inherited prepared slots to the DI input representation of the
     * replacement declaration. Named variadic values are literal argument
     * names, including a name equal to the variadic declaration itself.
     *
     * @param array<string|int, mixed> $arguments
     * @return array<string|int, mixed>
     */
    public static function supplied(?ReflectionFunctionAbstract $reflector, array $arguments, bool $logical): array
    {
        if ($reflector === null) {
            // A magic callable has no DI declaration. Retain native errors
            // for untouched vectors; logical edits need a valid native order.
            return $logical ? self::native(null, $arguments) : $arguments;
        }

        $remaining = $arguments;
        foreach ($reflector->getParameters() as $parameter) {
            if ($parameter->isVariadic()) {
                if ($remaining === []) {
                    return $arguments;
                }

                [$positional, $named] = self::splitTail($remaining);
                $fixed = array_diff_key($arguments, $remaining);
                $fixed[$parameter->getName()] = [...$positional, ...$named];

                return $fixed;
            }

            unset($remaining[$parameter->getName()], $remaining[$parameter->getPosition()]);
        }

        return $arguments;
    }

    /**
     * @param array<string|int, mixed> $remaining
     * @return array<string|int, mixed>
     */
    public static function native(?ReflectionFunctionAbstract $reflector, array $remaining): array
    {
        $fixed = [];
        $declarations = [];
        foreach ($reflector?->getParameters() ?? [] as $parameter) {
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

        [$positionalTail, $namedTail] = self::splitTail($remaining);

        if ($positionalTail === []) {
            return [...$fixed, ...$namedTail];
        }

        // Materialize only defaults needed to reach positional tail arguments.
        $arguments = [];
        foreach ($declarations as $parameter) {
            $name = $parameter->getName();
            if (array_key_exists($name, $fixed)) {
                $arguments[] = $fixed[$name];
            } elseif ($parameter->isDefaultValueAvailable()) {
                $arguments[] = $parameter->getDefaultValue();
            } else {
                throw new ArgumentCountError(sprintf(
                    'Missing required argument $%s for %s.',
                    $name,
                    $reflector->getName(),
                ));
            }
        }

        return [...$arguments, ...$positionalTail, ...$namedTail];
    }

    /**
     * @param array<string|int, mixed> $remaining
     * @return array{list<mixed>, array<string, mixed>}
     */
    private static function splitTail(array $remaining): array
    {
        $positional = [];
        $named = [];
        foreach ($remaining as $key => $value) {
            if (is_int($key)) {
                $positional[] = $value;
            } else {
                $named[$key] = $value;
            }
        }

        return [$positional, $named];
    }
}
