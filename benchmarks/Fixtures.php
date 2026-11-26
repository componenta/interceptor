<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Benchmarks;

use Attribute;
use Componenta\Interceptor\Attribute\After;
use Componenta\Interceptor\Attribute\Intercept;
use Componenta\Interceptor\Attribute\InterceptCallback;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use LogicException;

final class ForwardInterceptor implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $handler->handle($context);
    }
}

#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class ForwardAttribute implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $handler->handle($context);
    }
}

#[Attribute(Attribute::TARGET_METHOD)]
final class CachedResult implements InterceptorInterface
{
    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return 7;
    }
}

final class BenchDependency {}

final class BenchTarget
{
    public function plain(int $value): int
    {
        return $value;
    }

    #[ForwardAttribute]
    public function nativeAttribute(int $value): int
    {
        return $value;
    }

    #[Intercept(ForwardInterceptor::class)]
    public function factoryAttribute(int $value): int
    {
        return $value;
    }

    #[After(static function (mixed $result): mixed { return $result; })]
    public function afterAttribute(int $value): int
    {
        return $value;
    }

    #[InterceptCallback(static function (CallableContextInterface $context, ContextHandlerInterface $next): mixed {
        return $next->handle($context);
    })]
    public function callbackAttribute(int $value): int
    {
        return $value;
    }

    #[ForwardAttribute]
    #[ForwardAttribute]
    #[ForwardAttribute]
    #[ForwardAttribute]
    #[ForwardAttribute]
    public function fiveAttributes(int $value): int
    {
        return $value;
    }

    #[CachedResult]
    #[Intercept(ForwardInterceptor::class)]
    public function shortCircuit(int $value): never
    {
        throw new LogicException('Short-circuited benchmark must not call its target.');
    }

    public function dependency(BenchDependency $dependency, int $value): int
    {
        return $value;
    }

    public function variadic(int $value, int ...$rest): int
    {
        return $value + array_sum($rest);
    }

    /** @param array<string|int, int> $arguments */
    public function __call(string $name, array $arguments): int
    {
        if ($name !== 'dynamic') {
            throw new LogicException('Unexpected dynamic target.');
        }

        return array_sum($arguments);
    }
}
