<?php

declare(strict_types=1);

use Componenta\DI\CallableExecutorInterface;
use Componenta\Interceptor\CallableContext;
use Componenta\Interceptor\ContextHandler;

final class ContextHandlerCountingExecutor implements CallableExecutorInterface
{
    public int $calls = 0;

    public function resolve(mixed $callable): callable
    {
        return $callable;
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        $this->calls++;

        return $callable(...array_values($params));
    }
}

describe('ContextHandler', function () {
    it('delegates zero-argument callables to the executor', function () {
        $executor = new ContextHandlerCountingExecutor();
        $handler = new ContextHandler($executor);

        $result = $handler->handle(new CallableContext(static fn (): string => 'ok'));

        expect($result)->toBe('ok')
            ->and($executor->calls)->toBe(1);
    });

    it('delegates callables with positional parameters to the executor', function () {
        $executor = new ContextHandlerCountingExecutor();
        $handler = new ContextHandler($executor);

        $result = $handler->handle(new CallableContext(
            static fn (string $left, string $right): string => "{$left}:{$right}",
            [0 => 'left', 1 => 'right'],
        ));

        expect($result)->toBe('left:right')
            ->and($executor->calls)->toBe(1);
    });

    it('keeps named parameter sets on the executor path', function () {
        $executor = new ContextHandlerCountingExecutor();
        $handler = new ContextHandler($executor);

        $result = $handler->handle(new CallableContext(
            static fn (string $left, string $right): string => "{$left}:{$right}",
            ['left' => 'left', 'right' => 'right'],
        ));

        expect($result)->toBe('left:right')
            ->and($executor->calls)->toBe(1);
    });
});
