<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

use Componenta\Interceptor\Internal\ArraySlots;
use Componenta\Interceptor\Internal\CallbackInterceptor;
use Componenta\Interceptor\Internal\NativeCallable;

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
    public static function create(callable|string|array $callback): InterceptorInterface
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
    public static function before(callable|string|array $callback): InterceptorInterface
    {
        $callback = NativeCallable::capture($callback);
        return new CallbackInterceptor(
            static function (CallableContextInterface $context, ContextHandlerInterface $handler) use ($callback): mixed {
                return $handler->handle(self::applyBefore($callback, $context));
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
    public static function after(callable|string|array $callback): InterceptorInterface
    {
        $callback = NativeCallable::capture($callback);
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
    public static function catch(callable|string|array $callback): InterceptorInterface
    {
        $callback = NativeCallable::capture($callback);
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
    public static function finally(callable|string|array $callback): InterceptorInterface
    {
        $callback = NativeCallable::capture($callback);
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
    public static function around(callable|string|array $before, callable|string|array $after): InterceptorInterface
    {
        // Capture both callback specifications before validating either one.
        // Validating the first may execute an application autoloader.
        if (is_array($after)) {
            $after = ArraySlots::snapshot($after);
        }
        $before = NativeCallable::capture($before);
        $after = NativeCallable::capture($after);
        return new CallbackInterceptor(
            static function (CallableContextInterface $context, ContextHandlerInterface $handler) use ($before, $after): mixed {
                $modified = self::applyBefore($before, $context);
                $result = $handler->handle($modified);
                return $after($result, $modified);
            }
        );
    }

    private static function applyBefore(callable $callback, CallableContextInterface $context): CallableContextInterface
    {
        // Only the callback's return value may replace the current context.
        // A callback accepting its argument by reference can reassign its local slot.
        $input = $context;
        $modified = $callback($input);
        if ($modified === null) {
            return $context;
        }
        if (!$modified instanceof CallableContextInterface) {
            throw new \TypeError('Before interceptor callback must return a callable context or null.');
        }

        return $modified;
    }
}
