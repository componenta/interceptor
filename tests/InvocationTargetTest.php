<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\Container;
use Componenta\DI\ContainerFactory;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\InterceptingExecutor;

function interceptorNativeFunction(string $value = 'default'): string
{
    return 'native:' . $value;
}

class InterceptorStaticTarget
{
    public static function run(string $value = 'default'): string
    {
        return static::class . ':' . $value;
    }

    public static function __callStatic(string $name, array $arguments): array
    {
        return [static::class, $name, $arguments];
    }
}

final class InterceptorStaticChild extends InterceptorStaticTarget {}

it('invokes the selected native callable instead of reinterpreting it as a service ID', function (string $target, mixed $expected): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $di = $container->container;
    expect($di)->toBeInstanceOf(Container::class);
    $di->set('interceptor.test.route', $target);
    $di->set($target, static fn () => 'wrong service');

    $seen = [];
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class, CallableExecutorInterface::class),
        CallbackInterceptorFactory::before(
            static function (CallableContextInterface $context) use (&$seen): void {
                $seen[] = $context->getCallable();
            },
        ),
    );

    expect($pipeline->call('interceptor.test.route', ['value' => 'chosen']))->toBe($expected)
        ->and($pipeline->handle(new CallableContext($target, ['value' => 'chosen'])))->toBe($expected)
        ->and($seen)->toBe([$target, $target]);
})->with([
    'named function' => ['interceptorNativeFunction', 'native:chosen'],
    'static method' => [InterceptorStaticChild::class . '::run', InterceptorStaticChild::class . ':chosen'],
]);

it('preserves named arguments and the called class in native magic static dispatch', function (): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $pipeline = new InterceptingExecutor($container->get(CallableExecutorInterface::class, CallableExecutorInterface::class));

    expect($pipeline->handle(new CallableContext(InterceptorStaticChild::class . '::virtual', ['named' => 'value'])))
        ->toBe([InterceptorStaticChild::class, 'virtual', ['named' => 'value']]);
});
