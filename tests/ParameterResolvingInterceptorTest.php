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
        new ParameterResolvingInterceptor($container->get(ParametersResolver::class, ParametersResolver::class)->resolve(...))
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


it('accepts an independent argument resolution operation and uses its native result', function (): void {
    $calls = [];
    $pipeline = new InterceptingExecutor(
        new \Componenta\Interceptor\Tests\Fixture\PassThroughExecutor(),
        new ParameterResolvingInterceptor(static function (array $parameters, array $provided) use (&$calls): array {
            $calls[] = [array_map(static fn (ReflectionParameter $parameter): string => $parameter->getName(), $parameters), $provided];
            return ['value' => 'resolved:' . $provided['value']];
        }),
    );

    expect($pipeline->call(static fn (string $value): string => $value, ['value' => 'input']))->toBe('resolved:input')
        ->and($calls)->toBe([[['value'], ['value' => 'input']]]);
});

it('normalizes foreign preparation failures without invoking the target', function (): void {
    $failure = new DomainException('resolution failed');
    $called = false;
    $pipeline = new InterceptingExecutor(
        new \Componenta\Interceptor\Tests\Fixture\PassThroughExecutor(),
        new ParameterResolvingInterceptor(static fn (array $parameters, array $provided): never => throw $failure),
    );

    $caught = null;
    try {
        $pipeline->call(static function (string $value) use (&$called): string {
            $called = true;
            return $value;
        });
    } catch (\Componenta\DI\Exception\InvalidCallableException $error) {
        $caught = $error;
    }

    expect($caught)->toBeInstanceOf(\Componenta\DI\Exception\InvalidCallableException::class)
        ->and($caught?->getPrevious())->toBe($failure)
        ->and($called)->toBeFalse();
});

it('propagates DI resolution failures and downstream application failures unchanged', function (bool $duringResolution): void {
    $failure = $duringResolution
        ? new \Componenta\DI\Exception\ResolutionException('DI failure')
        : new DomainException('target failure');
    $pipeline = new InterceptingExecutor(
        new \Componenta\Interceptor\Tests\Fixture\PassThroughExecutor(),
        new ParameterResolvingInterceptor(
            static fn (array $parameters, array $provided): array => $duringResolution ? throw $failure : ['value' => 'ready'],
        ),
    );

    $caught = null;
    try {
        $pipeline->call(static fn (string $value): never => throw $failure);
    } catch (Throwable $error) {
        $caught = $error;
    }

    expect($caught)->toBe($failure);
})->with(['DI preparation' => [true], 'application invocation' => [false]]);

it('does not run a parameter resolution operation for prepared or native magic calls', function (): void {
    $pipeline = new InterceptingExecutor(
        new \Componenta\Interceptor\Tests\Fixture\PassThroughExecutor(),
        new ParameterResolvingInterceptor(static fn (): never => throw new LogicException('Unexpected resolution.')),
    );
    $target = new class {
        public function __call(string $name, array $arguments): array
        {
            return [$name, $arguments];
        }
    };

    expect($pipeline->call(new \Componenta\DI\PreparedCallable(static fn (int $value): int => $value), [7]))->toBe(7)
        ->and($pipeline->call([$target, 'virtual'], ['named' => 7]))->toBe(['virtual', ['named' => 7]]);
});
