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
            $name = $attribute->getName();
            // Discover the declaration once. Two separate autoloading is_a()
            // probes can load an Intercept subclass between the checks.
            if (!class_exists($name) && !interface_exists($name, false) && !trait_exists($name, false)) {
                $cacheable = false;
                continue;
            }
            if (is_a($name, Intercept::class, true) || is_a($name, InterceptorInterface::class, true)) {
                $selected[] = $attribute;
            }
        }
        return $selected;
    }

}
