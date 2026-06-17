<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use ReflectionParameter;

/**
 * Test resolver that returns "resolved-{name}" for any parameter and counts its invocations.
 */
final class SpyParameterResolver implements ParameterResolverInterface
{
    public int $callCount = 0;

    public function resolveParameter(
        ReflectionParameter $parameter,
        array $providedParameters = [],
        array $resolvedParameters = [],
    ): ?array {
        $this->callCount++;

        return [$parameter->getPosition(), 'resolved-' . $parameter->getName()];
    }
}
