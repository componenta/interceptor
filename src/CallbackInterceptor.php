<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

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
    private $callback;

    /**
     * @param callable(CallableContextInterface, ContextHandlerInterface): mixed $callback
     */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return ($this->callback)($context, $handler);
    }
}