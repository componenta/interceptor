<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

/**
 * Folds a list of interceptors into a {@see ContextHandlerInterface} chain
 * rooted at a given terminal.
 *
 * The first interceptor in the input list becomes the outermost wrapper
 * (FIFO on the entry path: first-registered runs first on entry, last
 * on unwind). When $weakInnermost is true, the deepest wrapper holds
 * the terminal weakly via {@see WeakTerminalHandler} - required when
 * the composed chain is retained in a long-lived cache that uses the
 * terminal as a {@see \WeakMap} key.
 *
 * Empty input list returns the terminal unchanged.
 */
final class ChainComposer
{
    /**
     * @param list<InterceptorInterface> $interceptors
     */
    public static function compose(
        array $interceptors,
        ContextHandlerInterface $terminal,
        bool $weakInnermost = false,
    ): ContextHandlerInterface {
        $handler = null;

        for ($i = count($interceptors) - 1; $i >= 0; $i--) {
            $interceptor = $interceptors[$i];

            $handler = $handler === null
                ? ($weakInnermost
                    ? new WeakTerminalHandler($interceptor, $terminal)
                    : new InterceptorHandler($interceptor, $terminal))
                : new InterceptorHandler($interceptor, $handler);
        }

        return $handler ?? $terminal;
    }
}
