<?php

declare(strict_types=1);
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\InterceptingExecutor;

it('releases objects captured by intercepted closures after invocation', function (string $representation): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new AttributeInterceptor($container->get(FactoryInterface::class))
    );
    $value = (object) ['message' => 'value'];
    $reference = WeakReference::create($value);
    $callable = static fn (): string => $value->message;
    $context = new CallableContext(
        $representation === 'closure' ? $callable : [$callable, '__invoke'],
        reflector: $representation === 'pre-reflected' ? new ReflectionMethod($callable, '__invoke') : null,
    );
    expect($pipeline->handle($context))->toBe('value');
    unset($value, $callable, $context);
    gc_collect_cycles();
    expect($reference->get())->toBeNull();
})->with(['closure', 'method', 'pre-reflected']);
