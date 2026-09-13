<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\ClosureInvocation;

use Attribute;
use Closure;
use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\DI\FactoryInterface;
use Componenta\DI\Resolver\Parameter\ParametersResolver;
use Componenta\Interceptor\AttributeInterceptor;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptingExecutor;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Interceptor\ParameterResolvingInterceptor;
use ReflectionMethod;

#[Attribute(Attribute::TARGET_FUNCTION | Attribute::TARGET_METHOD)]
final readonly class Envelope implements InterceptorInterface
{
    public function __construct(private string $label)
    {
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $this->label . ':' . $handler->handle($context);
    }
}

test('keeps interceptor attributes specific to each closure representation', function (string $representation): void {
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    $pipeline = new InterceptingExecutor(
        $container->get(CallableExecutorInterface::class),
        new ParameterResolvingInterceptor($container->get(ParametersResolver::class)),
        new AttributeInterceptor($container->get(FactoryInterface::class)),
    );
    $first = #[Envelope('first')] static fn (string $value): string => $value;
    $second = #[Envelope('second')] static fn (string $value): string => $value;
    $context = static fn (Closure $closure, string $value): CallableContext => new CallableContext(
        $representation === 'closure' ? $closure : [$closure, '__invoke'],
        ['value' => $value],
        reflector: $representation === 'pre-reflected' ? new ReflectionMethod($closure, '__invoke') : null,
    );

    expect($pipeline->handle($context($first, 'A')))->toBe('first:A')
        ->and($pipeline->handle($context($second, 'B')))->toBe('second:B')
        ->and($pipeline->handle($context($first, 'C')))->toBe('first:C');
})->with(['closure', 'method', 'pre-reflected']);
