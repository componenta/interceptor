<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture\Attribute;

use Attribute;
use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;

/**
 * Attribute that IS the interceptor (no factory indirection).
 *
 * Wraps the downstream result with a marker prefix - the wrapped result
 * lets tests confirm the attribute was actually invoked without sharing
 * mutable state across cases.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class WrapResultAttribute implements InterceptorInterface
{
    public function __construct(
        public string $marker,
    ) {}

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        return $this->marker . ':' . $handler->handle($context);
    }
}
