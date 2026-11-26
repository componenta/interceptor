<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\StaticAnalysis;

use Componenta\Interceptor\Attribute\After;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\InterceptorInterface;

/** Public consumer: the callback may declare the actual target result type. */
final class TypedResultCallbackConsumer
{
    #[After(self::increment(...))]
    public function value(): int
    {
        return 7;
    }

    public static function increment(int $result, CallableContextInterface $context): int
    {
        return $result + 1;
    }

    /** @return list<InterceptorInterface> */
    public function interceptors(): array
    {
        return [
            new After(self::increment(...)),
            CallbackInterceptorFactory::after(self::increment(...)),
            CallbackInterceptorFactory::around(
                static fn (CallableContextInterface $context): CallableContextInterface => $context,
                self::increment(...),
            ),
        ];
    }
}
