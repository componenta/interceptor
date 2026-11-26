<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Attribute;

use Attribute;
use Closure;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;

/** Transforms a successful continuation result through a native callback. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class After implements InterceptorInterface
{
    private InterceptorInterface $interceptor;

    /**
     * Calls $callback($result, $context) after a successful continuation.
     * Its return value replaces the result. The first parameter may declare
     * the target's result type, which PHP validates at invocation.
     *
     * @param Closure $callback
     */
    public function __construct(Closure $callback)
    {
        $this->interceptor = CallbackInterceptorFactory::after($callback);
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $this->interceptor->intercept($context, $handler);
    }
}
