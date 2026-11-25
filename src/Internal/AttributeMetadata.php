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
        $attributes = $reflector->getAttributes();
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
