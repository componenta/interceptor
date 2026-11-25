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

    /**
     * @param bool $cacheable False when an unavailable attribute class may become an interceptor later.
     * @return list<ReflectionAttribute<object>>
     */
    public static function resolve(ReflectionFunctionAbstract $reflector, bool &$cacheable): array
    {
        $cacheable = true;
        $selected = [];
        $pending = [];

        foreach ($reflector->getAttributes() as $position => $attribute) {
            $name = $attribute->getName();
            if (!class_exists($name) && !self::isAvailable($name)) {
                $pending[$position] = $attribute;
                continue;
            }
            if (self::isInterceptor($name)) {
                $selected[$position] = $attribute;
            }
        }

        // A later autoloader can make an earlier declaration available.
        // Recheck only unresolved symbols, without executing more autoloaders.
        if ($pending !== []) {
            foreach ($pending as $position => $attribute) {
                $name = $attribute->getName();
                if (!self::isAvailable($name)) {
                    $cacheable = false;
                    continue;
                }
                if (self::isInterceptor($name)) {
                    $selected[$position] = $attribute;
                }
            }
            ksort($selected);
        }

        return array_values($selected);
    }

    private static function isAvailable(string $name): bool
    {
        return class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false);
    }

    private static function isInterceptor(string $name): bool
    {
        return is_a($name, Intercept::class, true) || is_a($name, InterceptorInterface::class, true);
    }
}
