<?php

declare(strict_types=1);
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\ParameterResolvingInterceptor;

final class ParameterResolutionDependency
{
}
function parameterResolutionPipeline(): InterceptingExecutor
{
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    return new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new ParameterResolvingInterceptor($container->get(ParametersResolver::class))
    );
}
it('preserves calls without parameters', function (): void {
    expect(parameterResolutionPipeline()->call(static fn (): string => 'value'))->toBe('value');
});
it('resolves missing dependencies while retaining explicit arguments and PHP defaults', function (): void {
    $explicit = new ParameterResolutionDependency();
    $callable = static fn (ParameterResolutionDependency $dependency, string $label = 'default'): array => [$dependency, $label];
    $pipeline = parameterResolutionPipeline();
    expect($pipeline->call($callable, ['dependency' => $explicit]))->toBe([$explicit, 'default']);
    expect($pipeline->call($callable)[0])->toBeInstanceOf(ParameterResolutionDependency::class);
});
