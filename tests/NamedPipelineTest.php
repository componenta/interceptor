<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\InterceptingExecutor;

it('preserves named variadic interceptor order when creating and extending pipelines', function (): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        outer: CallbackInterceptorFactory::after(static fn ($value) => 'outer:' . $value),
    );
    expect($pipeline->call(static fn () => 'value'))->toBe('outer:value');
    $extended = $pipeline->pipe(inner: CallbackInterceptorFactory::after(static fn ($value) => 'inner:' . $value));
    expect($extended->call(static fn () => 'value'))->toBe('outer:inner:value')
        ->and($pipeline->call(static fn () => 'value'))->toBe('outer:value');
});
