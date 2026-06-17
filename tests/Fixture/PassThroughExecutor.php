<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\DI\CallableExecutorInterface;

final class PassThroughExecutor implements CallableExecutorInterface
{
    public function resolve(mixed $callable): callable
    {
        return $callable;
    }

    public function call(mixed $callable, array $params = []): mixed
    {
        return $callable(...array_values($params));
    }
}
