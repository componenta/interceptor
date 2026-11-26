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
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\Tests\Fixture\DelegatingContext;

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
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class NativePolicy implements InterceptorInterface
{
    public function __construct(private string $name) {}

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $this->name . ':' . $handler->handle($context);
    }
}
final class NativeAttributeTarget
{
    #[NativePolicy('authorization')]
    #[NativePolicy('cache')]
    public function policies(): string
    {
        return 'value';
    }

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
function nativeAttributePipeline(): InterceptingExecutor
{
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    return new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new AttributeInterceptor($container->get(FactoryInterface::class)),
    );
}
it('preserves fresh factory and nested attribute argument state on repeated calls', function (string $method): void {
    $pipeline = nativeAttributePipeline();
    expect($pipeline->call([new NativeAttributeTarget(), $method]))->toBe('1:value')
        ->and($pipeline->call([new NativeAttributeTarget(), $method]))->toBe('1:value');
})->with(['factory', 'nested']);

it('retains native attribute target and repeatability errors', function (string $method): void {
    $pipeline = nativeAttributePipeline();
    expect(fn () => $pipeline->call([new NativeAttributeTarget(), $method]))->toThrow(Error::class);
})->with(['wrongTarget', 'repeated']);

it('propagates a failing attribute constructor once per call with cold and cached metadata', function (): void {
    ThrowingNativeAttribute::$constructions = 0;
    $pipeline = nativeAttributePipeline();
    expect(fn () => $pipeline->call([new NativeAttributeTarget(), 'failure']))
        ->toThrow(RuntimeException::class, 'native constructor failed');
    expect(ThrowingNativeAttribute::$constructions)->toBe(1);

    expect(fn () => $pipeline->call([new NativeAttributeTarget(), 'failure']))
        ->toThrow(RuntimeException::class, 'native constructor failed');
    expect(ThrowingNativeAttribute::$constructions)->toBe(2);
});

it('preserves every declared policy in declaration order on cold and warm calls', function (): void {
    $pipeline = nativeAttributePipeline();
    $target = new NativeAttributeTarget();

    expect($pipeline->call([$target, 'policies']))->toBe('authorization:cache:value')
        ->and($pipeline->call([$target, 'policies']))->toBe('authorization:cache:value');
});

it('keeps metadata supplied by a custom reflector local to its context', function (bool $customFirst): void {
    $pipeline = nativeAttributePipeline();
    $native = new CallableContext([new NativeAttributeTarget(), 'nested']);
    $custom = new class ($native) extends DelegatingContext {
        public ?ReflectionFunctionAbstract $reflector {
            get => new class (NativeAttributeTarget::class, 'nested') extends ReflectionMethod {
                public function getAttributes(?string $name = null, int $flags = 0): array
                {
                    return [];
                }
            };
        }
    };

    $calls = [[$custom, 'value'], [$native, '1:value']];
    if (!$customFirst) {
        $calls = array_reverse($calls);
    }
    foreach ($calls as [$context, $expected]) {
        expect($pipeline->handle($context))->toBe($expected);
    }
})->with(['custom first' => true, 'native first' => false]);

it('retains declaration errors on subsequent attempts', function (string $method): void {
    $pipeline = nativeAttributePipeline();
    $target = new NativeAttributeTarget();

    expect(fn () => $pipeline->call([$target, $method]))->toThrow(Error::class);
    expect(fn () => $pipeline->call([$target, $method]))->toThrow(Error::class);
})->with(['wrongTarget', 'repeated']);

#[Attribute(Attribute::TARGET_METHOD)]
final class NativeDeny implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return 'denied';
    }
}

final class NativeForbiddenTarget
{
    #[NativeDeny]
    #[ThrowingNativeAttribute]
    public function direct(): never
    {
        throw new LogicException('The target must not be reached.');
    }

    #[NativeDeny]
    #[Intercept(FactoryStateInterceptor::class)]
    public function factory(): never
    {
        throw new LogicException('The target must not be reached.');
    }
}

it('does not construct native attributes after an outer attribute short-circuits', function (): void {
    ThrowingNativeAttribute::$constructions = 0;
    $pipeline = nativeAttributePipeline();

    expect($pipeline->call([new NativeForbiddenTarget(), 'direct']))->toBe('denied')
        ->and(ThrowingNativeAttribute::$constructions)->toBe(0);
});

it('does not invoke the factory for an attribute that is not reached', function (): void {
    $factory = new \Componenta\Interceptor\Tests\Fixture\FactoryStub();
    $pipeline = new InterceptingExecutor(
        new \Componenta\Interceptor\Tests\Fixture\PassThroughExecutor(),
        new AttributeInterceptor($factory),
    );

    expect($pipeline->call([new NativeForbiddenTarget(), 'factory']))->toBe('denied')
        ->and($factory->calls)->toBe([]);
});
