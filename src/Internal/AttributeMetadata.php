<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

use Closure;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\InterceptorInterface;
use ReflectionAttribute;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;

/** @internal Native attribute declarations, without materialized arguments or instances. */
final class AttributeMetadata
{
    public static function signature(ReflectionFunctionAbstract $reflector): ?string
    {
        if ($reflector->isClosure()
            || ($reflector instanceof ReflectionMethod && $reflector->class === Closure::class && $reflector->name === '__invoke')
        ) {
            return null;
        }

        return match (true) {
            $reflector instanceof ReflectionMethod => $reflector->class . '::' . $reflector->name,
            $reflector instanceof ReflectionFunction => $reflector->name,
            default => null,
        };
    }

    /** @return list<int>|null Null when an attribute class is not yet available. */
    public static function positions(ReflectionFunctionAbstract $reflector): ?array
    {
        $positions = [];
        foreach ($reflector->getAttributes() as $position => $attribute) {
            if (self::supports($attribute)) {
                $positions[] = $position;
            } elseif (!self::isLoaded($attribute)) {
                return null;
            }
        }
        return $positions;
    }

    /**
     * @param list<int>|null $positions
     * @param bool $cacheable False when an unavailable attribute class may become an interceptor later.
     * @return list<ReflectionAttribute<object>>
     */
    public static function resolve(ReflectionFunctionAbstract $reflector, ?array $positions = null, bool &$cacheable = true): array
    {
        $cacheable = true;
        if ($positions === []) {
            return [];
        }
        $attributes = $reflector->getAttributes();
        if ($positions !== null) {
            $selected = [];
            foreach ($positions as $position) {
                if (!isset($attributes[$position]) || !self::supports($attributes[$position])) {
                    $positions = null;
                    break;
                }
                $selected[] = $attributes[$position];
            }
            if ($positions !== null) {
                return $selected;
            }
        }
        $selected = [];
        foreach ($attributes as $attribute) {
            if (self::supports($attribute)) {
                $selected[] = $attribute;
            } elseif (!self::isLoaded($attribute)) {
                $cacheable = false;
            }
        }
        return $selected;
    }

    /** @phpstan-assert-if-true array<string, list<int>> $map */
    public static function validMap(mixed $map): bool
    {
        if (!is_array($map)) {
            return false;
        }
        foreach ($map as $signature => $positions) {
            if (!is_string($signature) || trim($signature) === '' || !is_array($positions) || !array_is_list($positions)) {
                return false;
            }
            $previous = -1;
            foreach ($positions as $position) {
                if (!is_int($position) || $position <= $previous) {
                    return false;
                }
                $previous = $position;
            }
        }
        return true;
    }

    /** @param ReflectionAttribute<object> $attribute */
    private static function isLoaded(ReflectionAttribute $attribute): bool
    {
        $name = $attribute->getName();
        return class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false);
    }

    /** @param ReflectionAttribute<object> $attribute */
    private static function supports(ReflectionAttribute $attribute): bool
    {
        return is_a($attribute->getName(), Intercept::class, true)
            || is_a($attribute->getName(), InterceptorInterface::class, true);
    }
}
