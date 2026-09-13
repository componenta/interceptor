<?php

declare(strict_types=1);

use Componenta\Config\Config;
use Componenta\Config\DependencyDefinitions;
use Componenta\Config\Environment;
use Componenta\DI\CallableExecutorInterface;
use Componenta\DI\ContainerFactory;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\InterceptingExecutor;

function terminalInvocationPipeline(): InterceptingExecutor
{
    $container = (new ContainerFactory())->create(new Config([], new Environment([])), new DependencyDefinitions([]));
    return new InterceptingExecutor($container->get(CallableExecutorInterface::class));
}

it('invokes a zero-argument callable from its public context', function (): void {
    $context = new CallableContext(static fn (): string => 'ok');

    expect(terminalInvocationPipeline()->handle($context))->toBe('ok');
});

it('passes positional arguments from the public context to the callable', function (): void {
    $context = new CallableContext(
        static fn (string $left, string $right): string => "{$left}:{$right}",
        ['left', 'right'],
    );

    expect(terminalInvocationPipeline()->handle($context))->toBe('left:right');
});

it('preserves named argument binding and omitted PHP defaults through the terminal', function (array $arguments, string $expected): void {
    $context = new CallableContext(
        static fn (string $left = 'default', string $right = 'default'): string => "{$left}:{$right}",
        $arguments,
    );

    expect(terminalInvocationPipeline()->handle($context))->toBe($expected);
})->with([
    'reverse key order' => [['right' => 'right', 'left' => 'left'], 'left:right'],
    'omitted first parameter' => [['right' => 'right'], 'default:right'],
]);
