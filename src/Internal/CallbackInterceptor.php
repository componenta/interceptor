<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Internal;

use Closure;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;

/**
 * Interceptor implementation that delegates to a callback.
 *
 * Allows creating interceptors without dedicated classes.
 *
 * @internal Created through the public callback factory.
 */
final readonly class CallbackInterceptor implements InterceptorInterface
{
    private Closure $callback;

    /**
     * @param callable(CallableContextInterface, ContextHandlerInterface): mixed $callback
     */
    public function __construct(callable|string|array $callback)
    {
        $this->callback = Closure::fromCallable(NativeCallable::capture($callback));
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return ($this->callback)($context, $handler);
    }
}