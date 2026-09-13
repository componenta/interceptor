<?php

declare(strict_types=1);

use Componenta\DI\ContainerFactory;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\FactoryInterface;
use Componenta\Config\Config;
use Componenta\Config\Environment;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\InterceptorInterface;

#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION)]
final class InvocationCounter implements InterceptorInterface
{
    private int $calls = 0;
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return ++$this->calls . ':' . $handler->handle($context);
    }
}
final class InvocationCounterTarget
{
    #[InvocationCounter]
    public function run(): string
    {
        return 'result';
    }
}

it('gives each attributed invocation its own attribute state', function (): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new \Componenta\Config\DependencyDefinitions([]));
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new AttributeInterceptor($container->get(FactoryInterface::class)),
    );
    $target = new InvocationCounterTarget();
    expect($pipeline->call([$target, 'run']))->toBe('1:result')
        ->and($pipeline->call([$target, 'run']))->toBe('1:result');
});
