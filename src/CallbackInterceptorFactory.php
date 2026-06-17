<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

/**
 * Factory for creating interceptors from callbacks.
 *
 * Provides convenient methods for common interception patterns
 * without requiring dedicated interceptor classes.
 *
 * @example
 * ```php
 * // Full control interceptor
 * $logging = CallbackInterceptorFactory::create(
 *     function (CallableContextInterface $ctx, ContextHandlerInterface $handler) use ($logger) {
 *         $logger->info('Before');
 *         $result = $handler->handle($ctx);
 *         $logger->info('After');
 *         return $result;
 *     }
 * );
 *
 * // Before-only interceptor
 * $validation = CallbackInterceptorFactory::before(
 *     function (CallableContextInterface $ctx) {
 *         if (!isset($ctx->parameters['id'])) {
 *             throw new InvalidArgumentException('Missing id');
 *         }
 *     }
 * );
 *
 * // After-only interceptor
 * $transform = CallbackInterceptorFactory::after(
 *     fn (mixed $result) => ['data' => $result]
 * );
 * ```
 */
final class CallbackInterceptorFactory
{
    /**
     * Creates an interceptor from a full intercept callback.
     *
     * @param callable(CallableContextInterface, ContextHandlerInterface): mixed $callback
     */
    public static function create(callable $callback): InterceptorInterface
    {
        return new CallbackInterceptor($callback);
    }

    /**
     * Creates an interceptor that executes before the handler.
     *
     * The callback can modify the context by returning a new instance.
     * If null is returned, the original context is used.
     *
     * @param callable(CallableContextInterface): ?CallableContextInterface $callback
     */
    public static function before(callable $callback): InterceptorInterface
    {
        return new CallbackInterceptor(
            static function (CallableContextInterface $context, ContextHandlerInterface $handler) use ($callback): mixed {
                $modified = $callback($context);
                return $handler->handle($modified ?? $context);
            }
        );
    }

    /**
     * Creates an interceptor that executes after the handler.
     *
     * The callback receives the result and can transform it.
     *
     * @param callable(mixed, CallableContextInterface): mixed $callback
     */
    public static function after(callable $callback): InterceptorInterface
    {
        return new CallbackInterceptor(
            static function (CallableContextInterface $context, ContextHandlerInterface $handler) use ($callback): mixed {
                $result = $handler->handle($context);
                return $callback($result, $context);
            }
        );
    }

    /**
     * Creates an interceptor that wraps execution in a try-catch.
     *
     * @param callable(\Throwable, CallableContextInterface): mixed $callback Exception handler.
     */
    public static function catch(callable $callback): InterceptorInterface
    {
        return new CallbackInterceptor(
            static function (CallableContextInterface $context, ContextHandlerInterface $handler) use ($callback): mixed {
                try {
                    return $handler->handle($context);
                } catch (\Throwable $e) {
                    return $callback($e, $context);
                }
            }
        );
    }

    /**
     * Creates an interceptor that always executes a finally callback.
     *
     * @param callable(CallableContextInterface): void $callback
     */
    public static function finally(callable $callback): InterceptorInterface
    {
        return new CallbackInterceptor(
            static function (CallableContextInterface $context, ContextHandlerInterface $handler) use ($callback): mixed {
                try {
                    return $handler->handle($context);
                } finally {
                    $callback($context);
                }
            }
        );
    }

    /**
     * Creates an interceptor that wraps execution with before and after callbacks.
     *
     * @param callable(CallableContextInterface): ?CallableContextInterface $before
     * @param callable(mixed, CallableContextInterface): mixed $after
     */
    public static function around(callable $before, callable $after): InterceptorInterface
    {
        return new CallbackInterceptor(
            static function (CallableContextInterface $context, ContextHandlerInterface $handler) use ($before, $after): mixed {
                $modified = $before($context);
                $result = $handler->handle($modified ?? $context);
                return $after($result, $modified ?? $context);
            }
        );
    }
}