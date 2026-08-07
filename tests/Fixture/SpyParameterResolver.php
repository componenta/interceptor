<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\DI\Resolver\Parameter\ParameterResolutionContext;
use Componenta\DI\Resolver\Parameter\ParameterResolverInterface;
use Componenta\DI\Resolver\Target\ParameterTarget;

/**
 * Test resolver that returns "resolved-{name}" for any parameter and counts its invocations.
 */
final class SpyParameterResolver implements ParameterResolverInterface
{
    public int $callCount = 0;

    public function supports(ParameterTarget $target): bool
    {
        return true;
    }

    public function resolveParameter(
        ParameterTarget $target,
        ParameterResolutionContext $context,
    ): ?array {
        $this->callCount++;

        return [$target->position, 'resolved-' . $target->name];
    }
}
