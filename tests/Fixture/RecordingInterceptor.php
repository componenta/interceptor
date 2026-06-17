<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;

/**
 * Records "{name}:before" and "{name}:after" markers around the inner call.
 *
 * Lets tests assert observable execution order without coupling to internals.
 */
final class RecordingInterceptor implements InterceptorInterface
{
    /** @param array<int, string> $log Shared log buffer (passed by reference). */
    public function __construct(
        private readonly string $name,
        private array &$log,
    ) {}

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        $this->log[] = $this->name . ':before';
        $result = $handler->handle($context);
        $this->log[] = $this->name . ':after';

        return $result;
    }
}
