<?php

declare(strict_types=1);
use Componenta\Config\ConfigFactory;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ConfigKey;
use Componenta\Interceptor\ConfigProvider;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\PipelineInterface;

final class HttpFactoryDependency
{
    public string $value = 'resolved';
}
final class HttpFactoryInterceptor implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $context->parameters[0]->value . ':' . $handler->handle($context);
    }
}
function httpFactoryContainer(mixed $interceptors): \Componenta\Config\ContainerValue
{
    $composition = (new ConfigFactory())->create(
        new Environment([]),
        new ConfigProvider(),
        static fn (): array => [ConfigKey::HTTP_INTERCEPTORS => $interceptors]
    );
    return (new ContainerFactory())->create($composition->config, $composition->dependencies);
}
it('resolves callable parameters before configured HTTP interceptors execute', function (): void {
    $pipeline = httpFactoryContainer([HttpFactoryInterceptor::class])->get(PipelineInterface::class);
    expect($pipeline->call(static fn (HttpFactoryDependency $dependency): string => $dependency->value))->toBe('resolved:resolved');
});
it('reports malformed interceptor configuration before executing a callable', function (): void {
    expect(fn () => httpFactoryContainer('wrong')->get(PipelineInterface::class))->toThrow(\Componenta\DI\Exception\ResolutionException::class, ConfigKey::HTTP_INTERCEPTORS);
});

it('builds configured interceptors once in list order and exposes the callable contract', function (): void {
    $outer = \Componenta\Interceptor\CallbackInterceptorFactory::after(static fn (string $value): string => 'outer:' . $value);
    $inner = \Componenta\Interceptor\CallbackInterceptorFactory::after(static fn (string $value): string => 'inner:' . $value);
    $container = httpFactoryContainer([$outer, $inner]);
    $pipeline = $container->get(PipelineInterface::class, PipelineInterface::class);
    $factory = $container->get(\Componenta\Interceptor\CallableContextFactoryInterface::class, \Componenta\Interceptor\CallableContextFactoryInterface::class);

    expect($pipeline)->toBeInstanceOf(\Componenta\DI\CallableExecutorInterface::class)
        ->and($pipeline->call(static fn (): string => 'value'))->toBe('outer:inner:value')
        ->and($pipeline->handle($factory->createContext(static fn (): string => 'next')))->toBe('outer:inner:next');
});

it('rejects associative and sparse interceptor configuration', function (array $interceptors): void {
    expect(fn () => httpFactoryContainer($interceptors)->get(PipelineInterface::class))
        ->toThrow(\Componenta\DI\Exception\ResolutionException::class, ConfigKey::HTTP_INTERCEPTORS);
})->with([
    'associative' => [['outer' => HttpFactoryInterceptor::class]],
    'sparse' => [[2 => HttpFactoryInterceptor::class]],
]);


it('rejects a configured item that does not implement the interceptor contract', function (mixed $entry): void {
    $container = httpFactoryContainer([$entry]);

    expect(fn () => $container->get(PipelineInterface::class))
        ->toThrow(\Componenta\DI\Exception\ResolutionException::class, InterceptorInterface::class);
})->with([
    'object' => [new stdClass()],
    'integer' => [42],
    'non-interceptor service' => [HttpFactoryDependency::class],
]);
