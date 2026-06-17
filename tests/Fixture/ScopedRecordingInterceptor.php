<?php

declare(strict_types=1);

namespace Componenta\Interceptor\Tests\Fixture;

use Componenta\Interceptor\CallableContextInterface;
use Componenta\Interceptor\ContextHandlerInterface;
use Componenta\Interceptor\InterceptorInterface;
use Componenta\Scope\ScopeInterface;
use Componenta\Scope\ScopedInterface;
use Componenta\Scope\Scopes;

/**
 * Recording interceptor with instance-level scope restriction.
 */
final class ScopedRecordingInterceptor implements InterceptorInterface, ScopedInterface
{
    public Scopes $scopes {
        get => Scopes::from($this->scopeItems);
    }

    /**
     * @param array<int, string> $log
     * @param list<ScopeInterface> $scopes
     */
    public function __construct(
        private readonly string $name,
        private array &$log,
        array $scopes,
    ) {
        $this->scopeItems = $scopes;
    }

    /**
     * @var list<ScopeInterface>
     */
    private readonly array $scopeItems;

    public function intercept(CallableContextInterface $context, ContextHandlerInterface $handler): mixed
    {
        $this->log[] = $this->name . ':before';
        $result = $handler->handle($context);
        $this->log[] = $this->name . ':after';

        return $result;
    }
}
