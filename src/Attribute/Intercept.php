<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Attribute;

use Attribute;
use Componenta\Interceptor\InterceptorInterface;

/**
 * Declares an interceptor to be applied to a callable.
 *
 * Can be used directly or extended by custom attributes.
 *
 * Subclasses may implement {@see \Componenta\Scope\ScopedInterface}
 * to restrict scope at the attribute level. Attribute-level scope
 * takes priority over interceptor-level scope.
 *
 * @param class-string<InterceptorInterface> $interceptor
 * @param array<string, mixed> $params Constructor parameters.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
class Intercept
{
    public function __construct(
        public readonly string $interceptor,
        public readonly array $params = [],
    ) {}
}
