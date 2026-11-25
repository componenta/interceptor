<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Closure;
use Componenta\DI\Resolver\Parameter\ParametersResolverInterface;
use ReflectionParameter;

/** A test substitute for the public DI parameter-resolution service. */
final readonly class CallbackParametersResolver implements ParametersResolverInterface
{
    /** @param Closure(list<ReflectionParameter>, array<string|int, mixed>): array<string|int, mixed> $callback */
    public function __construct(private Closure $callback) {}

    /**
     * @param list<ReflectionParameter> $parameters
     * @param array<string|int, mixed> $providedParameters
     * @return array<string|int, mixed>
     */
    public function resolve(array $parameters, array $providedParameters = []): array
    {
        return ($this->callback)($parameters, $providedParameters);
    }
}
