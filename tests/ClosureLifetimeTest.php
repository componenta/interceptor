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
use Componenta\Interceptor\Tests\Fixture\Attribute\WrapResultAttribute;

it('releases objects captured by intercepted closures after invocation', function (string $representation): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new AttributeInterceptor($container->get(FactoryInterface::class))
    );
    $value = (object) ['message' => 'value'];
    $reference = WeakReference::create($value);
    $callable = #[WrapResultAttribute('intercepted')] static fn (): string => $value->message;
    $context = new CallableContext(
        $representation === 'closure' ? $callable : [$callable, '__invoke'],
    );
    expect($pipeline->handle($context))->toBe('intercepted:value')
        ->and($pipeline->handle($context))->toBe('intercepted:value');
    unset($value, $callable, $context);
    gc_collect_cycles();
    expect($reference->get())->toBeNull();
})->with(['closure', 'method']);
