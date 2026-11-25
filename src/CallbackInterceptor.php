<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Closure;
use Componenta\Interceptor\Internal\NativeCallable;

/**
 * Interceptor implementation that delegates to a callback.
 *
 * Allows creating interceptors without dedicated classes.
 *
 * @internal Use {@see CallbackInterceptorFactory} to create instances.
 */
final class CallbackInterceptor implements InterceptorInterface
{
    /**
     * @var callable(CallableContextInterface, ContextHandlerInterface): mixed
     */
    private readonly Closure $callback;

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