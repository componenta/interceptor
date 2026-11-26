<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Attribute;

use Attribute;
use Closure;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\CallbackInterceptorFactory;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;

/** Gives a native callback control over the current continuation. */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class InterceptCallback implements InterceptorInterface
{
    private InterceptorInterface $interceptor;

    /** @param Closure(CallableContextInterface, ContextHandlerInterface): mixed $callback */
    public function __construct(Closure $callback)
    {
        $this->interceptor = CallbackInterceptorFactory::create($callback);
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $this->interceptor->intercept($context, $handler);
    }
}
