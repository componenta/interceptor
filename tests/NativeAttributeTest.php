<?php

declare(strict_types=1);
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\ContainerFactory;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\FactoryInterface;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\InterceptorInterface;

final class NativeValue
{
    public int $calls = 0;
}
final class FactoryStateInterceptor implements InterceptorInterface
{
    private int $calls = 0;
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return ++$this->calls . ':' . $handler->handle($context);
    }
}
#[Attribute(Attribute::TARGET_METHOD)]
final class NestedStateAttribute implements InterceptorInterface
{
    public function __construct(private NativeValue $value)
    {
    }
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return ++$this->value->calls . ':' . $handler->handle($context);
    }
}
#[Attribute(Attribute::TARGET_CLASS)]
final class ClassOnlyInterceptor implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $handler->handle($context);
    }
}
#[Attribute(Attribute::TARGET_METHOD)]
final class ThrowingNativeAttribute implements InterceptorInterface
{
    public static int $constructions = 0;
    public function __construct()
    {
        ++self::$constructions;
        throw new RuntimeException('native constructor failed');
    }
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $handler->handle($context);
    }
}
final class NativeAttributeTarget
{
    #[Intercept(FactoryStateInterceptor::class)]
    public function factory(): string
    {
        return 'value';
    }
    #[NestedStateAttribute(new NativeValue())]
    public function nested(): string
    {
        return 'value';
    }
    #[ClassOnlyInterceptor]
    public function wrongTarget(): string
    {
        return 'value';
    }
    #[NestedStateAttribute(new NativeValue())]
    #[NestedStateAttribute(new NativeValue())]
    public function repeated(): string
    {
        return 'value';
    }
    #[ThrowingNativeAttribute]
    public function failure(): string
    {
        return 'value';
    }
}
function nativeAttributePipeline(array $map): InterceptingExecutor
{
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    return new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new AttributeInterceptor($container->get(FactoryInterface::class), $map),
    );
}
it('preserves fresh factory and nested attribute argument state with source or prepared metadata', function (bool $prepared, string $method): void {
    $pipeline = nativeAttributePipeline($prepared ? [NativeAttributeTarget::class . '::' . $method => [0]] : []);
    expect($pipeline->call([new NativeAttributeTarget(), $method]))->toBe('1:value')
        ->and($pipeline->call([new NativeAttributeTarget(), $method]))->toBe('1:value');
})->with([false, true])->with(['factory', 'nested']);

it('retains native attribute target and repeatability errors with prepared metadata', function (bool $prepared, string $method): void {
    $pipeline = nativeAttributePipeline($prepared ? [NativeAttributeTarget::class . '::' . $method => $method === 'repeated' ? [0,1] : [0]] : []);
    expect(fn () => $pipeline->call([new NativeAttributeTarget(), $method]))->toThrow(Error::class);
})->with([false, true])->with(['wrongTarget', 'repeated']);

it('does not retry a failing attribute constructor when metadata is prepared', function (): void {
    ThrowingNativeAttribute::$constructions = 0;
    $pipeline = nativeAttributePipeline([NativeAttributeTarget::class . '::failure' => [0]]);
    expect(fn () => $pipeline->call([new NativeAttributeTarget(), 'failure']))
        ->toThrow(RuntimeException::class, 'native constructor failed');
    expect(ThrowingNativeAttribute::$constructions)->toBe(1);
});

it('falls back from malformed or out of range prepared metadata before materialization', function (array $map): void {
    $pipeline = nativeAttributePipeline($map);
    expect($pipeline->call([new NativeAttributeTarget(), 'factory']))->toBe('1:value');
})->with([
    'bad shape' => [[NativeAttributeTarget::class . '::factory' => ['wrong']]],
    'missing position' => [[NativeAttributeTarget::class . '::factory' => [50]]],
]);
