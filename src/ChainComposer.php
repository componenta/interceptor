<?php

declare(strict_types=1);

namespace Componenta\Interceptor;

/** Composes interceptors in declaration order around the supplied terminal. */
final class ChainComposer
{
    /** @param list<InterceptorInterface> $interceptors */
    public static function compose(array $interceptors, ContextHandlerInterface $terminal): ContextHandlerInterface
    {
        $handler = $terminal;
        for ($i = count($interceptors) - 1; $i >= 0; --$i) {
            $handler = new InterceptorHandler($interceptors[$i], $handler);
        }
        return $handler;
    }
}
