<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\InterceptingExecutor;

final class LateLoadedInterceptionTarget
{
    #[RuntimeLoadedInterceptorAttribute]
    public function run(): string
    {
        return 'value';
    }
}

it('recognizes a late loaded attribute on a pipeline that already executed the method', function (): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $create = static fn (): InterceptingExecutor => new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new AttributeInterceptor($container->get(FactoryInterface::class)),
    );
    $pipeline = $create();
    $callable = [new LateLoadedInterceptionTarget(), 'run'];
    expect($pipeline->call($callable))->toBe('value');

    $loader = static function (string $class): void {
        if ($class === 'RuntimeLoadedInterceptorAttribute') {
            require __DIR__ . '/Fixture/RuntimeLoadedInterceptorAttribute.php';
        }
    };
    spl_autoload_register($loader);
    try {
        expect($pipeline->call($callable))->toBe('wrapped:value')
            ->and($pipeline->call($callable))->toBe('wrapped:value')
            ->and($create()->call($callable))->toBe('wrapped:value');
    } finally {
        spl_autoload_unregister($loader);
    }
});

#[Attribute(Attribute::TARGET_METHOD)]
final class DeferredInterceptAliasTargetAttribute extends \Componenta\Interceptor\Attribute\Intercept
{
    public function __construct()
    {
        parent::__construct(DeferredInterceptAliasInterceptor::class);
    }
}

final class DeferredInterceptAliasInterceptor implements \Componenta\Interceptor\InterceptorInterface
{
    public function intercept(
        \Componenta\Interceptor\CallableContextInterface $context,
        \Componenta\Interceptor\ContextHandlerInterface $handler,
    ): mixed {
        return 'deferred:' . $handler->handle($context);
    }
}

final class DeferredInterceptAliasTarget
{
    #[DeferredInterceptAlias]
    public function run(): string
    {
        return 'value';
    }
}

it('rechecks an Intercept subclass that becomes available on a later discovery attempt', function (): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new AttributeInterceptor($container->get(FactoryInterface::class)),
    );
    $attempts = 0;
    $loader = static function (string $name) use (&$attempts): void {
        if ($name === 'DeferredInterceptAlias' && ++$attempts === 2) {
            class_alias(DeferredInterceptAliasTargetAttribute::class, $name);
        }
    };
    spl_autoload_register($loader);
    try {
        $target = new DeferredInterceptAliasTarget();
        expect($pipeline->call([$target, 'run']))->toBe('value')
            ->and($pipeline->call([$target, 'run']))->toBe('deferred:value')
            ->and($pipeline->call([$target, 'run']))->toBe('deferred:value');
    } finally {
        spl_autoload_unregister($loader);
    }
});
